# SimpleSAMLphp SP has never been configured in DDEV — deferred, but design it deliberately

**Area:** infrastructure / DDEV / local dev environment / SAML
**Raised during:** Session 2026-08-06 (PR #75 DDEV-readiness check)
**Jira:** (add when available)
**Priority:** Medium for the core gap (not breaking anything today, deliberately deferred;
noted so it isn't rediscovered as a surprise outage in local dev, and so the eventual fix
is designed rather than bolted on under pressure). **Medium-High, 2026-10-06, for the
personal workaround specifically:** it breaks local logout entirely for anyone who has it
enabled — see "Correction" below.

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

## Optional personal workaround (2026-10-05) — suppresses the admin error message only, not a fix

An admin account (one holding `administer simplesamlphp authentication`) visiting an admin
route in DDEV sees: *"There is a Simplesamlphp configuration problem. The configuration
(config/config.php) is invalid: Missing configuration file."* This is
`SimplesamlphpAuthManager::getSimpleSamlConfiguration()`/`getSimpleSamlInstance()`
(`simplesamlphp_auth` module) catching the library's `CriticalConfigurationError` and calling
`$messenger->addError()` — gated on that permission + admin route, so it's cosmetic (an admin
notice), not a crash, and nothing else in normal DDEV use depends on the library actually
loading. It is the exact symptom this note describes, now that it has a user noticing it.

If the message itself is annoying and you don't need real SAML sessions locally, each developer
can give their own DDEV a throwaway library config, entirely outside git and outside Drupal's
config:

1. Copy the library's own `.dist` templates into an untracked directory:
   ```bash
   mkdir -p drupal/simplesamlphp-local
   cp drupal/vendor/simplesamlphp/simplesamlphp/config/config.php.dist drupal/simplesamlphp-local/config.php
   cp drupal/vendor/simplesamlphp/simplesamlphp/config/authsources.php.dist drupal/simplesamlphp-local/authsources.php
   cp drupal/vendor/simplesamlphp/simplesamlphp/config/acl.php.dist drupal/simplesamlphp-local/acl.php
   ```
   The `.dist` `authsources.php` already defines a `default-sp` entry, which matters: that's
   the exact `auth_source` value in this project's `simplesamlphp_auth.settings.yml`, and
   `checkAuthStatus()` runs `isAuthenticated()` (hence `new Simple('default-sp')`) on **every**
   request. If the authsource were missing instead of just the config file, the result would be
   an uncaught `\SimpleSAML\Error\AuthSource` on every page load, not a quiet admin notice — worse
   than what this works around. Keeping the `.dist` default intact is what makes this safe.
2. Point the library's config loader at it, in your personal (already git-ignored) DDEV
   override file:
   ```yaml
   # .ddev/config.local.yaml
   web_environment:
     - SIMPLESAMLPHP_CONFIG_DIR=/var/www/html/drupal/simplesamlphp-local
   ```
3. `ddev restart`.

Both `drupal/simplesamlphp-local/` and `.ddev/config.local.yaml` are untracked (the former via
`.git/info/exclude`, the latter already covered by `.ddev/.gitignore`) — nothing is committed,
nothing on dev-0/staging/production is touched, and `drush config:status` is unaffected since no
Drupal config entity is involved.

**What this does and does not do:** it only stops the admin notice by giving
`\SimpleSAML\Configuration::getInstance()` a file to load. It does **not** stand up a working
SP, does not let you exercise "Netbadge Login," and does not touch anything in "What's true
today" above. This is explicitly **not** the checked-in mechanism decision item 3 above calls
for — it is the kind of personal, never-committed local file that decision warns will let the
gap silently re-form if mistaken for a real fix. Treat it as a cosmetic convenience for
individual developers, not a step toward closing this note.

**Correction (2026-10-06): this claim was wrong — it also breaks local logout.** Than
reported being unable to log out of DDEV as `ShantiAdmin` (tokenized logout link "does
nothing"). Root-caused live: the workaround changes `getSimpleSamlInstance()` from
throwing (caught, returns `NULL`) to succeeding, which flips
`simplesamlphp_auth_user_logout()`'s `isActivated() && isAuthenticated()` guard from
false to true **even for a plain `drush user:login`/local-password session that never
went through real SAML** — the `.dist` `authsources.php`'s `default-sp` example source
registers as "authenticated" in SimpleSAMLphp's own local session store once anything
has exercised it. That branch calls SimpleSAMLphp's own `Simple::logout()`, which (via
`Utils\HTTP::redirect()`) sends a raw `header('Location: ...')` + echoes its own minimal
HTML **directly**, bypassing Symfony's response pipeline entirely — and does so *before*
Drupal's `user_logout()` (the caller, which runs all `hook_user_logout()` implementations
first) reaches its own `session_manager->destroy()` + anonymous-account reset. Confirmed
via `curl` with the workaround active: the logout response's `Set-Cookie` headers clear
only the `SimpleSAML` cookie, never the Drupal `SSESS*` cookie — the browser keeps
presenting its original, still-valid authenticated session indefinitely, so clicking
"Log out" any number of times has no effect. Removing the workaround (or just not having
it configured) restores normal logout, confirmed by the same `curl` reproduction.

A related, not-fully-isolated observation from the same investigation: at least once,
immediately after hitting this broken logout path, Drupal's Dynamic Page Cache served
the **authenticated admin's rendered homepage to a brand-new request carrying no cookie
at all** (`x-drupal-dynamic-cache: HIT`, `body` class including `user-logged-in`) — i.e.
a real information-disclosure shape, not just a UX annoyance. A clean login→logout→
anonymous cycle (workaround removed, cache rebuilt) did **not** reproduce this on repeat
testing, so it's plausibly a side effect of the broken flow (headers already sent mid-
request leaving Drupal's own cache-writing code running against stale request state)
rather than a standing cache bug — but it was only observed once and not cleanly
isolated. **Flag for whoever next touches SAML/logout locally: re-check this specifically
with a real browser (not synthetic `curl` sessions) before assuming it's fully explained
by the logout defect above.**

**Practical takeaway:** if your local logout stops working, check whether
`.ddev/config.local.yaml` sets `SIMPLESAMLPHP_CONFIG_DIR` — remove it (or comment it out)
and `ddev restart` to get working logout back; you'll see the cosmetic admin notice
again as the tradeoff. This is not something to "fix" by patching the vendored
`simplesamlphp_auth` module — the real fix is the checked-in mechanism decision item 3
above still calls for, which wouldn't have this failure mode (a correctly-configured
local SP wouldn't report a phantom authenticated session for a non-SAML login in the
first place).

## Cross-references

- [Spike 10 — SAML + OAuth2 coexistence](../spikes/spike-10-saml-oauth2-coexistence.md) — proved coexistence using `drush user:login` as a stand-in; never exercised the real test IdP in DDEV
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md) — the shared-Redis half of the session-store design
- [redis-enterprise-store-location.md](redis-enterprise-store-location.md) — the two-Redis-store context this shares
- [saml-alb-routing-assumes-mod-shib.md](saml-alb-routing-assumes-mod-shib.md) — a different SAML/NetBadge gap (ALB routing on AWS), unrelated mechanism, same subsystem
- `terraform-infrastructure/mandala/drupal/staging/ansible/deploy_netbadge.yml` — the dev/staging deploy mechanism this note describes
- `package/data/files/etc/apache2/sites-available/000-default.conf` — the ProxyPass rule DDEV has no equivalent for
