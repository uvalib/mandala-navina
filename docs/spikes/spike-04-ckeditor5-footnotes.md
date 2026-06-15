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

## Scope note (added 2026-06-15, roadmap planning)
This spike originally fused two distinct concerns that the [roadmap](../roadmap.md)
separates:

- **(a) Tibetan Unicode round-trip — cross-cutting, not Texts-only.** True Tibetan
  *script* lives in Texts bodies and AV transcripts; *Latin transliteration*
  (EWTS/Wylie + diacritics) is pervasive in metadata across sites (e.g., Images).
  The transliteration risk is **Unicode normalization fidelity** (NFC vs NFD)
  through Migrate API → MySQL collation → Solr. The Images pilot exercises the
  transliteration-normalization path; this spike covers the true-script path in
  Texts/AV. A green Images pilot does **not** retire the script risk.
- **(b) CKEditor 4→5 footnote markup transformation — Texts-specific.** The
  rich-text-body problem; genuinely independent of (a).

**Open question:** depending on the **AV transcript format** (plain text vs.
structured / time-coded / rich markup), the footnote work (b) and AV transcript
handling may be the *same* underlying proof ("structured Tibetan rich-text
round-trip"). If so, this spike should formally split/merge accordingly — a change
to be ratified by the team before the work starts.

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
- Latin transliteration (EWTS/Wylie) preserves diacritics at a consistent Unicode
  normalization form (NFC/NFD) through Migrate API → MySQL → Solr — no silent
  normalization drift
- Edge cases are documented and accounted for

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| CKEditor 4 markup is inconsistent across the corpus | Plan a content cleanup pass in D7 before migration |
| `footnotes 4.x` uses a fundamentally different storage model | Evaluate alternative footnote modules for D11 |
| Complex footnote patterns cannot be transformed deterministically | Manual content review required; scope the cleanup effort |
| Tibetan Unicode corrupted in D11 database | Verify utf8mb4 charset and collation settings |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4)*
