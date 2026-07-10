# Spike 4b: CKEditor 5 Footnotes
**Status:** Pending
**Lead:** Than Grove (built D7 shanti_texts and footnotes)
**Mode:** Individual
**Date:** —
**Branch/commit:** —

**Split from [Spike 4](spike-04-ckeditor5-footnotes.md) on 2026-07-10** — team-ratified.
See that file for the original combined scope and why it was split.

## Theory
Existing Texts site CKEditor 4 footnote markup (`shanti_footnotes`) can be
reliably transformed to CKEditor 5's footnote markup format without data loss.

## Open dependency (unresolved as of the split)
Depending on the **AV transcript format** (plain text vs. structured /
time-coded / rich markup), this spike and the AV transcript work may turn out
to be the same underlying proof ("structured Tibetan rich-text round-trip") —
see [docs/roadmap.md](../roadmap.md#open-question-resolve-before-phase-3-scoping).
That determination is still open and independent of the Unicode/CKEditor split
that created this file — it does not need to block starting this spike, but
the findings here may end up folded into a merged spike with AV transcript
work later.

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
- Edge cases are documented and accounted for

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| CKEditor 4 markup is inconsistent across the corpus | Plan a content cleanup pass in D7 before migration |
| `footnotes 4.x` uses a fundamentally different storage model | Evaluate alternative footnote modules for D11 |
| Complex footnote patterns cannot be transformed deterministically | Manual content review required; scope the cleanup effort |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4b--ckeditor-5-footnotes)*
