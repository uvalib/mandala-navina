# Spike 3: Group Module Collections Architecture
**Status:** ● Proven (closed 2026-06-18) — structural model proven in this spike; the inheritance design question is resolved by [ADR 011](../adr/011-group-collections-inheritance.md) (Option D: entity-reference + custom hooks)
**Lead:** Than Grove
**Mode:** Team spike (candidate)
**Date:** 2026-06
**Branch/commit:** main (DDEV local, no committed code — proof-of-concept via Drush PHP eval)

---

## Theory
The Group module (3.3.5, D11) can model Mandala's collection and subcollection hierarchy — with one level of nesting only — with appropriate access control, replacing the D7 Organic Groups implementation.

---

## Demo

Run against the local DDEV environment:

```bash
ddev start
ddev drush site:install
ddev drush en group gnode -y
```

Then from Drush PHP eval, the spike exercised:

1. Created `collection` and `subcollection` group types
2. Registered `group_node:page` as eligible group content on both types
3. Added a `parent_collection` entity reference field on the subcollection group type, targeting only `collection` bundles
4. Created "Buddhist Texts Collection" (collection) and "Tibetan Sutras" (subcollection) groups
5. Created a test Page node and added it to both groups via `addRelationship()`
6. Attempted to create a sub-subcollection referencing a subcollection as parent — validation rejected it
7. Configured group roles: anonymous outsider, authenticated outsider, member, admin
8. Tested node access for anonymous, member, and non-member accounts under both restricted and public configurations

All tests passed after `ddev drush cr` (Group module permission results are heavily cached; full cache rebuild is required between permission changes in testing).

---

## Findings

### 1. Group 3.3.5 is already stable — no breaking API changes expected
Group 3.3.5 is the current stable release. There is no pending "1.0" that would introduce breaking changes. The version represents an already-completed naming migration from 2.x (GroupRelationship entities, updated plugin IDs). No upgrade blocker.

### 2. No built-in group-in-group plugin
Group 3.3.5 ships only three relation plugins: `group_membership`, `group_node:article`, and `group_node:page` (with additional `group_node:*` variants per registered node type). There is no built-in "subgroup" or "group in group" plugin.

**Two external modules exist but are mutually incompatible:**
- [`ggroup`](https://www.drupal.org/project/ggroup) — graph-based, standalone
- [`subgroup`](https://www.drupal.org/project/subgroup) — via group type config

**Workable alternative proven in this spike:** Add a `parent_collection` entity reference field on the `subcollection` group type, with `target_bundles` restricted to `['collection']`. This enforces the one-level constraint through the type system — attempting to reference a subcollection as parent fails Drupal entity validation (`This entity cannot be referenced`).

### 3. One-level constraint is enforced automatically
Because the `parent_collection` field only accepts `collection` bundle references, a sub-subcollection (subcollection pointing to another subcollection) is rejected at validation. No custom hook needed. The type system does the work.

### 4. Content membership in groups works correctly
`Group::addRelationship($node, 'group_node:page')` works. A node can belong to both a collection and a subcollection simultaneously. Group relationships are stored as `group_relationship` entities.

### 5. Group roles provide sufficient granularity
Successfully created and verified four scopes:
- **Anonymous outsider** (`scope=outsider`, `global_role=anonymous`) — controls public vs. restricted
- **Authenticated outsider** (`scope=outsider`, `global_role=authenticated`) — controls logged-in non-members
- **Member** (`scope=individual`) — controls what members can do
- **Admin** (`scope=individual`) — controls group management

### 6. Node-level access control works, but has known issues
Node access in Group 3.x is controlled via `hook_entity_access` (not `hook_node_access_records`), using the `group_node` plugin's access handler. After granting `view group_node:page entity` to a role:

| Scenario | Anonymous | Member | Authenticated Non-member |
|---|---|---|---|
| Public collection | YES | YES | NO |
| Restricted collection | NO | YES | NO |

**Known open issues in the Group issue queue (not blocking for our patterns, but must be monitored):**
- [#3162511](https://www.drupal.org/project/group/issues/3162511): `group_entity_access()` can return `forbidden()` that interferes with other access hooks
- [#2955656](https://www.drupal.org/project/group/issues/2955656): Edge case where anonymous users can view grouped nodes when access should be restricted
- [#3134058](https://www.drupal.org/project/group/issues/3134058): Node revision access control insufficient
- [#3263349](https://www.drupal.org/project/group/issues/3263349): Grouped nodes appearing in listings despite restrictions

### 7. Access is NOT inherited from collection to subcollection
This is the most significant architectural implication. Because subcollections are independent Group entities connected only by a reference field (not by a true group-in-group relationship), a node's access is determined by the groups it directly belongs to. A restricted collection does not automatically restrict content in its subcollections.

**Implication:** If a subcollection's content should inherit the parent collection's access rules, that must be implemented explicitly — either by always adding the node to both groups, by synchronizing permissions across the pair at save time, or by using `ggroup`/`subgroup` modules.

---

## What this does NOT establish

- Whether `ggroup` or `subgroup` module is production-ready and compatible with Group 3.3.x — not evaluated
- Whether the known access control issues (#3162511, etc.) affect Mandala's specific content types and query patterns — needs targeted testing in Phase 3
- Whether `ggroup`/`subgroup` would be preferable to the entity-reference approach for the Mandala architecture
- Whether access inheritance across collection → subcollection is required by Mandala's access patterns, or whether the simpler entity-reference model is sufficient
- D11 compatibility — this spike ran on Drupal 10.6.10 (current state of the repo). Group 3.3.5 is compatible with both D10 and D11; no issues expected, but confirmation should happen when the core version is bumped.

---

## Deferred notes

- [`docs/deferred/group-subgroup-nesting-approach.md`](../deferred/group-subgroup-nesting-approach.md) — Decision needed: entity reference vs. ggroup/subgroup module for collection nesting; impacts access inheritance model
- [`docs/deferred/group-access-inheritance-subcollections.md`](../deferred/group-access-inheritance-subcollections.md) — Access rules are not inherited collection → subcollection in the reference field model; need to decide how to handle this for restricted collections

---

## Reference: Pass Criteria Results

| Criterion | Result |
|---|---|
| Collection/subcollection nesting works as expected | PASS — via entity reference field |
| One-level-only constraint is enforceable | PASS — enforced by bundle targeting on reference field |
| Content can be added to groups and inherits group access rules | PASS — direct group access works; see deferred note on inheritance |
| Group roles provide sufficient granularity to model current access patterns | PASS — four scopes available and working |
| No critical open issues in Group module issue queue affecting the collections model | PARTIAL — four known access issues; not blocking but require monitoring |

**Overall: PARTIAL PASS.** The core collection model works. One architectural decision remains open: whether to use the entity reference approach (proven here, simpler, no access inheritance) or a subgroup module (unproven, adds inheritance but adds a dependency). This decision gates Phase 3 design.

---

## Closeout (2026-06-18)

The open architectural decision flagged above is resolved by
[**ADR 011 — Group collections inheritance**](../adr/011-group-collections-inheritance.md)
(Option D: entity-reference + custom hooks). Both inheritance requirements
(visibility, membership) are implemented by a custom inheritance module that
hooks Group's existing lifecycle events; no contrib subgroup dependency is
adopted for MVP. Inheritance lands in **Sprint 1 Step 1b (task 1b.2)** rather
than Phase 3 — see the ADR for the sequencing rationale and the
edge cases (`visibility_overridden` flag, sub-only member retention) the
implementation must handle. With that decision recorded, this spike is closed
**Proven** for MVP purposes.

---

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-3)*
