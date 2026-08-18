# Session Log: Dev Test IdP Setup And OAuth2 UserInfo Bug

**Date:** 2026-08-18 (second session of the day — continues directly from Than Grove's
earlier session the same afternoon)
**Participants:** ys2n, Claude Code
**Outcome:** Resolves
[dev-0-needs-test-idp-for-saml-login-testing.md](../deferred/dev-0-needs-test-idp-for-saml-login-testing.md),
the deferred note Than's session filed a few hours earlier after fixing the
`predis/predis` dependency gap that had been 500ing every `/saml_login` attempt (see
[simplesamlphp-redis-store-missing-predis.md](../deferred/simplesamlphp-redis-store-missing-predis.md),
PR #119) and getting as far as a correct redirect to UVA's real IdP, but stopping short
of a full login (Than's note correctly flagged that borrowing a real UVA NetBadge
credential — Dave Goldstein's, the one confirmed private-collection member found that
session — isn't a good fit for routine testing, and scoped two gaps: an attribute
mismatch between the built-in `example-userpass` test identities and what
`simplesamlphp_auth` expects, and no `authmap` row linking any of them to a real account).
This session closed both gaps and proved the full chain — SAML login through the new
test IdP → real non-admin Drupal session → OAuth2 authorization-code exchange — with a
real, group-scoped, non-admin migrated user (a different account than the one Than's
session identified). Found and fixed two further live bugs along the way (a bogus
`MYSQL_*` requirement in `deploy_netbadge.yml`, a missing `enable.saml20-idp` config
key). That full walkthrough then surfaced two further, independent, previously-uncaught
OAuth2 defects — written up as deferred notes rather than fixed tonight:
[oauth2-signing-keys-not-persisted-across-deploy.md](../deferred/oauth2-signing-keys-not-persisted-across-deploy.md)
(worked around live on dev-0) and
[solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](../deferred/solr-proxy-genericprovider-no-bearer-header-on-userinfo.md)
(still open — different repo, `uvalib/mandala-solr-proxy`).

---

*Hand-abridged, not the raw transcript — this session involved a real migrated user's*
*account for testing (referred to below only as "uid 600"); name/email are intentionally*
*omitted from this public repo.*

---

## What was built

Dev already had `netbadge-0` deployed with `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true`
(enabling the `example-userpass` authsource), but nothing actually routed Drupal's
"Netbadge Login" through it — `default-sp`'s `idp` pointed at the real UVA IdP. Added,
gated entirely behind the existing `SIMPLESAML_ENABLE_EXAMPLE_AUTH` flag (so
staging/production are untouched):

- `metadata/saml20-idp-hosted.php` — this SimpleSAMLphp instance also acts as a hosted
  IdP, authenticating via `example-userpass`, reusing the SP's own key pair (a
  self-contained test loop, not a real trust boundary).
- `metadata/saml20-sp-remote.php` — the SP's own metadata, so the local IdP trusts it
  back.
- `metadata/saml20-idp-remote.php` — added the local IdP as a trusted entry so
  `default-sp` accepts it (alongside the existing real UVA IdP entry, untouched).
- `config/authsources.php` — `default-sp`'s `idp` now resolves to the local test IdP
  when the flag is on, instead of the real UVA IdP.

Committed to `terraform-infrastructure` (`7ee3d2441`, `34febafd4` — that repo has no
branches/PRs, commits publish directly per team convention there).

## Two bugs found by actually running it, not by static check

1. **`deploy_netbadge.yml` required `MYSQL_HOST/DATABASE/USER/PASSWORD`**, which
   netbadge-0 (SimpleSAMLphp only, Redis-backed store) never uses — copy-pasted from the
   `dsf.library.virginia.edu` reference playbook, which has the identical unused
   requirement (confirmed by reading dsf's own `config.php`: also Redis-backed). Removed
   from mandala's copy only.
2. **`config.php` never set `enable.saml20-idp`**, which has no default in SimpleSAMLphp
   core — the `saml` module's IdP frontend (`/idp/metadata`, `/idp/singleSignOnService`,
   etc.) throws `Could not retrieve the required option` without it. Added, gated on the
   same flag.

Verified live: `/simplesaml/module.php/saml/idp/metadata` returns `200` with valid
metadata for `urn:mandala:dev:test-idp`.

## Getting `deploy_backend.yml` to actually run

Needed a real `MYSQL_PASSWORD` in the gitignored, terraform-rendered
`container_0.env.generated` (stale locally — `CHANGE_ME` placeholder). Ran a
narrowly-scoped `terraform apply -target=local_file.environment` (a pure
read-the-existing-secret + write-a-local-file resource — no infrastructure change) to
refresh it; `terraform init` had gone stale (last run mid-2024) but re-initialized
cleanly.

Linked a `staff` SAML test identity (one of the three `example-userpass` fixture users)
to a real migrated Drupal account via `ExternalAuth::linkExistingAccount()` — the same
mechanism the "Enable this user to leverage SAML authentication" admin-UI checkbox uses —
chosen because it's a real non-admin user already proven (in an earlier session) to be a
member of real private D11 collections, so a login as this identity exercises the actual
ADR 014 visibility-filtering path, not just admin's bypass-everything case.

## Live browser walkthrough — proven

1. SAML login through the new test IdP → real Drupal session as the linked non-admin
   user (confirmed via the `sessions` table, not just "it looked right in the browser").
2. Full `/oauth/authorize?client_id=solrproxy&response_type=code&redirect_uri=...&scope=openid`
   → automatic-authorization (no confirmation screen) → redirect to the solr-proxy's
   `/auth` callback with a valid `code` + matching `state`.
3. Along the way, hit and diagnosed **three** more distinct failures before getting
   this far — none were config-file mistakes, all found by actually exercising the real
   path with a real browser:
   - `/oauth/authorize` initially failed with a `client_id` error → traced to
     `deploy_backend.yml` having wiped `simple_oauth`'s signing keypair, which lives
     outside any persistent bind mount. Regenerated by hand on dev-0 (see the deferred
     note — this will recur on the next normal deploy until fixed at the infra level).
   - A second attempt hit "Invalid state" at the proxy — self-inflicted: an earlier
     diagnostic curl had hand-crafted the `/oauth/authorize` URL directly, skipping the
     proxy's own `/auth` entry point that generates and stores `state`. Not a bug;
     starting from the proxy's real `/auth` URL fixed it.
   - The proxy's own token exchange (`POST /oauth/token`) succeeded cleanly (`200`), but
     the very next call (`GET /oauth/UserInfo`) came back `302` (a login redirect, not
     JSON) — traced via the access log, the `oauth2_token` entity table, and finally the
     vendored `league/oauth2-client` source to: the proxy's `GenericProvider` never
     overrides `getAuthorizationHeaders()`, so it sends **no** `Authorization: Bearer`
     header on the UserInfo call at all. Confirmed (not assumed) with a live test: a
     garbage Bearer token gets a clean `401` from the same route, proving the route
     itself and its case-sensitivity are fine — the header is just never sent. Written
     up as a deferred note rather than fixed tonight (different repo,
     `uvalib/mandala-solr-proxy`).

## What's proven vs. still open

**Proven, with live evidence, not assumption:**
- Real SAML login through a local test IdP, no UVA NetBadge needed.
- Real non-admin Drupal session establishment.
- Real OAuth2 authorization-code exchange against the real `solrproxy` consumer,
  including `state` CSRF protection and `automatic_authorization`.
- The issued access token is correctly scoped (right user, right client) in Drupal's own
  token storage.

**Still open, both written up as deferred notes:**
- OAuth2 signing keys aren't persisted across `deploy_backend.yml` runs — will silently
  break OAuth2 again on the next normal merge until fixed.
- The proxy's `/oauth/userinfo` call never sends a Bearer token — blocks the
  authenticated (private-collection) path through the proxy entirely, though the token
  exchange itself works.

Once the UserInfo bug is fixed, the next unproven link is the proxy's own Redis
visibility-token read for a *real* OAuth2-authenticated session (previously only proven
with a hand-written Redis key) — natural next step once picked back up.
