# Spike 11: AV Transcript Replication on Drupal 11
**Status:** Pending
**Date:** —
**Branch/commit:** —

## Theory
Mandala's D7 AV **time-synced transcripts** — timecoded text segments, potentially
multilingual (incl. Tibetan), searchable, and synchronized to Kaltura playback — can be
replicated on D11 with equivalent capability (authoring, timecode↔playback sync, multilingual
display, and search) using a D11-native data model plus a defined display/sync mechanism,
without loss of function. This spike's job is to (1) reverse-engineer how D7 actually does it and
(2) recommend the best D11 mechanism to reproduce it.

## Background

The D7 AV site pairs Kaltura-hosted media (see [Spike 7](spike-07-kaltura-av-integration.md))
with **transcripts** — text keyed to points in the audio/video so that, during playback, the
transcript scrolls/highlights in sync and (typically) clicking a line seeks the player to that
timecode. In Mandala/SHANTI these are often scholarly, multilingual transcripts (e.g. Tibetan
source + translation), and they are usually **searchable** so a user can find a moment inside a
recording by its words.

This spike concerns the **transcript layer specifically** — distinct from, but dependent on,
Spike 7's media integration. Spike 7 answers "how does the media play on D11"; Spike 11 answers
"how do the time-synced transcripts that accompany that media get modeled, displayed, synced,
searched, and migrated on D11."

**What is not yet known (this spike resolves it):**
- The D7 storage model — is a transcript its own content type / node, a set of paragraphs on
  the AV node, a field, or an external/sidecar artifact? What is the timecode granularity and
  format (per-line start/end? cue points? WebVTT)?
- The display/sync mechanism — custom JS transcript viewer? Kaltura captions/cue-points? a
  component in the React/KMaps app? server-rendered?
- Multilingual handling — how are parallel languages (Tibetan + translation) represented, and
  does this carry the same NFC/NFD fidelity concerns as [Spike 4a](spike-04a-tibetan-unicode-roundtrip.md)?
- Search — are transcripts indexed in Solr (segment-level or document-level), and does that ride
  the kmassets pipeline or a separate index?
- Volume — how many recordings have transcripts, and how large.

## Work

1. **Reverse-engineer the D7 transcript data model** (DB + legacy code, `mandala-drupal` AV
   site): identify the content type/fields/paragraphs holding transcript text and timecodes, the
   timecode format and granularity, the language representation, and the relationship linking a
   transcript to its AV node and Kaltura entry ID.
2. **Determine the D7 display/sync mechanism**: how the running site renders the transcript and
   keeps it in sync with the player (custom JS, Kaltura cue points/captions, a dedicated viewer
   widget, or the standalone React app). Capture click-to-seek and highlight-on-playback
   behavior.
3. **Assess search**: whether transcripts are indexed (Solr/kmassets), at what granularity
   (whole-transcript vs. per-segment), and how results deep-link back to a timecode.
4. **Evaluate D11 data-model options** (weigh authoring UX, multilingual, search, migration):
   - **Paragraphs** (timecoded segment paragraphs) on the AV node/Media entity.
   - **Dedicated transcript entity / content type** referenced by the AV node.
   - **Kaltura-native captions / cue points** (transcript lives with the media, not in Drupal).
   - **Sidecar caption files** (WebVTT/SRT) attached to the Media entity + a viewer.
5. **Evaluate D11 display/sync options**: core Media + a `<track>` WebVTT caption, a custom field
   formatter + JS transcript viewer, or the **React/KMaps app** rendering transcripts
   client-side (tie-in to [Spike 6](spike-06-api-compatibility.md) — if transcripts are fetched
   via the node-JSON/API path, the mechanism is coupled to that spike).
6. **Multilingual / Tibetan fidelity**: confirm parallel-language transcripts round-trip
   correctly (ties to [Spike 4a](spike-04a-tibetan-unicode-roundtrip.md)).
7. **Migration path**: define how existing D7 transcript data → the chosen D11 model, including
   timecode preservation and volume.
8. **Recommend** the best end-to-end mechanism (data model + display/sync + search + migration)
   with a go/no-go and an implementation sketch for the AV phase.

## Pass Criteria

- The D7 transcript model, sync mechanism, and search approach are documented from real data/code.
- A recommended D11 mechanism is chosen, with rationale against the alternatives.
- A **minimal D11 prototype** demonstrates timecode↔playback sync for at least one sample
  transcript (click-to-seek and/or highlight-on-play).
- Multilingual/Tibetan handling is confirmed for the chosen model.
- A search strategy (index granularity + deep-linking to timecodes) is defined.
- A migration strategy and rough volume for existing transcripts are defined.

## Fail Criteria and Response

| Finding | Response |
|---|---|
| D7 transcript model is undocumented / inconsistent across recordings | Audit the full AV transcript corpus before designing the D11 model; document edge cases |
| Transcript sync is tightly bound to a D7-only JS widget with no D11 analogue | Prototype a replacement viewer (WebVTT `<track>` or a small custom component) and scope it as AV-phase work |
| Transcripts are Kaltura-native (cue points/captions), not in Drupal | Evaluate reading them back via the Kaltura API for display/search; coordinate scope with Spike 7 |
| Segment-level search requires a new/separate Solr index shape | Scope the index change with the Solr owner; decide whether MVP does document-level search only |
| Multilingual transcripts carry NFC/NFD or alignment issues | Fold into Spike 4a's normalization approach; test parallel-language round-trip explicitly |
| Migration would lose timecode precision or alignment | Escalate as a fidelity risk to David Germano — transcript integrity is scholarly-critical |
| Transcript rendering belongs in the React app, not Drupal | Coordinate with Spike 6; define the API contract for transcript + timecode delivery |

## Outputs

- Documented D7 transcript data model, sync mechanism, and search behavior.
- A recommended D11 mechanism (data model + display/sync + search) with rationale.
- A minimal working D11 prototype of timecode↔playback sync.
- Multilingual/Tibetan fidelity confirmation.
- Migration strategy + corpus volume for existing transcripts.
- Go/no-go recommendation and implementation sketch for the AV (Phase 4) work.

## Relationships

- **[Spike 7 — Kaltura AV integration](spike-07-kaltura-av-integration.md)** — the media layer
  transcripts attach to; run or coordinate together.
- **[Spike 4a — Tibetan Unicode round-trip](spike-04a-tibetan-unicode-roundtrip.md)** —
  multilingual transcript fidelity.
- **[Spike 6 — API compatibility](spike-06-api-compatibility.md)** — if transcripts render in
  the React/KMaps app via the node-JSON/API path.
- **[Spike 2 — Solr integration](spike-02-solr-integration.md)** — transcript search.

## Deferred notes

*(To be filled in after the spike runs.)*
