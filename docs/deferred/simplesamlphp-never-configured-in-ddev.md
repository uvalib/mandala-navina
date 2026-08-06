# SimpleSAMLphp SP has never been configured in DDEV — deferred, but design it deliberately

**Area:** infrastructure / DDEV / local dev environment / SAML
**Raised during:** Session 2026-08-06 (PR #75 DDEV-readiness check)
**Jira:** (add when available)
**Priority:** Medium — not breaking anything today, deliberately deferred; noted so it isn't
rediscovered as a surprise outage in local dev, and so the eventual fix is designed rather
than bolted on under pressure.

## What's true today

**Dev/staging/prod run SimpleSAMLphp as a split, two-container architecture**, per
`terraform-infrastructure/mandala/drupal/staging/ansible/deploy_netbadge.yml`:

- The SP itself runs in its own container, `netbadge-0` (network alias `sp`), serving
  `/simplesaml/*`.
- Drupal's Apache reverse-proxies `/simplesaml/` to it
  (`package/data/files/etc/apache2/sites-available/000-default.conf:36-43`) and sets
  `SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config` so the Drupal container's own
  composer-vendored copy of the library can validate sessions in-process.
- Real `config.php`/`authsources.php` are committed Ansible templates
  (`.../ansible/files/var/simplesamlphp/config/`), landed on the host and bind-mounted
  into both containers at deploy time.
- Cert/key material is decrypted from committed `.pem.cpt` files, never plaintext in git.
- Both containers share a Redis store for SAML sessions (`SIMPLESAML_STORE_TYPE=redis`,
  db 4) — see [redis-enterprise-store-location.md](redis-enterprise-store-location.md) and
  [ADR 014](../adr/014-hybrid-solr-proxy-design.md).
- Dev specifically sets `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true`, turning on
  SimpleSAMLphp's built-in example-userpass IdP so login can be exercised without real
  UVA NetBadge access.

**DDEV only has the Drupal-side half.** `simplesamlphp_auth` is enabled and its Drupal
config (`simplesamlphp_auth.settings.yml`) imports cleanly — but the underlying
`simplesamlphp/simplesamlphp` library (vendored via Composer, same as dev) has **no site
config at all**. Only the packaged `.dist` templates exist under `vendor/` — no real
`config.php`/`authsources.php` were ever generated. There is no `netbadge-0`-equivalent
service in `.ddev/`, no proxy rule, no `SIMPLESAMLPHP_CONFIG_DIR` env var, no cert/key
material.

**This is not a regression — it has never existed.** [Spike 10](../spikes/spike-10-saml-oauth2-coexistence.md)
(the SAML+OAuth2 coexistence proof, run in DDEV) planned to stand up the built-in test
IdP, but the actual run substituted `drush user:login` as a stand-in and explicitly
noted walking the real test IdP was optional "belt-and-suspenders" confirmation — and
skipped it. Local dev has always relied on `simplesamlphp_auth.settings.yml`'s
`default_login`/admin-fallback path (or a straight Drupal login) instead of exercising
real SAML.

## Why it hasn't caused a problem yet

Normal DDEV work never touches the actual SimpleSAMLphp bootstrap path — clicking
"Netbadge Login," or any code that instantiates `SimplesamlphpAuthManager`, would fail
today (no config for the library to load), but nothing in the day-to-day dev loop
exercises that path. The gap is invisible until someone specifically needs to test
SAML-authenticated sessions locally.

## The ask: don't fix this reactively

**Decision (Yuji, 2026-08-06):** defer the fix, but design it — don't let a future
session bolt on an ad hoc `config.php` under time pressure (e.g., mid-verification for
some other PR) and leave it as more untracked local drift. When this is picked up, it
should be a deliberate, checked-in mechanism, not a one-off local file:

1. **Mirror dev's example-auth pattern, not a bespoke DDEV shortcut.** Dev already has a
   documented, working "no real IdP needed" mode (`SIMPLESAML_ENABLE_EXAMPLE_AUTH`) —
   the DDEV setup should reuse that same activation path rather than inventing a
   separate local-only auth mechanism that could behave differently from dev.
2. **Decide where the SP runs in DDEV.** Options to weigh, not yet decided: a second
   DDEV service mirroring `netbadge-0` (closest to prod topology, more moving parts), or
   configuring the Drupal container's own vendored SimpleSAMLphp copy directly with a
   local `config.php`/`authsources.php` (simpler, but drifts from the two-container
   prod shape and doesn't exercise the Apache `ProxyPass` rule at all).
3. **Whatever config file(s) result should be a real, checked-in DDEV artifact** (or a
   `post-start` hook that generates it), not something a developer creates locally and
   never commits — that's exactly how this gap re-forms after being "fixed" once.
4. **Confirm scope first:** does anything currently planned (e.g. PR #75's
   `content_editor` verification checklist) actually need real SAML sessions, or does
   `drush user:login`/direct role assignment cover it? If nothing near-term needs it,
   this can sit exactly as deferred as it is now.

## Cross-references

- [Spike 10 — SAML + OAuth2 coexistence](../spikes/spike-10-saml-oauth2-coexistence.md) — proved coexistence using `drush user:login` as a stand-in; never exercised the real test IdP in DDEV
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md) — the shared-Redis half of the session-store design
- [redis-enterprise-store-location.md](redis-enterprise-store-location.md) — the two-Redis-store context this shares
- [saml-alb-routing-assumes-mod-shib.md](saml-alb-routing-assumes-mod-shib.md) — a different SAML/NetBadge gap (ALB routing on AWS), unrelated mechanism, same subsystem
- `terraform-infrastructure/mandala/drupal/staging/ansible/deploy_netbadge.yml` — the dev/staging deploy mechanism this note describes
- `package/data/files/etc/apache2/sites-available/000-default.conf` — the ProxyPass rule DDEV has no equivalent for
