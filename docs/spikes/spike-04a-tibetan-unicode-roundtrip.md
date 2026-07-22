# Spike 4a: Tibetan Unicode Round-Trip
**Status:** ● Proven
**Lead:** Than Grove
**Mode:** Individual
**Date:** 2026-07-22
**Branch/commit:** `spike/4a-tibetan-unicode-roundtrip`

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

All work done against local DDEV (ADR 012: MySQL 8.4, matching prod).

**1. Charset/collation check** — `SHOW VARIABLES LIKE 'character_set%'` /
`'collation%'` on the DDEV `db` connection: `character_set_database` =
`utf8mb4`, `character_set_connection` = `utf8mb4`, consistent throughout.
`collation_database` = `utf8mb4_unicode_520_ci`; `collation_connection` =
`utf8mb4_0900_ai_ci` (MySQL 8.4's session default). The two collations differ,
but this governs comparison/sort order only, not stored bytes — no corruption
risk from the mismatch, confirmed by (2) below.

**2. Fresh round-trip test** — created temporary `page` nodes via the entity
API with real Tibetan script in the body field, including a mantra with
stacked/subjoined conjuncts and vowel signs (`ཨོཾ་མ་ཎི་པདྨེ་ཧཱུྃ།`), a second
Tibetan phrase, and a mixed Latin-diacritic + Tibetan string. Saved each,
reloaded via (a) the entity API with static cache reset (forces a real DB
read) and (b) a raw `SELECT` against `node__body` (bypasses Drupal's entity
layer entirely), then compared byte-for-byte against the original and checked
Unicode normalization form. All three samples: **entity byte-match PASS, SQL
byte-match PASS, form preserved NFC→NFC.** Test nodes deleted immediately
after each check (script: not committed, ephemeral — see below).

**3. Already-migrated data audit** — swept all four Images KMaps fields'
`_header` and `_raw` columns (`field_subjects`, `field_places`,
`field_kmap_terms`, `field_kmap_collections`; 291,853 rows total) for every
non-ASCII value, checking `Normalizer::isNormalized()` against both NFC and
NFD forms:

| Field.column | total | ASCII-only | NFC | NFD | neither/mixed |
|---|---|---|---|---|---|
| field_subjects.header | 79,174 | 77,918 | 1,256 | 0 | 0 |
| field_subjects.raw | 79,174 | 73,067 | 6,107 | 0 | 0 |
| field_places.header | 68,790 | 63,745 | 5,045 | 0 | 0 |
| field_places.raw | 68,790 | 59,195 | 9,595 | 0 | 0 |
| field_kmap_terms.header | 55,553 | 40,493 | 15,060 | 0 | 0 |
| field_kmap_terms.raw | 55,553 | 40,493 | 15,060 | 0 | 0 |
| field_kmap_collections.header | 83,493 | 81,108 | 2,385 | 0 | 0 |
| field_kmap_collections.raw | 83,493 | 81,108 | 2,385 | 0 | 0 |

**100% of non-ASCII values are NFC; zero NFD; zero mixed/neither**, across
every field and both raw D7 (EWTS/Wylie) and rendered header forms.

**4. Solr leg** — checked whether the same fidelity holds through to the
live shared `kmassets` index using Spike 2's proven read-only path
(`mandala-solr-proxy.internal.lib.virginia.edu/solr/kmassets/select`). No
D11-era documents (`uid` matching `*-11-*`) exist in the index yet — Mandala
hasn't started publishing D11 Solr docs at this stage of the project, so this
leg is **not yet applicable**, not a finding. Re-check once D11 kmassets
publishing begins (1a.8's sync path, or whenever it actually fires against
the real index).

## Findings

- **Tibetan Unicode round-trips through the D11 database without
  corruption** — confirmed via both the entity API and a raw SQL read,
  byte-for-byte, including a script sample with stacked/subjoined conjuncts
  (the hardest case: Tibetan's real complexity isn't NFC/NFD combining-mark
  drift the way Latin diacritics can have, it's precomposed subjoined
  code points — and those round-tripped intact).
- **Already-migrated Latin transliteration (EWTS/Wylie) data is 100%
  normalization-consistent (NFC)** across all four KMaps fields, both raw
  and header columns, 291,853 rows checked. No drift found — nothing to fix.
- **DB/connection charset is `utf8mb4` throughout** (ADR 012 confirmed in
  practice); the database-vs-connection collation difference
  (`utf8mb4_unicode_520_ci` vs `utf8mb4_0900_ai_ci`) is a comparison/sort-order
  detail, not a storage-fidelity risk.
- The Migrate API → MySQL leg of the pipeline is clean. The MySQL → Solr leg
  can't be exercised yet because D11 content isn't being published to Solr
  at this stage of the project — not a gap in this spike, just out of scope
  until that milestone.

## What this does NOT establish

- Whether Tibetan script survives the MySQL → Solr leg specifically (Solr
  analysis chain, `search_api_solr` field mapping) — untestable until D11
  kmassets publishing starts; re-verify then.
- Whether CKEditor 5 (the actual Texts body editing surface) introduces its
  own normalization behavior on save/reload — this spike tested the entity
  API/SQL layer directly, not a CKEditor round-trip. Relevant to Spike 4b if
  it proceeds with a CKEditor-based approach.
- Font rendering / display correctness in the browser — this spike checked
  byte/codepoint fidelity in storage, not visual rendering.

## Deferred notes
None — no gaps found requiring a deferred item. The Solr-leg limitation above
is a scope/sequencing note, not a defect.

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
