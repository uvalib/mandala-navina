# SimpleSAMLphp Redis session store was missing predis/predis — every NetBadge login 500'd

**Area:** infrastructure / SAML / SimpleSAMLphp / Composer dependencies / dev environment
**Raised during:** Session 2026-08-18 (attempted first live 1b.3 NetBadge login)
**Jira:** (add when available)
**Priority:** High — blocked all NetBadge login on dev-0.

**Status: RESOLVED 2026-08-18.** [PR #119](https://github.com/uvalib/mandala-navina/pull/119),
merged and deployed same day.

## What we found

Clicking NetBadge login on dev-0 produced a white screen (HTTP 500, no Drupal error
page rendered). `watchdog:show` surfaced a `SimpleSAML\Error\MetadataNotFound` for the
UVA IdP entity — which turned out to be a red herring; the IdP metadata file was
present, syntactically valid, and correctly loaded when queried directly via
SimpleSAMLphp's `MetaDataStorageHandler`.

The real cause only surfaced by simulating exactly what `simplesamlphp_auth` does
(`new SimpleSAML\Auth\Simple('default-sp')`) directly inside the running container:

```
SimpleSAML\Error\CriticalConfigurationError: The configuration is invalid: predis/predis is not available.
  .../simplesamlphp/simplesamlphp/src/SimpleSAML/Store/RedisStore.php:36
```

dev-0 is configured for `SIMPLESAML_STORE_TYPE=redis` (session storage), and
SimpleSAMLphp's `RedisStore` hard-requires the **`predis/predis` Composer package**
(`Predis\Client`) — not the native `ext-redis` PHP extension, which *is* present on the
box but is irrelevant to this code path (`RedisStore.php` explicitly checks
`class_exists(Predis\Client::class)`). `predis/predis` was only ever a
`require-dev`/`suggest` of `simplesamlphp/simplesamlphp` itself, never pulled into
`drupal/composer.json`. So `Auth\Simple`'s constructor — the very first thing that
runs for any login attempt — threw before any SAML/metadata logic executed at all.

All `store.redis.*` config wiring on dev-0 (env vars → `config.php`) was already
correct; this was purely a missing dependency, not a config gap.

## The fix

Added `predis/predis: ^3.3` to `drupal/composer.json` (matching the version
`simplesamlphp/simplesamlphp`'s own test suite pins) via `ddev composer require`, which
regenerated `composer.lock` (predis v3.6.0 resolved, no conflicts, no new security
advisories per `composer audit`).

**Verified end-to-end after deploy** (not just locally): `predis/predis` present in
dev-0's deployed `vendor/`; `class_exists('Predis\Client')` true; a direct
`Auth\Simple::getLoginURL()` call succeeds; and over real HTTP, `/saml_login` now
returns `303` redirecting to UVA's real production IdP
(`shibidp.its.virginia.edu/idp/profile/SAML2/Redirect/SSO?SAMLRequest=...`) instead of
a 500.

## What this does NOT close

Getting a valid `303` redirect to the IdP proves the *first leg* of the SAML flow
works. It does not prove the full round trip (IdP → assertion → Drupal session →
Redis visibility token), and a real NetBadge login wasn't completed this session — see
[dev-0-needs-test-idp-for-saml-login-testing.md](dev-0-needs-test-idp-for-saml-login-testing.md)
for why (borrowing a real person's UVA credential isn't a good fit for routine testing)
and what's needed to close that gap.

## Cross-references

- [dev-0-needs-test-idp-for-saml-login-testing.md](dev-0-needs-test-idp-for-saml-login-testing.md) — the next blocker in the same chain
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the Redis-backed design this session store is part of
- [redis-enterprise-store-location.md](redis-enterprise-store-location.md) — the shared-Redis context (SAML sessions use db 4, distinct from the visibility-token store)
- [Sprint 1 / 1b.3](../sprints/sprint-01-images-implementation.md) — the task this was blocking
