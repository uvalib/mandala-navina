# Session Log: OAuth2 Fixes Deployed And Verified; Third Defect Found

**Date:** 2026-08-19
**Participants:** ys2n, Claude Code
**Outcome:** Fixes and deploys both OAuth2 defects filed 2026-08-18
([oauth2-signing-keys-not-persisted-across-deploy.md](../deferred/oauth2-signing-keys-not-persisted-across-deploy.md),
[solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](../deferred/solr-proxy-genericprovider-no-bearer-header-on-userinfo.md)),
verifies both live with hard evidence, then re-running the full 1b.3 chain surfaces a
**third**, independent, previously-hidden defect in `simple_oauth`'s interaction with
Drupal core's Access Policy API — written up as
[simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md](../deferred/simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md).

---

*Hand-abridged — this session reused the same real migrated user's account for testing*
*as 2026-08-18 (referred to below only as "uid 600"); name/email intentionally omitted.*

---

## Correction before any code changed

Both 2026-08-18 deferred notes said the Bearer-header bug lived in "a different repo,
`uvalib/mandala-solr-proxy`." That repo doesn't exist on GitHub. ADR 014 forked the D11
proxy into this monorepo at `solr-proxy/` back in 1b.1 (commit `1b8e682`); the name in
the notes was mistaken for the ECR *container image* repo, which is genuinely named
`uvalib/mandala-solr-proxy` in the registry. Corrected both deferred notes, the sprint
doc, the prior session log (with an addendum, not a rewrite), and local memory before
starting any fix. Practical effect: both defects were fixable directly in local repos,
no missing clone needed.

Also confirmed, by reading the legacy D7 proxy directly
(`mandala-legacy/mandala-solr-proxy`, cloned locally): it has the **identical**
un-overridden `GenericProvider`, so the Bearer-header bug isn't a D11-introduced
regression — it was inherited from D7 and (per the D7 team's design intent that D11
should be based on it) had presumably been just as latent there, never exercised
end-to-end with a real browser on either codebase before 2026-08-18.

## Fix 1: solr-proxy Bearer header

Added `solr-proxy/proxy/src/BearerGenericProvider.php` — a thin subclass overriding
`getAuthorizationHeaders()` to add `Authorization: Bearer <token>`, autoloaded via the
PSR-4 `mandala\oauth\` → `src/` mapping already declared in `composer.json` (unused
until now). `auth.php` now instantiates that instead of the bare `GenericProvider`.
Verified locally first with a standalone script confirming
`getAuthenticatedRequest()` now carries the header. [PR #121](https://github.com/uvalib/mandala-navina/pull/121),
merged.

## Fix 2: OAuth2 signing-key persistence

`terraform-infrastructure/mandala/drupal/staging/ansible/deploy_backend.yml` (commit
`29bdb6cc9`, committed straight to `master` per that repo's no-branches convention):
added a host-mounted `keys/` directory (same pattern as the existing `simplesamlphp/*`
mounts) plus an idempotent generate-if-missing step — checks for `private.key` before
running `drush simple-oauth:generate-keys`, only regenerates and `chown`s to
`www-data:www-data` when missing. Deliberately idempotent: regenerating on every deploy
would invalidate every outstanding token, not just recover a genuinely missing keypair.

## Deploying both

PR #121 only touches `solr-proxy/**` — the monorepo's path-filtered CI/CD keeps that
pipeline (`uva-mandala-solr-proxy-codepipeline`) separate from the one that actually
deploys to dev-0 (`uva-mandala-drupal-codepipeline`), by design (a solr-proxy-only
change shouldn't trigger a full `drush updb`+`cim` run). Merging built and pushed a new
solr-proxy image automatically; getting it (and the terraform-infrastructure fix) onto
the box needed a manual `aws codepipeline start-pipeline-execution` against the
`mandala-drupal` pipeline, which runs `deploy_redis` → `deploy_netbadge` →
`deploy_solrproxy` → `deploy_backend` in one pass. Ran clean end to end in ~8 minutes
(faster than 2026-08-18's ~26 minutes). Confirmed both containers running today's fresh
builds, and the keys directory correctly generated on the host with `www-data:www-data`
ownership, mode 600.

## Re-verification: reconstructing the SAML flow without a browser

The Chrome extension wasn't connected this session, so the full SAML → OAuth2 →
UserInfo chain was reproduced via SSH + curl directly on dev-0, replaying each real HTTP
exchange by hand: `GET /saml_login` → follow to the `example-userpass` login form →
parse and resubmit its `AuthState`-bearing action URL → parse the resulting auto-post
SAML response form → `POST` it to the ACS endpoint (landing at `/user/600`, confirming a
real Drupal session) → `GET` the real deployed proxy's `/auth` endpoint (not a
hand-crafted URL) to exercise the actual fixed code path end to end.

**Both fixes confirmed working, with direct evidence:**
- Added temporary `error_log()` debug output to the *live* container's
  `BearerGenericProvider.php` (approved by Yuji specifically for this — ephemeral,
  container-local, no secrets touched, removed immediately after) — confirmed `auth.php`
  now builds and sends a correct, well-formed Bearer JWT (`sub:"600"`,
  `aud:"solrproxy"`, `scope:["openid"]`).
- Drupal's response to that request carries `X-Consumer-ID: solrproxy` — proof the
  resource server validated the token and identified the consumer, which could not have
  happened without a header being present at all.
- The signing keys survived the redeploy correctly (confirmed by direct inspection, not
  just "no 500 seen").

## But the chain still doesn't complete — a third defect

`/oauth/userinfo` still returns a `302` HTML redirect (to `/`, not `/user/login` this
time) instead of JSON. Root-caused via a sequence of direct `drush php:eval` checks
against the exact token entity involved (not guessed):

- `Oauth2Token::getRoles()` → `[]` (expected — the `openid` scope is `umbrella: true`
  with no role-granting config, per Spike 10's design).
- `TokenAuthUser::getRoles()` → `["authenticated"]` — correctly resolved, combining the
  token's roles with the real user's own roles and the default authenticated role.
- `TokenAuthUser->hasPermission('access content')` → **NO**.
- The same `permission_checker` service called directly on a **real `User` entity**
  with the identical single role → **YES**.

So the role resolution is correct; the defect is specifically in how Drupal core's
Access Policy API (`AccessPolicyProcessorInterface`, D10.2+) computes permissions for
`simple_oauth`'s `TokenAuthUser` decorator — it isn't a real `User` entity, just an
`AccountInterface` implementation wrapping one, and something in that processing chain
returns an empty/negative result for it specifically. No mandala custom code is
involved (checked — no custom `AccessPolicy` plugin exists in this codebase). Full
writeup: [the new deferred note](../deferred/simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md).

## What's proven vs. still open

**Proven, with live evidence:**
- Both 2026-08-18 defects are genuinely fixed, deployed, and working as designed.
- SAML login → real Drupal session → full OAuth2 authorization-code exchange → Bearer
  token correctly sent and authenticated by Drupal, all end to end with a real user.

**Still open:**
- The `TokenAuthUser`/Access-Policy-API permission defect blocks `/oauth/userinfo` from
  ever returning JSON, regardless of anything on the proxy side.
- Once that's fixed, the proxy's own Redis visibility-token read for a real
  OAuth2-authenticated session remains the next and (as far as currently known) last
  unproven link in 1b.3.

## Next-session starting point

Check `drupal/simple_oauth`'s issue queue for a known Access Policy API incompatibility
(versions: `drupal/core` 11.3.11, `drupal/simple_oauth` 6.1.1, `drupal/consumers`
1.24.0) before attempting a fix — this may be an upstream bug rather than something to
patch locally.
