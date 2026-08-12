# User migration executed on dev-0 (1,543 users) — 2026-08-12

**Area:** migration / users / dev environment
**Jira:** (add when available)
**Status:** ✅ **DONE 2026-08-12.** Recorded because the code had been merged and
verified since July while the *run* had never happened on dev-0 — a distinction the
notes and memory had blurred.

## Why it hadn't run

Not for want of code. `d7_users`, `d7_user_authmap` and the `mandala_users` group were
merged in PR #45 and hardened by #66 (authmap destination plugin) and #73 (the
`d7_user_role` permission wipe), then independently verified end to end on a full
scrubbed DB on 2026-07-24.

They had simply never been **registered** on dev-0: `migrate:status` did not list them
at all, because the deploy never imports `config/sync`. See
[deploy-never-imports-config-sync.md](deploy-never-imports-config-sync.md). Fixed here
with a targeted `--partial` import of just those three configs.

## Results

| Migration | Result |
|---|---|
| `d7_users` | **1,542 created, 0 updated, 0 failed, 0 ignored** |
| `d7_user_authmap` | **1,384 created, 0 failed** |
| `d7_images_collection_memberships` | **210 created, 36 updated, 0 failed** |

- Users on dev-0: **2 → 1,543**
- `d7_images_collection_memberships`: **36/246 → 246/246**, clearing the 210 failures
  that had stood since 2026-07-19 and were always attributed to the missing users
- **All 22 private/restricted groups now have members** — 48 memberships in total

**The `d7_user_role` permission wipe did not recur.** Post-migration role permission
counts: `anonymous` 5, `authenticated` 10, `content_editor` 23, `administrator` 0
(correct — it is `is_admin: true`, so it holds no explicit permissions). PR #73's
in-process `mandala_role_map` held under a real run.

The `MigrateSyncSubscriber` guard also behaved: every run logged *"kmassets per-node
Solr sync suppressed … re-enabled after migration"*, so no stray per-node Solr writes.

## Sharp edges hit along the way

- **`--update` was required to retry failures.** The 210 failed rows are recorded in
  the map as *processed*, so a plain re-run reported `Processed 0 items`. Earlier notes
  said to "re-run … afterward", which is not sufficient. Use
  `migrate:import <id> --update` (which also harmlessly re-processes the 36 already
  imported).
- **`MIGRATE_SOURCE_DATABASE` / `MIGRATE_USERS_DATABASE` are still not in dev-0's
  container env**, so every invocation needs `docker exec -e …`. Without them
  `migrate:status` fails with *"No database connection configured for source plugin
  variable"*. Long-standing open item.
- **`migrate:status` OOMs at the default 128M.** Use
  `php -d memory_limit=1024M vendor/bin/drush.php …` — note `drush.php`, since
  `vendor/bin/drush` is a bash wrapper and `php -d` cannot apply to it.
- Migrations were run **individually, not via `--group`**, because a partial failure in
  a group aborts the whole remaining sequence.

## What this unblocks

Half of the authenticated-access prerequisite. Real users with real private-collection
memberships now exist, which is what an ADR 014 visibility token needs to be meaningful.

**Still blocked:** the kmassets index carries no D11-format uids, so those tokens match
nothing — see [kmassets-index-has-no-d11-uids.md](kmassets-index-has-no-d11-uids.md).
Both halves are needed before the authenticated path can be demonstrated end to end.

## Safety nets used

- Manual RDS snapshot `mandala-preusermigration-20260812-1151` on `rds-mysql8-staging`
  (note: instance-level, and that instance is **shared with unrelated projects**, so
  restoring it is not a realistic per-database rollback).
- `drush config:export` of the pre-change active config (448 files) to `/tmp/config-pre`
  inside the container — the proportionate rollback for a config-level change.

## Cross-references

- [deploy-never-imports-config-sync.md](deploy-never-imports-config-sync.md) — why it had never run
- [kmassets-index-has-no-d11-uids.md](kmassets-index-has-no-d11-uids.md) — the remaining half
- [d7-shared-user-database.md](d7-shared-user-database.md) — why users come from `mandala_shared`
- [migrate-large-migration-oom-and-resume-behavior.md](migrate-large-migration-oom-and-resume-behavior.md)
- [migrate-group-import-aborts-on-partial-failure.md](migrate-group-import-aborts-on-partial-failure.md)
