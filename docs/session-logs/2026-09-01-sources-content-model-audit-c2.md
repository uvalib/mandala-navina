# Session Log: Sources content-model audit (Sprint 2 Workstream C2)

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** `docs/planning/sources-content-model-audit.md` written and committed, validated against a real production dump. Found one significant migration-shape bug hiding in the D7 data.

---

## 1. Why C2 next

Started while Than re-generates a fresh AV production dump (the copy used for C1 was
found corrupted — see the prior 2026-09-01 log). C2 has no dependency on C1's data
availability and Xiaoming already had a head start from Spike 5's bibcite type-mapping
research, so it was a natural pick to keep the live session moving.

## 2. Dump integrity — good news this time

Checked `~/Sandbox/Mandala/data/mandala-prod-sources-db_2026-06-11.sql.gz` (38.6MB)
with `gzip -t` before relying on it, per the lesson from the AV corruption incident.
**Passed.** Loaded into DDEV's `d7_sources` database — quick, unlike AV's failed 1.7GB
attempt. Left the database loaded afterward (matches the existing convention of keeping
`d7_images`/`d7_texts` around for ongoing dev work, rather than treating it as scratch).

## 3. Key structural finding: `biblio` is a hybrid, not a Field-API content type

Unlike Images (referenced-node satellites) and AV (field_collection satellites),
`biblio` predates Drupal's Field API for its core ~50 columns — they're one flat legacy
table (`{biblio}`), with a junction-table system (`biblio_field_type`) that governs
per-type field visibility/required-ness, not schema. All 36 publication types share the
identical column set. This closes Spike 5's explicitly-left-open "field-level
compatibility" question: there's no differing-field-sets problem to solve against
bibcite's shipped types, only a labels/required-ness mapping.

A real Field-API layer is also attached to `biblio` (KMaps taxonomy fields, Zotero
fields, OG access) — so `biblio`'s D11 migration needs two different source-plugin
strategies for one content type, a bigger remodeling question than either sibling audit
faced.

## 4. The important bug: collection membership isn't where it looks like it should be

While profiling real data, `field_data_field_og_collection_ref` (the Field-API table
for Sources' collection-membership field) came back **empty — 0 rows** — for both
`biblio` and `asset_link`, despite the field being genuinely `field_sql_storage`-backed
in `field_config`. Chased it down: the actual membership data lives entirely in the
**`og_membership`** table (26,762 rows), keyed by a `field_name` label that matches the
field's machine name but isn't mirrored into that field's own value table. 21,104 of
25,627 biblio nodes (82.3%) and 2,808 of 2,862 asset_link nodes (98.1%) have real
memberships recorded there.

**This would have silently broken a migration** if it read the Field API value table
(the natural first approach, and what the static-code-only version of this audit would
have assumed) — it would see zero collection memberships for Sources and nobody would
notice until content appeared un-collected in D11. Flagged as the audit's headline
finding, in both the doc and a dedicated line in "What this audit establishes."

## 5. Other real-data findings

- Legacy `biblio_collection`/`biblio_collection_type` tables (a second, biblio-native
  collection system) are confirmed **empty (0 rows)** — dead code, safe to drop from
  migration scope.
- All 4 custom biblio types from Spike 5 confirmed as real, populated types (Review 599,
  Dictionary 375, Block Print 140, Obituary 63).
- Zotero collections (Spike 5's "~6.2k collection rows") are stored in the
  `field_zotero_collections` taxonomy-reference field, **not** the contrib module's own
  `biblio_zotero_collections` mapping table (also empty) — a small correction to how
  that figure should be understood.
- Author-identity dedup mechanism (`aka`/`alt_form`) exists in schema but is used on only
  5 of 36,151 contributor records — effectively dead in practice, de-risking a future
  "drop the dedup graph" decision.
- KMaps subject tagging covers only 20.9% of biblio nodes (5,358/25,627) — much lower
  coverage than Images/AV, worth noting as a real difference in how thoroughly Sources
  content was classified.

Full numbers (identifier fill rates, access-field distribution, etc.) in the doc's Data
profile section.

## 6. Doc status

Explicit throughout about which claims are code-derived vs. data-verified — unlike the
AV audit, this one has a complete, real data-profile section since the dump was intact.
Sprint 2 backlog row C2 marked ☑.

## Next-session starting point

- C3 (Texts, Than) is unblocked and can proceed the same way.
- Once Than's fresh AV dump lands and passes `gzip -t`, re-run C1's data-profile
  section — worth specifically checking whether AV has an `og_membership`-vs-field-storage
  gap analogous to what was just found on Sources, since both sites share the same OG
  collection pattern.
- Workstream B (Images interactive UI) remains the largest unstarted Sprint 2 piece.
