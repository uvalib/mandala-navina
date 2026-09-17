# AV4: `field_relation_identifier` never migrated — empty across all 836 `av_pbcore_relation` paragraphs

**Area:** migration / AV / data fidelity
**Raised during:** Session 2026-09-17, building AV15's technical-metadata accordion
**Jira:** (add when available)
**Priority:** Medium — no user-facing feature currently reads this field (AV15's Details panel is the first display surface to touch it at all), but it's real data loss versus D7, and AV3's own plan already flagged the risk

## What happened

Building AV15's "Details" panel (`field_pbcore_coverage` + `field_pbcore_relation`), a real AV node (D11 nid 116965, legacy nid 24621) rendered nine `av_pbcore_relation` paragraph instances with no visible content at all. Traced two layers of cause, not one:

1. A real rendering bug (fixed same session, PR #220): `field_relation_identifier` is a plain `entity_reference` — unlike every other paragraph-to-paragraph link AV15 walks, which is `entity_reference_revisions` — and the generic renderer only special-cased the latter, silently producing nothing for the former instead of erroring.
2. Fixing that bug changed nothing for this specific node, because the underlying data is genuinely empty. Confirmed directly against both databases:
   - **D11**: `paragraph__field_relation_identifier` has **zero rows** — not just empty for this node, empty for all 836 nodes carrying `field_pbcore_relation` paragraphs.
   - **D7** (`d7_av`, legacy nid 24621): all nine of this node's `field_pbcore_relation` field_collection items have a real, populated `field_relation_identifier_target_id` (e.g. `24626`, `24631`, `24636`, …) — pointing at other D7 AV nids, i.e. genuine "Is Part Of"/"Related Media" cross-references, confirmed live on `av.mandala.library.virginia.edu`'s own "Details" accordion under "RELATED MEDIA."

So this is not a display bug and not a sparse-data coincidence — every `field_relation_identifier` value that existed in D7 was dropped during AV4's migration.

## Why

**This was already flagged as a risk, not a surprise.** AV3's own paragraph-model doc says explicitly:

> `field_relation_identifier` has no bundle restriction yet... It is also an intra-AV node reference, so AV4 needs a second pass or stub-and-backfill to resolve it.

The field is a **self-reference within the AV corpus being migrated in the same pass** — a chicken-and-egg problem: at the point AV4 processes a given relation paragraph, the *target* node it needs to point at may not have a D11 nid yet (it hasn't been migrated itself, or migrate's own ID map hasn't caught up). AV4 evidently shipped without the second pass/backfill AV3 called for, and nothing since has caught it because nothing displayed the field until AV15.

## Recommendation

1. **A backfill migration pass, not a fresh migration.** All 836 source rows still exist in `d7_av`'s `field_data_field_relation_identifier` table with real `target_id` values pointing at other D7 AV nids. Since every AV node has `field_legacy_nid` recorded, resolving D7 nid → D11 nid is a straight lookup, not a re-derivation — the fix is a small standalone script/migration that reads the D7 field_collection data, maps both ends through `field_legacy_nid`, and sets `field_relation_identifier` on the already-migrated `av_pbcore_relation` paragraphs. Genuinely a **second pass**, exactly as AV3 anticipated, not a fresh AV4 run.
2. **Check whether this is the only affected self-referencing field.** `field_relation_identifier` was the only D7 `entityreference` field flagged in AV3's type-mapping table, so it's likely the only instance of this exact problem — but worth a quick grep of AV3's field list for any other `entity_reference` (not `_revisions`) field before closing this out, in case something else has the same gap silently.
3. **Not urgent to fix before other AV work.** No shipped feature (AV6, AV9, AV13, or AV15's other panels) depends on this field being populated — AV15 itself degrades gracefully (the paragraph renders, just without a relation row). Pick it up whenever "Related Media" cross-references become a real requirement, or bundle it with any other AV4 data-fidelity cleanup.

## Related

[[av-paragraph-model.md]] (AV3 — the original risk flag), [[project-sprint-3-av-decisions]] memory (AV15 build history), `docs/sprints/sprint-03-av-core-implementation.md` AV15 row (where this was discovered).
