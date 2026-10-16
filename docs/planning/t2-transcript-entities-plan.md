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
  keep working). The migration sets them explicitly. New ids are allocated well above the D7 maxima
  (27,016 for transcripts, 1,394,104 for units) because legacy D7 keeps minting ids until cutover:
  transcripts start at 100,000 (done in phase 1, PR #343); units will start at 2,000,000.
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
| 1 | `av_transcript` entity (new module `mandala_av_transcripts`) | 2-4 h | First custom content entity in the repo; sets the pattern. Node reference (unique), source file, source format, tier list, `has_edits`, `edits_flagged_at`, workflow fields (open question 1), revision log. Id allocation starts at 100,000. **Done: PR #343, merged 2026-10-15** (workflow fields left out pending open question 1). |
| 2 | `av_tcu` entity | 2-3 h | Transcript reference, sequence, `start`, `end`, `end_original`, `timecode_status`, tier map, speaker map. Per-unit revisions. Indexes on (transcript, sequence) and (transcript, start). Id allocation above 1,394,104. A 245k-row insert check. **Done: PR pending (branch `feat/t2-2-av-tcu-entity`)**; ids start at 2,000,000, installed on existing sites by `update_10001`. Open: Drupal's delete removes a unit's revision rows, so "a deleted unit keeps its history" (Tests section) needs a decision (soft-delete flag or archive) before T7. |
| 3 | Access handler and tests | 3-5 h | Delegates to the parent node. Kernel tests: private node, node in a collection the user is not in, anonymous, orphaned transcript. Security-critical. **Done: PR pending (branch `feat/t2-3-transcript-access`).** View = node view; every other operation = node update (unknown ops fail closed); orphans denied; create = administer permission only until T6. Kernel tests run on the real AV7 fixture. |
| 4 | Read service | 1-2 h | One range read per transcript; tested on the largest (1,380 units). **Done: PR pending (branch `feat/t2-4-transcript-read-service`, stacked on phase 3).** `mandala_av_transcripts.reader` (`read`, `readForNode`, `readEntity`): access-checked, a denial looks like "no such transcript", one query on av_tcu (1,380 units tested), optional start window, cache tags incl. `av_transcript_units:{trid}` which unit save/delete invalidate. |
| 5 | Hook surface and kmassets reindex subscriber | 2-4 h | Events on unit save/delete and whole-transcript operations. T4 subscribes for Solr. One subscriber reindexes the parent node's kmassets document so `trid_i` stays current; it must not loop. **Done: PR #349 (stacked on phase 4).** `TranscriptChangeNotifier` + five events (`TranscriptEvents`); `suppressed(callable)` coalesces a bulk operation to one `UNITS_REPLACED` and one cache invalidation per transcript; a throwing subscriber is caught and logged, because `postSave` runs inside the storage transaction and would otherwise roll the save back. `TranscriptReindexSubscriber` in `mandala_kmassets_sync` refreshes the node's document on transcript create/move/delete (not on unit events). |
| 6 | Timecode status classifier | 2-3 h | Pure function of start, end and neighbours: `ok`, `zero_length_shared_start`, `zero_length_other`, `inverted`, `last_unit_no_end`, `end_implausible`. Fixtures from the 4,269 bad rows. **Done: PR #348 (independent of the others).** `TimecodeClassifier`, a pure function of start, end and next start, in whole milliseconds. Checked against an independent SQL version over all 245,158 D7 units (0 mismatches): ok 240,833; zero_length_shared_start 3,111; inverted 904; zero_length_other 188; last_unit_no_end 66; end_implausible 56. |
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

## For review Monday 2026-10-19

Nothing below blocked phases 2-6. Items marked **T5** must be settled before the migration runs.

### Decisions needed

1. **Repair policy for bad timecodes (T5; needs Than or AV staff).** The classifier only labels;
   it never changes a value. Whether the migration repairs anything, and what `end_original`
   then holds, is open. Counts by class (D7, all 245,158 units): `inverted` 904, of which 137 have
   `end = 0` (D7's "unknown"; 71 mid-transcript, 66 last units, across 75 transcripts);
   `last_unit_no_end` 66; `zero_length_other` 188; `end_implausible` 56 (54 overrun the next
   unit by over 10 minutes, 2 are last units over 4 hours). Candidate repairs: a missing end
   becomes the next unit's start; an implausible end is clamped to it; zero-length units stay
   (they may be deliberate point cues, per the Spike 11 audit). Carrying everything as found, with
   the status only, is the zero-risk default.
2. **Untimed units.** 3,039 units have `start = end = 0`, in 163 transcripts (62 entirely untimed,
   101 partly). They classify `zero_length_shared_start`, which is accurate but not the whole
   story: they are untimed rather than zero-length. Should the viewer treat them as having no
   time (no playhead sync) instead of a cue at 0:00?
3. **The one negative start** (tcu 142269, trid 2371, start -0.125, first unit) has no status of
   its own and classifies `ok`. Add a status, clamp to 0 with `end_original`-style bookkeeping, or
   leave.
4. **Deleting a transcript.** Its units are not deleted with it, and an orphaned unit is denied to
   everyone but administrators. Cascade the delete (and keep history, see 5), or forbid deleting a
   transcript that has units?
5. **Deleted units and history.** Drupal's delete removes a unit's revision rows, so "a deleted
   unit keeps its history" needs a soft-delete flag or an archive table before T7 (revert).
6. Open questions 1-4 above (workflow fields, existence rule, module home, sequencing). The
   module home is already `mandala_av_transcripts` as recommended; confirm or move.

### Notes for T5 (migration)

- **Round before classifying.** D7 stores single-precision floats; the stored value is
  DECIMAL(10,3). Two units are inverted by 0.001 s only, and a float compare would decide on
  noise. The classifier works in whole milliseconds, so call it on the rounded values.
- **Wrap the migration in `TranscriptChangeNotifier::suppressed()`** so 245,158 unit saves cost one
  event and one cache invalidation per transcript, not one each.
- **Transcript-level events are not coalesced.** Creating 5,343 transcripts fires 5,343
  `TRANSCRIPT_SAVED` events and the kmassets reindex would write each node to Solr. Inside
  `drush migrate:import` the existing migrate suppressor covers it; a custom drush script or batch
  does not. Index with `kmassets:index-all` afterwards, or add transcript events to the same
  suppression.
- Ids: set D7's `trid` and `tcuid` explicitly; allocated ids start at 100,000 and 2,000,000.
- Both the classifier and its SQL check were written by the same author. The SQL is an independent
  expression of the rules, not an independent derivation; the thresholds (10 minutes, 4 hours)
  are calibrated to D7 and worth a look from someone who knows the recordings.

### Notes for later phases and other work

- **T4 (Solr).** Subscribe to `UNIT_SAVED`, `UNIT_DELETED`, `UNITS_REPLACED`, `TRANSCRIPT_*`. The
  notifier swallows and logs a subscriber's exception; the retry queue is T4's job (open question 5).
- **T6 (editor).** Create access is the `administer av transcripts` permission only; the editor
  create path has no node to ask yet. Update access follows node update, but whether an editor who
  can edit a node *inside a collection* gets there depends on Group's per-collection grants, which
  no test here covers (the update tests use a collection-less node).
- **Reader caching.** The read service's cache contexts include `user`, so a cached transcript is
  per-viewer. Cheap now; revisit if the viewer API gets heavy traffic (the access decision is the
  same for everyone with the same node access).
- **Phase 7 (resolver swap) is gated on T5**; until then the kmassets reindex rebuilds `trid_i`
  from the legacy table, so the plumbing is live but document content does not change.
- **Load numbers are DDEV-only** (245,158 inserts 260 s; range read 1.5 ms); re-measure on dev-0
  after T5.

### Fixed along the way

- The shared `CollectionVisibilityKernelTestBase` made `outsider` uid 1, a superuser, so it passed
  every access check. It now reserves uid 1 for a placeholder and asserts no labelled account is
  it (in PR #346). The Oracle test now compares PHP and SQL for a real outsider.

## Risks

- If the entity pattern needs a rethink after phase 1 (for example, the migrate destination does
  not fit), phases 2-5 are reworked. Mitigation: check phase 1 against a throwaway migrate
  destination before starting phase 2.
- If question 1 changes after phase 1 ships, it becomes an update hook plus a data move.
- Kernel tests with Group-based access have been slow elsewhere in this repo.
