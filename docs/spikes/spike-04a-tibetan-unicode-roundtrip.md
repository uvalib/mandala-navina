# Spike 4a: Tibetan Unicode Round-Trip
**Status:** Pending
**Lead:** Than Grove
**Mode:** Individual
**Date:** —
**Branch/commit:** —

**Split from [Spike 4](spike-04-ckeditor5-footnotes.md) on 2026-07-10** — team-ratified.
See that file for the original combined scope and why it was split.

## Theory
Unicode Tibetan script (Texts bodies, AV transcripts) and Latin transliteration
(EWTS/Wylie + diacritics, pervasive in metadata across sites including Images)
survive the full pipeline — Migrate API → MySQL collation → Solr — without
silent normalization drift (NFC vs NFD) or corruption.

## Why this is cross-cutting, not Texts-only
True Tibetan *script* lives in Texts bodies and AV transcripts. Latin
*transliteration* of Tibetan terms (EWTS/Wylie + diacritics) is pervasive in
metadata across sites that have **already been migrated** — e.g. Images KMaps
fields (`field_subjects`, `field_places`, `field_kmap_terms`,
`field_kmap_collections`; 111,343 `shanti_image` nodes migrated in 1a.7).
The transliteration-normalization path has been *exercised* by the Images
pilot but never explicitly *verified* — a green Images pilot does not by
itself retire this risk.

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
- Tibetan Unicode content round-trips through the D11 database without corruption
- Latin transliteration (EWTS/Wylie) preserves diacritics at a consistent
  Unicode normalization form through Migrate API → MySQL → Solr — no silent
  normalization drift
- Already-migrated Images data checked and confirmed (or fixed) for
  normalization consistency

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| Tibetan Unicode corrupted in D11 database | Verify utf8mb4 charset on database and connection; investigate collation settings |
| Normalization drift found between pipeline stages | Add explicit normalization step at the stage where drift occurs; document as a required step for all future migrations |
| Already-migrated Images data has drift | File a deferred/high-priority remediation item; assess Solr search-quality impact before deciding whether to reindex |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4a--tibetan-unicode-round-trip)*
