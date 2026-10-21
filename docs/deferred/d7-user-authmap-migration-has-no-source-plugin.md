# `d7_user_authmap` names a source plugin that does not exist, so `migrate:*` aborts

**Category:** migration-general
**Raised during:** adding the transcript workflow fields to `d7_av_workflow`, 2026-10-21
**Issue:** none
**Priority:** Medium. It blocks every `drush migrate:*` command wherever the migration is active, including dev-0, until worked around.
**Owner:** unassigned

## What is wrong

`migrate_plus.migration.d7_user_authmap` (in `config/sync` and in `mandala_migrations/config/install`) declares
`source: plugin: authmap`. No such source plugin exists: `externalauth` 2.0.15 provides only the `authmap`
**destination** (`src/Plugin/migrate/destination/Authmap.php`), nothing in `mandala_migrations` provides it, and git
history shows none ever did. Migrate discovery instantiates every migration, so while this one is in active
config, `drush migrate:status` and `drush migrate:import` abort for **all** migrations, including unrelated ones:

> The "authmap" plugin does not exist. Valid plugin IDs for Drupal\migrate\Plugin\MigrateSourcePluginManager are: ...

The same trap is described in
[d7-user-role-migration-wipes-committed-role-permissions.md](d7-user-role-migration-wipes-committed-role-permissions.md)
("`externalauth` must be enabled first"), which says the source plugin comes from `externalauth`. Enabling it does not
help: it was already enabled when this was hit.

## Where it bites

Checked 2026-10-21: the migration is active on DDEV and on dev-0 (read-only check), and `migrate:import` aborted on
DDEV. On dev-0 it would abort the same way; nothing has needed a migration run there since it became active.

## Workaround used

On DDEV: `drush config:delete migrate_plus.migration.d7_user_authmap`, run the migration, then restore it with a
partial config import of that one file (`drush config:import --partial --source=<dir holding the file>`). A full
`config:import` would also act on the known `stage_file_proxy` differences, so import only that file.

## Options

- Write the missing source plugin (reads D7 `authmap`), if the NetBadge account-link migration is still wanted.
- Or remove the migration from `config/sync` and `config/install` if it is not, and note why.
- Either way, correct the claim in the user-role note above.

## Start trigger

Before the next migration run on dev-0 or any shared environment, or sooner: it costs everyone a workaround.
