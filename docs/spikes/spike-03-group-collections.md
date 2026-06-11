# Spike 3: Group Module Collections Architecture
**Status:** Pending
**Lead:** Than Grove (designed D7 collections architecture)
**Mode:** Team spike (candidate)
**Date:** —
**Commit:** —

## Theory
The Group module (D11) can model Mandala's collection and subcollection hierarchy —
with one level of nesting only — with appropriate access control, replacing the D7
Organic Groups implementation.

## Demo
*To be completed when spike is run.*

## Findings
*To be completed when spike is run.*

## What this does NOT establish
*To be completed when spike is run.*

## Deferred notes
*To be completed when spike is run.*

---

## Reference: Pass Criteria
- Collection/subcollection nesting works as expected
- One-level-only constraint is enforceable
- Content can be added to groups and inherits group access rules
- Group roles provide sufficient granularity to model current access patterns
- No critical open issues in the Group module issue queue affecting the collections model

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| Subcollection nesting is broken or incomplete | Evaluate workarounds; consider flat collection model with parent field |
| One-level constraint cannot be enforced by Group module | Implement via custom validation hook |
| Group module access control insufficient | Investigate supplementary access control modules (e.g., `group_permissions`) |
| Breaking API changes expected before stable release | Pin to current version and plan upgrade sprint post-stable |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-3)*
