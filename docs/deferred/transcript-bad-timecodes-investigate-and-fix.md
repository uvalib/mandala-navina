# Transcript bad timecodes: investigate and fix

**Area:** migration / AV / transcripts / data fidelity
**Raised during:** Spike 11, 2026-10-01 audit; deferral decided with Than 2026-10-02
**Jira:** (add when available)
**Priority:** Medium. Must be understood before the transcript migration (Sprint 4 T5) is
called verified; does not block building the model or the viewer.

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

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): corpus audit and data-model evaluation
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T5 (migration), T6 (editor)
