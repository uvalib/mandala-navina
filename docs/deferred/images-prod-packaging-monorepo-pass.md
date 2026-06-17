# Prod packaging needs a monorepo + D11 pass (Dockerfile / pipeline)

**Area:** deployment / packaging / CI
**Raised during:** Sprint 1 (Step 1a — content-model build session, 2026-06-17)
**Jira:** (add when available)
**Priority:** High — blocks any deployed (non-local) environment
**Owner:** Yuji Shinozaki / Xiaoming Wang (DevOps)

## Context

`package/Dockerfile` was a near-verbatim copy of the `drupal-dsf` Dockerfile and
assumed dsf's **flat** layout (the Drupal app at the repo root). `mandala-navina`
is a **monorepo**: the Composer project and webroot live under `drupal/`
(`composer_root: drupal`, `docroot: drupal/web`). As copied, the image would have
run `composer install` where there is no `composer.json` and pointed Apache at a
non-existent webroot.

During this session the Dockerfile was corrected far enough to be internally
consistent with the monorepo and bumped to Drupal 11:

- base image `drupal:10-php8.1-apache` → `drupal:11-php8.3-apache`
- `WORKDIR /opt/drupal/app/drupal` (composer.json lives in the `drupal/` subdir)
- `APACHE_DOCUMENT_ROOT /opt/drupal/app/drupal/web`
- `config_sync_directory` (`../config/sync` in the committed `settings.php`)
  therefore resolves to `/opt/drupal/app/drupal/config/sync` — the committed CMI
  baseline — **without** the symlink dsf uses (the whole repo is copied in).

## What still needs doing (not done — out of scope for the 1a content-model slice)

1. **Build the image.** Nothing here was build-tested; it needs a real
   `docker build` (locally and via the pipeline) against the D11 base.
2. **`pipeline/buildspec.yml` / `deployspec.yml`** — confirm they reference the
   monorepo paths (build context, Drush invocation under `drupal/`) and run
   `drush config:import` against `../config/sync` on deploy.
3. **Settings provisioning in the deployed env** — confirm `MYSQL_*` and
   `DRUPAL_HASH_SALT` env vars are supplied (Terraform/Ansible) so the now
   env-driven committed `settings.php` connects. See the DSF settings convention
   adopted in `drupal/web/sites/default/settings.php`.
4. **Apache vhost** — the inherited `sed` rewrites `/var/www/...`; verify that
   matches the official `drupal:11` image's actual vhost docroot.

## Relates to

- ADR 003 (Terraform + Ansible + CodePipeline deployment)
- Sprint 1 Step 1b (auth/deploy increment) — natural home for this work
- The committed `settings.php` / `config_sync_directory` convention established this session
