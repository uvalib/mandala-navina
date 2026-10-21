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

## 2026-10-20 update: repair rule for `last_unit_no_end`

**Decision (Than, group session with Yuji and Xiaoming): the repair for `last_unit_no_end` is to
use the media duration as the unit's end.** This settles the "Kaltura duration for last units"
option in step 2 of "What closes it" for this class only. The other classes are unchanged.
David Germano's sign-off (step 3) is still needed before any correction is applied.

How the rule would work:

- The end becomes the node's `field_kaltura_duration` (whole seconds, so about ±1 s) and the
  unit's `timecode_status` becomes `corrected`, with the unit's old end kept in `end_original`.
- Apply it only when the duration is at or after the unit's start. If it is not, leave the unit
  as found and flag it for a person.

What the original files show (local DDEV database and the exported `.vtt` and `.srt` files; not
checked on dev-0):

- 65 `.vtt` and `.srt` files have a last cue whose end is `00:00:00.000` while its start is
  positive, which is the `last_unit_no_end` shape. Every one of the 65 has a duration on its node,
  and none has a duration earlier than the last cue's start.
- Worked example: `BU_URA_2014_08_27_HISTORY_OF_CHORTEN.vtt`. Its last cue starts at
  00:17:23.575 with end 0, and the node's duration is 1070 s (00:17:50), so the cue would run about
  26 s.
- **Match files to nodes by `file_managed.uri`, not `filename`.** Drupal renamed colliding uploads
  on disk (`6-04_0.vtt`, `..._Revised_3.vtt`) but left the original name in `filename`, so a
  `filename` match misses them. Three of the 65 failed to match until this was found.
- The duration is the media length, not necessarily where the speech stops, so the corrected end
  is an upper bound on the true one.

The 65 files are the exports on disk; the classifier's 66 units in 66 transcripts come from the D7
database, so the two counts differ by one.

## 2026-10-20 findings: the exported transcript files, and the repair plan

Group session (Yuji, Xiaoming, Than). Than scanned all 5,232 timed files in `files/transcripts`
(`.vtt`, `.srt` and `.xml`); 448 contain an inverted cue. Filenames and counts only; nothing is
committed from the files themselves.

| Bucket | Files | Cues | Notes |
|---|---|---|---|
| Fixable by "following start's minutes + the end's own seconds" | 1 | 7 | The Gebchak Wangdrak interview file, which Than confirmed. The rule fixes almost nothing else. |
| Placeholder cue or bad last cue only | 350 | 372 | See below. |
| Anomalous | 97 | 627 | 158 cues start after the next cue's start (the `..._0073.vtt` pattern: the ends are consistent with the next start, so the *start* is wrong), plus 12 others. Cause unknown. |

**Placeholder cues** (end one unit before start, next cue shares the start): 539 runs, all of
length 1, so no chains. 535 are followed by a cue with a real span; 509 of those have the same
speaker, so they are not bilingual pairs. **Decision: leave them as found, flagged by the
classifier.** The D7 player (`transcripts_ui/js/transcripts-scroller.js` in `mandala-drupal`)
only highlights a cue while begin < now < end and only seeks when end - begin > 0, so these cues
were silently skipped on the old site and nothing looked wrong (checked on `Lhalung Hermitage 1`).
An equal split of the time between the two cues, which Than proposed, would change what D7
visitors saw and gives meaningless values for very short spans (0.033 s each in that file; 52
splits come out under 0.5 s). The D11 spike viewer highlights by start only. T6 decides how the
viewer treats these units.

**Last cue with a bad end** (301 cues): 65 files end at `00:00:00.000`; the rest carry a stray
non-zero value. Than: use the duration for both when one exists. How many of the non-zero ones
have a duration is not yet counted.

**Repair process (agreed with Yuji: post-import, not inside T5).** A re-runnable
`transcripts:repair` drush command that runs small, idempotent rules, each selecting by
`timecode_status`, with `--dry-run`. Rule 1 is `last_unit_no_end` -> duration (status `corrected`,
old end kept in `end_original`, only when duration >= start). It runs after the T5 import and
after `av:backfill-kaltura-duration`, which is not in `scripts/run-av-migration.sh` yet. T5 must
not overwrite `corrected` units on a re-run, and the T7 revert parser must re-apply the rule.
**No David Germano sign-off is needed provided the original transcripts are backed up**; where the
backups are, and whether they cover every format, still has to be confirmed. `corrected` is not yet
in `AvTcu::TIMECODE_STATUSES`. Nothing has been built.

**Still open:** the 97 anomalous files; other repair types Than wants to add; the 4 placeholders
followed by another bad cue; the garbage-last-end durations count; the backup question above.

## 2026-10-21 update: sorting every transcript into lists, and the rules that fell out

Group session (Yuji driving his own Claude; Than reviewing transcripts). All 5,343 tracked
transcripts were sorted into lists for a review workbook (not built yet; three or four sheets once
the problem set is covered). Counts are from the local DDEV copy of the D7 data and the exported
files, not dev-0, and come from scratch scripts kept locally (not in the repo).

| List | Transcripts (draft) |
|---|---|
| Needs hand review | 256 |
| Repairable by `transcripts:repair` | 274 |
| Needs timecodes | 62 |
| OK as is | 4,751 |

**Hand-review list (Than):** D7 nodes 2067, 3652, 3535, 3553, 4624, plus 796 (did not work properly
in the player). Also in review: any transcript with a cue that starts earlier than the one before,
`end_implausible`, an inverted unit that is not a placeholder, a negative start, a first cue at
25 s or later (applied only inside the transcripts already flagged: 458 otherwise-fine transcripts
also start at 15 s or later, so it is not a signal on its own), and the ten whose last cue starts
after the node's duration.

**Rules decided 2026-10-21:**

1. **Rule 1 is extended to a last unit whose non-zero end is before its start.** The stray ends
   repeat: 185.292, 56.628, 210.390 and 413.250 s account for 215 of 222 such cases, and
   `00:03:05.292` is in 166 source files, so they were typed or exported into the files. 212-214
   transcripts have a duration at or after the last start and are repairable.
2. **Ten stay in review** (last cue starts after the node's duration). In four the stray end equals
   the duration, so the start is the wrong value and setting the end would hide it (for example
   node 20236, a 5:09 recording with cues around 1:02:55, about an hour late).
3. **Fully untimed transcripts (62) are a separate list, "Needs timecodes".** The source files
   carry no timing at all (no `-->` cues, no `<AUDIO start end>`); they do not highlight in the
   reader. 15 are single-unit stubs (node 502's one unit is a lone tsheg).
4. **Untimed units at the end of a transcript that are empty (no text in any tier) go to OK.** Only
   5 of the 25 trailing-untimed transcripts qualify (3938, 369, 372, 31, 32); the other 20 have real
   text with no time and stay in review.
5. **A single untimed unit between two timed units: start = the previous unit's end, end = the next
   unit's start** (new repair rule). The text is real and the neighbours bracket it (node 517: a
   1.33 s gap; node 796: 6.5 s). Runs of two or more untimed units are not covered. Only one
   transcript qualified outright under the draft lists, because most candidates also carry another
   review reason (see the classifier artifact below).

**Classifier artifact found.** The unit just before an untimed unit is compared with a "next start"
of 0, so a unit ending after 600 s is flagged `end_implausible`; this probably explains the flag on
node 796 and several other single-gap cases. Separately, an untimed unit's start of 0 looks like a
start earlier than the previous one if not skipped (it inflated the out-of-order count from 141 to
186 in the first draft). Both are in the audit, not in the stored data. `TimecodeClassifier`
should skip untimed units when picking the neighbour's "next start" before the repair rule relies
on `end_implausible`. Not changed here.

**Orphan trids:** see [transcript-orphan-trids-keep-for-review](transcript-orphan-trids-keep-for-review.md)
(19 matched to files; 16 superseded, 3 unplaced; plan recorded, not carried out).

**Still open:** the 97 anomalous files; where backups of the original transcripts are kept; the
paired-cue pattern (about 37 transcripts like node 2067; only 2067 was opened); the middle and
leading untimed units not covered by rule 5; the 20 transcripts with trailing untimed units that
have text; the 4 placeholders followed by another bad cue; and the workbook itself.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): corpus audit and data-model evaluation
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T5 (migration), T6 (editor)
