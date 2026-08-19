# Session Log: OAuth2 Fixes Deployed And Verified; Third And Fourth Defects Found

**Date:** 2026-08-19
**Participants:** ys2n, Claude Code
**Outcome:** Fixes and deploys both OAuth2 defects filed 2026-08-18
([oauth2-signing-keys-not-persisted-across-deploy.md](../deferred/oauth2-signing-keys-not-persisted-across-deploy.md),
[solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](../deferred/solr-proxy-genericprovider-no-bearer-header-on-userinfo.md)),
verifies both live with hard evidence. Re-running the full 1b.3 chain surfaces a
**third** defect (a scope-configuration gap — the `openid` scope granted no
permission) — root-caused, decided, fixed, and **confirmed correct live against a real
token** (PRs #124, #125). That surfaces a **fourth**, distinct, not-yet-root-caused
issue right behind it — a session-handling redirect loop for stateless
Bearer-authenticated requests — left for a fresh session. Full detail:
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

## But the chain still doesn't complete — a third defect (a scope-configuration gap, not a bug)

`/oauth/userinfo` still returns a `302` HTML redirect (to `/`, not `/user/login` this
time) instead of JSON. Root-caused via a sequence of direct `drush php:eval` checks
against the exact token entity involved (not guessed), then confirmed precisely by
reading `simple_oauth` 6.1.1's own source:

- `TokenAuthUser::getRoles()` → `["authenticated"]` — correctly resolved.
- `TokenAuthUser->hasPermission('access content')` → **NO**, while the same
  `permission_checker` service called on a **real `User` entity** with the identical
  role → **YES**.
- Yuji recalled that simple_oauth requires an explicit scope grant for permissions —
  correct, and confirmed by reading the source: `Oauth2AccessPolicy::alterPermissions()`
  (added by security advisory **SA-CONTRIB-2025-114**) intersects the user's real
  permissions with only what the token's granted OAuth2 *scopes* explicitly confer via a
  `ScopeGranularity` plugin (`Permission` or `Role`, both available but unused here).
  Our token's only scope, `openid`, is `umbrella: true` with no granularity configured —
  by design, an identity-only scope (Spike 10), never wired to grant any permission. The
  intersection with an empty grant is always empty, for any user, regardless of role.

Not a Drupal core or simple_oauth bug — a scope-configuration gap left over from Spike
10 that nothing had exercised against a real permission-gated route until this session.
Two fix directions, needing a human call on scope design rather than a pure code fix:
add `Permission` granularity directly to `openid` (simplest, mixes identity/authz), or
add a dedicated second scope for authorization and request both together. Full writeup:
[the corrected deferred note](../deferred/simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md).

## Scope fix: decided, applied, tested on dev-0 directly, and confirmed correct

Yuji's call: **reuse the `openid` scope** — the established pattern for this project —
rather than adding a second scope. First attempt added `Permission` granularity
(`access content`) to `openid` but left `umbrella: true`; tested as correct in DDEV
(`Oauth2Scope::getPermissions()` returned `["access content"]`) and PR #124 merged, but
**failed identically live** after a full pipeline redeploy.

Root cause of the miss: `Oauth2AccessPolicy` actually calls
`Oauth2ScopeProvider::getPermissions($scope)`, not the entity's own
`getPermissions()` — and that method checks `isUmbrella()` *first*: true means it
ignores the scope's own granularity and unions permissions from child scopes instead
(`openid` has none), making the granularity config dead code on this exact path. The
module's own admin form enforces this by force-nulling the granularity fields whenever
`umbrella` is checked; the hand-edited YAML bypassed the UI constraint, not the
functional one.

**Per Yuji's request, tested the correction directly on dev-0 before a full pipeline
redeploy** — `drush config:set --input-format=yaml simple_oauth.oauth2_scope.openid
umbrella false` (the plain-string form silently no-ops on booleans; needs
`--input-format=yaml` to actually parse `false` as boolean rather than a truthy
string — cost one failed attempt). Confirmed against the real token from a fresh live
SAML→OAuth2 walkthrough, not synthetic: `TokenAuthUser->hasPermission('access
content')` → **YES** (was NO), and
`access_manager->checkNamedRoute('simple_oauth.userinfo', ..., $account)` → **isAllowed:
YES**. The scope-permission mechanism is now genuinely correct. PR #125 committed the
matching config change and merged.

## A fourth, distinct defect — not yet root-caused

With the scope fix live, `/oauth/userinfo` still doesn't return JSON, but the failure
mode changed: watchdog no longer logs `access denied` — instead repeated `Session
closed for Nicholas Osborne` / `session_destroy(): Trying to destroy uninitialized
session` pairs, and the HTTP response is a redirect loop between `/oauth/userinfo` and
`/` (the proxy's Guzzle client hits `GuzzleHttp\Exception\TooManyRedirectsException`
after 5 hops). Looks like Drupal's session-handling layer reacting badly to a
*stateless* Bearer-authenticated request that resolves to a real user identity with no
matching session cookie — repeatedly treating it as a logout event. This is a
genuinely new problem, only reachable now that the scope-permission fix works.

## What's proven vs. still open

**Proven, with live evidence:**
- Both 2026-08-18 defects are genuinely fixed, deployed, and working as designed.
- SAML login → real Drupal session → full OAuth2 authorization-code exchange → Bearer
  token correctly sent and authenticated by Drupal, all end to end with a real user.
- The `openid` scope's permission grant is fixed and confirmed correct at the Drupal
  access-control layer (`hasPermission`, `access_manager`), against a real token.

**Still open:**
- A fourth, distinct, not-yet-root-caused session-handling issue blocks the actual
  end-to-end `/oauth/userinfo` HTTP response, despite access now correctly being
  allowed.
- Once that's fixed, the proxy's own Redis visibility-token read for a real
  OAuth2-authenticated session remains the next and (as far as currently known) last
  unproven link in 1b.3.

## Next-session starting point

Root-cause the session-handling redirect loop — likely needs stepping through
Drupal core's session subsystem (`SessionManager`, whatever triggers `session_destroy()`
mid-request) to find what's treating a stateless Bearer-authenticated request as a
logout trigger. Deliberately not investigated further this session — a fresh session
with clear head room is a better fit than continuing to dig at the end of a long one.
