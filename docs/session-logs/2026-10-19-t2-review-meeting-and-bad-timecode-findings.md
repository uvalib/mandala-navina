# Session Log: T2 Review Meeting And Bad Timecode Findings

**Date:** 2026-10-19  
**Participants:** Yuji Shinozaki, Than Grove, Xiaoming Wang (meeting, which ended early when Than had to leave); Claude Code  
**Outcome:** The plan's open questions for Monday were worked through. Decided: bad timecodes do not block the migration; soft-delete for transcripts and units ([ADR 019](../adr/019-av-transcript-lifecycle-soft-delete-and-existence-rule.md)); the four D7 workflow fields become base fields on `av_transcript`; an `av_transcript` exists exactly when D7 has a tracking row. Open: untimed units, the one negative start, and the PR #350 merge decision. Abridged by hand: the working material includes transcript text, so no transcript is quoted here.

### Pick-up list

- **PR #350 (T2 phases 3-6) is still open and unmerged.** No merge decision was recorded. Review phase 3 (`src/Access/*`) first. This session's doc changes (the ADR, the deferred notes, this log) ride on the same branch, `integration/t2-transcripts`, so they reach `main` only when #350 merges. Merging deploys to dev-0 and runs `updb`.
- **Open for Than (neither blocks T5):** how the viewer treats the 3,039 untimed units (start = end = 0, 163 transcripts), and what to do with the one negative start. Both are in the [deferred note](../deferred/transcript-bad-timecodes-investigate-and-fix.md) (issue #353, assigned to Than).
- **T2 work this adds:** a soft-delete field on `av_transcript` and `av_tcu` (needed before T7, not T5), the four workflow base fields on `av_transcript` (check their D7 types first), and a place to keep the 19 orphan trids' units ([note](../deferred/transcript-orphan-trids-keep-for-review.md)).
- **Unchecked:** what happens to a transcript and its units when the parent node is deleted.
- **Unchanged:** #290 stays open until T5 populates the entities (do not remove `mandala_kmassets_trid_map` before then). Module home and sequencing were not discussed; the plan's recommendations stand.
- **Unassigned deferred issues:** #336 (solr-proxy EOL base image) and #300 are still free to pick up.

### What the D7 data showed

Recomputed the timecode classes in SQL over all 245,158 units (counts match the classifier): inverted 904 in 414 transcripts, last unit with no end 66, zero-length 188, implausible end 56 in 11 transcripts, shared-start 3,111 in 168. Untimed (start = end = 0): 3,039 units in 163 transcripts, 62 fully untimed.

Reading the original files, all 3,283 `.srt` and `.vtt` files scanned:

- Only two files have fractions with fewer than three digits (D7 nids 3751 and 42262, 99 timestamps in total); D7 stored those times as 0. Zero-padding fixes them.
- One `.vtt` (nid 2067) shows a bilingual paired-cue pattern: each cue of one language ends 1 ms before it starts, and the partner cue carries the real span. 37 transcripts show the same pattern by their D7 values; only that one file was opened.
- The classifier's `end_implausible` includes units whose start is 0 and whose end is real (seen in nid 3751). Labels only, nothing is changed by the migration.

### Working material

A workbook of every flagged unit (node id, transcript id, filename, times, flags, likely cause) was built for Than and shared through an unlisted artifact page. It contains transcript text, so it is not committed or linked in the repo.
