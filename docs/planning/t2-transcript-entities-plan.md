# T2 plan: transcript entities (`av_transcript`, `av_tcu`)

**Status:** Phases 1-6 built 2026-10-15/16 (phase 7 gated on T5); phases 3-6 merged to `integration/t2-transcripts` and awaiting review into `main`. Sprint 4 task T2; the entity decisions below come
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
| 2 | `av_tcu` entity | 2-3 h | Transcript reference, sequence, `start`, `end`, `end_original`, `timecode_status`, tier map, speaker map. Per-unit revisions. Indexes on (transcript, sequence) and (transcript, start). Id allocation above 1,394,104. A 245k-row insert check. **Done: PR #345, merged 2026-10-16**; ids start at 2,000,000, installed on existing sites by `update_10001`. Open: Drupal's delete removes a unit's revision rows, so "a deleted unit keeps its history" (Tests section) needs a decision (soft-delete flag or archive) before T7. |
| 3 | Access handler and tests | 3-5 h | Delegates to the parent node. Kernel tests: private node, node in a collection the user is not in, anonymous, orphaned transcript. Security-critical. **Done: PR #346.** View = node view; every other operation = node update (unknown ops fail closed); orphans denied; create = administer permission only until T6. Kernel tests run on the real AV7 fixture. |
| 4 | Read service | 1-2 h | One range read per transcript; tested on the largest (1,380 units). **Done: PR #347.** `mandala_av_transcripts.reader` (`read`, `readForNode`, `readEntity`): access-checked, a denial looks like "no such transcript", one query on av_tcu (1,380 units tested), optional start window, cache tags incl. `av_transcript_units:{trid}` which unit save/delete invalidate. |
| 5 | Hook surface and kmassets reindex subscriber | 2-4 h | Events on unit save/delete and whole-transcript operations. T4 subscribes for Solr. One subscriber reindexes the parent node's kmassets document so `trid_i` stays current; it must not loop. **Done: PR #349.** `TranscriptChangeNotifier` + five events (`TranscriptEvents`); `suppressed(callable)` coalesces a bulk operation to one `UNITS_REPLACED` and one cache invalidation per transcript; a throwing subscriber is caught and logged, because `postSave` runs inside the storage transaction and would otherwise roll the save back. `TranscriptReindexSubscriber` in `mandala_kmassets_sync` refreshes the node's document on transcript create/move/delete (not on unit events). |
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

### Outcome of the review, 2026-10-19

Meeting of Yuji Shinozaki, Than Grove and Xiaoming Wang. The meeting ended early (Than had to
leave), so items 2 and 3 and the PR decision are open.

| # | Decision | Decided by |
|---|---|---|
| 1 | **Bad timecodes do not block the migration.** T5 carries units as found, with the classifier status only; no repair in the migration. Keep the original start and end values so a later repair is possible. Than owns the transcript fixes (see [the deferred note](../deferred/transcript-bad-timecodes-investigate-and-fix.md)). | Yuji |
| 2 | **Untimed units: open, a note for Than.** 3,039 units (163 transcripts: 62 fully untimed, 101 partly) have start = end = 0. Does the viewer treat them as having no time (no playhead sync) or as a cue at 0:00? Recommendation: untimed, as a derived flag, values stored as found. | open |
| 3 | **The one negative start (tcu 142269): open, a note for Than.** Own status, clamp to 0 with `end_original`-style bookkeeping, or leave (classifies `ok`). Leaving it is lowest-risk under decision 1. | open |
| 4 | **Workflow fields** (`field_transcript_input`, `_proofed`, `_timecoded`, `_workflow_notes`) become base fields on `av_transcript`. Field types in D7 are still to be checked. | Than |
| 5 | **Existence rule:** an `av_transcript` exists exactly when D7 has a tracking row. The 41 never-processed nodes get none; the 4 tracked nodes with no current file get one with no source file. The 19 orphan trids (units, no tracking row, no node) are kept somewhere for later review, not dropped. | Than |
| 6 | **Deleting a transcript soft-deletes it and its units** (reversible). | Yuji |
| 7 | **Deleted units keep their history by soft-delete flag**, not an archive table. See [ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md). | Yuji |
| 8 | Module home (`mandala_av_transcripts`) and sequencing (resolver swap waits for T5): not discussed, the recommendations stand. | n/a |

Work this adds, none of it blocking T5:

- A soft-delete base field on `av_transcript` and `av_tcu` (update hook), respected by the reader,
  the access handlers and the kmassets reindex. Needed before T7 (revert).
- The four workflow base fields on `av_transcript` (update hook).
- A place to keep the 19 orphan trids' units for review ([deferred note](../deferred/transcript-orphan-trids-keep-for-review.md)).
- Deleting the parent node cascades a soft-delete to its transcript and units, and administrators manage the
  orphans with drush commands, with a screen deferred (decided 2026-10-20, see "Parent-node delete and orphan management" below).
- Add an `untimed` and a `start_missing` reading to the classifier or a derived flag: the review
  found that `end_implausible` includes units whose start is 0 and whose end is real (see the
  deferred note's 2026-10-19 section).

### Parent-node delete and orphan management (decided 2026-10-20)

Checked first: nothing in `mandala_av_transcripts` reacts to a node delete, so today a deleted node
leaves its transcript and units behind, denied to everyone but administrators. Collection content
editors (`collection-content_editor_*` and the subcollection roles) hold `delete any group_node:audio`
and `:video`, so this happens in day-to-day editing, not only in rollbacks. `mandala_kmassets_sync_node_delete`
already deletes the node's kmassets document inline and is suppressed during migrate import and rollback.

**Decision (Yuji): option B.** Deleting an AV node soft-deletes its transcript and all its units
([ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md)). Administrators, and only
administrators, deal with the orphans, with drush commands first and a screen only if wanted.

- **Hook.** `hook_node_delete` for the AV bundles sets the soft-delete flag on the transcript and its units.
  It dispatches the existing `TRANSCRIPT_DELETED` and `UNIT_DELETED` events, so T4's Solr subscriber needs no new
  case, and it is suppressed during migrate import and rollback like the kmassets hook.
- **Snapshot at delete time**, because the node's title, legacy key and collection membership vanish with it:
  `deleted_by`, `deleted_at`, node title, legacy composite key (ADR 017), and the collection ids.
- **Two kinds of orphan.** A transcript soft-deleted while its node still exists can be restored (flip the flag).
  A transcript whose node was deleted cannot be restored on its own: core has no node undelete.
- **Orphan manager service plus drush commands (first version).** One service holds the logic: find orphans,
  restore, export, purge. The commands sit on top of it, following the repo's existing admin housekeeping
  (`group:sweep-orphans`, `mandala:missing-file-audit`, the `av:backfill-*` commands). All are
  administrator-only in the sense that they need shell access to the server (drush runs with no Drupal user, so
  there is no permission check to make); the purge command's confirmation is the safeguard.
  - `av-transcripts:orphans` lists them with state, the delete-time snapshot, unit count and timecode-problem
    count; `--format=json` for scripting.
  - `av-transcripts:export <id> --format=srt|vtt|json` before purging.
  - `av-transcripts:restore <id>` where the node still exists (flips the flag).
  - `av-transcripts:purge <id...>`, a hard delete and the only operation that removes revisions. Takes
    `--dry-run`, prints the count, and asks for confirmation.
- **Admin screen: deferred, not in the first version.** A list at `/admin/content/av-transcripts` as a thin
  layer over the same service (custom list builder, entity operations, bulk form). The entities have no UI
  handlers today (no list builder, routes or forms), so this is a day or more of work. Build it if
  administrators want something other than the command line.
- **Warning on the node-delete confirmation form** naming the transcript and its unit count, and saying the
  units are kept for administrators.
- **T5 consequence.** Migration rollback deletes nodes, so under this rule it soft-deletes their transcripts. The
  transcript import must be idempotent and keyed on the legacy composite key, so a re-run revives the existing
  transcript and units instead of creating duplicates. Design this before T5.
- **Not in the first version:** the admin screen, re-attaching an orphan to another node, any editor access to
  orphans, and retention-based auto-purge. See the [deferred note](../deferred/transcript-orphan-management-reattach-and-editor-access.md).
  The first version leaves room: the node reference stays a changeable field, the snapshot is stored from day one,
  and one node per transcript is enforced on the entity, not in a form. It all sits behind one service, so
  the commands and a later screen share the logic.
- **Open:** whether the 19 orphan trids ([deferred note](../deferred/transcript-orphan-trids-keep-for-review.md))
  use the same service and commands. It would conflict with ADR 019, which says they are not migrated into `av_transcript`.
- **Not checked:** what deleting a whole collection (the editor roles hold `delete group`) does to its member
  nodes, and so to their transcripts.

### Soft-delete as built (2026-10-20)

Implements [ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md) decisions 1 and 2's
mechanics; the node-delete cascade, snapshot fields and drush commands above are the next PR.

- **Fields.** `deleted` on `av_transcript`; `deleted` and `deleted_with_transcript` on `av_tcu`. Not revisionable
  (lifecycle state, not content), `NOT NULL DEFAULT 0`, so a plain `deleted = 0` filter is right for every row.
  Update hook `10002`.
- **`deleted_with_transcript`** marks the units a transcript's soft-delete took. Restoring the transcript revives
  exactly those, so a unit an editor deleted on its own earlier stays deleted. ADR 019 says the cascade flags the
  units; this keeps that and makes restore safe.
- **`mandala_av_transcripts.lifecycle`** (`TranscriptLifecycle`): `softDelete()` and `restore()`. Units are flagged
  with one UPDATE, not loaded and saved (over a thousand per transcript). The transcript is saved as a new
  revision with a log message, user and time, which is the audit trail for who deleted or restored it. Purge is
  still the entity's own `delete()`.
- **Events.** A soft-delete dispatches `TRANSCRIPT_DELETED` once and no per-unit events, so T4 should treat that
  event as "remove every unit of this transcript". A restore dispatches `TRANSCRIPT_SAVED` and `UNITS_REPLACED`
  (rewrite the whole transcript). A single unit soft-deleted on its own dispatches `UNIT_DELETED`; restored,
  `UNIT_SAVED`. Saving an already-deleted entity dispatches nothing. This refines the earlier note that a soft-delete
  would fire `UNIT_DELETED` per unit, which does not scale.
- **Respected by:** the reader (hidden from everyone, administrators included, and its raw unit query filters the
  flag), and both access handlers (denied to everyone but `administer av transcripts`).
- **Consequence to know.** `av_transcript.node` is a database unique key and a soft-deleted row still holds it, so
  a node with a soft-deleted transcript cannot get a new one until the old one is restored or purged.
- **Restore access.** Only administrators can restore for now. The earlier idea that whoever can edit the node can
  restore a transcript soft-deleted on its own arrives with the restore command and screen, if wanted.

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
- **The entity-backed `trid` resolver (phase 7) must skip soft-deleted transcripts**, or `trid_i` stays on the
  node's kmassets document after the transcript is deleted. The legacy-table resolver in use until then does
  not know about the flag.
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
