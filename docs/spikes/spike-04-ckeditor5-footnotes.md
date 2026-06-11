# Spike 4: CKEditor 5 Footnotes and Tibetan Unicode
**Status:** Pending
**Lead:** Than Grove (built D7 shanti_texts and footnotes)
**Mode:** Individual
**Date:** —
**Commit:** —

## Theory
Existing Texts site content — including CKEditor 4 footnote markup and Unicode
Tibetan script — can be reliably transformed and migrated to Drupal 11 without
data loss.

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
- Footnote markup format for both CKEditor 4 and 5 is fully documented
- A deterministic transformation function handles all patterns found in the sample
- Transformed content renders correctly in CKEditor 5
- Tibetan Unicode content round-trips through the D11 database without corruption
- Edge cases are documented and accounted for

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| CKEditor 4 markup is inconsistent across the corpus | Plan a content cleanup pass in D7 before migration |
| `footnotes 4.x` uses a fundamentally different storage model | Evaluate alternative footnote modules for D11 |
| Complex footnote patterns cannot be transformed deterministically | Manual content review required; scope the cleanup effort |
| Tibetan Unicode corrupted in D11 database | Verify utf8mb4 charset and collation settings |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4)*
