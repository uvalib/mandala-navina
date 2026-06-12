# Group Subgroup Nesting Approach Decision
**Area:** collections / group module
**Raised during:** Spike 3
**Jira:** (add when available)
**Priority:** High

## Decision needed

Spike 3 proved two viable approaches to modeling collection → subcollection nesting in Group 3.x:

### Option A: Entity reference field (proven in Spike 3)
Add a `parent_collection` entity reference field on the `subcollection` group type, restricted to `collection` bundle targets. One-level constraint is enforced by the type system. No additional modules required.

**Trade-offs:**
- Simple, no extra dependencies
- One-level constraint enforced automatically
- **Access is NOT inherited** — restricted collection does not propagate access rules to its subcollections (see companion deferred note)
- Matches a "flat with parent pointer" model rather than a true hierarchy

### Option B: `ggroup` module
Install [`drupal/ggroup`](https://www.drupal.org/project/ggroup) to enable true group-in-group relationships. Groups propagate membership and permissions down the hierarchy.

**Trade-offs:**
- True hierarchical access inheritance
- Requires an additional module dependency
- `ggroup` and `subgroup` modules are mutually incompatible — must choose one
- D11 compatibility and maintenance status of `ggroup` not yet evaluated

### Option C: `subgroup` module
Install [`drupal/subgroup`](https://www.drupal.org/project/subgroup). Similar approach to `ggroup` but uses group type configuration.

**Trade-offs:**
- Same access inheritance benefit as `ggroup`
- Mutually incompatible with `ggroup`
- D11 compatibility and maintenance status not yet evaluated

## What needs to happen before Phase 3

1. Clarify with David Germano / the Mandala team whether restricted-collection access rules need to propagate to subcollections, or whether subcollections are always independently configured
2. If inheritance is required: evaluate `ggroup` and `subgroup` for D11 compatibility and maintenance health before choosing
3. Record the decision as an ADR before Phase 3 collections implementation begins
