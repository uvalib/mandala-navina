# Group Access Inheritance: Collection → Subcollection
**Area:** collections / access control
**Raised during:** Spike 3
**Jira:** (add when available)
**Priority:** High

## Problem

In Group 3.x with the entity reference nesting approach (Option A from the companion deferred note), subcollections are independent Group entities. A node's access is determined by the groups it directly belongs to.

**Consequence:** If a collection is restricted (anonymous role has no view permissions), that restriction does not automatically apply to nodes in the collection's subcollections. Each subcollection's access must be configured independently.

**Example:**
- "Buddhist Texts" collection — restricted
- "Tibetan Sutras" subcollection inside it — public by default if anonymous role has view permissions on subcollection group type
- A node in "Tibetan Sutras" would be publicly visible even if the parent collection is restricted

## What needs to be decided

1. **Is this acceptable for Mandala?** If subcollections are always independently governed (i.e., each subcollection's admin sets its own access), the entity reference model is fine.

2. **If inheritance is required:** Either adopt `ggroup`/`subgroup` modules (see companion deferred note), or implement custom logic in a `hook_group_relationship_insert` / `hook_entity_presave` that mirrors parent collection access down to subcollections when a new subcollection is created or when the parent's access changes.

3. **D7 baseline:** Confirm from David Germano whether D7 `og_subgroups` enforced access inheritance from parent to child group, or whether subcollection access was independently managed.

## Impact

This decision affects Phase 3 (collections implementation) and the access control section of the migration plan. If inheritance is required and addressed via custom code rather than a contrib module, the implementation effort for Phase 3 increases.
