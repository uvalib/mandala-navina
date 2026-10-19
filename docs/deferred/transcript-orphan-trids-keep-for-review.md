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

## Related

- [T2 plan](../planning/t2-transcript-entities-plan.md): outcome of the 2026-10-19 review
- [Transcript source-file accounting](transcript-source-file-accounting.md)
