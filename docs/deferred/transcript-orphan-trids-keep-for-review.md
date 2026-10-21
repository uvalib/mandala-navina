# Transcript orphan trids: keep their units for later review

**Category:** migration-av
**Raised during:** T2 review meeting, 2026-10-19 (Spike 11 audit found them 2026-10-01)
**Issue:** none
**Priority:** Low. Not blocking T2 or T5; needs a decision before the units are migrated.
**Owner:** unassigned

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

## Related

- [T2 plan](../planning/t2-transcript-entities-plan.md): outcome of the 2026-10-19 review
- [Transcript source-file accounting](transcript-source-file-accounting.md)
