# T5 plan: migrate D7 transcripts into `av_transcript` / `av_tcu`

**Status:** plan reviewed; decisions 1 and 4-7 taken 2026-11-10. Source plugins `d7_av_transcript` and `d7_av_tcu` built and tested (below); the migration YAMLs, the process plugin that classifies timecodes, and the verification script are not. Sprint 4 task T5.
**Depends on:** T2 (entities, lifecycle, classifier; built, in `main`), Sprint 3 AV4 (AV nodes and `field_transcript` files migrated).
**Does not need:** T3 (viewer) or T4 (transcript core indexing) to run; see "Dependencies" below.
**Unblocks:** the resolver swap (T2 phase 7, [#290](https://github.com/uvalib/mandala-navina/issues/290)), and T3/T4/T6/T7/T8 on real data.

Background and decisions this plan builds on, not reopened here:
[Spike 11](../spikes/spike-11-av-transcript-replication.md),
[T2 plan](t2-transcript-entities-plan.md),
[ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md),
[ADR 017](../adr/017-legacy-identity-composite-key.md),
[bad timecodes note](../deferred/transcript-bad-timecodes-investigate-and-fix.md).

## What was and was not verified for this draft

**Checked 2026-11-10 against the `d7_av` dump in DDEV** (the 2026-09-01 dump):
the counts in the tables below, the tracking table's columns, the edits flag, and the ordering numbers.

**Read 2026-11-10 from the D7 staging host** (`mandala-drupal-dev-1`, container `dockerfiles-hostmaster-1`,
`/var/aegir/platforms/mandala-base/docroot/sites/all/modules/custom/transcripts`; read-only). The module source
is also in the local legacy checkout (`~/Code/mandala-legacy/mandala-drupal/docroot/sites/all/modules/custom/transcripts`,
repo `shanti-uva/mandala-drupal`); only the ordering line (`fts_start asc`, line 84 of `transcripts_apachesolr.module`)
was compared, and it is identical. The two copies have not been diffed, and neither has been compared with production's,
though the dump's `variable` table (production data) agrees with them on the tier configuration.

**Dump currency, checked 2026-11-10:** the transcript tables were re-dumped read-only from production (`mandalaavlibvirg` on `rds-mysql8-production`: `tcu`, `tcu_tier`, `tcu_speaker`, `transcripts_apachesolr_transcript`, `flag`, `flagging`, `field_data_field_transcript`, `field_revision_field_transcript`, `file_managed`, `node`, and only the `transcripts%` rows of `variable`) into a separate local database, `d7_av_fresh`. Row counts, maximum ids, the latest edit-flag, unit-created and unit-changed timestamps, and `CHECKSUM TABLE` for `tcu`, `tcu_tier`, `tcu_speaker`, the tracking table, `flagging` and `field_data_field_transcript` are all **identical** to the 2026-09-01 dump. Nothing in the transcript data has changed since then, so every number in this plan holds for production as of 2026-11-10. The newest unit was created 2024-11-19 and the newest edit flag is 2026-04-30.

**Not verified:**
- The viewer's read order on the other 200 transcripts whose `tcuid` order and time order differ, and the order for equal starts (one of the 201 was checked on a live page; see open question 1).
- Anything on dev-0. (The 2026-09-01 dump was checked against production on 2026-11-10; see below.)
- The D11 behaviour of the migration itself. No migration code exists yet.
- The timing on dev-0. Local timings do not carry over (see "Running it").

## Source facts

| Fact | Value |
|---|---|
| Tracked transcripts (`transcripts_apachesolr_transcript`, module `transcripts_node`, type `node`) | 5,343, one per node, all status 1 |
| Units (`tcu`) | 245,158 in 5,362 distinct `trid`s |
| Tracking rows' `trid` set vs units' `trid` set | 5,343 tracked + 19 orphan trids (units, no tracking row, no node) |
| Orphan-trid units | 2,153 |
| **Units to migrate** | **243,005** (245,158 − 2,153) |
| `tcu_tier` rows | 355,536 (18 rows point at no unit) |
| `tcu_speaker` rows | 142,445 (95 rows point at no unit) |
| Units with no `tcu_tier` row | 908 |
| Empty tier value / empty speaker value rows | 176 / 67 |
| `keep_transcript_edits` flaggings | 540, on 540 distinct nodes, every one with a tracking row; timestamps 2016-01-06 to 2026-04-30; one flagging user |
| Tracking rows whose `fid` has no `file_managed` row | 71 (the 68 replaced files and 3 with no file; ADR 019 / Spike 11 decisions cover them) |
| Time range | start -0.125 to 81,795.3 s; end 0 to 81,807.4 s; fits `DECIMAL(10,3)` |
| `tcu` keys | primary key on `tcuid` only; **no index on `trid`**, so any per-transcript query on D7 must be written as a window or grouped scan (a self-join on `trid` did not finish) |

## Mapping

**`av_transcript`** (one per tracking row; id = D7 `trid`):

| D11 field | From | Rule |
|---|---|---|
| `id` | `trid` | Set explicitly. Allocated ids start at 100,000, clear of D7's 27,016. |
| `node` | tracking `id` | The D11 node, found by legacy composite key `(field_legacy_site = audio-video, field_legacy_nid)`, never by a D11 nid (ADR 017, CLAUDE.md). Unique. |
| `source_file` | the node's D11 `field_transcript` | The current attachment is the original of record (decided 2026-10-02). The 4 tracked nodes with no current file get none (ADR 019). |
| `source_format` | file extension | `vtt`, `xml`, `txt`, `srt`. Checked: the current attachments are 3,241 vtt, 1,930 xml, 138 txt and 30 srt (5,339), none with another extension; 4 tracked nodes have no `field_transcript` row. An unrecognised extension would leave it empty and be reported, not guessed. |
| `tiers` | tracking `tiers` and D7 config | The tracking `tiers` column (space-delimited; no value contains a comma) gives the transcript's tier **set**. **Display order is not per transcript:** D7 shows `transcripts_ui_tiers()` (the site-wide `transcripts_ui_tiers` variable, 35 tiers, plus `transcripts_ui_hidden_tiers`, currently `ts_content_wylie`) intersected with the transcript's tiers. Store the transcript's tiers in that configured order. Where the tracking column is empty (1 NULL and 8 empty rows, 9 transcripts), derive the set from the distinct tiers on its units. Checked: all 17 tier names in use, on tracking rows and on units, are in the configured list, and the two sets are identical, so nothing would be hidden or invented. |
| `has_edits`, `edits_flagged_at` | `flagging` for `keep_transcript_edits` | `TRUE` and the flagging timestamp for the 540 nodes. The flagging user is not carried (no meaning in D11). |
| `created` | none | Not in the tracking table; use the earliest unit `created` of the transcript. Checked: none is zero (range 2015-08-29 to 2024-11-19). 571 of them are earlier than their node's `created`, so do not derive it from the node; in 9 transcripts the units span more than a day. |

**`av_tcu`** (id = D7 `tcuid`):

| D11 field | From | Rule |
|---|---|---|
| `id`, `transcript` | `tcuid`, `trid` | Set explicitly; units start at 2,000,000 for new ids. |
| `sequence` | derived | From 0, per transcript, ordered by `(start, tcuid)` (open question 1: proposed, matches D7's display order; the tie rule is a choice, not D7's). |
| `start`, `end` | `start`, `end` | Round to whole milliseconds (D7 floats carry sub-millisecond noise). Stored as found: no repair, no `end` rewrite. `end_original` stays empty. |
| `timecode_status` | `TimecodeClassifier` | Computed per transcript over the rounded, ordered values. Depends on the ordering in question 1. |
| `tiers` | `tcu_tier` | JSON object tier → text, NFC-normalised (Spike 4a). Recounted 2026-11-10: **3,526 of 355,536 tier values are not NFC, across 12 tiers**, not only the 933 Dzongkha and 12 Tibetan the audit named; 1,919 of them are wholly decomposed (NFD). Largest: `dzo_bod` 933, `ts_content_gyal` 735, `ts_content_eng` 487, `ts_content_tsum` 422, `ts_content_nmm` 402, `ts_content_gvr` 348. Normalising changes the stored text of those values, so the fidelity hash must compare NFC-normalised D7 text with D11 text. Units with no tier rows get `{}`. Empty values are carried as empty strings, not dropped. |
| `speakers` | `tcu_speaker` | Same shape and normalisation (136 of 142,445 speaker values are not NFC; 68 are wholly NFD). |
| `created`, `changed` | `created`, `changed` | As found. |
| initial revision | none | One revision per unit; history starts here (about 490k revision rows across units and transcripts). |

Not migrated: the 18 and 95 tier and speaker rows that point at no unit, the 41 never-processed nodes (no tracking row, so no transcript) and the 19 orphan trids (open question 2). All are counted in the run report so none disappear silently.

## Open questions

1. **Ordering: how is `sequence` derived? Answered 2026-11-10 (time order, confirmed on live D7).**
   The data (local dump): in **201 transcripts**, 377 units start *earlier* than the unit with the previous
   `tcuid`, so `tcuid` order and time order disagree for 8,741 units. 63 of the 201 are on `keep_transcript_edits`
   nodes and 2 are orphan trids. The largest backward step is 3,650 s. Separately, 1,278 transcripts have units that
   share a start (5,409 units).

   **What D7 does (read from the module on dev-1):** the viewer does not read the database. It queries Solr for
   `is_trid = <trid>`, `rows = 9999`, sorted **`fts_start asc`**
   (`transcripts_apachesolr_transcripts_ui_transcript()` in `transcripts_apachesolr.module`). Readers therefore see
   **time order**, not `tcuid` order. (The 9,999-row cap is not a concern: the largest transcript has 1,380 units.)
   For units that share a start, the sort has no second key, so their order is whatever Solr returns for equal values
   (index order), which D7 does not define and which can shift when a unit is re-indexed after an edit.

   **Proposal:** `sequence` by `(start, tcuid)`. Time order matches what readers see, and `tcuid` is a stable,
   deterministic tie-break that D7's own ties cannot give us. `tcuid` is kept as the id, so `sequence` can be
   recomputed at any time. The classifier's "next unit" and the reader's range read then agree with the display.

   **Confirmed on live D7, 2026-11-10:** node 761 ("Painting a Deity's Face and Other Details: Tangka Painting
   Techniques in Action #10", trid 8181, edited, 18 units) was opened on `av.mandala.library.virginia.edu`. Unit
   614901 (start 152 s) is the last unit by `tcuid` but the page shows it **4th**, between 561681 (1:50) and 561691
   (2:37). All 18 units appear in start-time order (the page's `data-begin` values are non-decreasing) and the order
   differs from `tcuid` order. The page therefore shows what the Solr sort gives, matching the dev-1 code and the
   dump, so question 1 is closed for this transcript. One transcript was checked; the tie order for equal starts and
   the index-versus-tables comparison across all 201 remain unchecked.

2. **Where do the 19 orphan trids' units go?** Than decides after analysing them
   ([note](../deferred/transcript-orphan-trids-keep-for-review.md)). It does not block T5: they are excluded and counted, and the expected total is 243,005. It must be settled before the cutover run, not before the first dev run.
3. **Untimed units and the one negative start** (3,039 untimed units in 163 transcripts; one start of -0.125). Than's call; T5 stores them as found with whatever status the classifier gives, so nothing is lost, and the status can change later without re-importing text.
4. **`field_transcript` after T5. DECIDED 2026-11-10 (Yuji): keep it.** The sprint says T5 replaces the inert placeholder; this plan keeps the file field instead. It stays on the node as the upload field and is the source of `source_file` and the base for revert-to-upload (T7).
5. **Entity API or bulk insert. DECIDED 2026-11-10 (Yuji): entity destination, with a 5% dev-0 sample run first.** The spike chose the migrate entity destination for row-count verification, access and hooks. Measured on DDEV, 245,158 unit inserts took 260 s through the entity layer; dev-0 will differ. The change notifier is suppressed during the run, and the sample run on dev-0 measures time and memory before the full run.
6. **Rollback of the transcript migrations themselves. DECIDED 2026-11-10 (Yuji): hard-delete.** Rolling back the *node* migration soft-deletes transcripts (ADR 019). Rolling back `d7_av_transcript` / `d7_av_tcu` hard-deletes what they created, so a rollback-and-rerun while developing leaves no stale rows. Checked in the T2 code: soft-delete fires only from the node-delete cascade (`NodeDeleteCascade`, on `hook_node_delete`); deleting an `av_transcript` or `av_tcu` entity directly is a real delete whose `postDelete` only emits change events, so there is no conflict. Two things to build and test: a hard delete of a transcript does **not** cascade to its units, so units must roll back first (migrate rolls back in reverse dependency order, but add a test); and each hard delete emits `TRANSCRIPT_DELETED` / `UNIT_DELETED`, and whether the migrate suppressor covers rollback as it covers import has **not** been verified.
7. **Dependencies. DECIDED 2026-11-10 (Yuji): relax to T2** (and Sprint 3 AV4). Nothing in the import needs the viewer or the transcript core; T4 is needed afterwards to see results in search. The Sprint 4 table is updated to match. Recorded here for the team to see; it gates nothing.
8. **Who spot-checks against source files.** The sprint asks for checks on each source format. Than holds the files (Spike 11 question 4). The sprint says three formats (Toolbox, SRT, XML), but WebVTT is the majority (3,227 of the tracked files), so the check needs four formats.

## D7 configuration the viewer (T3) will need, found while reading the module

Not part of T5's data migration, but T3 cannot reproduce D7's display without it, and it lives in D7's `variable`
table, not in `tcu`: `transcripts_ui_tiers` (35 tiers: key to label, in display order),
`transcripts_ui_hidden_tiers` (`ts_content_wylie`), and `transcripts_ui_speaker_names` (37 speaker labels). Capture
them as D11 configuration when T3 starts. Tier labels and speaker labels are *not* stored per unit.

## Design

**Migrations**, new in `mandala_migrations`, in group `mandala_av`, reading the D7 AV database through the existing key:
1. `d7_av_transcript`: source reads the tracking table joined to the edits flag; depends on the AV node migrations and `d7_av_files`. Destination `entity:av_transcript`.
2. `d7_av_tcu`: source reads `tcu` with `tcu_tier` and `tcu_speaker` aggregated, **one transcript per batch** (the large-migration memory and resume behaviour is documented in the
   [deferred note](../deferred/migrate-large-migration-oom-and-resume-behavior.md): raise the CLI `memory_limit`, and a resume re-reads the whole source). Depends on `d7_av_transcript`. Destination `entity:av_tcu`. Sequence and status are computed per transcript, in the same pass, with the classifier.

The workflow-field migrations already exist (`d7_av_workflow`, `d7_av_transcript_workflow_notes`); the workflow fields stay on the node (T2 plan correction 2026-10-21) and are not part of T5.

**Wrapping:** run inside `TranscriptChangeNotifier::suppressed()` so units cost one event and one cache invalidation per transcript, not one each. Transcript-level events are *not* coalesced: 5,343 transcript saves would each write a node to Solr, so either rely on the migrate suppressor (inside `drush migrate:import` it applies) or index afterwards with `kmassets:index-all video` and `audio`. Do not use a custom script without checking this.

**Idempotency (ADR 019).** Keyed on `trid` and `tcuid`. A re-run updates in place. If a transcript exists soft-deleted (because its node was rolled back and re-imported), the import finds it by id, re-points `node` at the new node and restores it, with its units, instead of creating a duplicate. This needs a test that rolls back the node migration, re-imports, and checks the counts, the ids and the restored flags.

**Ids are set, never allocated,** and the id-sequence reservation (`AvTranscriptIdSequence`) must stay above D7's maxima while D7 keeps minting ids.

## Running it

1. **DDEV first**, on the local dump: proves correctness and the verification script, not runtime.
2. **dev-0 sample run** (about 5% of transcripts), to measure time and memory on the real database. Do not extrapolate the DDEV figure; dev-0 migrations have run several times slower than local.
3. **dev-0 full run**, after node migrations are in place. `drush migrate:*` works on dev-0 again since the authmap fix (#371).
4. `kmassets:index-all video` and `audio`, then confirm `trid_i` still covers the same 5,343 documents.
5. Only then the resolver swap (T2 phase 7): entity-backed resolver that skips soft-deleted transcripts, then remove the interim `mandala_kmassets_trid_map` table. Removing it earlier drops `trid_i`.

## Verification (acceptance for T5)

| Check | Pass |
|---|---|
| `av_transcript` count | 5,343 (live), each with a distinct node |
| `av_tcu` count | 243,005, and per transcript equal to D7's count for that `trid` |
| Text fidelity | A hash of every transcript's tier text in D7, NFC-normalised, equals the same hash read back from D11 |
| `timecode_status` counts | Recorded per status; flagged units are in the counts (none dropped) |
| `has_edits` | 540 transcripts true, with the flagging timestamps |
| Dropped rows | Exactly the 18 + 95 orphan tier/speaker rows, the 2,153 orphan-trid units and the 41 never-processed nodes, each reported |
| Idempotent re-run | Second run: zero created, zero updated |
| Rollback and re-import | Node rollback soft-deletes; re-import restores the same ids with no duplicates |
| Spot-check against source files | A sample from each of vtt, xml, txt, srt matches (Than) |
| `trid_i` | Still on 5,343 documents after the reindex |

## Source plugins: built 2026-11-10

`D7AvTranscript` (`d7_av_transcript`) and `D7AvTcu` (`d7_av_tcu`) in `mandala_migrations`, with
`AvTranscriptSourcesTest` (15 Kernel tests). Run against the full production re-dump (`d7_av_fresh`) they yield:

| Check | Result |
|---|---|
| Transcripts | 5,343; 540 with the edits flag; 9 with an empty tracking `tiers` column; formats vtt 3,241, xml 1,930, txt 138, srt 30, none 4 |
| Units | **243,005** in 5,343 transcripts; per-transcript counts equal D7's for every transcript |
| `sequence` | contiguous from 0 in every transcript, no duplicates, exactly one last unit (no `next_start`) each |
| Unit 614901 (node 761) | sequence 3, start 152.000, next start 157.267: the order the live D7 page shows |
| NFC | 3,658 tier and speaker values change on migrated units (3,662 corpus-wide; the other 4 are on orphan-trid units) |
| Units with no tier rows | 900 migrated (908 corpus-wide; 8 are orphan units) |
| Run time | transcripts 1.3 s; units 38.8 s on DDEV, reading and normalising only, no writes |

Two design points the tests caught before real data did. Migrate joins its id map on the bare source id, so the
unit id needs `alias: c` (the derived tables also expose `tcuid`). And SQL evaluates `WHERE` before window
functions, while migrate adds its own `WHERE` to skip imported rows; the numbering therefore lives in a derived
table, or a run resumed part-way through a transcript would restart `sequence` over the remaining units.

Still to build: the migration YAMLs, a process plugin that calls `TimecodeClassifier` on the rounded start, end
and `next_start_s`, the verification script, the node lookup by legacy key, the soft-delete revive path, and the
rollback tests (units before transcripts; whether the migrate suppressor covers rollback events).

## Order of work

1. Confirm questions 4–7 (question 1 is settled).
2. Source plugins and the two migrations, with Kernel tests for: the mapping, NFC, rounding, classifier call, idempotency and the soft-delete revive path.
3. A verification script that compares D7 and D11 (counts, hashes, status counts, dropped-row report), in `scripts/`, with a howto in `docs/dev-notes/`.
4. DDEV run; fix what it shows.
5. dev-0 sample run, then full run.
6. Reindex, then the resolver swap, as its own PR.
7. Update #290, the Sprint 4 T5 and T9 rows, and the T2 plan's phase 7.
