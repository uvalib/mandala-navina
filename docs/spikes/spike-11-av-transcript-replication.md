# Spike 11: AV Transcript Replication on Drupal 11
**Status:** Partial — corpus audit done (2026-10-01); data-model, prototype, search and migration work not started
**Date:** 2026-10
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
  `type`, `id`, `status`, `tiers`) is tracking metadata only. **~~Content lives only in
  the uploaded file~~ — corrected by the 2026-10-01 audit below: the parsed content is
  stored in the D7 database in the `tcu`, `tcu_tier` and `tcu_speaker` tables**, and is
  also indexed into a separate Apache Solr core via `transcripts_apachesolr`.
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

## Corpus audit (2026-10-01, against the `d7_av` production dump loaded in DDEV)

Read-only SQL against the 2026-09-01 AV dump. Counts are aggregate; nothing here
identifies individuals. **Not covered yet:** the D7 Solr index shape, a sample of
real source files, and the `transcripts_editor` feature surface.

**Where the content lives (corrects the earlier assumption).** D7 already stored the
converted transcripts in the database: `tcu` (245,158 rows: `trid`, `start`, `end`),
`tcu_tier` (355,536 rows: `tcuid`, `tier`, `value`) and `tcu_speaker` (142,445 rows).
A D11 migration can read these tables directly. It does **not** need to re-run the
Saxon XSLT pipeline or parse the uploaded Toolbox/SRT/XML/VTT files.

**Volume.**
- 5,343 tracked transcripts, exactly one per node (4,231 video, 1,112 audio); every
  one has TCUs (mean 46, max 1,380 per transcript).
- 5,380 nodes have a `field_transcript` file (4,252 video, 1,128 audio), but only 5,272
  of those files are tracked, so about 108 attached files were never processed. This is
  the gap between "file attached" (the audit's 46.4%) and "transcript exists".
- 19 transcript ids have TCUs but no tracking row (orphans from 2016-2021, 1-526 TCUs
  each); not yet investigated. Probably stale rows from replaced uploads.

**Source formats** (tracked files): `.vtt` 3,227, `.xml` 1,881, `.txt` (Toolbox) 136,
`.srt` 28. WebVTT is the majority format; Toolbox is about 2.5%. The "Live evidence"
framing above (Toolbox as the headline format) overstates it.

**Tiers are language-coded parallel text, not the Toolbox tx/mb/ge/ft set.** By tier
value count: `content_bod` (Tibetan) 173,764; `ts_content_eng` 71,873; `dzo_bod`
(Dzongkha) 35,525; `ts_content_wylie` 30,497; Nepali 13,625; Chinese 5,191; ten smaller
languages. `ts_content_gloss` has only 3,491 values, so interlinear gloss is minor.

**Authoring is nearly dormant.** TCUs were created 2015-2024, peaking 2018 (81,717).
Since 2021 only 38 transcripts were created (about 3,900 TCUs), the last in 2024. No
TCU has ever been edited (`changed` equals `created` for all 245,158 rows). Whether
this means the editor is unused or just that corrections never happened is not
established. This supports, but does not settle, the migration-only option for
Work item 2; it needs confirmation from the people who run AV.

**Timecode quality (a migration-fidelity risk, escalate per the fail table).**
- 3,299 TCUs have zero length (start = end), 970 have end before start, 1 has a
  negative start (-0.125). Together 4,269 TCUs across 679 transcripts, concentrated in a
  few transcripts (the worst has 406).
- 190 TCUs end after 4 hours (maximum 81,807 s, about 22 h), so some are certainly bad
  values; 113 TCUs span more than 10 minutes.
- Zero-length rows may be deliberate point cues; this needs checking against a real
  file before treating them as corruption.

**Unicode (relates to Spike 4a).** Not NFC: 933 of 35,525 Dzongkha tier values (2.6%),
12 of 173,764 Tibetan, 0 of 30,497 Wylie. None are pure NFD. The normalization approach
from Spike 4a should be applied at migration; round-trip through the chosen model is
still to be demonstrated.

## Prototype: timecode-to-playback sync (2026-10-01)

Module `drupal/web/modules/custom/spike_transcript_demo` (throwaway, not enabled in
`config/sync`). Route `/spike/transcript-demo/{legacy_nid}`. It resolves the migrated
D11 node by `field_legacy_site` + `field_legacy_nid` (ADR 017), embeds the Kaltura player
through the existing `mandala_kaltura` preset, and reads that node's TCUs **straight from
the D7 source DB** (`migrate_av` connection), so no file parsing is involved. A small
JS behavior renders the TCUs as a list and binds to the player.

**Tested** on D7 nid 218 (D11 node via legacy key; 41 TCUs, Tibetan + Wylie + English
tiers, 316 s) in Chrome against the real Kaltura entry, on DDEV:
- All 41 TCUs rendered with the three tiers; Tibetan script displayed correctly.
- **Click-to-seek:** clicking the `0:16` timestamp moved the player to 0:16 and
  started playback; that row was highlighted.
- **Highlight-on-play:** after seeking to 0:31 through the player API with no further
  input, the highlight moved from the 0:16 row to the 0:34 row about 3.75 s later
  (expected 3 s), via the `playerUpdatePlayhead` event.

**Not established.** One node, one player preset (`31832371`), one browser. The
highlight uses "last TCU whose start is at or before the playhead", so the zero-length
and inverted-end TCUs found by the audit did not matter here, but this node had none.
Search, deep-linking to a timecode, nodes with speakers, very long transcripts (max
1,380 TCUs) and the 22-hour bad-timecode cases are untested. The player paused on its
own at times during automated testing; that was not investigated (likely the
non-foreground tab), so playback itself was driven partly through the player API.

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
