# How-To: Local development with DDEV

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-06-17

## Goal

Get a working Drupal 11 Mandala site running locally under DDEV, and know how to
rebuild it from a clean state.

## Prerequisites

- [DDEV](https://ddev.readthedocs.io/) and Docker installed.
- Run all commands from the **repo root**. DDEV is configured here
  (`.ddev/config.yaml`): project `mandala`, `drupal11`, docroot `drupal/web`,
  composer root `drupal`, PHP 8.3, MariaDB 10.11.

## First-time setup

1. Start the environment. On first start, a post-start hook runs
   `composer install` automatically if `drupal/vendor` is missing:
   ```bash
   ddev start
   ```
2. Install a fresh Drupal site and import the committed config:
   ```bash
   ddev drush site:install --yes
   ddev drush config:import --yes
   ddev drush cache:rebuild
   ```
3. Open the site. DDEV chooses an appropriate URL/port and prints it — use the
   `Primary URL` from `ddev describe` (or the URL echoed by `ddev start`) rather
   than assuming a fixed address. It's typically something like
   `https://mandala.ddev.site:8443` (the host is stable; the HTTPS port is often
   `8443` when `443` is already in use):
   ```bash
   ddev launch        # opens the primary URL in your browser
   # or:
   ddev describe      # lists the project URL(s) and ports
   ```

   !!! note "First-visit SSL warning"
       The first time you open the HTTPS URL, your browser will likely show a
       security/"not private" warning. DDEV serves over HTTPS with a locally
       generated certificate that your system doesn't trust by default. In
       practice most of us just **accept the exception** ("Proceed anyway") —
       the browser then ignores it for the rest of the session. That's perfectly
       fine for local dev.

       If you'd rather silence the warning permanently, install the
       [mkcert](https://github.com/FiloSottile/mkcert) root CA once:
       ```bash
       mkcert -install   # installs the local CA into your system trust store
       ddev restart      # re-issue certs trusted by your browser
       ```
       Or just use the plain `http://` URL from `ddev describe`.

## Full rebuild (clean slate)

When you want to drop the database and reinstall from committed config, use the
helper script — it installs with admin/admin credentials, imports config, and
rebuilds caches:

```bash
./scripts/rebuild.sh
```

> ⚠️ This **drops and reinstalls** the database. Anything not exported to
> `drupal/config/sync/` is lost. Export first if you have config changes you
> want to keep (see below).

## Common Drush commands

```bash
ddev drush cr            # cache rebuild
ddev drush cim           # config import (config/sync → DB)
ddev drush cex           # config export (DB → config/sync)
ddev drush updb          # run pending DB updates
ddev drush en <module>   # enable a module
ddev drush pmu <module>  # uninstall a module
```

## Config workflow

Drupal config is tracked in git under `drupal/config/sync/`. The loop is:

1. Make changes in the admin UI (or via Drush).
2. Export them: `ddev drush cex`.
3. Review and commit the resulting diff in `drupal/config/sync/`.
4. Teammates pull and run `ddev drush cim` to apply.

## Verify

- `ddev describe` shows the project running and lists its `Primary URL`.
- That URL loads the site in a browser (`ddev launch`).
- `ddev drush status` reports Drupal bootstrapped and DB connected.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `composer` deps missing after start | post-start hook only runs when `drupal/vendor` is absent | `ddev composer install --prefer-dist` |
| Config import does nothing / errors | local DB drifted from `config/sync` | `./scripts/rebuild.sh` for a clean slate |
| Site URL won't resolve | DDEV/router not up | `ddev restart`, then `ddev describe` |

## Related

- [Run the docs site locally](howto-run-docs-locally.md)
- [Session rituals](rituals-session-workflow.md)
