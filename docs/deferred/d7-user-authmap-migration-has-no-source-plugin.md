# `d7_user_authmap` names a source plugin that does not exist, so `migrate:*` aborts

**Category:** migration-general
**Raised during:** adding the transcript workflow fields to `d7_av_workflow`, 2026-10-21
**Issue:** #369
**Priority:** Medium. It blocks every `drush migrate:*` command wherever the migration is active, including dev-0, until worked around. Nothing is broken today; a re-run (the cutover user sync) is what needs it fixed.
**Owner:** unassigned

## Root cause (found 2026-10-21)

Upstream `externalauth` removed its migrate support on purpose. Issue
[#3618043](https://www.drupal.org/node/3618043), "Remove deprecated migrate support" (2026-09-28):

- The **source** plugin `authmap` and the `d6_authmap` / `d7_authmap` migration templates were **deleted**. They
  were in 2.0.11 and are gone in 2.0.14 and 2.0.15.
- The **destination** plugin `authmap` still exists in 2.0.15 but is **deprecated** (it logs a deprecation when
  instantiated) and is **removed in 2.1.0**.
- The README now says the module ships no migrations: define the migration in a custom module, with a destination
  that calls `\Drupal\externalauth\AuthmapInterface::save()`.

Our `composer.lock` moved `externalauth` from 2.0.11 to 2.0.15 in the 2026-10-05 "update core + contrib" commit
(`40d9c39`). Until then the source existed; since then `migrate_plus.migration.d7_user_authmap` (`source: plugin:
authmap`) names a plugin nothing provides. Migrate discovery instantiates every migration, so while that config is
active, `drush migrate:status` and `migrate:import` abort for **all** migrations:

> The "authmap" plugin does not exist. Valid plugin IDs for Drupal\migrate\Plugin\MigrateSourcePluginManager are: ...

The earlier note
([d7-user-role-migration-wipes-committed-role-permissions.md](d7-user-role-migration-wipes-committed-role-permissions.md))
says the source plugin comes from `externalauth` and that enabling it is the fix. That was true for 2.0.11 and is
not true now.

## Where it bites

- **Active on DDEV and on dev-0** (checked 2026-10-21). `migrate:import` aborts on both without the workaround.
- **No data is lost today.** dev-0 already holds 1,385 `simplesamlphp_auth` authmap rows, with 1,384 entries in
  `migrate_map_d7_user_authmap`: the links were migrated before the update.
- **A re-run is where it hurts.** The final user sync before cutover has to link migrated accounts to their
  NetBadge identity again (otherwise every NetBadge login creates a duplicate account), and any migration run on a
  fresh environment hits the abort first.
- **The destination will break next.** Moving to `externalauth` 2.1.0 removes the destination plugin too.

## Workaround used

On DDEV: `drush config:delete migrate_plus.migration.d7_user_authmap`, run the migration, then restore it with a
partial config import of that one file (`drush config:import --partial --source=<dir holding the file>`). A full
`config:import` would also act on the known `stage_file_proxy` differences, so import only that file.

## Options

1. **Write both plugins in `mandala_migrations` (recommended).** It is what upstream tells you to do and it fixes
   both halves at once:
   - a source plugin reading the D7 `authmap` table (`uid`, `authname`, `module`; about 20 lines, and the 2.0.11
     source is a ready model);
   - a destination plugin calling `AuthmapInterface::save($account, $provider, $authname)`;
   - point `d7_user_authmap` (in `config/sync` and `config/install`) at them, using distinct plugin ids so a future
     `externalauth` release cannot collide;
   - a Kernel test of the destination, and a run against the real D7 `authmap` on DDEV checking the 1,384 rows
     land with the same `authname` and `provider` as dev-0's.
   The `mandala_migrations` module already has `source`, `process` and `destination` plugin directories.
2. **Pin `externalauth` to 2.0.11.** Brings the source back but keeps the deprecated destination, loses the
   security fixes in 2.0.12 to 2.0.15 and only postpones the 2.1.0 break. Not recommended.
3. **Remove the migration.** Only reasonable if nobody will re-run the user migration, which the cutover sync
   rules out.

Until one is done, correct the claim in the user-role note above (a one-line pointer to this note).

## Start trigger

Before the next migration run on dev-0 or any shared environment, or sooner: it costs everyone a workaround.
