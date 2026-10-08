# Group entity editing is fully unwired — no one can edit a group, and a subcollection's parent can't be set

**Area:** migration / Group module / content model / 1b.2 collections implementation
**Raised during:** Session 2026-10-08 (Than, creating review-holding groups on dev-0 for the
orphaned-content sweep, issue #289)
**Issue:** [#301](https://github.com/uvalib/mandala-navina/issues/301)
**Priority:** High — blocks managing any collection/subcollection, including the review-holding
groups issue #289 needs.

## What's broken

Than noticed, working on dev-0:

1. **No group can be edited by anyone, through the UI.** There is no visible Edit tab on a
   group's page, and navigating directly to `/group/{gid}/edit` returns "Access denied."
2. **A subcollection's parent collection can't be set.** The "Add Subcollection" form has no
   field for it at all.

Reproduced locally on DDEV, not just observed on dev-0 — see below. These are two separate root
causes that happen to block the same workflow.

## Finding 1: no group role grants `edit group` / `delete group`, and this is not bypassed by site-admin roles

Group module's access control for a group entity's `update`/`delete` operations
(`GroupAccessControlHandler::checkAccess()`, in `web/modules/contrib/group/src/Entity/Access/
GroupAccessControlHandler.php`) is:

```php
case 'update':
  return GroupAccessResult::allowedIfHasGroupPermission($entity, $account, 'edit group');
case 'delete':
  return GroupAccessResult::allowedIfHasGroupPermission($entity, $account, 'delete group');
```

This routes entirely through `GroupPermissionChecker::hasPermissionInGroup()`, which computes
permissions from the account's **group-scoped roles** (`group.role.{collection,subcollection}-*`
config) — it never consults the account's site-wide roles at all. Site roles flagged `is_admin:
true` (like `administrator`, ShantiAdmin's role) bypass ordinary `$account->hasPermission()`
checks, but that bypass has no effect here, because this code path doesn't call
`$account->hasPermission()` for `edit group`/`delete group` at all.

When the 1b.2 Group collections implementation wired up group roles
(`collection-member`, `collection-content_editor_insider`, `collection-content_editor_outsider`,
and their `subcollection-*` equivalents), it only ever granted permissions over **content inside
the group** — `create`/`update any`/`delete any` on `group_node:{audio,shanti_image,video}`. **No
group role, in any group type, grants `edit group` or `delete group`.** This means the gap
applies to every collection and subcollection, not just the review-holding ones.

**Confirmed live on DDEV, logged in as ShantiAdmin (uid 1, `administrator` role):**
- `/group/3/edit` (a `collection`) → "Access denied"
- `/group/56/edit` (a `subcollection`) → "Access denied"
- `/group/3`'s Primary tabs show only "View" / "Devel" — no Edit tab renders for anyone, since
  the local task's own access check is the same `update` operation above

This is not a per-group misconfiguration — it is a complete gap in the permission model, and it
is still a real site-admin problem to fix: granting `edit group`/`delete group` needs setting it
on the right group-role config, not hand-fixing one group.

## Finding 2: `field_parent_collection` exists but is hidden on the subcollection form

`field_parent_collection` (entity reference, target bundle `collection`) exists on the
`subcollection` group bundle — this is the parent-link field that
[group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) (superseded by
[ADR 011](../adr/011-group-collections-inheritance.md), Option D) always intended subcollections
to carry, so `mandala_group_inheritance`'s visibility/membership-inheritance hooks have something
to key off of. It is not dead data — the field is populated by migration and actively read by the
inheritance hooks.

But `core.entity_form_display.group.subcollection.default` has it explicitly hidden:

```yaml
hidden:
  field_group_access: true
  field_legacy_nid: true
  field_legacy_site: true
  field_parent_collection: true
  field_visibility_overridden: true
  uid: true
```

**Confirmed live:** `/group/add/subcollection` has no "Parent collection" field anywhere on the
form — only Featured image, Overview, Title, Is review-holding group, and the usual revision/
path/publish controls. The other four hidden fields in that list are correctly non-editable by
design (`field_group_access`/`field_visibility_overridden` are inheritance-hook-managed,
`field_legacy_nid`/`field_legacy_site` are migration bookkeeping, same pattern as
[the AV edit-form field audit](av-field-audit-d7-vs-d11-technical-metadata.md)) — but
`field_parent_collection` has no such reason to stay hidden. This looks like an oversight from
the same original form-display pass, not a deliberate exclusion.

## Why this matters for #289 right now

Than is creating review-holding groups by hand for the orphaned-content sweep. Both gaps bite
that workflow directly: once a review-holding group is created, its title/overview/access can't
be corrected without going around the UI, and if a review-holding group should nest under (or a
subcollection otherwise needs) a parent collection, there's no way to set it.

## What needs doing

1. Grant `edit group` (and likely `delete group`) on the appropriate group role(s) — almost
   certainly `collection-content_editor_insider`/`subcollection-content_editor_insider` at
   minimum, since those are the "real editor" roles; whether `-outsider` variants or `-member`
   should also get it is a design question, not just a config flip. Build live in DDEV and
   export, per the repo's config convention.
2. Un-hide `field_parent_collection` on `core.entity_form_display.group.subcollection.default`
   (same "build live, export" convention) and pick a sensible widget/weight.
3. Verify live: a non-superuser account with the fixed role can edit a real collection and a
   real subcollection, and can set/change a subcollection's parent, without the admin bypass
   ShantiAdmin has been inadvertently relying on not needing.

## Related

- [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) — the superseded
  options note; Option D (entity reference + custom hooks) is what introduced
  `field_parent_collection`
- [ADR 011](../adr/011-group-collections-inheritance.md) — the accepted design
- [group-access-inheritance-subcollections.md](group-access-inheritance-subcollections.md) —
  related inheritance-hook context
- [av-field-audit-d7-vs-d11-technical-metadata.md](av-field-audit-d7-vs-d11-technical-metadata.md) —
  the same "field exists but is hidden on the form" defect class, found on AV nodes and on the
  av_pbcore_* paragraph bundles this same session
- Issue #289 — orphaned-content review-group sweep, the workflow that surfaced this
