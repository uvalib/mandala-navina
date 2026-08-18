# OAuth2 signing keypair isn't in any persistent bind mount — wiped on every `deploy_backend.yml` run

**Area:** deployment / Ansible / simple_oauth / OAuth2 (dev environment)
**Raised during:** Session 2026-08-18 (first real browser walkthrough of SAML → OAuth2 → proxy)
**Jira:** (add when available)
**Priority:** High — silently breaks the entire OAuth2 authenticated path (Drupal `/oauth/*`, the solr-proxy, and anything else built on `simple_oauth`) on every normal CI/CD deploy to dev, until someone manually regenerates the keys

## Issue

`simple_oauth`'s RSA signing keypair lives at `/opt/drupal/app/drupal/keys/{private,public}.key`
inside the `mandala-drupal-0` container (path resolved from `simple_oauth.settings`:
`private_key: '../keys/private.key'`, relative to the Drupal webroot). That directory is
**not** one of `deploy_backend.yml`'s persistent bind mounts — only
`sites/default/files`, and the four `simplesamlphp/{cert,log,config,metadata,drupal-config}`
directories, are mounted from the host. `deploy_backend.yml` always does
`docker_container: state: absent` then recreates the container, so anything living only in
the container's own ephemeral filesystem is gone after every deploy.

Discovered live on dev-0 tonight: after an otherwise-successful `deploy_backend.yml` run
(pushing an unrelated SimpleSAMLphp config change), `/oauth/authorize` started failing
with:

```
{"error":"server_error","error_description":"...You need to set the OAuth2 private key."}
```

The keys directory didn't exist at all (`ls: cannot access '/opt/drupal/app/drupal/keys'`).

## Why this had never been caught before

Same root cause as the sibling note
([solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md)):
nobody had exercised the real `/oauth/authorize` flow through an actual browser session
before this session. Every prior deploy since the keys were first generated (by hand, at
some point during earlier 1b.1/solr-proxy work) had silently wiped and never regenerated
them, and nothing noticed because nothing exercised the code path that reads them.

## What was done tonight (unblocks testing, does NOT fix the underlying gap)

Regenerated on dev-0 by hand:

```
docker exec mandala-drupal-0 mkdir -p /opt/drupal/app/drupal/keys
docker exec mandala-drupal-0 /opt/drupal/app/drupal/vendor/bin/drush simple-oauth:generate-keys /opt/drupal/app/drupal/keys
docker exec mandala-drupal-0 chown www-data:www-data /opt/drupal/app/drupal/keys/{private,public}.key
```

The `chown` step matters and is easy to miss: `drush` run via a bare `docker exec` creates
the files as `root`, mode `600` — but Apache's workers run as `www-data`, which can't read
a root-owned `600` file. That mismatch produces the *same* misleading "You need to set the
OAuth2 private key" error as a genuinely missing file, so don't assume the file's presence
alone means it's usable.

This regenration is **local to the current container instance** — the next
`deploy_backend.yml` run (i.e. the next normal merge to `main` touching `drupal/**`)
will wipe it again.

## Fix options (not yet decided)

1. Add `/opt/drupal/app/drupal/keys` to `deploy_backend.yml`'s persistent volume list
   (mirroring the `simplesamlphp/*` pattern), and have the playbook generate the keypair
   on first deploy if missing (idempotent — don't regenerate if already present, since
   rotating invalidates every outstanding token).
2. Move key storage to something inherently persistent/shared instead of a host bind
   mount — e.g. the `key` module's env-var or Secrets-Manager-backed key providers,
   consistent with how `container_0.env.secret` already handles other secrets for this
   environment. This also sidesteps the ownership/permissions footgun entirely.

Either way: whatever mechanism is chosen must not regenerate the keypair on every deploy
(that would invalidate all outstanding access/refresh tokens on every merge, not just the
one time this happened by accident tonight).

## Related

- [solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md) — the second, independent OAuth2 defect found in the same session, once this one was fixed enough to get past it
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- `terraform-infrastructure/mandala/drupal/staging/ansible/deploy_backend.yml` — the volume-mount list that needs the new entry
