# Transcript source files: account for every original

**Area:** migration / AV / transcripts / data fidelity
**Raised during:** Spike 11, reconciliation 2026-10-02; deferred the same day (Yuji)
**Jira:** (add when available)
**Priority:** Medium. Must be done before Sprint 4 T7 (revert-to-upload) can be built or
trusted; does not block the model, the viewer or the editor.

## What is deferred

Revert-to-upload (Sprint 4 T7) needs every original transcript file accounted for. A first
reconciliation against D7 database records (the 2026-09-01 `d7_av` dump) found these
loose ends. For now they are only **tracked**, not worked.

| Item | Count | Status |
|---|---|---|
| Nodes with a transcript file D7 never processed (no transcript, no units) | 41 | Open: migrate and parse them, or leave? Needs Than |
| Transcript ids with units but no tracking row (orphans, 2016-2021) | 19 | Open: expected or stale? Needs Than |
| Tracked transcripts with no file attached at all | 3 | Open: no source exists, so revert is impossible for them |
| Nodes whose current file differs from the file the units came from | 68 | **Decided 2026-10-02:** treat the current file as the original and ignore the missing earlier file. Open check: compare what the current file parses to against the stored units (D7 may never have processed the current file) |
| Source files exist on disk or S3 and parse to the stored units | all 5,343 + 41 | **Not checked.** Records only so far |

Of the 540 nodes edited in D7, 539 have a matching source-file record; one is missing its
record and one has a replaced file (the same node may be both; not checked).

## Where the originals are

On dev-0, at `/opt/drupal/app/drupal/web/sites/default/files/transcripts` (the real files
path, not the stub tree at `/var/www/html`). Recorded 2026-10-02 (Yuji). **Not yet listed or
counted**; the inventory below starts there and compares against the D7 file records.

## What closes it

1. Build the inventory: for every transcript and the 41 unprocessed nodes, confirm the file
   exists (disk or S3) and parses to the stored units; follow the existing
   `drush mandala:missing-file-audit` pattern and make it re-runnable.
2. Get Than's decisions on the 41, the 19 and the 3.
3. For the 68, report any parse-versus-stored differences; do not rewrite migrated units.
4. Record the final unrevertable list in this note.

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): reconciliation, F1 and question 5
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T7
- [Transcript bad timecodes](transcript-bad-timecodes-investigate-and-fix.md)
