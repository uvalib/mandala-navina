# `drush migrate:import --group` aborts the whole remaining group on any migration's partial failure

**Area:** migration / DX / tooling
**Raised during:** Session 2026-07-17 (first live `migrate:import` on dev-0, against the newly-loaded `mandala_d7_images`/`mandala_d7_shared` staging-RDS source — see `d11-dev-database-bootstrap-and-migration-source.md`)
**Jira:** (add when available)
**Priority:** Medium — not a correctness bug, but blocks a full `--group` run every time the known `d7_images_collection_memberships` partial failure occurs, which is every run until the user migration is unblocked

## Observation

Running `drush migrate:import --group=mandala_images` on dev-0 stopped after only
three migrations (`d7_images_collections`, `d7_images_subcollections`,
`d7_images_collection_memberships`) even though the group has nine. The other
six — including the three big ones (`d7_images_image_agent`,
`d7_images_image_descriptions`, `d7_images_shanti_image`) — never started.

`d7_images_collection_memberships` (**not** `d7_images_image_collection_membership`
— easy to conflate; see the "Two similarly-named migrations" note below) processed
246 rows, 36 created, **210 failed**. This is the documented, expected limitation:
this migration maps D7 Organic Groups **user** memberships (`etid` = a D7 user id)
to D11 Group membership relationships, and the real user migration
(`feat/user-migration`, PR #45, still a held draft) hasn't landed — so 210 of 246
rows reference D7 users that don't exist in D11 yet, and fail their entity lookup.
This matches the same ratio previously seen in DDEV (38/249, per `[[project-d7-shared-user-database]]`) — not a new defect.

The problem is what happened *next*: `migrate_tools`' `--group` runner treats a
migration ending with any failed rows as throwing an `Exception`
(`MigrateToolsCommands.php` line 1215: `<migration> Migration - N failed.`),
and that exception **aborts the entire remaining `--group` sequence** — the six
migrations queued after `d7_images_collection_memberships` in dependency order
never ran at all, silently (well — loudly, but easy to miss: the shell just
exits non-zero and stops).

## Two similarly-named migrations — don't confuse them

| Migration ID | Label | Maps | Expected result |
|---|---|---|---|
| `d7_images_collection_memberships` (**plural** "memberships") | *OG user memberships → Group membership relationships* | D7 user `etid` → D11 user | **Partial failure expected** until the user migration lands |
| `d7_images_image_collection_membership` (**singular** "membership") | *OG image→group memberships → group_node:shanti_image relationships* | D7 image node `etid` (via `d7_images_shanti_image` lookup) → group | Should be clean — not user-dependent |

Verified directly against `migrate_plus.migration.*.yml` in `config/sync`, not
assumed — the naming is genuinely easy to mix up mid-conversation.

## Workaround used (2026-07-17)

Ran the remaining six migrations individually, in dependency order, chained
with `;` (not `&&`) so one migration's own exit code can't block the next:

```bash
drush migrate:import d7_images_external_classification_scheme --verbose
drush migrate:import d7_images_external_classification --verbose
drush migrate:import d7_images_image_agent --verbose
drush migrate:import d7_images_image_descriptions --verbose
drush migrate:import d7_images_shanti_image --verbose
drush migrate:import d7_images_image_collection_membership --verbose
```

Migrate API resolves each migration's own dependencies via its migration map
tables regardless of how the ID was invoked (not only via `--group`), so
running them individually in the right order works the same as the group run
would have — it just doesn't abort partway.

## Recommendation

Pick one (not yet decided):

1. **Exclude `d7_images_collection_memberships` from `--group` runs** until the
   user migration lands, running it as a separate, explicitly-acknowledged step
   — cleanest, but means `--group` alone is never sufficient for a full run
   until then.
2. **Raise `migrate_tools`' failure-tolerance for this one migration** (if such
   a per-migration setting exists — not yet investigated) so a *known,
   expected* partial failure doesn't abort the group.
3. **Just document the individual-invocation sequence as the standard runbook**
   for now (what this note does) and revisit once user migration removes the
   partial-failure case entirely.

Whichever is picked, update `scripts/migration-cycle.sh` (currently DDEV-first,
`--group`-based) to account for it — it will hit the identical abort the first
time it's run against a source that includes real collection membership data
with no matching user migration.

## Related operational notes from the same run

- **Confirms `kmassets-sync-hook-fires-during-migration.md`'s guard fires
  correctly in a live (non-DDEV) run** — `[notice] kmassets per-node Solr sync
  suppressed...` / `...re-enabled after migration.` appeared around every
  migration in the sequence, exactly as `MigrateSyncSubscriber` (PR #51)
  intends. See that file's update.
- **Throughput on dev-0 (talking directly to `rds-mysql8-staging`, no VPN in
  the data path — the process runs inside the container via `docker exec`,
  parented by `containerd-shim-runc-v2` under init, not by any SSH session):
  ~1,000–1,100 rows/min** for `image_agent` (paragraph entity creates). Same
  order of magnitude as the historical DDEV baseline ("~an hour" for the full
  ~111k-row run, i.e. ~1,850 rows/min) — the cost is inherent per-row Drupal
  Entity API overhead (field storage writes, validation, hooks), not network
  latency, so don't expect a materially faster run from a "closer to the DB"
  environment. Plan for **multiple hours**, not "~an hour", for a full fresh
  Images migration run outside DDEV.
- **A non-interactive `docker exec` survives the launching SSH session dying**,
  *if* its output is redirected to a file inside the container (`>> file`)
  rather than streamed back through the `docker exec` API pipe to the client.
  Verified via process ancestry: the actual work is parented by
  `containerd-shim-runc-v2` (PPID 1), not by the `sudo docker exec` client
  process tied to the SSH session. Safe to disconnect/reconnect and just
  `tail`/`grep` the log file to check on a long-running migration.

## Related

- [kmassets sync hook fires during migration](kmassets-sync-hook-fires-during-migration.md)
- [D7 shared user database](d7-shared-user-database.md)
- [Dev database: bootstrap + migration source](d11-dev-database-bootstrap-and-migration-source.md)
- `scripts/migration-cycle.sh`, `drupal/config/sync/migrate_plus.migration.d7_images_collection_memberships.yml`, `drupal/config/sync/migrate_plus.migration.d7_images_image_collection_membership.yml`
