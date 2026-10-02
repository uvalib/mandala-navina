# Session Log: Spike 11 decisions and the transcript core design

**Date:** 2026-10-02
**Participants:** Yuji Shinozaki, Claude Code (Than Grove and Xiaoming Wang were in the session for the first half and gave the editor and data-model answers)
**Outcome:** Reviewed the 2026-10-01 work, demoed the Spike 11 prototype, then took the Spike 11 questions for Than and the remaining search and design questions to decisions. Merged [PR #272](https://github.com/uvalib/mandala-navina/pull/272) (docs plus a first Solr schema; no deploy). Spike 11 stays Partial.

**This is an abridged, hand-written log, not a transcript.** The session probed live legacy systems and touched material tracked privately, so `save-session-log.py` was deliberately not run (the repo is public). Details that belong in the private docs repos are not recorded here; ask Yuji.

---

## What happened

1. **Review of 2026-10-01.** Pulled `main`, read both session logs and a new deferred note from Than (AV technical-metadata field audit, not started). Confirmed the drupal deploy for #269 succeeded (execution `b937d83a`) and listed recent PRs (#262 to #271, all merged).
2. **Prototype demo.** Re-enabled `spike_transcript_demo` locally for a demo to Xiaoming and Than, then disabled it again afterwards; local `config:status` was clean.
3. **Spike 11 questions, answered or decided** (all recorded in the spike doc; Than owns data decisions):
   - **Editor kept** (Sprint 4 T6 in scope). **One row per TCU**, following D7.
   - **Bad timecodes:** carry as found; fixup deferred. Than proposed "end = next unit's start"; measured, it covers only 383 of 4,269 (3,487 share a start with the next unit, 399 are last units). Data model gains `timecode_status` and `end_original`.
   - **Edit flag:** `keep_transcript_edits` migrates as data; **revert-to-upload is required** (new Sprint 4 T7, needs a parser per source format); staff-only "edited" marker.
   - **Editors are the THL team.**
   - **React client must remain viable.**
4. **Corrections to my own earlier findings** (recorded in the spike doc and memory):
   - Editing is **not dormant**: D7 marks edits with a node flag, set on 540 nodes through 2026-04-30; `tcu.changed` is never touched.
   - The React viewer reads the **same `mandala-av` documents** D7 writes, not an independent index.
   - The "Transcript Languages" facet is **live**: 5,342 node documents carry `sm_has_tier`; the values match the `tiers` column exactly. I had wrongly said it was probably empty, from a code search that missed the writer.
   - "About 108 unprocessed files" was two groups: 41 never processed, 68 replaced.
5. **Source-file reconciliation and scan.** The originals are on dev-0 inside the `mandala-drupal-0` container (5,379 files, all current attachments present, none stray). Accounting issues (41 unprocessed, 19 orphan ids, 3 with no file; the 68 replaced files use the current attachment as the original) are **deferred and tracked**.
6. **Transcript core design (question 6).**
   - New separate core **`mandala-av-transcripts`**, compatible with the React client; unit documents only.
   - D7 `tcuid` and `trid` preserved as ids; Solr id `tcu-11-{id}`.
   - **Access via a cross-core join to kmassets** (prototype first).
   - Write path: new sink, per-edit `commitWithin`, batch for bulk, retry-queue fallback; replica lag accepted.
   - **Same search features as D7.**
   - **New requirement found:** D11's AV kmassets documents carry no `trid_i` (0 of 11,584 on the dev replica), so the client could not find transcripts on D11.
7. **Minimal schema** written at `solr/mandala-av-transcripts/conf/schema.xml` and checked in a throwaway Solr 7.7.3 container with synthetic units (client query shape, highlighted per-tier phrase search, cross-transcript search, facet, delete by `is_trid`). No `solrconfig.xml` yet. `ts_*` tiers use ICU tokenizing as a default; the analyzer choice and separate language fields are deferred.
8. **A process slip, caught the same day:** a legacy-access detail was written into the public spike doc before checking `docs/non-public-documentation.md`. It was removed in a follow-up commit; it remains in earlier commits of the branch. Yuji judged the data not important and asked for no further action.

## Added to the repo (PR #272)

- Spike 11 doc: audit corrections, decisions, search-feature list, access options, core name, schema notes.
- Sprint 4: T6 rewritten, new **T7** (revert-to-upload), **T8** (transcript search UI), **T9** (`trid_i` on kmassets plus the proxy join filter); T4 now names the new core.
- Deferred notes (all indexed): `transcript-bad-timecodes-investigate-and-fix`, `transcript-source-file-accounting`, `transcript-core-replica-lag-review`, `transcript-index-queue-triage`, `transcript-tier-analyzers-and-language-fields`.
- `solr/mandala-av-transcripts/` (schema and README).

## Still open

- **Spike 11 not closed:** needs the join prototype, `solrconfig.xml`, and a migration plan. The Unicode round-trip and live-core checks of the unit-document shape remain.
- **For Than:** the `ts_*` analyzer and language-field design; the 41 / 19 / 3 accounting decisions; whether English stemming matters; the AV technical-metadata field audit he raised (a separate deferred note, not started).
- **Create the `mandala-av-transcripts` core** on the dev/staging Solr (the team can create `mandala*` cores there); not done.
- **Solr 7.x versus 9.x target** and production core creation: unconfirmed.
- **Replica lag** unmeasured; **queue triage** unbuilt.

## Tidy-up at close

Local DDEV: prototype module disabled, `config:status` clean, no background jobs; the throwaway Solr container was removed.
