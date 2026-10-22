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

## 2026-10-21 findings and agreed plan (not yet carried out)

**How D7 links them: it does not.** Only two D7 tables carry a `trid` column, `tcu` and the
tracking table `transcripts_apachesolr_transcript`. The 19 exist only in `tcu` (2,153 units in all),
so the only way to place them is to match their text to the exported transcript files. The matching
method was checked first: sampled units from five tracked transcripts were found in their own files,
33 of 33, across `.xml`, `.txt` and `.vtt`. Each orphan was then matched on five sampled units
(the first 25 characters of each) against all 5,378 files in `files/transcripts`. A comparison of
start times against the tracked transcripts found nothing (best overlap 17%), so the match is by
text, not by timing.

| Group | Orphan trids | What they are |
|---|---|---|
| Superseded, same file | 2994, 2995, 2996, 3082, 3215, 21251, 22756, 24236, 26026 | The same file is on a node that has a tracking row, under a trid a few ids later. The orphan holds fewer units than the current transcript (for example 1 against 27, 175 against 261). Why it is shorter was not checked. |
| Superseded, file replaced after tracking | 3551, 3586, 3616, 23546 | The node's tracking row points to an older file id; the node's field now holds a newer file, and the orphan's text matches the newer one (nodes 1788, 2007, 2012, 1269). |
| Matched, node not fully traced | 17151, 23416, 26932 | The text matches an existing file. 23416 is on node 868, whose tracking row was not looked up. 17151 and 26932 were not mapped to a node. |
| **Unplaced** | **10266, 10271, 12361** | No file on disk contains their text and there are no tracking rows nearby. Created August-September 2018; 3, 135 and 118 units. |

That makes **16 superseded** and **3 unplaced**. Caveat: a match is five sampled units, strong
evidence of the same transcript but not proof that the whole unit list is identical. The three
"not fully traced" trids are counted as superseded on the text match alone.

**Plan, agreed with Than 2026-10-21. Nothing below has been done; it waits until Yuji's T2
branch has merged.**

1. **The 16 superseded trids are to be trashed.** Their units are older or partial copies of a
   transcript that is migrated anyway.
2. **The 3 unplaced trids are to be backed up first.** Because no source file exists for them, the
   backup is an export of their units (times and text) from `d7_av.tcu` and `tcu_tier`, zipped,
   and saved by Than to Teams. The zip contains transcript text, so it is not committed or linked
   here.
3. **Then mark the orphan-trid question resolved everywhere:** this note (a dated *Resolved*
   section), the deferred README entry, and any issue or doc that still lists the 19 as open
   (ADR 019 is immutable; the resolution is recorded here and not by editing it).

This replaces the two candidate keep-places listed above (a staging table or a CSV): the 3 are
kept as a zip outside the repository, and the 16 are not kept.

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
