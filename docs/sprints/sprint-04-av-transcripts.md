# Sprint 4: AV transcripts (D7 authoring/rendering pipeline replication)

**Status:** ○ Planned — not started. **Blocked on [Sprint 3](sprint-03-av-core-implementation.md)**
(needs real migrated `audio`/`video` nodes to attach transcripts to) **and on
[Spike 11](../spikes/spike-11-av-transcript-replication.md)** (still Pending).
**Phase:** [Roadmap](../roadmap.md) Phase 3 (AV), the second of AV's two independently-
scheduled sprints per [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md).
**Lead:** Yuji Shinozaki (tentative — same as Sprint 3; not yet confirmed as a separate
assignment).
**Mode:** Individual.
**Relates to:** [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md),
[Spike 11](../spikes/spike-11-av-transcript-replication.md) (this sprint builds what
Spike 11 recommends), [Spike 4a](../spikes/spike-04a-tibetan-unicode-roundtrip.md)
(multilingual/Tibetan fidelity, already proven — reused here, not re-proven),
[Spike 2](../spikes/spike-02-solr-integration.md) (transcript search), **[Sprint 3](sprint-03-av-core-implementation.md)
— this sprint depends on Sprint 3's content type and migrated nodes; Sprint 3 does NOT
depend on this sprint.**

---

## Goal

Replicate D7's transcript **authoring/rendering pipeline** — Toolbox/SRT/XML source
files → Java Saxon XSLT transform → multi-tier TCUs (Time-Coded Units) → a
scroll-synced, searchable viewer — on D11, scoped to the Drupal-side system only. The
React app's independent transcript viewer (a separate client-side engine reading its
own `mandala-av` Solr core) is deliberately out of scope; see Spike 11's 2026-09-04
scope note for why and how to revisit that decision later.

This sprint exists as a separate, later unit specifically because `field_transcript` is
a plain file field on the D7 `audio`/`video` node — Sprint 3 migrates it inertly, so
this sprint's job is entirely the processing/display/search layer on top of nodes that
already exist, not the node migration itself.

## Scope boundary

| In scope (Sprint 4) | Out of scope |
|---|---|
| Spike 11's central open question: reproduce the XSLT authoring pipeline as a live workflow, or convert existing transcripts once at migration time? | The React app's independent transcript viewer / `mandala-av` Solr core reconciliation (system 2) — deliberately deferred |
| D11 data model for TCUs (Paragraphs vs. a dedicated entity vs. sidecar WebVTT — Spike 11 to recommend) | Spike 6 (API/URL reconciliation) — not assumed relevant unless system 2 comes back in scope |
| Display/sync mechanism (scroll-synced viewer matching the D7 pattern, or a WebVTT `<track>`) | Kaltura-native captions/cue-points, unless Spike 11's fail-criteria table routes there |
| Multilingual/Tibetan fidelity for parallel-language transcripts (tx/mb/ge/ft tiers), reusing Spike 4a's proven approach | A new Tibetan round-trip proof — Spike 4a already closed this generally |
| Search-within-transcript, indexed appropriately (whole-transcript vs. per-TCU) | Segment-level search requiring a new Solr index shape beyond MVP, unless explicitly scoped in during Spike 11 |
| Migration of existing D7 transcript content (all three source formats) into the chosen D11 model, with timecode fidelity preserved | Live editor UI for TCU authoring/correction, **unless** Spike 11 decides the authoring pipeline must be reproduced live (see the first row) |

## Backlog

| | Task | Depends on | Status |
|---|---|---|---|
| T1 | Run Spike 11 in full: confirm TCU shape/volume per input format against real data; resolve the authoring-pipeline-vs-migration-time-conversion question; evaluate D11 data-model and display/sync options; confirm multilingual fidelity; define a search strategy and migration path | Sprint 3 (for a real node to prototype against); Spike 11's "live evidence" section (already recorded) | ○ |
| T2 | Build the chosen D11 transcript data model on the AV content type | T1, Sprint 3 AV2/AV3 | ○ |
| T3 | Build the display/sync mechanism (field formatter + JS, or WebVTT `<track>`) | T2 | ○ |
| T4 | Wire search indexing (Solr/kmassets integration, or a dedicated index if Spike 11 finds the D7 shape requires one) | T2 | ○ |
| T5 | Migrate existing D7 transcript content (all three formats) into the chosen D11 model; replace Sprint 3's inert `field_transcript` placeholder | T2–T4, Sprint 3 AV4 | ○ |
| T6 | If Spike 11 decides live multi-format authoring must be reproduced: build an editor UI equivalent. Otherwise, record the explicit decision that transcripts become migration-only content in D11 | T1 | ○ |

## Acceptance criteria

- [ ] Spike 11's own pass criteria are all met (documented D7 mechanism, a chosen D11 mechanism with rationale, a working prototype, multilingual confirmation, a defined search strategy, a defined migration strategy)
- [ ] Existing D7 transcripts migrate with timecode fidelity preserved, spot-checked against real source files from each of the three input formats (Toolbox, SRT, XML)
- [ ] Multilingual/Tibetan parallel-tier transcripts round-trip correctly (verified against Spike 4a's approach, not re-derived)
- [ ] The transcript viewer renders and stays in sync with Kaltura playback on a real migrated AV node from Sprint 3
- [ ] Search-within-transcript works and deep-links to the correct timecode
- [ ] A decision is recorded (not left implicit) on whether live multi-format transcript authoring is reproduced in D11, or transcripts are migration-only content going forward

## References

See the **Relates to** line above. This sprint closes Phase 3's transcript half of
ADR 018; [Sprint 3](sprint-03-av-core-implementation.md) is the AV-core half this
sprint depends on.
