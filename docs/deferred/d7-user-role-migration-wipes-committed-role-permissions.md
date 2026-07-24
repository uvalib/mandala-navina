# `d7_user_role` migration wipes committed D11 role permissions

**Area:** migration / users / roles / config
**Raised during:** Local synthetic user-migration smoke-test (2026-07-21, Xiaoming + Than)
**Jira:** (add when available)
**Priority:** **High — blocks the dev-0 user migration; running `d7_user_role` as-is strips editorial + authenticated-user access**
**Status: RESOLVED 2026-07-24** (branch `fix/user-role-permission-wipe`) — candidate fix 1 implemented; see "Resolution" below.

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

**Still open, tracked separately:** this fix stops the *destruction* of
`content_editor`'s permissions but does not make that permission list *correct* —
see [d7-editor-permissions-og-group-scoped-not-migrated.md](d7-editor-permissions-og-group-scoped-not-migrated.md)
(committed `content_editor` covers stock article/page, not Mandala's content model;
D7's real grant was OG group-scoped). Authoring the correct sitewide `content_editor`
permissions (and deciding whether per-group Group-roles are in MVP scope) remains a
separate task.

## Verification handoff — run on Xiaoming's DDEV

This is the end-to-end proof the author's DDEV could **not** do (no shared-user D7
source loaded there). Xiaoming's DDEV has the **PR #66 synthetic (non-PII)
shared-user fixture** reachable via the `migrate_users` connection — the same
harness that originally reproduced the wipe (`content_editor` 23→0). Running the
fix on it gives the symmetric proof: the wipe is gone **and** users get the right
roles.

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

## Cross-references

- `drupal/web/modules/custom/mandala_migrations/config/install/migrate_plus.migration.d7_user_role.yml`
- [d7-shared-user-database.md](d7-shared-user-database.md)
- Fixed together with the authmap destination bug (`entity:authmap` → `authmap`)
  found in the same smoke-test.
