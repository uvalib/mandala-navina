# ADR 011: Group collections inheritance — entity-reference nesting + custom hooks

**Status:** Accepted
**Date:** 2026-06-18
**Deciders:** Than Grove (Spike 3 lead, decision owner), Yuji Shinozaki (Lead Architect), Xiaoming Wang
**Relates to:** [Spike 3](../spikes/spike-03-group-collections.md) (Partial → resolved by this ADR),
[ADR 009](009-migration-sequencing-strategy.md) (migration sequencing),
[Sprint 1](../sprints/sprint-01-images-implementation.md) (Step 1b consumes this),
[Collections Inheritance Decision Brief](../planning/collections-inheritance-decision-brief.md) (closed by this ADR)
**Supersedes:** the "Recommended" standing of
[group-subgroup-nesting-approach.md](../deferred/group-subgroup-nesting-approach.md) and
[group-access-inheritance-subcollections.md](../deferred/group-access-inheritance-subcollections.md)

## Context

The D11 rebuild replaces D7's Organic Groups (OG) + `og_subgroups` with the
**Group 3.x** module. Spike 3 proved the structural half on D11 — `collection` and
`subcollection` group bundles, one-level nesting via a `parent_collection`
entity-reference field, content membership, and basic access control — but
explicitly did not resolve the **inheritance model**.

Two requirements were confirmed (2026-06-12) as the minimum to match D7
`og_subgroups` behaviour, and are settled inputs to this decision:

1. **Visibility inheritance** — a private parent collection ⇒ its subcollections
   are private by default; a subcollection may independently override to public.
2. **Membership inheritance** — a subcollection inherits all members of its
   parent collection; additional subcollection-specific members may be added.

Production scale (verified against the 2026-06-11 D7 dump):
**55 collections, 116 subcollections, one level deep** — so the model only has
to handle a single layer of nesting. Deeper nesting is explicitly deferred
(separate concern, not gating MVP).

The Group 3.x contrib landscape, investigated 2026-06-12:

| Option | Membership inheritance | Visibility inheritance | D11 status |
|---|---|---|---|
| A — entity-reference field alone | ✗ | ✗ | Proven (Spike 3) |
| B — `ggroup` | ✓ | ✗ (needs custom) | **Not D11-ready** (no stable release) |
| C — `subgroup` | ✓ | ✗ (needs custom) | D10-stable; D11 unconfirmed |
| D — entity-reference + custom hooks | ✓ | ✓ | No dependency risk |
| E — `ggroup`/`subgroup` + custom visibility hook | ✓ | ✓ | Dependency-blocked (per B/C) |

The decisive observation: **no Group 3.x contrib option provides visibility
inheritance out of the box** — visibility always requires custom logic.
That removes the strongest argument for taking on `ggroup`/`subgroup` as a
dependency, since their unique value (membership inheritance) doesn't
eliminate the custom-code requirement; it only shrinks it.

## Decision

**Adopt Option D: entity-reference nesting + a custom module providing both
inheritance behaviours.** Structural nesting uses the Spike 3 pattern
(`parent_collection` entity-reference field on `subcollection`, restricted to
`collection` targets). A custom module implements visibility and membership
inheritance via Group's existing event hooks.

### Structural shape (unchanged from Spike 3)

- `collection` and `subcollection` are sibling Group bundles.
- A `subcollection` references its parent via a single-cardinality
  `parent_collection` entity-reference field, target-bundle restricted to
  `collection`. This is what enforces "one level only" at the schema layer.
- Content membership uses the standard `group_node:shanti_image` relation
  plugin (and analogous relations for other content types in later sprints).

### Custom inheritance hooks

**Membership inheritance** (matching D7 `og_subgroups` member cascade):

- `hook_group_relationship_insert` — when a `subcollection` is created with a
  `parent_collection`, enumerate the parent's members and add them to the
  subcollection.
- `hook_group_relationship_insert` on collection membership — when a member is
  added to a `collection`, also add them to every `subcollection` of that
  collection.
- `hook_group_relationship_delete` on collection membership — when a member is
  removed from a `collection`, remove them from its subcollections **unless**
  they were also added directly to the subcollection (the sub-only retention
  edge case below).

**Visibility inheritance** (matching D7 private-parent cascade):

- `hook_group_presave` — when a `subcollection` is created, copy the parent
  collection's outsider/anonymous permissions to the subcollection as defaults.
- `hook_group_update` — when a `collection`'s visibility changes, propagate to
  subcollections **except** those whose `visibility_overridden` flag is set.

### The `visibility_overridden` flag

A boolean field on the `subcollection` bundle records whether an editor has
intentionally diverged from the parent's visibility. The flag is the integrity
guarantee that parent updates do not silently stomp deliberate per-subcollection
choices.

- Set automatically when a user changes a subcollection's visibility away from
  the parent's value.
- Cleared automatically when a user resets the subcollection's visibility to
  match the parent's current value.
- The `hook_group_update` propagation reads this flag and skips overridden
  subcollections.

### Sub-only member retention (membership edge case)

When a member is removed from a parent `collection`, the cascade-delete must
**not** remove that user from a subcollection if they were added to the
subcollection directly (independent of parent inheritance). The hook
distinguishes these cases by tracking whether a subcollection membership was
created by the cascade or by direct assignment — implementation may use a
provenance field, a relationship origin marker, or a lookup against the parent's
historical members; the choice is left to the module implementation but the
behavioural requirement is fixed by this ADR.

### Sequencing — closes Q2 and Q3 of the decision brief

- **Q2 (sequencing).** All Group collections work — both structural CMI config
  and the inheritance module — is **held for Step 1b**. No `collection` /
  `subcollection` group config lands in the Sprint 1 Step 1a baseline. The
  Images content-model audit explicitly puts OG-related fields out of scope for
  Step 1a, and Sprint 1 task 1a.7 (the pattern-setting image migration) does
  not touch OG/Group fields — they are deferred to 1b.2.
- **Q3 (phase ownership).** The inheritance hooks land **with 1b.2 in Sprint 1
  Step 1b**, not deferred to Phase 3. Rationale: Sprint 1's security acceptance
  criterion ("a restricted Images item is non-retrievable by an unauthorized
  user via the D11 search path") cannot honestly pass for content in a
  subcollection of a private collection without visibility inheritance in
  place. The "before Phase 3" wording in the superseded deferred notes was
  defensive against contrib-readiness risk, which Option D eliminates.

## Consequences

### Implementation work this adds to Sprint 1 Step 1b

Folded into task 1b.2 (currently "OG → Group migration"):

- Structural CMI config: `collection` and `subcollection` group bundles, the
  `parent_collection` reference field, the `visibility_overridden` flag field,
  permission grids for both bundles, and the `group_node:shanti_image`
  relation plugin.
- A new custom module (working name `mandala_group_inheritance` or similar)
  implementing the five hooks above. Approximate size: ~2–3 days of
  module work plus tests.
- Migration of D7 `field_og_collection_ref` → Group membership, and of D7
  `group_content_access` → `collection`/`subcollection` visibility — bridged by
  the inheritance module so the migrated state reproduces D7 behaviour.

Sprint 1 acceptance gates that now have a real basis:

- 1b.3 (Solr-proxy visibility coherence) can be exercised end-to-end against
  nested-collection content.
- 1b.4 (paragraph access inheritance) composes correctly because the parent
  image's access is now correct under nesting.
- Sprint 1's overall security acceptance criterion is meetable for the full
  collection / subcollection topology, not only flat-collection content.

### Architectural consequences

- **No new contrib dependency.** The MVP avoids `ggroup` (not D11-ready) and
  `subgroup` (D11 unconfirmed). The Group 3.x contrib bet remains revisitable
  later (Option E retains design value) without blocking MVP.
- **Inheritance logic is explicit, in this repo, and testable.** All
  behavioural divergence from off-the-shelf Group access lives in one module
  rather than being implicit in a contrib module's plugin internals.
- **One-level-only is enforced structurally** by the entity-reference target
  bundle restriction. Deeper nesting is not blocked by this ADR but is
  out of scope; revisiting it would require schema changes and a successor
  ADR.

### What this ADR does not decide

- The custom module's machine name, internal API surface, or test framework
  choice — those are implementation details for the 1b.2 work.
- The visibility-flag UI affordance (whether to expose `visibility_overridden`
  as an editable toggle in the subcollection form or to manage it
  automatically only) — recommended automatic management, but a UI affordance
  may be added without superseding this ADR.
- Performance characteristics under large parent-membership cascades. With
  production at 55/116 and small membership counts, this is not a near-term
  concern; revisit if it becomes one.

### Documentation effects

- Closes the [Collections Inheritance Decision Brief](../planning/collections-inheritance-decision-brief.md).
- Supersedes the "Recommended" standing of the two deferred notes
  (`group-subgroup-nesting-approach.md`, `group-access-inheritance-subcollections.md`);
  they remain as historical context for the investigation but the open
  recommendation is now this ADR.
- [Spike 3](../spikes/spike-03-group-collections.md) transitions from ◐ Partial
  to ● Proven for MVP purposes — its open question (the inheritance approach)
  is now resolved here.
