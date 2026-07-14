# Where the enterprise (production) Redis store resides is unresolved

**Area:** infrastructure / Redis / ADR 014 / SAML session store
**Raised during:** Session 2026-07-14 (1b.1 part 4 — D11 backend deploy)
**Jira:** (add when available)
**Priority:** Medium — not blocking dev/validation; blocks production cutover

## Decision so far

**Dev is settled (Yuji, 2026-07-14): a `redis:alpine` container on `drupalnet`**,
deployed by `mandala/drupal/staging/ansible/deploy_redis.yml`, network alias
`redis`. A development box does not need an enterprise instance, and reindeer_x
already set the on-box precedent by running its own Redis there.

**Production/enterprise is explicitly deferred** — "we will need to resolve where
the enterprise redis store will reside, but later" (Yuji).

## Why this is cheap to defer

Every consumer reads its Redis host from configuration, so moving to a managed
instance is a config change rather than a code change:

| Consumer | Setting | Dev value |
|---|---|---|
| SimpleSAMLphp session store | `SIMPLESAML_REDIS_HOST` (`container_0.env.managed`) | `redis` |
| ADR 014 visibility tokens (Drupal) | `mandala_solr_visibility.settings.redis_host` | `redis` (installed default) |
| ADR 014 visibility tokens (proxy) | `REDIS_HOST` (`solr-proxy/.env`) | defaults `127.0.0.1` — **needs setting to `redis`** when solr-proxy is deployed to the D11 box |

The dev alias is deliberately `redis` because that is DDEV's `ddev-redis` service
name **and** `mandala_solr_visibility`'s installed default — so the dev box mirrors
local DDEV and ADR 014 needs no per-environment `settings.php` override.

## Database separation (applies wherever it lands)

Two consumers share one instance and must not collide:

- **db 0** — ADR 014 visibility tokens, keys `mandala_solr_fq:{uid}`.
  `VisibilityTokenStore::getConnection()` never calls `->select()`, so it uses
  Redis' default db 0. Both Drupal (writer) and solr-proxy (reader) must agree.
- **db 4** — SimpleSAMLphp session store, prefix `SIMPLESAML_MANDALA:`.

reindeer_x's `workqueue` is a **separate container** (`container_name: workqueue`,
bound to its own compose network) and is not part of this — left alone deliberately.

## What production must decide

1. **Shared vs. dedicated.** The fleet pattern is the shared `ha-redis-staging` /
   equivalent ElastiCache cluster (dsf, library.virginia.edu, avalon all point at
   it). If mandala joins it, pick an unclaimed database per consumer and keep the
   `SIMPLESAML_MANDALA:` prefix. Note mandala currently defines **no** Redis in
   terraform at all — no ElastiCache resource exists for it.
2. **Reachability.** ADR 014 requires Drupal *and* the standalone solr-proxy to
   reach the same instance. Whatever is chosen must be reachable from both.
3. **Durability.** The dev container runs `--appendonly yes` so SAML sessions and
   tokens survive a restart. A managed instance has its own persistence story;
   losing the keyspace logs every user out and drops visibility tokens until the
   next login (tokens carry a TTL and are rewritten on login, so they self-heal;
   sessions do not).
4. **Credentials.** `SIMPLESAML_REDIS_USERNAME`/`PASSWORD` are currently empty
   (`config.php` maps empty to null), which suits an IP-gated instance. A Redis 6+
   ACL-protected cluster needs real values added to `container_0.env.secret` and
   re-encrypted.

## Cross-references

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — Redis visibility tokens
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5.3
- `mandala/drupal/staging/ansible/deploy_redis.yml` (terraform-infrastructure)
