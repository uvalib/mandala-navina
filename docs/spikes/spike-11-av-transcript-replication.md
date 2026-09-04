# Spike 11: AV Transcript Replication on Drupal 11
**Status:** Pending
**Date:** —
**Branch/commit:** —

## Scope note (2026-09-04)

**Two independent, non-identical transcript systems exist in production** — this spike
covers only the first:

1. **The D7 Drupal-side authoring/rendering pipeline** (`transcripts_ui` +
   `transcripts_apachesolr` + `transcripts_xslt`) — **in scope.** This is the system
   this spike replicates on D11. See "Live evidence" below for what was already found
   by reading the real code, so this spike does not have to re-derive it.
2. **The React app's (`mandala-om`/`kmaps-app`) independent client-side transcript
   viewer** (`src/legacy/audiovideo.js`), which fetches directly from a dedicated Solr
   core (`REACT_APP_SOLR_TRANSCRIPTS` → `/solr/mandala-av`) and has its own
   sync/search/download logic, entirely decoupled from the D7 module — **explicitly out
   of scope for now** (decided 2026-09-04, Yuji). Not reconciled with system 1 by this
   spike.

## Theory
Mandala's D7 AV **time-synced transcripts** — timecoded text segments, potentially
multilingual (incl. Tibetan), searchable, and synchronized to Kaltura playback — can be
replicated on D11 with equivalent capability (authoring, timecode↔playback sync, multilingual
display, and search) using a D11-native data model plus a defined display/sync mechanism,
without loss of function. This spike's job is to (1) reverse-engineer how D7 actually does it and
(2) recommend the best D11 mechanism to reproduce it.

## Live evidence available before this spike starts (found 2026-09-04, reading the real D7 module code)

Recorded here so this spike does not re-derive it — **none of it is a finding of this
spike, and none of it has been run/tested, only read.**

- **Transcripts are not simple timecoded captions — they are multi-tier interlinear
  linguistic annotation.** Source files are uploaded in one of three raw formats —
  Toolbox `.txt` (a linguistic fieldwork tool's export format), **SRT**, or arbitrary
  **XML** — and transformed at *runtime* by **shelling out to a Java Saxon XSLT
  processor** (`transcripts_xslt.module`, `saxon9he.jar`) into "TCUs" (Time-Coded
  Units). The default tier mapping —
  `tx|ts_content_qya, mb|ts_content_morph, ge|ts_content_igt, ft|ts_content_epo` — is
  transcription / morpheme breakdown / interlinear gloss / free translation, the
  standard Toolbox/FLEx tier set. This is scholarly fieldwork transcription, not
  WebVTT-shaped captions.
- **The DB table `transcripts_apachesolr_transcript`** (`trid`, `fid`, `module`,
  `type`, `id`, `status`, `tiers`) is tracking metadata only — it does not hold
  transcript content or timecodes itself. Content lives in the uploaded file, processed
  through the XSLT pipeline, then indexed into a separate Apache Solr core via
  `transcripts_apachesolr`.
- **Rendering and sync**: `TranscriptUI.php` builds a server-rendered `<ul>` of TCU
  `<li>` elements (one per tier per sentence, speaker-turn-aware), attaches
  `transcripts-ui.js` + `transcripts-scroller.js` + `jquery.scrollTo.min.js`, and syncs
  scroll position to playback via a `data-transcripts-role="transcript"` container.
  Search-within-transcript is built in (per-tier highlight, hit count, a search form
  rendered inline when a term is present).
- **There is a dedicated authoring/editing UI** (`transcripts_editor` submodule,
  including a "TCU delete" modal) — this is not read-only playback tooling, editors
  correct/manage transcripts through Drupal, which is real functionality any D11
  replacement needs to account for (or explicitly scope out).
- **Multi-format ingestion is genuinely three separate code paths** in
  `transcripts_xslt_as_tcus()` (Toolbox `.txt`, `.srt`, arbitrary `.xml`), each with
  different parameters passed to the XSLT transform — not a single normalized input
  format.

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

**What is not yet known (this spike resolves it):** — narrowed to system 1 (the D7
Drupal pipeline) only, per the 2026-09-04 scope note above.
- **Format-specific parsing detail** — the "Live evidence" section above establishes the
  three input formats (Toolbox `.txt`, SRT, XML) and that a Java Saxon XSLT transform
  produces TCUs, but not the exact TCU JSON/XML shape, timecode granularity, or how
  much real transcript content is in each of the three formats (volume per format).
- Whether the XSLT-based transform pipeline (a shelled-out Java process) is something to
  literally reproduce on D11, or whether a one-time D7→D11 migration-time conversion is
  sufficient (i.e., does D11 need ongoing multi-format authoring, or just needs to
  display/search already-converted TCU data going forward?) — an authoring-UX and
  scope question the D7 code alone doesn't answer.
- Multilingual handling — how are parallel languages (Tibetan + translation, the
  tx/mb/ge/ft tiers) represented per TCU, and does this carry the same NFC/NFD fidelity
  concerns as [Spike 4a](spike-04a-tibetan-unicode-roundtrip.md)?
- Search — the D7 pipeline indexes TCUs into its own Apache Solr core via
  `transcripts_apachesolr`; whether that's a document-level or segment-level index, and
  whether D11 rides the kmassets pipeline or needs its own index shape.
- Volume — how many AV nodes have a real transcript (the content-model audit found
  `field_transcript` present on 46.4% of nodes, but that's file-attachment presence, not
  confirmation every one is in a format the XSLT pipeline actually processes).
- The `transcripts_editor` authoring UI's real feature surface (TCU editing/deletion) —
  whether D11 needs equivalent editor tooling, or whether transcripts become
  migration-only content with no in-D11 authoring workflow.

## Work

1. **Confirm the TCU shape and volume per input format** against real production data
   (DB + a real transcript file sample from each of the three formats), building on the
   "Live evidence" section above rather than re-deriving the module's structure from
   scratch.
2. **Decide the authoring-pipeline question**: does D11 need to reproduce the
   Toolbox/SRT/XML → Saxon XSLT → TCU conversion as a live authoring path (editors
   uploading new/corrected source files), or is a one-time migration-time conversion of
   existing transcripts sufficient, with D11 authoring (if any) happening in a
   D11-native format from then on? This is the central scope decision — resolve before
   evaluating data-model options.
3. **Assess search**: the D7 pipeline's own Solr indexing granularity (whole-transcript
   vs. per-TCU/segment), and how results deep-link back to a timecode.
4. **Evaluate D11 data-model options** (weigh authoring UX, multilingual, search, migration):
   - **Paragraphs** (timecoded segment paragraphs) on the AV node/Media entity.
   - **Dedicated transcript entity / content type** referenced by the AV node.
   - **Kaltura-native captions / cue points** (transcript lives with the media, not in Drupal).
   - **Sidecar caption files** (WebVTT/SRT) attached to the Media entity + a viewer.
5. **Evaluate D11 display/sync options**: core Media + a `<track>` WebVTT caption, or a
   custom field formatter + JS transcript viewer (server-rendered TCU list +
   scroll-sync, matching the D7 pattern) — a Drupal-side viewer only; the React app's
   independent viewer (system 2) is out of scope per the 2026-09-04 decision above, so
   no [Spike 6](spike-06-api-compatibility.md) coupling is assumed here for now.
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
| Live multi-format authoring (the Saxon XSLT pipeline) turns out load-bearing — editors actively upload new Toolbox/SRT/XML source files, not just view existing transcripts | Scope reproducing the XSLT authoring pipeline (or an equivalent) as real AV-phase work, not a one-time migration step; confirm with David Germano/AV stakeholders whether this workflow is still active |

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
- **[Spike 6 — API compatibility](spike-06-api-compatibility.md)** — deliberately NOT assumed
  in scope for now (2026-09-04): the React app's own transcript viewer (system 2, a
  separate client-side engine reading a dedicated `mandala-av` Solr core) is out of
  scope until revisited. Revisit this relationship if that decision changes.
- **[Spike 2 — Solr integration](spike-02-solr-integration.md)** — transcript search.

## Deferred notes

*(To be filled in after the spike runs.)*
