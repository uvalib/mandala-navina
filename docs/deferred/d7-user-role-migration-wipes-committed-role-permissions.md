# `d7_user_role` migration wipes committed D11 role permissions

**Area:** migration / users / roles / config
**Raised during:** Local synthetic user-migration smoke-test (2026-07-21, Xiaoming + Than)
**Jira:** (add when available)
**Priority:** **High — blocks the dev-0 user migration; running `d7_user_role` as-is strips editorial + authenticated-user access**
**Status: RESOLVED + VERIFIED end-to-end 2026-07-24** (branch `fix/user-role-permission-wipe`) — candidate fix 1 implemented and proven twice: on Xiaoming's synthetic fixture (which caught a *second* bug, missing `handle_multiples`, now fixed on the same branch) and independently on the author's DDEV against the full 1,538-user scrubbed shared DB (0 failed, no wipe, roles correct). See "Resolution", "Verification handoff", and "Independent confirmation" below.

## What we found

`d7_user_role` (in `mandala_migrations`) exists only to give `d7_users` a
`migration_lookup` from D7 `rid` → D11 role id. Its own header comment claims the
`entity:user_role` destination "loads the existing role and leaves it fully intact
(label AND permissions)". **That is false.** Running the migration against a
synthetic fixture, then inspecting active config:

| Role | permissions before | permissions after `d7_user_role` |
|---|---|---|
| `content_editor` | 23 | **0** |
| `authenticated`  | (several) | **0** |
| `anonymous`      | (several) | **0** |
| `administrator`  | 0 (is_admin) | 0 (unaffected — computed) |

Reproduced deterministically with `drush migrate:import d7_user_role --update`
(6 updated → all mapped non-`is_admin` roles emptied). The `entity:user_role`
destination **replaces** the existing config entity with the migration row's
values; since the process supplies only `id` (no `permissions`), permissions
default to `[]` on save. Dropping the `label` process (done earlier to stop the
collapse renaming the shared role) does **not** help — the wipe is independent of
`label`.

## Why it matters

On dev-0 the deploy runs `cim` (roles get their committed permissions), then we
run `migrate:import d7_user_role` → **content_editor / authenticated / anonymous
lose all permissions** until the next `cim`. That silently breaks editorial and
even basic authenticated-user access. This is a bigger hazard than the authmap
destination bug fixed alongside this note, because it degrades a running site
rather than just failing a migration.

## Constraints on the fix

- The role set must still be resolvable as an **array** in `d7_users`
  (`roles: [rid, rid, …]` → D11 role ids). `static_map` can't do this — it treats
  an array source as a nested key-path (`map[4][6]`), not element-wise — so an
  array-capable lookup is required. `migration_lookup` is the only core plugin
  that maps an array element-wise, and it needs a migration's map table.
- We deliberately **do not** migrate D7 permissions — D11 roles/permissions are
  owned by committed config (`user.role.*.yml`). So the fix must populate the
  lookup **without** the migration owning/rewriting role config.

## Candidate fixes (needs a design call — Than owns these migrations)

1. **Custom process plugin** — a small `mandala_migrations` process plugin that
   maps the `rid` array → role-id array from a static dict (element-wise), used
   directly in `d7_users.roles`. Eliminates `d7_user_role` entirely; nothing ever
   re-saves a role. (Preferred — smallest blast radius.)
2. **Non-destructive role-lookup migration** — keep `d7_user_role` but stop it
   clobbering: e.g. write to a throwaway destination whose map still records
   `rid → role id`, or make the save idempotent by supplying each target role's
   full committed permission set (rejected: duplicates committed config, drifts).
3. **`overwrite_properties`** — investigated; the observed wipe happens even with
   only `id` in the row, so limiting overwrite properties does not prevent it.

## Interim guardrail

**Do NOT run `d7_user_role` (or the `mandala_users` group) on dev-0 until this is
fixed.** The other two user migrations are unaffected in isolation, but `d7_users`
depends on `d7_user_role`, so the whole user migration is gated on this.

## Update 2026-07-22 — this note's "leave committed permissions alone" fix is not sufficient on its own

Follow-up investigation (live dev-0 query session, 2026-07-22) found that D11's
committed `content_editor` permissions — the thing candidate fix 1 above would
leave untouched — are **themselves wrong**: they cover `article`/`page`
(Drupal's stock demo content types), not `shanti_image`/`subcollection`/
`asset_link`/`collection` (Mandala's real content model). D7's actual editorial
grant turned out to be Organic Groups' own group-scoped role system, not core
`role_permission` at all. See
[d7-editor-permissions-og-group-scoped-not-migrated.md](d7-editor-permissions-og-group-scoped-not-migrated.md)
for the full data and what it means for the fix design — the destructive-wipe
fix here is still correct and necessary, but no longer sufficient by itself.

## Resolution — 2026-07-24 (branch `fix/user-role-permission-wipe`)

Implemented **candidate fix 1**. The `d7_user_role` lookup migration is deleted
entirely; role translation now happens in-process:

- New process plugin `mandala_role_map`
  (`mandala_migrations/src/Plugin/migrate/process/RoleMap.php`) — "static_map but
  array-aware." Holds the D7-rid → D11-role-machine-name dictionary in
  configuration and maps each element itself. No `entity:user_role` save happens
  anywhere, so committed role permissions can no longer be clobbered.
- `d7_users.roles` now calls `mandala_role_map` directly with an inline
  `map: {3: administrator, 4/5/6: content_editor}`; the `d7_user_role` entry was
  dropped from `migration_dependencies`.
- `migrate_plus.migration.d7_user_role.yml` deleted from both
  `mandala_migrations/config/install/` and `drupal/config/sync/`.

**Verified locally (DDEV, MySQL 8.4):**
- Real plugin exercised via the process-plugin manager: `[2,4,6]→[content_editor]`
  (rid 2 dropped, editor rids collapse+dedupe), `[3,4]→[administrator,content_editor]`,
  `[2]→[]`, scalar `5→[content_editor]`, `[]→[]`.
- `d7_users` partial-imported and instantiated cleanly: `roles` plugin =
  `mandala_role_map`, migration_dependencies has no `d7_user_role`.
- `content_editor` held at its committed **23 permissions** across cache rebuild,
  plugin exercise, and the migration import — no wipe.

**Not yet done here (deliberately, needs the D7 shared-user fixture):** a full
`migrate:import d7_users` against real shared-user data to confirm actual accounts
receive the mapped role set. That is the end-to-end proof for the pairing session
with Xiaoming's smoke-test harness (this DDEV has no shared-user D7 source loaded).

### Follow-up 2026-07-24 (Xiaoming) — end-to-end proof done, and it caught a second bug

The end-to-end `migrate:import d7_users` above was run against the rebuilt synthetic
fixture (see "Verification handoff" below). It confirmed the wipe is gone — **but
every migrated user came out with no editor/admin roles at all.** Root cause was a
second, independent bug in the `mandala_role_map` plugin: it was missing
**`handle_multiples = TRUE`** in its annotation.

The source `roles` is a multi-value property (array of rids). migrate's pipeline
prepends a `get` plugin for the `source: roles` line, and that `get` sets the
pipeline's `$multiple` flag TRUE. With `handle_multiples` defaulting FALSE,
`MigrateExecutable::processPipeline()` then applies the plugin **element-wise** —
calling `transform()` once per rid — and nests the results, e.g.
`[["administrator"],["content_editor"]]`. The `entity:user` destination cannot
assign that shape, so users silently received no mapped roles (a *silent*
correctness failure, not a loud one). The plugin's own unit test missed it because
it calls `transform()` directly with the whole array, which bypasses the pipeline's
per-element dispatch.

Fix (commit `56331f1` on this branch): declare `handle_multiples = TRUE` on
`mandala_role_map` so migrate passes the whole rid array to `transform()` in a
single call — matching the plugin's array-aware design. Re-verified after the fix:
all five fixture users received exactly the mapped roles (see the results table
below).

**Still open, tracked separately:** this fix stops the *destruction* of
`content_editor`'s permissions but does not make that permission list *correct* —
see [d7-editor-permissions-og-group-scoped-not-migrated.md](d7-editor-permissions-og-group-scoped-not-migrated.md)
(committed `content_editor` covers stock article/page, not Mandala's content model;
D7's real grant was OG group-scoped). Authoring the correct sitewide `content_editor`
permissions (and deciding whether per-group Group-roles are in MVP scope) remains a
separate task.

## Verification handoff — run on Xiaoming's DDEV — ✅ DONE 2026-07-24

**Result: PASS after fixing a second bug.** Regression proven (`content_editor`
held at 23, no wipe) *and* role assignment proven, once the `handle_multiples`
fix above was applied. Per-user results:

| uid | D7 rids | D11 roles after import |
|----|---------|------------------------|
| 5  | 4 (editor)            | `content_editor` |
| 8  | 5 (workflow editor)   | `content_editor` |
| 12 | 3 + 4                 | `administrator`, `content_editor` |
| 17 | 4 + 5 + 6             | `content_editor` (single — collapse+dedupe ✓) |
| 20 | — (none)              | (plain) |

`d7_users` 5/5 imported, `d7_user_authmap` 5/5 linked (`simplesamlphp_auth` →
bare computing-id), 0 messages, `content_editor` still 23. Local env restored,
fixture DB dropped (no PII persisted).

**Fixture had to be rebuilt** — the 2026-07-21 fixture was ephemeral (isolated DB
dropped, `migrate_users` connection only ever wired ad-hoc via env vars), so it
was gone. Rebuilt from `~/Desktop/Mandala/mandala_shared.sql` using **schema-only**
extraction (0 data rows) + synthetic uid-keyed rows. Two gotchas for anyone
re-running: (1) beyond users/users_roles/role/role_permission/authmap, the d7_user
source also needs a `system` table (its `checkRequirements()` probes `source_module
= user`; without it the migration is silently filtered out of `migrate:status`) and
empty `field_config` + `field_config_instance` tables (the source reads user
fields). (2) A fresh checkout's *active* config is stale — run `drush cim` first so
active config uses `mandala_role_map` and drops `d7_user_role` before importing.

The original handoff intent (kept below for reference):
This was the end-to-end proof the author's DDEV could **not** do (no shared-user D7
source loaded there). Running the fix on the synthetic (non-PII) fixture gives the
symmetric proof: the wipe is gone **and** users get the right roles.

Prereqs: on branch `fix/user-role-permission-wipe`; the PR #66 fixture loaded and
its users carry rids 3/4/5/6 so the whole map is exercised (add roles to fixture
users if it only had one).

```bash
# 1. Discover the new plugin.
ddev drush cr

# 2. Baseline BEFORE running — record permission counts.
ddev drush php:eval 'foreach(["content_editor","authenticated","anonymous"] as $r){$o=\Drupal\user\Entity\Role::load($r);echo "$r: ".($o?count($o->getPermissions()):"MISSING")."\n";}'
#    Expect content_editor = 23.

# 3. Put the fixed migration into active config (partial import leaves unrelated
#    local drift untouched).
mkdir -p tmp_um && cp drupal/config/sync/migrate_plus.migration.d7_users.yml \
  drupal/config/sync/migrate_plus.migration.d7_user_authmap.yml \
  drupal/config/sync/migrate_plus.migration_group.mandala_users.yml tmp_um/ \
  && ddev drush config:import --partial --source=/var/www/html/tmp_um -y && rm -rf tmp_um
#    Confirm wiring: roles plugin = mandala_role_map, no d7_user_role dependency.
ddev drush php:eval '$m=\Drupal::service("plugin.manager.migration")->createInstance("d7_users");$p=$m->getProcess();echo "roles plugin: ".$p["roles"][0]["plugin"]."\n";echo "deps: ".json_encode($m->getMigrationDependencies())."\n";'

# 4. Run the user migration.
ddev drush migrate:import d7_users

# 5. THE REGRESSION CHECK — re-run step 2. content_editor must STILL be 23,
#    authenticated/anonymous intact. (Before the fix this was 0.)

# 6. Mapping worked on real rows — spot-check migrated users' roles.
ddev drush php:eval 'foreach(\Drupal\user\Entity\User::loadMultiple() as $u){if(!$u->id())continue;echo $u->id()." ".$u->getAccountName().": ".implode(",",$u->getRoles())."\n";}'
#    - user who had an editor rid (4/5/6) -> has content_editor
#    - user with two/three editor rids   -> single content_editor (no duplicate)
#    - user with rid 3                   -> administrator
#    - plain user                        -> no editor role

# 7. Close the rid loose end — list distinct rids in the fixture; confirm none
#    outside {3,4,5,6} (unmapped rids are silently dropped by design).
ddev drush php:eval '$db=\Drupal\Core\Database\Database::getConnection("default","migrate_users");foreach($db->query("SELECT DISTINCT rid FROM users_roles ORDER BY rid")->fetchCol() as $r){echo $r."\n";}'
```

**Pass = step 5 (wipe gone) AND step 6 (roles correctly assigned).** If step 7
surfaces a rid outside {3,4,5,6}, decide whether it maps to a D11 role and add it
to `map` in `d7_users.yml` (both `config/install` and `config/sync`).

## Independent confirmation 2026-07-24 (Than) — full scrubbed DB, second machine

Re-ran the end-to-end on a *different* DDEV (author's, MySQL 8.4) against the
**full scrubbed shared-user dump** (`Mandala/data/mandala_shared.sql`, 1,538
real-shaped users) — not the synthetic fixture. Confirms the fix on real data and
on a second machine.

- Imported the dump into a **separate DDEV database** (`ddev import-db
  --database=mandala_shared --file=…`) so it never touches the site DB; pointed the
  `migrate_users` connection at it via the env-driven block in `settings.php`
  (`MIGRATE_USERS_DATABASE=mandala_shared MIGRATE_SOURCE_HOST=db
  MIGRATE_SOURCE_USER=db MIGRATE_SOURCE_PASSWORD=db drush migrate:import d7_users`).
- Source role reality (matches the map exactly, no unmapped rids): rid 3
  administrator ×23, rid 4 editor ×142, rid 5 workflow editor ×2, rid 6 shanti
  editor ×0.

Results — **1,538 imported, 0 failed:**

| Check | Result |
|---|---|
| `content_editor` perms after import | **23** (baseline 23 — no wipe) |
| `authenticated` / `anonymous` perms | 10 / 6 — intact |
| D11 users with `content_editor` | **144** = distinct rid 4/5/6 users (142+2+0) |
| D11 users with `administrator` | **23** = rid 3 users |
| admin uid 1 | `[administrator]` |
| plain uid 2 | `[]` |

**Two operational gotchas for whoever runs this on dev-0** (neither is a fault in
the fix; both cost time here):

1. **`externalauth` must be enabled first.** migrate's discovery instantiates *all*
   migrations, and the sibling `d7_user_authmap` migration's `authmap` source +
   destination plugins come from `externalauth`. If it's off, discovery throws
   *"The 'authmap' plugin does not exist"* and **aborts the entire `migrate:import`
   run — even for `d7_users`, which doesn't depend on authmap.** dev-0 has the SAML
   stack so this is moot there, but a bare DDEV needs `drush en externalauth`.
2. **Stale active config silently drops `d7_users`.** If the `migrate_plus.migration.d7_users`
   config entity isn't in *active* config (fresh checkout, or drift), migrate_tools
   reports *"Migration d7_users does not exist"* — not a connection error. Run
   `drush cim` (or partial-import the three `mandala_users` configs) and verify with
   `\Drupal::config('migrate_plus.migration.d7_users')->get('id')` before importing.

## Cross-references

- `drupal/web/modules/custom/mandala_migrations/config/install/migrate_plus.migration.d7_user_role.yml`
- [d7-shared-user-database.md](d7-shared-user-database.md)
- Fixed together with the authmap destination bug (`entity:authmap` → `authmap`)
  found in the same smoke-test.
