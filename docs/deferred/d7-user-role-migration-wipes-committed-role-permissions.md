# `d7_user_role` migration wipes committed D11 role permissions

**Area:** migration / users / roles / config
**Raised during:** Local synthetic user-migration smoke-test (2026-07-21, Xiaoming + Than)
**Jira:** (add when available)
**Priority:** **High — blocks the dev-0 user migration; running `d7_user_role` as-is strips editorial + authenticated-user access**

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

## Cross-references

- `drupal/web/modules/custom/mandala_migrations/config/install/migrate_plus.migration.d7_user_role.yml`
- [d7-shared-user-database.md](d7-shared-user-database.md)
- Fixed together with the authmap destination bug (`entity:authmap` → `authmap`)
  found in the same smoke-test.
