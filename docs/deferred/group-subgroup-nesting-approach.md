# Group Subgroup Nesting Approach Decision
**Area:** collections / group module
**Raised during:** Spike 3
**Jira:** (add when available)
**Priority:** High

## Confirmed requirements (2026-06-12)

The full inheritance model, matching D7 `og_subgroups` behaviour:

1. **Visibility inheritance** — if a parent collection is private (restricted), its subcollections are private by default. A subcollection can be independently set to public, overriding the parent.
2. **Membership inheritance** — a subcollection inherits all members of its parent collection. Additional members can be added to the subcollection independently (subcollection-specific members).

---

## Options evaluated

### Option A: Entity reference field alone (proven in Spike 3)
Add a `parent_collection` entity reference field on the `subcollection` group type, restricted to `collection` bundle targets.

**What it provides:**
- One-level constraint enforced automatically by the type system
- No extra dependencies

**What it does NOT provide:**
- No visibility inheritance
- No membership inheritance

**Verdict:** Insufficient on its own for the confirmed requirements.

---

### Option B: `ggroup` module
[`drupal/ggroup`](https://www.drupal.org/project/ggroup) — graph-based group-in-group module.

**Investigated 2026-06-12:**
- ✓ Handles **membership inheritance** — parent members can access child groups; additional members can be added to child groups independently. This matches requirement 2.
- ✗ Does NOT handle **visibility/access inheritance** — does not propagate public/private status from parent to child group. Requirement 1 still needs custom logic.
- ✗ No stable release; D11 compatibility work still in progress.
- Mutually incompatible with `subgroup`.

---

### Option C: `subgroup` module
[`drupal/subgroup`](https://www.drupal.org/project/subgroup) — group-in-group via group type configuration.

**Investigated 2026-06-12:**
- ✓ Handles **membership inheritance** — similar to `ggroup`; parent members inherit access to child groups with additive membership supported.
- ✗ Does NOT handle **visibility/access inheritance**. Requirement 1 still needs custom logic.
- ✓ D10-stable (2.0.1+); D11 compatibility unconfirmed.
- Mutually incompatible with `ggroup`.

---

### Option D: Entity reference + custom hooks (recommended)
Entity reference field (Option A) plus a custom module handling both inheritance requirements:

**Membership inheritance:**
- `hook_group_relationship_insert` — when a subcollection is created, enumerate parent collection members and add them to the subcollection automatically
- `hook_group_relationship_insert` (collection membership) — when a new member is added to a collection, also add them to all subcollections of that collection
- `hook_group_relationship_delete` (collection membership) — when a member is removed from a collection, remove them from subcollections unless they were added directly to the subcollection

**Visibility inheritance:**
- `hook_group_presave` — when a subcollection is created, copy the parent collection's anonymous outsider permissions to the subcollection as defaults
- `hook_group_update` — when a collection's visibility changes, propagate to subcollections that have not been independently overridden
- A `visibility_overridden` boolean field on the `subcollection` group type tracks whether a subcollection has diverged from its parent (so parent changes don't stomp independent subcollection settings)

**Trade-offs:**
- Implements exactly both confirmed requirements
- No extra module dependencies, no mutual incompatibility risk
- Logic is explicit and under our control
- ~2–3 days of custom module work
- Membership sync logic must handle edge cases (member removed from collection but added directly to subcollection)

---

### Option E: `ggroup` or `subgroup` + custom visibility hook
Use `ggroup` (once stable on D11) or `subgroup` for membership inheritance, plus a custom hook for visibility inheritance.

**Trade-offs:**
- Reduces custom code for membership inheritance
- Still requires custom visibility logic regardless
- `ggroup` not D11-ready; `subgroup` D11 status unconfirmed
- Adds a module dependency and mutual incompatibility constraint

**Verdict:** May be worth revisiting once `ggroup` reaches a stable D11 release. For now, Option D is lower risk.

---

## Recommendation

**Option D** — entity reference + custom hooks. Implements both requirements cleanly, no external module risk, and the logic is straightforward to write and test.

## What needs to happen before Phase 3

1. ~~Clarify inheritance requirements~~ — **Confirmed** (2026-06-12): visibility inheritance (private parent → private child, child can override) and membership inheritance (child inherits parent members, can add its own).
2. ~~Evaluate ggroup/subgroup~~ — **Done** (2026-06-12): neither provides visibility inheritance; both provide membership inheritance but `ggroup` is not D11-ready.
3. **Design the custom module** — scope the membership sync edge cases before Phase 3 implementation begins.
4. Record the final decision as an ADR before Phase 3 collections implementation begins.
