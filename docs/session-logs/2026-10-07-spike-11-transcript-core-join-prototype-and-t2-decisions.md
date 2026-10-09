# Session Log: Spike 11 transcript core config, cross-core join prototype, and T2 design decisions

**Date:** 2026-10-07
**Participants:** Yuji Shinozaki (driving), with Xiaoming Wang and Than Grove in the room for the second half; Claude Code
**Outcome:** PR #285 open (`solrconfig.xml`, join prototype, `is_trid` int, and the decisions below). Nothing merged this session. Hand-written summary, not a transcript; no privately-tracked material was touched.

## What happened

1. **Orientation.** Session-start check clean (dev-0 unreachable, VPN off, so the dev-0 content-count comparison was skipped). Reviewed open work; PR #284 (AV edit form fields) had just been closed by Than as not ready. It showed as open again by session close, so treat it as Than's in-progress work.
2. **Spike 11 work.** Wrote a lean `solr/mandala-av-transcripts/conf/solrconfig.xml` and ran the cross-core join prototype (access option C) on a throwaway Solr 7.7.3 (`prototype/join-check.sh`, synthetic data, stand-in kmassets core). The access shape works (anonymous, member and bypass fq admit exactly the right units; units with no kmassets document fail closed), after two fixes the prototype found:
   - `is_trid` (long) cannot join kmassets `trid_i` (int): `bytesPerDim` error. `is_trid` is now an int. D7's maximum `trid` is 27,016.
   - Joining into a Point field needs docValues on the from field; legacy kmassets `trid_i` is a Trie int without them.
3. **Transcript-to-node audit** against `d7_av` (DDEV): one-to-one, a node may have none. 5,343 tracked transcripts = 5,343 nodes; no node has a second file in current data or any revision; the 68 replaced files leave no trace of the earlier file in revisions. One discrepancy left unreconciled: 4 tracked nodes have no `field_transcript` row, the notes say 3.

## Decisions (Yuji, with Xiaoming and Than)

- **docValues:** add `docValues="true"` to kmassets `trid_i`; keep `is_trid` a Point int (not Trie, which Solr 9 removes). Nothing carries `trid_i` yet, so no reindex.
- **T9 owner:** Yuji owns both halves, the `solr-proxy` join filter and the kmassets `trid_i` writer. Review happens in session.
- **Revision history:** per-unit revisions on `av_tcu`; `av_transcript` revisions are metadata-only (structural operations log); history access follows edit permissions; no retention limit; Solr indexes the current revision only. Whole-transcript snapshots rejected.
- **Entity kind:** non-fieldable custom content entities `av_transcript` and `av_tcu`; the viewer reads through a service doing one indexed range read.
- **Tier and speaker maps:** a JSON column on the unit, not child rows.
- **AV field audit:** has the team's go-ahead; owner Than; step 1 is PR #284.

All recorded in `docs/spikes/spike-11-av-transcript-replication.md`, `docs/sprints/sprint-04-av-transcripts.md` (T9) and `solr/mandala-av-transcripts/README.md`.

## Open at close

- **Check on dev-0 (needs VPN):** whether the deployed kmassets `trid_i` already has docValues; if so, no schema change.
- **Not started:** the `solr-proxy` join filter (encode `{`/`}` as `%7B`/`%7D`), the kmassets `trid_i` writer, creating the core on dev Solr (suggested after #285 merges and the dev-0 check), join cost at real scale and replication.
- **T2 remainder:** timecode storage type (`DECIMAL(10,3)` unverified), id allocation above the D7 maximum per environment, the service API, and running the sync prototype against real D11 entities.
- **Unreconciled:** the 4-versus-3 tracked-nodes-with-no-file count.
