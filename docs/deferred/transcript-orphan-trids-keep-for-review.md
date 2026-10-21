# Transcript orphan trids: keep their units for later review

**Category:** migration-av
**Raised during:** T2 review meeting, 2026-10-19 (Spike 11 audit found them 2026-10-01)
**Issue:** none
**Priority:** Low. Not blocking T2 or T5; needs a decision before the units are migrated.
**Owner:** unassigned (Than is analysing the 19; the storage decision waits for that, 2026-10-21)

## What is deferred

19 transcript ids in D7's `tcu` table have time-coded units but no tracking row and no node
(orphans from 2016-2021, 1 to 526 units each). The Spike 11 audit suspected stale rows from
replaced uploads and did not investigate further
([spike](../spikes/spike-11-av-transcript-replication.md)).

**Decided 2026-10-19 (Than):** they are not dropped. They get no `av_transcript` (the existence
rule is "a transcript exists exactly when D7 has a tracking row", see
[ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md)), but their
units are kept somewhere for later review.

## What is still open

**Storage decision deferred to Than (Yuji, 2026-10-21):** he decides where the units are kept once he has finished
analysing the 19. Until then nothing is built for them, and the options below stand. It is still needed before T5
loads the units. Note the orphan manager cannot hold them without an `av_transcript` row, which ADR 019 rules out
(these have no tracking row and no node).

- **Where they are kept.** `av_tcu` units belong to a transcript, which belongs to a node, so these
  cannot be `av_tcu` rows. Candidates: a plain staging table loaded from D7 (queryable, easy to
  drop later), or a CSV kept with the migration notes.
- **Who reviews them and when.** Than for content; nothing is scheduled.
- Whether any belongs to a replaced upload that does have a current node under another trid.

## For Than: what the D7 data shows so far (2026-10-21)

A quick, read-only look at the local D7 AV copy, to save you starting from nothing. These are hints from counts
and text matches, not conclusions; nothing was written anywhere. No transcript text is quoted here.

**The 19** (`tcu` trids with no `transcripts_apachesolr_transcript` row). Every one was created in a single day, so
each looks like one upload processed once.

| trid | units | created | | trid | units | created |
|---|---|---|---|---|---|---|
| 2994 | 1 | 2016-03-18 | | 12361 | 118 | 2018-09-06 |
| 2995 | 11 | 2016-03-18 | | 17151 | 9 | 2018-09-20 |
| 2996 | 6 | 2016-03-18 | | 21251 | 175 | 2018-10-15 |
| 3082 | 7 | 2016-03-24 | | 22756 | 11 | 2018-12-27 |
| 3215 | 6 | 2016-03-30 | | 23416 | 25 | 2019-01-08 |
| 3551 | 305 | 2016-12-01 | | 23546 | 22 | 2019-02-01 |
| 3586 | 526 | 2016-12-01 | | 24236 | 19 | 2019-05-13 |
| 3616 | 439 | 2016-12-01 | | 26026 | 63 | 2020-06-16 |
| 10266 | 3 | 2018-08-07 | | 26932 | 272 | 2021-07-26 |
| 10271 | 135 | 2018-08-07 | | | | |

**No shape twins.** No tracked transcript has the same unit count, first start and last end as any of the 19.

**Text twins.** Comparing each orphan's tier text (values of 30 characters or more) with every other transcript
finds identical text in a nearby tracked transcript for most of them. That fits a replaced upload: re-uploading
made a new trid and the old one lost its tracking row.

| Orphan | Tracked trid with the same text | Matching values / orphan units |
|---|---|---|
| 2995 | 3003 | 12 / 11 |
| 2996 | 3002 | 24 / 6 |
| 3215 | 3216 | 6 / 6 |
| 3551 | 3581 | 5714 / 305 |
| 21251 | 21256 | 152 / 175 |
| 22756 | 22761 | 5 / 11 |
| 23416 | 23421 | 24 / 25 |
| 23546 | 23551 | 22 / 22 |
| 24236 | 24301 | 14 / 19 |
| 26026 | 26031 and 26316 | 63 / 63 each |
| 3586 | 3591 (408), and a few values shared with 3355, 3361, 3363 to 3365, 3601, 3606, 3621 | 408 / 526 |
| 3616 | 3621 (299) | 299 / 439 |

Counts are matching values, not units, so a figure can exceed the unit count when a value repeats. Notes:

- **3586 and 3616** share text with several tracked transcripts and with each other, so they may be overlapping
  or merged material, not a simple replacement. Worth a look by someone who knows the recordings.
- **10266 and 10271** match only each other, and both are orphans.
- **No match at all (values of 30 characters or more):** 2994, 3082, 12361, 17151 and 26932. These are the
  likeliest to be genuinely unreferenced. Short units (under 30 characters) were not compared, so 2994 (one
  unit) and 3082 (seven) in particular may simply have too little text to match.
- **Not checked:** whether any matching tracked transcript's file is the current one for its node, and who
  uploaded what. Both would need the node and file tables.

To reproduce: the orphans are `select distinct trid from tcu where trid not in (select trid from
transcripts_apachesolr_transcript)`; text matches join `tcu_tier` on equal `tier` and `value` across different
trids. In DDEV the D7 copy is the `d7_av` database.

## Related

- [T2 plan](../planning/t2-transcript-entities-plan.md): outcome of the 2026-10-19 review
- [Transcript source-file accounting](transcript-source-file-accounting.md)
