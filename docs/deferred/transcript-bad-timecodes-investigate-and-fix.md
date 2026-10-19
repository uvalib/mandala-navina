# Transcript bad timecodes: investigate and fix

**Category:** migration-av
**Raised during:** Spike 11, 2026-10-01 audit; deferral decided with Than 2026-10-02
**Issue:** #353
**Priority:** Medium. Must be understood before the transcript migration (Sprint 4 T5) is
called verified; does not block building the model or the viewer.
**Owner:** Than (transcript fixes); Yuji (migration side)

## What is deferred

4,269 of the 245,158 D7 time-coded units (TCUs), across 679 transcripts, have an end time
at or before their start (measured on the 2026-09-01 `d7_av` dump). A further 190 end
after 4 hours (maximum about 22 hours), which are also implausible but are not counted in
the 4,269. The team decided on 2026-10-02 to **carry them as found** and investigate and
fix them later.

| Category | Count | Notes |
|---|---|---|
| Zero-length, next TCU has the same start | 3,487 | Look like stacked or simultaneous cues, not broken ends. Unconfirmed. |
| Last TCU of its transcript | 399 | No next TCU exists. Kaltura entry duration is one candidate end. |
| End before start, next TCU starts later | 383 | Fixable by Than's proposed rule (end = start of the next TCU). Only 8 would get a gap over 120 s. |
| End over 4 hours | 190 | Separate from the 4,269; the next-start rule would not touch them. |

The counts overlap slightly in how they were derived (the first three partition the 4,269;
the 190 are a separate query), so do not add all four.

## How they are handled in the meantime

The Spike 11 data-model sketch (see [the spike](../spikes/spike-11-av-transcript-replication.md))
carries every unit unchanged and adds:
- `timecode_status`, computed at migration (`ok`, `zero_length_shared_start`,
  `zero_length_other`, `inverted`, `last_unit_no_end`, `end_implausible`, later `corrected`);
- `end_original`, filled whenever a correction is applied, so a fix is reviewable and reversible.

The viewer, sync rule, editor, search and migration verification each have a stated rule
for flagged units in that sketch.

## What closes it

1. Open real source files (Than is to supply a few per format) for a sample of the
   3,487 same-start units and decide what they are: deliberate stacked cues, or an artefact
   of the D7 conversion.
2. Decide per category: keep as found, apply "end = next start", use "next distinct start",
   or use the Kaltura duration for last units.
3. Get David Germano's sign-off on any correction (scholarly integrity).
4. Add a re-runnable report (suggested name `transcripts:timecode-audit`) listing flagged
   transcripts and counts per status, built with the transcript migration (Sprint 4), and a
   short howto beside the other audit commands.
5. Test the highlight-on-play rule on a transcript that has flagged units; the prototype's
   node had none.

**Owner:** Than (transcript fixes); Yuji (migration side).

## 2026-10-19 update: T2 review meeting

**Decision (Yuji): the bad timecodes do not block the migration.** T5 carries every unit as
found, with the classifier status only, and keeps the original start and end values. Than owns
the transcript fixes. Decision recorded in the
[T2 plan](../planning/t2-transcript-entities-plan.md#outcome-of-the-review-2026-10-19).

**Counts by the current classifier** (recomputed in SQL over all 245,158 D7 units; these replace
the 2026-10-02 figures above, which used different rules):

| Class | Units | Transcripts |
|---|---|---|
| `inverted` (end before start, or end = 0 with start > 0) | 904 | 414 |
| `last_unit_no_end` | 66 | 66 |
| `zero_length_other` | 188 | 175 |
| `end_implausible` | 56 | 11 |
| `zero_length_shared_start` | 3,111 | 168 |

**What the original files show** (three files opened by hand, then every `.srt` and `.vtt` scanned):

- **Short millisecond fractions.** Two files write fractions with fewer than three digits (for
  example `00:01:55,29` where `00:01:55,029` is meant), and D7 stored those times as 0: the Tale of
  Tsar Saltan `.srt` (D7 nid 3751, 42 starts and 56 ends of 538 cues) and one Session III `.vtt`
  (nid 42262, one end). No other `.srt` or `.vtt` file of the 3,283 has this. Zero-padding the
  fraction fixes all 99 timestamps mechanically. The 1,949 `.xml` files use two-digit fractions
  (centiseconds) in 912 files; those were not counted as errors and how D7 parsed them was not
  checked.
- **Paired-cue pattern.** In the Tibetan Pottery `.vtt` (nid 2067) each Tibetan cue ends 1 ms
  before it starts and its Chinese partner cue carries the real span (271 of 288 inverted units).
  By the D7 values, 37 transcripts show the same pattern; only nid 2067's file was opened, so the
  others are inferred. A human decision is needed (it looks like a bilingual export, not a timing
  error).
- **`end_implausible` is partly mislabelled.** In nid 3751, 36 of the flagged units have
  `start = 0` and a real end: the start was lost, not the end. The classifier compares the end
  with the next unit's start, which is also 0. The other ten flagged transcripts were not
  checked. The migration only carries the label, so nothing is damaged, but a repair rule of
  "clamp the end" must not be applied to these.
- Of 682 transcripts with a flagged unit, 603 have a cause that is neither confirmed nor inferred
  yet ("not yet diagnosed" in the workbook).

**Open questions for Than** (the meeting ended before these were answered; neither blocks the
migration):

1. **Untimed units.** 3,039 units (163 transcripts: 62 fully untimed, 101 partly) have
   `start = end = 0`. Does the viewer treat them as having no time (no playhead sync), or as a
   cue at 0:00? Recommendation: untimed, as a derived flag, values stored as found. Also: is a
   `0 / 0` unit in the middle of a timed transcript untimed, or could it be a deliberate cue?
2. **The one negative start** (D7 tcu 142269, trid 2371, nid 554, start -0.125, first unit). It
   classifies `ok`. Give it its own status, clamp it to 0 and keep the original, or leave it.
3. **Where repairs land after the migration:** fix the source files and re-import, or edit the
   units in D11. A re-import is only clean if the migration can run again safely.

**Working material.** A workbook lists every flagged unit (node id, transcript id, filename,
unit times, flags, likely cause) for Than to work from. It was shared with Than directly and is
not linked here because it includes transcript text.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): corpus audit and data-model evaluation
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T5 (migration), T6 (editor)
