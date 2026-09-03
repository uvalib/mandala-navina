# `drush migrate:import --update` fails on already-migrated `entity:group` rows — `uid` comes back NULL

**Area:** migration / Group entity
**Raised during:** Session 2026-09-03 (backfilling `field_featured_image`/`field_overview`
onto the already-migrated `d7_images_collections`/`d7_images_subcollections` Group
entities — see the Sprint 2 B5 planning subsection,
[docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md))
**Priority:** Medium — doesn't block the real staging/production cutover migration (that
runs as a fresh import against production source, never `--update` against
already-migrated rows), but blocks the normal "extend a migration, `--update` the
already-run rows" workflow for `d7_images_collections`/`d7_images_subcollections`
specifically, on any environment that already has these 174 Group entities.

## Observation

Adding two new field mappings (`field_overview`, `field_featured_image`) to
`d7_images_collections.yml`/`d7_images_subcollections.yml` and running
`drush migrate:import d7_images_collections --update` to backfill the already-migrated
174 Group entities failed on **every** row:

```
SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'uid' cannot be null:
INSERT INTO "groups_field_data" (...) VALUES (55, 55, 'collection', 'en', 1, , 'Chollectesting', ...)
```

Note it's an **INSERT**, not an UPDATE, targeting an `id` that already exists (55) —
the destination plugin isn't loading and updating the existing entity, it's trying to
recreate it, and this time `uid` comes back empty even though `uid: uid` is a trivial
1:1 source-field copy that has never changed and the D7 source row's `uid` (41) is
present and valid (`SELECT uid FROM node WHERE nid=1631766` → `41`, confirmed live).

**Confirmed this is unrelated to the new field mappings**: reverting
`d7_images_collections.yml` to its exact pre-session state (git stash) and re-running
`--update --idlist=1631766` reproduces the identical failure. This is a **pre-existing
latent bug** in this migration's `--update` path — nobody had run `--update` on
`d7_images_collections`/`d7_images_subcollections` before this session (only fresh
imports, previously). Group is a revisionable + translatable content entity;
suspect the `entity:group` migrate destination plugin's update path isn't correctly
loading/re-hydrating the existing entity's base fields before re-processing, but this
wasn't root-caused further — out of scope for what this session actually needed.

## What was done instead (not a fix, a workaround for this session's real goal)

Backfilled the two new fields directly via the Entity API (`drush php:eval`, load each
already-migrated Group by its `migrate_map_d7_images_collections`/`..._subcollections`
row, `->set()` the two fields from a direct D7-source query, `->save()`) — bypassing
Migrate's `--update` path entirely. 148 of 174 groups updated (135 got a real featured
image; 82 got real overview text; the delta from 174 is collections with neither field
populated in D7). Verified live: `/collections` shows real production images mixed with
the default-thumbnail fallback for the rest.

**This workaround is not portable to the real staging/production cutover** — it's a
one-off script pointed at DDEV's local migrate-map tables, not something that should be
re-run generically. The real cutover migration doesn't need it: that's a **fresh**
`migrate:import` (not `--update`) against production source, and `field_overview`/
`field_featured_image` are permanent mappings in the same committed migration YAML now
— they'll populate correctly on first import, same as every other field, with no
`--update`-path bug in play.

## Still open

- Root-cause why `entity:group`'s migrate destination inserts instead of updates (or
  loses `uid`) on `--update` for this specific migration. Worth a focused debugging pass
  (compare against a migration confirmed to `--update` cleanly, if one exists, to isolate
  whether this is Group-specific, revisionable-entity-specific, or specific to something
  about these two migrations' own configuration).
- Until fixed, **any future field addition to `d7_images_collections`/
  `d7_images_subcollections` that needs backfilling onto already-migrated rows** (on
  DDEV, dev-0, or any environment with these 174 rows already migrated) will hit the
  same wall and need the same kind of direct-Entity-API workaround, not a routine
  `--update` run.
