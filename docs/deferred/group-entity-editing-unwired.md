# Group entity editing is fully unwired — no one can edit a group, and a subcollection's parent can't be set

**Area:** migration / Group module / content model / 1b.2 collections implementation
**Raised during:** Session 2026-10-08 (Than, creating review-holding groups on dev-0 for the
orphaned-content sweep, issue #289)
**Issue:** [#301](https://github.com/uvalib/mandala-navina/issues/301)
**Priority:** High — blocks managing any collection/subcollection, including the review-holding
groups issue #289 needs.
**Status (2026-10-09):** Built, verified live, **open for review in
[PR #308](https://github.com/uvalib/mandala-navina/pull/308) — Than's explicit instruction is to
hold it, not merge without asking.** All three findings below are fixed, plus two follow-on asks
from the same conversation (an administrator superset, and a node-level "Collection" field) — see
"What was built" below.

## What was built (PR #308, five commits)

1. **Findings 1 and 2 below, fixed as designed**: `edit group`/`delete group` granted to
   `content_editor_insider`/`content_editor_outsider` on both group types;
   `field_parent_collection` un-hidden on the subcollection form display with its already-resolved
   default widget.
2. **Than's follow-up: "an administrator should be able to edit any group, they should be able to
   edit any content... all the permission of content_editors plus the admin permissions normally
   granted in Drupal."** Granting `content_editor` the permission didn't cover `administrator` —
   they're different site roles, and Group's permission system never consults `is_admin` (see
   Finding 1). Fixed with Group's own equivalent of `is_admin`: four new group roles
   (`collection`/`subcollection` × insider/outsider), each flagged `admin: true` and synced to the
   site `administrator` role. An admin-flagged group role gets every group permission
   automatically, present and future — no permission list to maintain.
3. **View-page affordances**, now that editing works: a subcollection's "Parent collection" moved
   into the sidebar in the same list shape the collection page uses for "Subcollections"; an "Add
   Item" link (Group's own add-content dashboard) on both bundles; an "Add Subcollection" link on
   collections only — both gated on real route access via the access manager, not a hardcoded
   permission string.
4. **The Add Subcollection link pre-fills its parent.** It carries the originating collection's id
   as a `?parent=` query parameter; a `hook_form_FORM_ID_alter()` reads it and defaults
   `field_parent_collection`. (Separately confirmed, no code needed: creating a node via "Add Item"
   already auto-links it to the group — that's Group's own `group_relationship_entity_submit()`,
   wired whenever a form is reached through the `create_form` route.)
5. **Than: "In existing nodes there is no way to change or set the collection... a field in the
   edit form that owners can edit... a drop down listing all the collections the owner belongs
   to." Then: "An admin should be able to change the collection of any node."** Group membership is
   a relationship row, not a node field, so there was no edit-form UI at all for this. Added a
   "Collection" select to the edit form of any existing `group_node` bundle (discovered via
   `CollectionVisibility::groupNodeBundles()`, not hardcoded). Defaults to the node's current
   collection; lists every collection the editing account is a literal member of, **plus** — for
   accounts with a membership-independent create permission (the new admin-flagged roles, and
   `content_editor`'s outsider-scope role) — every collection/subcollection on the site, computed
   once per bundle via the outsider-scope permission item rather than once per group. Moving is
   delete-old-relationship + add-new-relationship (a node belongs to exactly one collection in
   practice). Submitted values are re-validated server-side against real Group permissions, not
   just the rendered options list, so a tampered POST naming an unauthorized or
   bundle-incompatible group is rejected.

**Verified live** for every piece above: a real non-superuser `content_editor` test account; a
fresh `administrator` account with **zero** group memberships (saw and could use all 415
collections/subcollections on the site); a real node move (confirmed via
`group_relationship_field_data`, reverted after); a tamper attempt against an unauthorized
collection, correctly rejected with no DB change. `mandala_group_inheritance` kernel suite 11/11
throughout, no regressions. Test accounts and test content cleaned up after each check.

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

All three items below are **done** (see "What was built" above) — only merging PR #308 remains,
and that is explicitly Than's call, not automatic once review finishes.

1. ~~Grant `edit group` (and likely `delete group`) on the appropriate group role(s)~~ — done:
   `content_editor_insider`/`content_editor_outsider` on both group types, plus the new
   admin-flagged roles for `administrator`.
2. ~~Un-hide `field_parent_collection`~~ — done, with the default `entity_reference_autocomplete`
   widget.
3. ~~Verify live~~ — done, with both a real `content_editor` account and a zero-membership
   `administrator` account, not just ShantiAdmin's pre-existing session.

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
