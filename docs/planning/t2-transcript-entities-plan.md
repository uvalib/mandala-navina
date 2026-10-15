# T2 plan: transcript entities (`av_transcript`, `av_tcu`)

**Status:** Proposed 2026-10-15, not started. Sprint 4 task T2; the entity decisions below come
from [Spike 11](../spikes/spike-11-av-transcript-replication.md) and are not reopened here.
**Depends on:** T1 (done) and Sprint 3 AV2/AV3 (Sprint 3 closed 2026-10-01).
**Unblocks:** T3 viewer, T4 index, T5 migration, T6 editor, T7 revert, and the `trid_i` resolver
swap from [#290](https://github.com/uvalib/mandala-navina/issues/290).

## Goal

`av_transcript` and `av_tcu` exist as Drupal entities with access, revisions, id allocation and
a stable save/delete hook surface. Nothing is migrated and no viewer exists yet.

## Already decided (Spike 11)

- **Entity kind:** both are non-fieldable custom content entities (base fields only), both
  revisionable. `av_tcu` has per-unit revisions; `av_transcript` revisions are metadata only.
- **Ids:** `av_transcript` id is D7's `trid`; `av_tcu` id is D7's `tcuid` (so `#tcu/NNN` links
  keep working). The migration sets them explicitly. New ids are allocated above the D7 maxima:
  27,016 for transcripts, 1,394,104 for units.
- **Timecodes:** `DECIMAL(10,3)`. Bad values are carried as found, with a `timecode_status`
  and `end_original` whenever a fix is applied.
- **Tier and speaker maps:** JSON columns on the unit (17 tier names; 245,158 units).
- **Cardinality:** one transcript per node, a node may have none; unique, nullable node
  reference.
- **Access:** follows the parent node (AV7 rules); history follows edit permissions; TCUs are
  never exposed on their own. Fail closed for an orphaned transcript.
- **Reads:** a service does one indexed range read per transcript; the entity layer handles
  writes, access and hooks.

## Phases (one PR each)

| # | PR | Estimate | Notes |
|---|---|---|---|
| 1 | `av_transcript` entity (new module `mandala_av_transcripts`) | 2-4 h | First custom content entity in the repo; sets the pattern. Node reference (unique), source file, source format, tier list, `has_edits`, `edits_flagged_at`, workflow fields (open question 1), revision log. Id allocation above 27,016. |
| 2 | `av_tcu` entity | 2-3 h | Transcript reference, sequence, `start`, `end`, `end_original`, `timecode_status`, tier map, speaker map. Per-unit revisions. Indexes on (transcript, sequence) and (transcript, start). Id allocation above 1,394,104. A 245k-row insert check. |
| 3 | Access handler and tests | 3-5 h | Delegates to the parent node. Kernel tests: private node, node in a collection the user is not in, anonymous, orphaned transcript. Security-critical. |
| 4 | Read service | 1-2 h | One range read per transcript; tested on the largest (1,380 units). |
| 5 | Hook surface and kmassets reindex subscriber | 2-4 h | Events on unit save/delete and whole-transcript operations. T4 subscribes for Solr. One subscriber reindexes the parent node's kmassets document so `trid_i` stays current; it must not loop. |
| 6 | Timecode status classifier | 2-3 h | Pure function of start, end and neighbours: `ok`, `zero_length_shared_start`, `zero_length_other`, `inverted`, `last_unit_no_end`, `end_implausible`. Fixtures from the 4,269 bad rows. |
| 7 | Resolver swap (the #290 follow-up) | 1-2 h | Entity-backed `TridResolverInterface`; repoint `mandala_kmassets_sync.trid_resolver`; remove the interim `mandala_kmassets_trid_map` table, update hook 10001 and `kmassets:load-trid-map`. **Gated on T5**: removing the table before the entities are populated would drop `trid_i`. |

Phases 1-6: about 12-21 h of working time. With phase 7: 13-23 h. These are rough estimates for
a driver with review alongside; they exclude review turnaround and dev-0 verification (about 30
minutes per merged PR). Least certain: phases 1 and 3. Phases 1 and 2 are independent but both
feed 3.

## Tests

- Unit: the classifier, id allocation.
- Kernel: access, revisions (a save writes one revision row; a deleted unit keeps its history),
  the unique node reference.
- Load: insert 245k units in batches; confirm the range-read time.

## Open questions

1. **Workflow fields.** D7 has `field_transcript_input`, `_proofed`, `_timecoded` (2,482 rows
   each) and `_workflow_notes` (565). Base fields on `av_transcript`, or do they stay on the
   node? The spike says only "workflow flags". Recommended: on `av_transcript`.
2. **Existence rule.** 41 nodes have a transcript file but no tracking row, and 3 have none at
   all. Do they get an empty `av_transcript`?
3. **Module home.** New `mandala_av_transcripts` (recommended), or inside `mandala_av_metadata`.
4. **Sequencing.** Ship phases 1-6 as T2 and do the resolver swap together with T5's cutover
   (recommended).
5. **Retry queue** for failed index writes belongs to T4, not T2.

## Risks

- If the entity pattern needs a rethink after phase 1 (for example, the migrate destination does
  not fit), phases 2-5 are reworked. Mitigation: check phase 1 against a throwaway migrate
  destination before starting phase 2.
- If question 1 changes after phase 1 ships, it becomes an update hook plus a data move.
- Kernel tests with Group-based access have been slow elsewhere in this repo.
