# Large migrations hit PHP's 128M CLI memory_limit; resuming re-iterates the FULL source count

**Area:** migration / infrastructure / DX
**Raised during:** Session 2026-07-17/18 (dev-0's first live `migrate:import` — `d7_images_shanti_image`, 111,340 rows)
**Jira:** (add when available)
**Priority:** High — will recur on every large (100k+ row) migration run outside DDEV until the CLI memory limit is raised persistently

## Observation

`drush migrate:import d7_images_shanti_image` (a single long-running PHP CLI
process, not per-request) ran for ~4h25min, reached 76,583/111,340 imported,
then crashed:

```
Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to
allocate 20971520 bytes) in .../CacheTagsChecksumTrait.php on line 64
[warning] Drush command terminated abnormally.
```

**134,217,728 bytes = 128MB** — PHP's `memory_limit`. This is a well-known
Drupal Migrate API pattern: a single long-running CLI process accumulates
static caches (cache tags, entity storage) across tens of thousands of entity
saves without them ever being cleared (unlike a normal web request, which is
short-lived), eventually exceeding the limit. **No data was lost** — all
76,583 rows were already committed and marked imported in the migrate map.

Because the six-migration chained script (see
`migrate-group-import-aborts-on-partial-failure.md`) runs each `drush`
invocation plain (no `&&`), the crash didn't halt the whole script — it moved
on to `d7_images_image_collection_membership`, which correctly refused to run
(`did not meet the requirements. Missing migrations d7_images_shanti_image`)
since its dependency wasn't cleanly complete. The overall script still reached
its `ALL_DONE` marker — useful signal that something needed attention, rather
than silently hanging.

## Two more landmines hit while fixing this

1. **Wrong entrypoint on the first fix attempt.** `vendor/bin/drush` is a
   **bash wrapper script**, not a PHP file — running
   `php -d memory_limit=1024M vendor/bin/drush migrate:import ...` tries to
   parse the bash script as PHP and silently no-ops (dumps the wrapper's own
   source as "output", exits 0, does nothing). The real PHP entrypoint is
   **`vendor/bin/drush.php`** (confirmed via `drush status`'s own `Drush
   script` field). Always target that file when passing `php -d` flags
   directly, or use a `.drush/drushrc.php`/env var approach for the memory
   limit instead of prefixing `php -d` at all.
2. **A crashed migration's status sticks at `Importing` — refuses to re-run
   until reset.** After the crash, `migrate:status` showed
   `d7_images_shanti_image` as `"status": "Importing"` (not `Idle`), and a
   naive retry hit `Migration d7_images_shanti_image is busy with another
   operation: Importing`. Fix: `drush migrate:reset-status
   d7_images_shanti_image` — this is a documented Migrate API recovery step
   (already noted, independently, in this repo's `scripts/load-d7-source.sh`
   comments: *"If a run is interrupted and the migration locks as
   'Importing': `drush migrate:reset-status <migration_id>`"* — worth
   remembering that note exists next time this happens). Resetting preserves
   the already-imported count; it does not restart from zero.

## The ETA-relevant finding: resuming re-iterates the FULL source count

After `reset-status` + a proper resume (`php -d memory_limit=1024M
vendor/bin/drush.php migrate:import d7_images_shanti_image`), the progress
bar's denominator was still **111,340** — the full migration total, not the
34,757 rows actually remaining — and its pace (~190–200 rows/min) was similar
to the original run's pace, not dramatically faster. This matches an existing
finding from the 2026-06-29 session log: **the D7 source plugin's
`prepareRow()` runs on every row the source query returns, regardless of
whether that row will ultimately be skipped (already in the migrate map) or
newly saved** — `--idlist` and, apparently, a plain resume, don't filter at
the SQL level. Practical consequence: **a resumed run costs close to the same
wall-clock time as a fresh one**, even though it (should) skip re-saving
already-migrated entities. This was not independently re-verified against the
final `created`/`updated`/`ignored` breakdown at time of writing — confirm
that on completion (should show ~34,757 in some combination of `created` +
`ignored`, not ~111,340 `created`, which would indicate rows were actually
re-saved rather than skipped).

## Recommendation

1. **Raise the CLI-SAPI `memory_limit` persistently** (e.g. a `php.ini` CLI
   override baked into the Drupal image, or a Drush-specific config) to
   something like 512M–1G for any environment expected to run large
   migrations outside DDEV — don't rely on remembering `php -d` on every
   invocation.
2. **Document `migrate:reset-status` as a standard step in the runbook**,
   not just a comment in `load-d7-source.sh` — anyone hitting a crashed large
   migration needs to find this fast.
3. **Don't assume a resume is fast.** Budget close to a full fresh-run's worth
   of wall-clock time for any resume of a large migration, given the
   `prepareRow()`-runs-on-everything behavior. If this becomes a recurring
   pain point, investigate whether the D7 source plugin can filter its own
   SQL query by already-imported IDs rather than relying on iterate the whole set.

## Related

- [migrate:import --group aborts on partial failure](migrate-group-import-aborts-on-partial-failure.md)
- [Dev database: bootstrap + migration source](d11-dev-database-bootstrap-and-migration-source.md)
- `scripts/load-d7-source.sh` (already had the `migrate:reset-status` recovery note, independently)
