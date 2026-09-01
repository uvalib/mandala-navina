# Session Log: AV content-model audit — data profile completed on a fresh dump

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** `docs/planning/av-content-model-audit.md` now has a complete, real data-profile section. Confirms the same `og_membership`-vs-field-storage bug found on Sources also affects AV, plus resolves the corrupted-legacy-field open question with real counts.

---

## 1. Fresh dump, this time intact

Than re-exported the AV production dump while C2 (Sources) was underway:
`mandala-prod-av-db_2026-09-01.sql.gz` (186MB — much smaller than the corrupted 1.7GB
file from earlier, consistent with that one being a bad export rather than a
same-size-but-truncated copy). `gzip -t` passed. Loaded into DDEV's `d7_av` database
(replacing the earlier failed partial import) and profiled.

## 2. Confirms the Sources bug also affects AV

The headline finding from C2 — `field_data_field_og_collection_ref` is empty despite
being `field_sql_storage`-backed, with the real collection-membership data living in
`og_membership` instead — **also holds for AV**: 0 rows in the Field API table, 11,587
memberships in `og_membership` (essentially all 11,583 audio/video nodes) plus 85
subcollection→collection memberships matching the subcollection count exactly. This was
flagged as a prediction worth checking in the original AV audit draft ("worth
double-checking against the actual dump once loaded") — now confirmed. **The same
migration-source fix applies to both sites: read `og_membership`, not the Field API
value table.**

## 3. Clean numbers elsewhere

- 11,583 audio/video nodes total (7,396 video, 4,187 audio).
- Required fields are clean: `field_pbcore_title` 100%, `group_content_access` 100%,
  Kaltura entry-id fields >99.7% filled.
- `field_transcript` covers 46.4% of nodes — real, worth knowing before scoping any
  transcript-dependent UI work later.
- The historical corrupted-field columns (`field_extended_cataloging`,
  `field_translation_lang_1/2`) still hold 2,482 rows each in production, confirming
  they must be excluded from any future migration — this closes the open question the
  original code-only pass could only flag, not confirm.

## 4. One new anomaly found

68 nodes carry a literal, corrupted `MISSING_TYPE` value in the raw `node.type` column
— not a display artifact, confirmed by direct query. Not investigated further; filed as
a new open question in the audit doc (#8) rather than chased down mid-session, since it
wasn't part of what C1 was scoped to resolve.

## 5. Doc/tracking updates

- `docs/planning/av-content-model-audit.md` — data-profile section rewritten with real
  numbers; "establishes"/"does not establish" sections updated; open questions #6 and
  #7 marked resolved, #8 added.
- `docs/deferred/av-dump-corrupted-no-data-profile.md` — marked RESOLVED, with the
  resolution recorded and the original problem kept as history. One follow-up item
  (parameterizing `load-d7-source.sh`) left open.
- Sprint 2 backlog: C1's caveat removed, now a clean ☑ like C2.

## Next-session starting point

- C3 (Texts, Than) is the only audit left — same template, and now all three other
  site audits (Images, AV, Sources) have real data-profile validation to match. Worth
  checking Texts for the same `og_membership` pattern too, now that it's shown up
  twice.
- Workstream B (Images interactive UI) remains the largest unstarted Sprint 2 piece and
  has no dependency on C.
