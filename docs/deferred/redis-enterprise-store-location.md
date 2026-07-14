# Redis: TWO stores required; enterprise/production location unresolved

**Area:** infrastructure / Redis / ADR 014 / SAML session store / Drupal object cache
**Raised during:** Session 2026-07-14 (1b.1 part 4 — D11 backend deploy)
**Jira:** (add when available)
**Priority:** Medium — not blocking dev/validation; blocks production cutover

## Requirement: TWO Redis stores, not one (Yuji, 2026-07-14)

1. **SimpleSAMLphp session store** — small.
2. **Drupal object cache** — a separate, **bigger** instance.

**This must be two instances, not two databases on one instance.** `maxmemory` and
`maxmemory-policy` are **instance-wide, not per-database**. An object cache sized to
fill memory under `allkeys-lru` evicts keys from *every* database on that instance —
so co-tenanting it with the session store would silently evict SAML sessions (logging
users out) and, if they shared it, ADR 014 visibility tokens. Separate instances is a
correctness requirement, not just sizing.

### Open: where do ADR 014's visibility tokens live?

Not yet decided, and it does not follow automatically from the two-store split.
Tokens are Drupal-written / solr-proxy-read with a TTL. They are cache-shaped, but
**eviction is not harmless**: losing a user's `mandala_solr_fq:{uid}` silently
degrades them to public-only results until their next login (fail-closed, so not a
security hole — but confusing and hard to diagnose). That argues for the
**noeviction** session-side instance rather than the `allkeys-lru` object cache.

Naming tension to resolve with it: `mandala_solr_visibility` ships `redis_host: redis`
as its installed default, and DDEV's `ddev-redis` add-on serves the object cache *and*
the tokens from the single service named `redis`. If `redis` becomes the object cache
in production, ADR 014's default silently points at the evicting instance and needs a
per-environment `settings.php` override.

### Status: the object cache does not exist yet

Nothing to migrate — it has never been built for production:

- **`drupal/redis` is not in `drupal/composer.json`** at all.
- `settings.php`'s only Redis-cache hook is **DDEV-gated**:
  `if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(.../settings.ddev.redis.php))`.
  So the object cache is currently a DDEV-only convenience.

So this is a requirement to design, not a deployment to move.

### Related: phpredis was missing from the production image (FIXED 2026-07-14)

Found while investigating the above and worth keeping visible, because it hid a real
defect in **merged** 1b.1 part 3 code:

`VisibilityTokenStore::getConnection()` does `new \Redis()` (phpredis) but the
production image never installed the extension. A missing extension raises
`Error: Class "Redis" not found` — an `\Error`, **not** a `\RedisException` — and the
method only catches `\RedisException`. So `hook_user_login` would have **fatalled on
every login in the deployed image**, while passing in DDEV (where `ddev-redis`
supplies the extension). Verified empirically in the built image.

Fixed by `pecl install redis && docker-php-ext-enable redis` in `package/Dockerfile`
(also a prerequisite for the object cache). **The catch remains fragile** — it still
only intercepts `\RedisException`, so any future build without the extension fatals
the same way rather than degrading as the module intends. Consider guarding with
`extension_loaded('redis')` or widening the catch.

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
