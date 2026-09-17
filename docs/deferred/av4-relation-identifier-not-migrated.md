# AV4: `field_relation_identifier` never migrated — empty across all 836 `av_pbcore_relation` paragraphs

**Area:** migration / AV / data fidelity
**Raised during:** Session 2026-09-17, building AV15's technical-metadata accordion
**Jira:** (add when available)
**Priority:** Medium — no user-facing feature currently reads this field (AV15's Details panel is the first display surface to touch it at all), but it's real data loss versus D7, and AV3's own plan already flagged the risk
**Status:** RESOLVED, same day (2026-09-17 afternoon) — see Resolution below

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

## Resolution (2026-09-17 afternoon)

Confirmed the exact mechanism before writing anything: `d7_av_pbcore_relation`'s
migration YAML already had a `migration_lookup` process for this field,
targeting `d7_av_audio`/`d7_av_video` — so this wasn't a missing process, it
was a **migration-ordering chicken-and-egg**. Relation paragraphs must exist
before the host `audio`/`video` node migrations that reference them, so
`d7_av_pbcore_relation` necessarily runs *before* `d7_av_audio`/`d7_av_video`
— meaning every `migration_lookup` call happens while its target migrations'
id maps are still empty. Not a partial-failure case; **100% of lookups were
guaranteed to return NULL**, which matches the observed zero rows exactly.

Built the second pass exactly as recommended: `drush av:backfill-relation-identifier`
(new command in `mandala_migrations`, `AvRelationIdentifierBackfillCommands.php`).
Reads D7's `field_data_field_relation_identifier` directly (cross-database
join against the `migrate_av` connection's schema — both connections share
one MySQL instance, so `{db}.{table}` works without any data export step),
resolves each row's item id through `migrate_map_d7_av_pbcore_relation`'s own
id map to a D11 paragraph id, resolves the D7 target nid through
`migrate_map_d7_av_audio`/`migrate_map_d7_av_video`'s id maps to a D11 nid,
and sets `field_relation_identifier` directly via the entity API. Confirmed
before running that a plain `$paragraph->save()` (no `setNewRevision(TRUE)`)
updates the paragraph's *current* revision in place — verified against a real
row that the host node's `field_pbcore_relation_target_revision_id` is
unchanged after the save, so no revision drift was introduced.

**Result:** 6,114 of 6,116 D7 source rows backfilled cleanly (`--dry-run`
first, matched exactly). The 2 unresolved are pre-existing D7 data quality,
not migration bugs — one field_collection item outside the migrated
audio/video host scope, one target nid (45368) that doesn't exist in D7's own
`node` table at all (a dangling reference already broken in the source).
Neither is worth chasing further. Verified live in DDEV against this doc's
own reference node (D11 nid 116965, `/audio/tulku-urgyen-bardo-teaching-1`):
the Details panel's "Relation identifier" rows now resolve to real node
titles ("Tulku Urgyen: Bardo Teaching 2" through "10"), matching D7's 9 items
exactly.

**Checked recommendation #2 (other affected self-referencing fields):**
confirmed `field_relation_identifier` was the only true D7 `entityreference`
field among AV's paragraph fields. The other D7 `entityreference`-type fields
(`field_og_collection_ref`, `field_og_parent_collection_ref`, `og_user_node`,
`og_group_ref`) are Organic Groups fields on the group-membership migration
path, already correctly populated (125,455 `group_relationship` rows) —
different migration, not affected by this ordering problem.

## Related

[[av-paragraph-model.md]] (AV3 — the original risk flag), [[project-sprint-3-av-decisions]] memory (AV15 build history), `docs/sprints/sprint-03-av-core-implementation.md` AV15 row (where this was discovered).
