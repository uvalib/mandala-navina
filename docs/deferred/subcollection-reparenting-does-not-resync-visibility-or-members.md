# Re-parenting a subcollection does not re-sync its visibility or inherited memberships

**Category:** access-control
**Raised during:** Review-holding flag inheritance, 2026-10-14 (Than)
**Issue:** none
**Priority:** Low — re-parenting should be rare (staff move *nodes* between collections, not whole subcollections), but it silently leaves the child stale
**Owner:** unassigned

## What was found

`field_parent_collection` is an editable, optional field on the subcollection form. The inheritance
hooks in `mandala_group_inheritance` act only when a subcollection is **created**
(`group_presave` returns early unless `isNew()`, and `group_insert` backfills members only on
insert). Changing the parent of an existing subcollection therefore does not:

- re-copy the new parent's `field_group_access`, nor revisit `field_visibility_overridden`
  (ADR 011);
- drop the old parent's inherited memberships (`mandala_inherited`), or add the new parent's.

The review-holding flag is **not** affected: since 2026-10-14 it is re-derived from the parent on
every save (see [orphaned-content-temp-group-on-migration.md](orphaned-content-temp-group-on-migration.md)).

## Options

1. Lock `field_parent_collection` once a subcollection exists (simple; removes the ability to fix a
   mis-filed subcollection).
2. Handle the change in `group_presave`/`group_update`: re-derive visibility unless overridden, and
   reconcile inherited memberships against the new parent.

Not decided.
