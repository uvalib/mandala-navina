# dev-0 needs a test IdP wired up — real NetBadge login isn't practical for 1b.3 testing

**Area:** infrastructure / SAML / SimpleSAMLphp / dev environment / 1b.3
**Raised during:** Session 2026-08-18 (predis/predis fix + first live NetBadge attempt)
**Jira:** (add when available)
**Priority:** **RESOLVED 2026-08-18** (same day, a second session). Was High — blocked
the live end-to-end proof for
[Sprint 1 task 1b.3](../sprints/sprint-01-images-implementation.md).

## ✅ Resolution (2026-08-18, later the same day)

Both open gaps below were closed. Full writeup:
[session log](../session-logs/2026-08-18-dev-test-idp-and-oauth2-userinfo-bug.md).

- **Attribute mismatch — fixed with a general mapping, not per-identity hardcoding.**
  Rather than editing each canned identity's static attribute array to carry a specific
  `unique_id` OID value (this note's first "option to weigh"), the hosted IdP config
  (`metadata/saml20-idp-hosted.php`, new) applies SimpleSAMLphp's standard
  `core:AttributeMap`/`name2oid` authproc filter, which translates *all* friendly
  attribute names (`uid`, `mail`, `eduPersonAffiliation`, ...) to their OID equivalents
  uniformly. Any of the three canned identities now resolves correctly, not just one
  hand-edited for the purpose.
- **`default-sp`'s `idp` now actually points at the test IdP** when
  `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true` — this note correctly identified that
  `simplesamlphp_auth.settings.yml`'s `auth_source: default-sp` was the missing wire,
  but the fix lives one level down: `authsources.php`'s `default-sp` entry itself now
  switches its `idp` target on the same flag, rather than changing which Drupal auth
  source is active.
- **Authmap row added.** The `staff` identity is now linked
  (`ExternalAuth::linkExistingAccount`) to a real migrated non-admin user with genuine
  private-collection membership — full identity intentionally kept out of this public
  repo, see `mandala-navina-docs` (private).
- **Two more real bugs surfaced and fixed getting this far** (both found by actually
  running the deploy, not by static check): a bogus `MYSQL_*` requirement in
  `deploy_netbadge.yml` copy-pasted from the dsf reference playbook, and a missing
  `enable.saml20-idp` config key with no default in SimpleSAMLphp core.
- **The cookie-domain question above was not resolved, just not hit.** Tonight's full
  walkthrough used `https://mandala-dev.internal.lib.virginia.edu` throughout — the
  same hostname the SP's `SIMPLESAML_SP_ENTITY_ID`/`BASE_URL`/`COOKIE_DOMAIN` are pinned
  to — and completed the full round trip (SAML login → Drupal session → OAuth2
  authorize/token exchange) successfully. Whether `mandala-images-dev` would still fail
  on a cookie-domain mismatch remains untested; use `mandala-dev` for SAML testing until
  someone checks.
- **Proven live, with a real non-admin user, not assumed:** SAML login → real Drupal
  session (verified via the `sessions` table) → full OAuth2 authorization-code exchange
  against the real `solrproxy` consumer, including `state` CSRF round-trip and
  `automatic_authorization`. That surfaced **two further, independent** OAuth2 defects
  (signing keys not persisted across deploy; the proxy's OAuth2 client never sends a
  Bearer token to `/oauth/userinfo`) — both written up as their own deferred notes,
  neither fixed tonight.

## Where this sits

1b.3 (Solr-proxy visibility coherence, [ADR 014](../adr/014-hybrid-solr-proxy-design.md))
is proven at the data/token level (real user, real token, real D11 kmassets docs — see
the 2026-08-13 progress row in the sprint doc) but has never been proven through an
actual browser + NetBadge login. Today's session got the login *mechanism* itself
working (see
[simplesamlphp-redis-store-missing-predis.md](simplesamlphp-redis-store-missing-predis.md)
— `predis/predis` was missing, causing a hard 500 on every `/saml_login` attempt; fixed
and deployed) — `/saml_login` now correctly redirects to UVA's real production IdP,
`shibidp.its.virginia.edu`.

**But completing that flow means someone authenticating with a real UVA NetBadge
credential**, for an account that also happens to be a real migrated user with private
collection membership. The one such account confirmed today (uid 4, computing-id
`dfg9w`, real member of ~9 private collections from the completed user migration) maps
to a specific real person (Dave Goldstein, going by the initials) — not something to
casually borrow for ad hoc testing sessions.

## The decision: stand up a test IdP instead

**dev-0 already has the mechanism for this, just not wired to be used.** Confirmed live
today:

- `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true` is already set in dev-0's `netbadge-0`/
  `mandala-drupal-0` environment.
- `authsources.php` (env-driven, from `terraform-infrastructure`'s
  `ansible/saml_config`) already defines a built-in `example-userpass` auth source with
  three canned identities (`student:studentpass`, `staff:staffpass`,
  `faculty:facultypass`) — SimpleSAMLphp's standard no-real-IdP-needed test mechanism.
- This is the exact mechanism [simplesamlphp-never-configured-in-ddev.md](simplesamlphp-never-configured-in-ddev.md)
  describes dev already using conceptually — that note is about DDEV never having
  *any* SP config at all; dev-0 is different, it has the real mechanism present and
  enabled, just not the active `auth_source`.

**What's NOT wired:** Drupal's `simplesamlphp_auth.settings.yml` has
`auth_source: default-sp` — the real-IdP-backed source — not `example-userpass`. Two
non-trivial gaps to close before this actually produces a usable 1b.3 test, found while
scoping today (not yet solved):

1. **Attribute mismatch.** `simplesamlphp_auth.settings.yml`'s `unique_id` is
   `urn:oid:0.9.2342.19200300.100.1.1` (the real NetBadge computing-id attribute).
   `example-userpass`'s three canned identities don't emit that attribute at all — they
   only carry `uid`, `eduPersonAffiliation`, `eduPersonScopedAffiliation`, `mail`,
   `displayName`, `givenName`, `sn`. Switching `auth_source` to `example-userpass`
   as-is will fail to resolve any existing Drupal account, since the module looks for
   an attribute that source never sends.
2. **No matching authmap entry.** Even with attribute mapping fixed, `register_users`
   is `false` (correct, keep it that way) — so whichever identity logs in via
   `example-userpass` needs an `authmap` row (`provider = simplesamlphp_auth`,
   `authname` = whatever value ends up in the resolved unique-id attribute) pointing at
   a real migrated `shanti_image` user who actually has private-collection membership.
   None of the three canned identities (`student`/`staff`/`faculty`) currently has one.

**Options to weigh (not decided):**
- Edit `example-userpass`'s static attribute array in `authsources.php` (env-templated,
  committed in `terraform-infrastructure`) to add the `unique_id` OID attribute with a
  value matching an existing authmap `authname` for a real migrated private-collection
  user (e.g., point the `staff` identity's OID attribute at `dfg9w`, or pick a less
  personally-identifying account if one with private membership exists).
- Or add a brand-new synthetic authmap row + a purpose-built test user with real
  private-collection membership (mirrors what today's session did programmatically via
  `mandala_solr_visibility.token_builder`, but this time wired through the real
  attribute → authmap → login path instead of a script).
- Either way, this should be a **committed, deliberate mechanism** (matching the
  `simplesamlphp-never-configured-in-ddev.md` note's stated preference — "don't bolt
  this on reactively") since it'll get reused every time 1b.3 (or any future
  SAML-session-dependent work) needs testing.

## One more open thread from today, unverified

`/saml_login` was tested via `https://mandala-images-dev.internal.lib.virginia.edu` —
the hostname the team currently uses for HTTPS access to dev-0. But the SP's own
configured identity (`SIMPLESAML_SP_ENTITY_ID`, `SIMPLESAML_BASE_URL`, and
`SIMPLESAML_COOKIE_DOMAIN`) are all pinned to a *different* hostname,
`mandala-dev.internal.lib.virginia.edu`. The redirect-to-IdP leg worked fine over the
`images-dev` hostname, but the session cookie SimpleSAMLphp sets is scoped to
`mandala-dev` — **the assertion-consumer-service callback leg (IdP → back to Drupal)
was not tested today** and may fail on a cookie-domain mismatch if the login is driven
from `mandala-images-dev`. Worth confirming which hostname is actually correct to use
before the next live-login attempt, rather than assuming the redirect succeeding means
the whole flow will.

## Cross-references

- [simplesamlphp-redis-store-missing-predis.md](simplesamlphp-redis-store-missing-predis.md) — today's fix that unblocked the login mechanism itself
- [simplesamlphp-never-configured-in-ddev.md](simplesamlphp-never-configured-in-ddev.md) — the analogous DDEV gap; same example-auth mechanism, different environment
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — what 1b.3's live test needs to prove
- [Sprint 1 / 1b.3](../sprints/sprint-01-images-implementation.md) — the task this blocks
