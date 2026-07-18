# PR #45's `migrate_shared` connection duplicates the already-merged `migrate_users` connection

**Area:** migration / users / infrastructure / DX
**Raised during:** Session 2026-07-17 (PR sweep after the first live `migrate:import` on dev-0)
**Jira:** (add when available)
**Priority:** High — blocks PR #45 (`feat/user-migration`) from being safely un-drafted/merged as-is; doesn't block anything else today

## Observation

PR #45 (`feat/user-migration`, Than, draft) and PR #49 (`feat/env-driven-migrate-connection`,
merged 2026-07-17 01:13) both add a database connection in `settings.php` for the
same purpose — reading the shared D7 user database — via two different,
independently-invented mechanisms:

| | **PR #49 (merged, on `main`)** | **PR #45 (draft)** |
|---|---|---|
| Connection key | `migrate_users` | `migrate_shared` |
| Env var | `MIGRATE_USERS_DATABASE` | `MIGRATE_SHARED_DATABASE` |
| Default DB name | *(none — must be set explicitly)* | `mandala_shared_dev` |
| Host/user/password override | Yes (`MIGRATE_SOURCE_HOST`/`USER`/`PASSWORD`, falls back to `MYSQL_*`) | No — hardcoded to `MYSQL_HOST`/`USER`/`PASSWORD` only |

PR #45's `migrate_plus.migration_group.mandala_users.yml` sets
`shared_configuration.source.key: migrate_shared`, so its three new migrations
(`d7_users`, `d7_user_role`, `d7_user_authmap`) are wired to the `migrate_shared`
connection, not `migrate_users`.

**No git-level conflict exists** — confirmed via `gh pr view --json mergeable`
(`MERGEABLE`) and independently via `git merge-tree` (zero conflict markers).
The two additions land in different line ranges of `settings.php`. This is a
purely *semantic* duplication that git cannot detect.

## Why it happened

Pure timing. PR #45 was opened 2026-07-16 15:39 — **PR #49 didn't merge until
2026-07-17 01:13, almost 10 hours later.** The generic `migrate`/`migrate_users`
mechanism didn't exist yet when #45 was drafted, so it built its own. #45 has
stayed in draft since and was never rebased onto #49.

## Why it matters now specifically

Today's session (see `d11-dev-database-bootstrap-and-migration-source.md` and
`migrate-group-import-aborts-on-partial-failure.md`) dumped the real shared
user DB from `rds-mysql8-production` (verified live name: **`mandala_shared`**,
not `mandala_shared_dev` — that name doesn't correspond to anything real; it
was a stale value in `platform.settings.php` on a disabled site) and loaded it
onto `rds-mysql8-staging` as **`mandala_d7_shared`** — deliberately not
`mandala_shared_dev`, since that name was already in use by an unidentified
pre-existing DB on staging RDS.

If PR #45 merges as-is, its migrations would default to reading a DB literally
named `mandala_shared_dev` via a connection key (`migrate_shared`) that nothing
else in the codebase uses — someone would have to independently discover and
set `MIGRATE_SHARED_DATABASE=mandala_d7_shared`, a second, redundant env var
nobody would think to check, instead of the already-wired, already-proven
`MIGRATE_USERS_DATABASE`.

## Recommendation

Before PR #45 comes out of draft:

1. Rebase onto current `main`.
2. Drop the `migrate_shared` connection block PR #45 adds to `settings.php`
   entirely.
3. Change `migrate_plus.migration_group.mandala_users.yml`'s
   `shared_configuration.source.key` from `migrate_shared` to `migrate_users`
   — the connection PR #49 already built and generalized for exactly this.
4. Set `MIGRATE_USERS_DATABASE=mandala_d7_shared` wherever the migration is
   run (matches the `MIGRATE_SOURCE_DATABASE=mandala_d7_images` pattern
   already in use for the Images source).
5. Update the two `⚠ VERIFY ON THE REAL DUMP` comments in `d7_user_authmap.yml`
   (authname format, `module` filter value) against the now-real
   `mandala_d7_shared` data rather than a dump that no longer exists under the
   name the comments reference.

Flagged to Than directly on PR #45.

## Related

- [Dev database: bootstrap + migration source](d11-dev-database-bootstrap-and-migration-source.md)
- [migrate:import --group aborts on partial failure](migrate-group-import-aborts-on-partial-failure.md)
- [D7 shared user database](d7-shared-user-database.md)
- PR #45 (`feat/user-migration`), PR #49 (`feat/env-driven-migrate-connection`, merged)
