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

## Resolution (2026-06-12)

**D7 baseline confirmed — full inheritance model:**
1. **Visibility**: private parent → private subcollection by default; subcollection can independently override to public
2. **Membership**: subcollection inherits all parent collection members; additional members can be added to subcollection independently

**`ggroup` and `subgroup` investigated:** Both handle membership/role inheritance (partially addressing requirement 2) but neither propagates group-level visibility (public/private) from parent to child (requirement 1 unaddressed by either).

**Recommended approach:** Entity reference field (proven in Spike 3) + custom hooks for both inheritance requirements. See [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) — Option D for full details including membership sync edge cases.

## Impact

This decision affects Phase 3 (collections implementation) and the access control section of the migration plan. If inheritance is required and addressed via custom code rather than a contrib module, the implementation effort for Phase 3 increases.
