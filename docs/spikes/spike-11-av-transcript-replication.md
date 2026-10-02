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
2. **The React app's (`mandala-om`/`kmaps-app`) client-side transcript viewer**
   (`src/legacy/audiovideo.js`) — **its UI is out of scope for building** (decided
   2026-09-04, Yuji). It fetches from `REACT_APP_SOLR_TRANSCRIPTS` (`/solr/mandala-av`)
   and has its own sync, search and download logic.

   **Correction (2026-10-02): the data is not independent of system 1.** The first version
   of this note called the viewer "entirely decoupled" and its core a separate index. It is
   not. D7's AV site writes its TCU documents to that same core (its
   `apachesolr_environment` points at `.../solr/mandala-av`; `tcu.module` writes `is_trid`
   and `fts_start`), and the React viewer queries `is_trid:<trid>` sorted by `fts_start`.
   The `mandala-av` configset is the Drupal ApacheSolr schema. So the viewer is a second
   consumer of the documents the D7 pipeline indexes. **Consequence:** when D7 is retired,
   whatever D11 builds for search (T4) must keep producing compatible documents, or the
   React viewer loses its transcripts. That is a requirement on the search design, not a
   separate project (confirmed as a requirement 2026-10-02, question 7). Read from the D7 dump and the legacy code; **not confirmed against the
   live core**. The viewer also already filters out `fts_start = 0` units (MANU-7271, 2022),
   i.e. it hides some of the same bad-timecode data found in the audit.

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
- 5,380 nodes have a `field_transcript` file (4,252 video, 1,128 audio). Reconciled
  2026-10-02 against the 5,343 tracked transcripts (`transcripts_apachesolr_transcript`,
  all status 1): **5,271** nodes' current file is the file their units came from;
  **68** have a *different* current file, and the file the units came from no longer has a
  `file_managed` row; **41** have a file but were never processed (no transcript, no
  units); **4** tracked transcripts have no current file attached (3 of them also have no
  source-file record). So **71 transcripts (68 + 3) have units but no record of their
  source file** (the 68 are resolved by treating the current file as the original, see question 5), and 41 nodes have a file with no units. This replaces the earlier
  "about 108 never processed" figure, which was computed by file id and mixed the two
  groups. Checked against database records only; whether the files exist on disk or S3 has
  not been checked.
- 19 transcript ids have TCUs but no tracking row (orphans from 2016-2021, 1-526 TCUs
  each); not yet investigated. Probably stale rows from replaced uploads.

**Source formats** (tracked files): `.vtt` 3,227, `.xml` 1,881, `.txt` (Toolbox) 136,
`.srt` 28. WebVTT is the majority format; Toolbox is about 2.5%. The "Live evidence"
framing above (Toolbox as the headline format) overstates it.

**Tiers are language-coded parallel text, not the Toolbox tx/mb/ge/ft set.** By tier
value count: `content_bod` (Tibetan) 173,764; `ts_content_eng` 71,873; `dzo_bod`
(Dzongkha) 35,525; `ts_content_wylie` 30,497; Nepali 13,625; Chinese 5,191; ten smaller
languages. `ts_content_gloss` has only 3,491 values, so interlinear gloss is minor.

**Authoring: new transcripts are rare, but editing is live (corrected 2026-10-02).**
TCUs were created 2015-2024, peaking 2018 (81,717). Since 2021 only 38 transcripts were
created (about 3,900 TCUs), the last in 2024. The first version of this audit also said
no TCU had ever been edited because `changed` equals `created` for all 245,158 rows.
**That inference was wrong:** the D7 editor does not touch `tcu.changed`; it marks the
*node* with the `keep_transcript_edits` flag (`transcripts_editor_mark_edited`). That flag
is set on **540 nodes, with flaggings from 2016-01-06 to 2026-04-30**, so staff were
correcting transcripts as recently as five months before this note. Editing is in use.
Consequences: Than decided on 2026-10-02 that the editor is kept (see Questions for Than);
and the 540 flagged nodes carry human corrections that a re-import from the source
files would overwrite, which is one more reason to migrate from the database rows and to
carry the flag across.

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

## Data-model evaluation (2026-10-01)

**Recommendation (provisional, pending the authoring decision and review by Than and
Yuji): Option B, a dedicated transcript entity plus one row per TCU, keeping D7's own
shape.** Reasoning and the evidence behind it follow.

### What the model has to carry (from the audit)
- One transcript per node (5,343); 245,158 TCUs total, 1 to 1,380 per transcript.
- Each TCU is `start`, `end`, plus **a variable set of language tiers** (17 distinct
  tier names; 75 distinct tier combinations across transcripts; 1.45 tiers per TCU on
  average, at most 5) and **speaker values** (14 distinct speaker tier names; 142,445
  rows; 32,919 TCUs carry more than one speaker row).
- Total text is small: about 58 MB across 355,536 tier values (mean 78 characters,
  maximum 10,611). The largest transcript is 336,543 characters.
- D7 itself models this as a **dedicated non-fieldable `tcu` entity** (base table
  `tcu`, plus `tcu_tier` and `tcu_speaker` key/value tables), indexed one Solr document
  per TCU into the AV site's own core (`fts_start` sort field). Read from the D7
  module code; not confirmed against the live core.

### Options against the evidence

| Option | Verdict | Why |
|---|---|---|
| **A. Paragraphs (one per TCU)** | Reject | 245,158 paragraph entities at minimum. Tiers are variable, so either ~31 sparse fields on one type or nested paragraphs per tier value (roughly 740k entities). Each host-node revision save copies the paragraph tree (reasoned from how Paragraphs revisions work, not measured here), and the node edit form would try to render up to 1,380 widgets. **Performance is not the objection:** loading 1,380 simple Paragraph entities took 189 ms and 49 MB on DDEV, against 29 ms for one SQL query for the largest transcript's 2,760 tier rows. The objection is entity count, revision multiplication and editing UX. |
| **B. Dedicated transcript entity + TCU rows** | **Recommend** | Mirrors D7, so migration is a near 1:1 copy and can be verified by row counts (245,158 in, 245,158 out). One indexed range read gets a transcript in order. A stable per-TCU id gives search results a deep link. Leaves room for editing later if AV staff want it. 245k small rows is trivial for MySQL. |
| **B2. One row per transcript with the TCUs as a JSON blob** | Viable alternative | Only 5,343 rows, one read per transcript, simplest migration. Costs: no stable per-TCU id for deep links unless one is synthesised, no per-TCU edit or constraint, and an opaque column. Reasonable if editing is confirmed out of scope. |
| **C. Sidecar WebVTT or JSON files per language** | Reject as the store | WebVTT requires each cue's end to be after its start, but **4,269 TCUs have zero-length or inverted timecodes** and would be invalid or silently altered. Per-language files also split the shared time spans the tiers sit on, and carry speakers poorly. A WebVTT file can still be **generated** from the model later if a `<track>` is wanted. |
| **D. Kaltura-native captions or cue points** | Reject (reasoned, not checked live) | Same WebVTT limits, plus 5,343 transcripts times several languages pushed into Kaltura, a second access-control surface next to the AV7 enforcement, and Spike 7's finding that uploads are a separate path. The audit did not look for existing transcripts inside Kaltura; if any exist, revisit. |

### Sketch for the recommended model (for T2)
- `av_transcript`: node reference, source file reference, source format (vtt/xml/txt/srt),
  tier list, legacy transcript id (`trid`), `has_edits` and `edits_flagged_at` (from D7's
  `keep_transcript_edits` flag, decided 2026-10-02), workflow flags. Access follows the parent node
  (the AV7 rules); the viewer and any API must check node access, never expose TCUs on
  their own.
- `av_tcu`: transcript reference, sequence number, `start`, `end`, tier map (tier name to
  text), speaker map, legacy TCU id. Tier names kept as D7's values.
- Timecodes: store as `DECIMAL(10,3)`. D7 uses single-precision `FLOAT`, so sub-millisecond
  digits are float noise, not data, but this has not been verified value by value.
- **Do not repair the 4,269 bad timecodes at migration.** Carry them as found. Add a
  `timecode_status` on `av_tcu`, computed deterministically at migration so a re-run gives
  the same answer: `ok`, `zero_length_shared_start`, `zero_length_other`, `inverted`,
  `last_unit_no_end`, `end_implausible` (the 190 over 4 hours), and later `corrected`.
  Keep the D7 value in `end_original` whenever a fix is applied. Decided 2026-10-02: the
  fixup is deferred (see "Bad timecodes" under Questions for Than and the deferred note).
  Where the bad values have to be accounted for:
  - **Migration verification:** row counts must include flagged units (245,158 in, 245,158
    out), and the per-status counts are recorded so the number can be watched going down.
  - **Display and sync:** the active-unit rule is "last TCU whose start is at or before
    the playhead". With zero-length units that share a start with their neighbour, only the
    last unit of such a stack can ever be highlighted. The prototype did not meet this case
    (its node had no bad units), so it must be tested on a flagged transcript before T3.
  - **Editor (T6):** flagged units must stay editable, and editors need a "needs timecode
    review" marker or filter. The editor is the natural place to fix them one transcript at a time.
  - **Readers:** the status is for staff and never shown on public pages.
  - **Search:** indexing must not depend on `end`, so a bad end never drops a unit.
- Search: index one Solr document per TCU (matching D7) or per transcript; this is the
  next open question and depends on the Solr owner (see the Spike 2 relationship).
- Unicode: normalise to NFC at migration per Spike 4a (933 Dzongkha values are not NFC).

### Still open before this can be called decided
- ~~The authoring question.~~ Settled 2026-10-02: editing is kept, so Option B stands
  and B2 is out (see Questions for Than).
- Search index shape and where it lives (D7 used a per-site core; D11 has the flat
  kmassets documents and the visibility proxy).
- The prototype read the D7 tables directly; it has not been run against the proposed
  D11 entities.

## Questions for Than (decisions are his; recorded 2026-10-01, answers 1-3 added 2026-10-02)

The data decisions below belong to Than. The recommendation above is Claude's reading of
the audit, not a decision.

1. **Authoring. ANSWERED 2026-10-02 (Than): keep the editing UI; do not drop it.** The
   D7 editor is in live use (540 nodes flagged as edited, latest 2026-04-30; see the
   corrected audit finding). Sprint 4 T6 is therefore in scope: an editor equivalent to
   D7's (in-place tier edit with optimistic locking, speaker edit, time edit, insert
   before/after, copy, delete, per-node and per-tier disable hooks, access following
   node-edit permission, immediate reindex). Open follow-ups for Than: who the editors are,
   and whether the `keep_transcript_edits` flag should migrate as a visible marker.
2. **Data model. ANSWERED 2026-10-02 (Than): one row per TCU, following D7's design.**
   Option B stands; B2 (JSON blob) is out because editing is kept. Paragraphs, sidecar VTT
   and Kaltura captions stay rejected.
3. **Bad timecodes. DECIDED 2026-10-02 (Than): carry as found now; fix later.** Than's
   proposed fixup rule is "end = start of the next TCU". Measured on the D7 dump, that
   rule only covers 383 of the 4,269 bad TCUs: 3,487 share their start with the next TCU
   (the rule would give a zero-length unit again) and 399 are the last unit of their
   transcript (no next TCU). The investigation and the fix are deferred to
   [a deferred note](../deferred/transcript-bad-timecodes-investigate-and-fix.md); the data
   model carries a `timecode_status` and `end_original` so nothing is lost meanwhile. Any
   correction beyond Than's rule still goes to David Germano.
4. **Source files.** Please supply a few real files per format (VTT, XML, Toolbox, SRT),
   including one of the high-count transcripts, to check the TCU shape against the
   database rows.
5. **Unprocessed and replaced files.** Numbers corrected 2026-10-02 (see the audit): 41
   nodes have a transcript file that D7 never processed into units; 68 nodes have a
   replaced file, so their units came from an earlier upload that is no longer recorded;
   19 transcript ids have units but no tracking row. **DECIDED 2026-10-02 (Yuji): for the 68
   replaced files, treat the current attachment as the original of record** and ignore the
   missing earlier file. This is an assumption that the current file is the right source;
   whether D7 ever processed it is unknown, so the stored units may differ from what the
   current file parses to. T7's inventory compares the two for these 68 and reports
   differences; it does not rewrite the migrated units. The rest (the 41 never
   processed, the 19 orphan ids, the 3 with no file) is **deferred and tracked** in
   [a deferred note](../deferred/transcript-source-file-accounting.md) (2026-10-02).
6. **Search. PARTLY DECIDED 2026-10-02 (Yuji): D11 gets a completely new and separate Solr
   core for transcript units**, compatible with the existing React client. It does not write
   to D7's `mandala-av` core. "Compatible" means the document shape and query interface the
   client uses (`is_trid`, `fts_start`, `fts_end`, `fts_duration`, language-tier and speaker
   fields; `select` by `is_trid`, up to 1,000 rows). Extra fields are allowed, so D11 can add
   its own (for example a node id and an access field). Consequences: no write collision with
   D7 at cutover; the D7 core is left to be retired with D7; the client is pointed at the new
   core through `REACT_APP_SOLR_TRANSCRIPTS`. Still open: the core's name and schema source,
   document id scheme, access enforcement, the write path and edit freshness, who creates
   the core. Decided 2026-10-02: D11 needs the same search features as D7 (see below).
### Search features D7 offers, which D11 must reproduce (decided 2026-10-02, Yuji)

Decision: **D11 gets the same search features as D7.** Read from the D7 module code and the
`d7_av` dump; the block and search page below are enabled in the dump, but actual usage is
unknown and none of it was run live.

1. **Search within one transcript:** a term is matched as a phrase against every tier;
   matches are wrapped in `<mark>`, the full tier text is returned (no snippet cut-off), and
   a "hits only" option shows just the matching units.
2. **Cross-transcript search:** a "Transcripts" search page (`search/transcripts`) over
   unit documents, with a "Search Transcripts" block enabled in the AV site's themes. Each
   tier is queried with equal weight (`qf` of every tier at 1.0). Results show a highlighted
   unit snippet, and a result links to the node at `#tcu/{unit id}` (the
   `transcripts_apachesolr_redirect` deep link). Sorts: temporal order (`fts_start`), and
   transcript title (D7's title field was commented out as a FIXME in `tcu_solr_document`,
   so title sort may never have worked).
3. **"Transcript Languages" facet** (`sm_has_tier`): **live and populated** (verified
   2026-10-02 against the live `mandala-av` core). 5,342 **node** documents carry
   `sm_has_tier` (one value per language tier the transcript has; 0 unit documents do).
   Counts by value: `content_bod` 2,891, `ts_content_eng` 2,095, `dzo_bod` 1,787,
   `ts_content_wylie` 611, `ts_content_und` 483, Nepali 146, Chinese 80, plus ten smaller
   languages and 8 empty values. It is the **only** transcript-related facet enabled on the
   D7 search page (`transcript_languages`, a facet block); no tier or speaker facets are
   enabled, so nothing else covers that function. An earlier version of this note said
   nothing writes this field and that the facet was probably empty. **That was wrong**: that
   conclusion came from a code search that did not find the writer (it is not in the module
   code available here; it is probably in another module or an indexing hook). The live data
   shows it works. D11 does not need D7's writer, only the same data: the per-transcript tier
   list that D7 keeps in `transcripts_apachesolr_transcript.tiers`. **Verified 2026-10-02:** the
   live facet counts for the six largest tiers equal the counts from that column exactly
   (2,891 / 2,095 / 1,787 / 611 / 483 / 18). Small unexplained difference: the column has 9
   transcripts with no tiers, the core 8 empty values and 5,342 nodes against 5,343
   transcripts. D11 should recompute the value on every save, since an edit can add a tier;
   when D7 refreshed it was not established.
4. **Per-transcript tier list:** the viewer shows only the tiers a transcript actually has.

**What the D7 `mandala-av` core is (Yuji, 2026-10-02; not verified against the live core).**
It is the Drupal ApacheSolr module's index. Live counts (2026-10-02): 256,975 documents,
245,159 units and 11,816 nodes (video 7,402, audio 4,194, collection 131, subcollection 85,
page 4). The D7 sites do not use the node documents for asset discovery or search, which go
through the **kmassets** core. **The transcript units are, as far as Yuji knows, the only
real use of Drupal Solr.** The node documents are not wholly idle, though: they are where
`sm_has_tier` (the language facet) lives. So for D11 the unit documents are what matters,
and the per-transcript language set has to come from somewhere other than a copy of the
Drupal node documents. The new core should not copy the Drupal ApacheSolr schema.

**Access.** The new core must enforce the same visibility rules as the rest of D11 (see
question 6). A separate concern about the legacy core's access is tracked privately; ask
Yuji Shinozaki.

Design consequences for the new core (it needs unit documents only; **there was no
transcript-level document in D7**, an earlier version of this section wrongly proposed one
to carry the facet):
- **Unit documents** keep the client-compatible shape and add the unit id as `entity_id`
  (D7's value, used for the deep link), a node id, and access fields.
- **Language facet:** it is live in D7, so reproduce it. The per-transcript language list is
  already part of the planned `av_transcript` record (the tier list). Options for serving it:
  facet over the unit documents in the new core (a per-transcript value on each unit, or a
  group/stats query), or from the node's kmassets document. Avoid a new document type in
  this core, or if one is added it must **not carry `is_trid`**, because the React client
  selects everything matching `is_trid:<trid>` and would receive the extra document.
- **Titles in results:** look up from Drupal by node id at render time, or denormalise onto
  the unit documents (cheap but needs reindexing when a title changes).
- **Compatibility test:** capture real responses from the D7 `mandala-av` core for a few
  transcripts and diff them against the new core's output, because the full set of fields the
  client reads has not been enumerated (only the ones in `audiovideo.js`).
- **Not yet checked:** the D7 search page names Solr environment `solr`, which is not in the
  environment table (only `mandala_library_rw` is); it presumably falls back to the default.
- **Work:** a Drupal-side search UI (page, header block, snippets, deep links) is new work
  beyond indexing, so it is added to Sprint 4 as T8.

7. **React viewer. DECIDED 2026-10-02 (Yuji and Than): the current React client must remain
   viable.** Its UI is still not part of this spike's build, but D11 must keep the
   `mandala-av` index (or an equivalent the client can be pointed at through
   `REACT_APP_SOLR_TRANSCRIPTS`) serving the documents the client already reads:
   `is_trid`, `fts_start` and the language tiers, queried by `is_trid`, up to 1,000 rows,
   through the visibility proxy. Consequences for the design: (a) question 6 (search shape)
   is constrained to per-TCU documents in that shape; (b) editor saves (T6) must reach
   that index, as D7's real-time reindex did, or the client shows stale text; (c) the
   client's `fts_start = 0` filter (MANU-7271) means flagged zero-start units stay hidden
   there, which the bad-timecode work should account for; (d) cutover needs a check that the
   client renders a migrated transcript from D11-written documents.

**Follow-ups raised by answer 1 (the editor):**

F1. **The `keep_transcript_edits` flag. DECIDED 2026-10-02 (Yuji, with Than): yes to all three.**
In D7 the flag is set automatically on a node's first edit; while set, the transcript is
excluded from the re-processing queue and the file field's remove button is hidden;
unflagging (by anyone who can edit the node) **discards the edits and re-imports the units
from the originally uploaded file** (`transcripts_editor_discard_edits`). D7 does not keep
the pre-edit units, so the original text exists only in the uploaded file.
1. **Migrate the flag as data:** a boolean on `av_transcript` ("has human corrections since
   upload") plus the flagging date (about the first-edit date), from the 540 flagged nodes.
2. **Revert-to-upload is a required D11 feature.** This is new scope: with no XSLT in D11,
   it needs a parser per source format (VTT 3,227 files, XML 1,881, Toolbox 136, SRT 28),
   producing the same unit shape as the migrated rows. Added to Sprint 4 as T7. It also
   makes question 4 (real source files per format) a prerequisite, and means the uploaded
   source files must be kept and stay reachable for every transcript.
3. **Show it to staff:** an "edited" marker in the editor, staff only, never on public
   pages. It can share the "needs timecode review" marker surface.

**Source-file inventory (follows from F1.2, raised 2026-10-02).** Revert-to-upload means
every original transcript must be accounted for, not just those that migrate. Reconciliation
so far (database records only): of the 540 edited nodes, 539 have a source file record that
matches their current file, so revert is possible for them if the files exist; **1 is
missing its source record and 1 has a replaced file** (the same node can be in both; not
checked). Before T7 the inventory must also confirm, for all 5,343 transcripts plus the 41
unprocessed nodes, that the file parses to the stored units (existence on disk was
checked 2026-10-02), and list the 3 tracked transcripts with no attached file as unrevertable (the 68 with a replaced file use the current attachment as the original, decided 2026-10-02). Follow the existing
missing-file-audit pattern (`drush mandala:missing-file-audit`). The originals are on dev-0 at
`/opt/drupal/app/drupal/web/sites/default/files/transcripts` (recorded 2026-10-02; listing scan done the same day: 5,379 files, all current attachments present, see the deferred note).

F2. **Who the transcript editors are. ANSWERED 2026-10-02 (Yuji): the THL team** (Tibetan
and Himalayan Library) are the editors. Follow-ups not yet done: which D7 roles or groups
THL staff hold, and how that maps to D11 editor permission; it ties into the
contributor-CRUD and editor-permissions gaps, so it is recorded there as a dependency, not
decided here.

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

- [Transcript bad timecodes: investigate and fix](../deferred/transcript-bad-timecodes-investigate-and-fix.md) (2026-10-02)
- [Transcript source files: account for every original](../deferred/transcript-source-file-accounting.md) (2026-10-02)
