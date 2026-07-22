# Spike 5: bibcite for the Sources Site
**Status:** Pending
**Lead:** Than Grove (reassigned from Xiaoming, 2026-07-22, by mutual agreement)
**Mode:** Individual
**Date:** —
**Commit:** —

## Theory
The `bibcite` module on Drupal 11 supports all reference types currently in use on
the Sources site, handles the `zotero_feed` workflow, and provides a viable migration
path from D7 `biblio`.

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
- `bibcite` has a stable or beta D11 release
- All reference types in current use have `bibcite` equivalents
- Required citation styles are available
- A viable Zotero feed management approach exists in bibcite
- Zotero API import works with current credentials
- Citation output is comparable to current D7 output

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| `bibcite` has no D11 release | Evaluate alternatives (custom entity type, Scholarly Communications module) |
| A critical reference type is missing | Assess whether a custom bundle can fill the gap |
| No equivalent for `zotero_feed` workflow | Design a custom config entity or Feeds-based approach |
| Zotero API credentials are expired | Obtain current credentials from Sources site admin |
| Citation output differs significantly | Identify correct CSL style; document differences for stakeholder review |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-5)*
