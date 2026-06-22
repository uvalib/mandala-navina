# `drush ms` is noisy because migrate_drupal expects a full D7 Drupal install

**Area:** migrate / DX
**Raised during:** Sprint 1 (Step 1a.6 — first migration setup, 2026-06-22)
**Jira:** (add when available)
**Priority:** Low — cosmetic; a one-flag workaround exists
**Owner:** Than Grove

## What we observed

After enabling `migrate_drupal`, running plain `drush ms` (or `drush migrate:status`)
emits a wall of errors:

```
[error]  Could not retrieve source count from d6_filter_format: SQLSTATE[42S02]:
Base table or view not found: 1146 Table 'd7_images.filter_formats' doesn't exist
[error]  Could not retrieve source count from d6_custom_block: SQLSTATE[42S02]:
Base table or view not found: 1146 Table 'd7_images.boxes' doesn't exist
... (~50 of these)
```

…then prints our actual `mandala_images` group rows at the bottom.

## Why

`migrate_drupal` auto-registers ~50 default D6/D7 → D11 migrations (filter formats,
custom blocks, user roles, system files, etc.) that all assume the source DB is a
**complete** D6 or D7 Drupal install with the standard core tables.

Our source DB is *not* — it's `mandala-prod-images-db_2026-06-11.sql.gz`, a
**site-specific dump** that only carries the Images-site schema (nodes, fields,
shanti_images sidecar, OG, paragraphs-adjacent contrib). It deliberately doesn't
contain core admin tables that aren't relevant to the migration.

So `migrate_drupal`'s default migrations probe `filter_formats`, `boxes`, etc.,
hit a 42S02, and dump a stack trace. The actual migrations we wrote work fine
— they're using the tables that *do* exist in the dump (`node`, `field_data_*`).

## Workaround

Always scope migrate commands to our migration group:

```
drush ms --group=mandala_images
drush migrate:import --group=mandala_images
drush migrate:rollback --group=mandala_images
```

This filters out the default `migrate_drupal` migrations and gives clean output.

## Why not "fix" it

Three options to actually silence the noise, all worse than the workaround:

1. **Disable `migrate_drupal`.** It defines the `d7_node`, `d7_user`, etc. source
   plugin definitions our migrations use under the hood. Disabling it kills our
   migrations too.
2. **Stub the missing tables** in `d7_images` with empty schemas. Works but
   pollutes the source DB with fake content and tightly couples it to whatever
   `migrate_drupal` happens to look for in this Drupal version.
3. **Custom drush command that wraps `migrate:status` and filters.** Solves DX,
   adds maintenance.

The workaround flag is the least invasive option. Document it once in the team's
muscle memory and move on.

## What to do

- Update CLAUDE.md or a `docs/dev/` cheat-sheet entry to default to
  `--group=mandala_images` for migrate commands when 1a.7+ work is active.
- No code changes.

## Related

- [Sprint 1 — Step 1a.6](../sprints/sprint-01-images-implementation.md)
- The Migrate API's design intentionally lets you enable `migrate_drupal` even
  without a complete source — but it doesn't suppress the discovery probes.
