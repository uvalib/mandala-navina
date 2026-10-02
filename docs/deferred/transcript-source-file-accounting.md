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
| Source files exist on disk | all attached files | **Checked 2026-10-02 (listing only, see below):** all present. Whether they **parse** to the stored units is not checked |

Of the 540 nodes edited in D7, 539 have a matching source-file record; one is missing its
record and one has a replaced file (the same node may be both; not checked).

## Where the originals are

On dev-0, at `/opt/drupal/app/drupal/web/sites/default/files/transcripts` (the real files
path, not the stub tree at `/var/www/html`). Recorded 2026-10-02 (Yuji). The path is inside
the `mandala-drupal-0` container (it does not exist on the host).

### Quick scan, 2026-10-02 (read-only listing, names and sizes only; contents not opened)

- **5,379 files, flat (no subdirectories), 93 MB, none zero-byte.**
  By extension: `.vtt` 3,249, `.xml` 1,949, `.txt` 147, `.srt` 34.
- **Every one of the 5,380 current D7 attachments has its file on disk** (5,379 distinct
  files). **No file on disk lacks a D7 record**, so there are no stray originals.
- This also confirms the 68 replaced files' earlier uploads are not on disk either, so
  there is nothing to fall back to; consistent with the decision to use the current file.
- **1 tracked source file is not on disk** (a `.vtt` whose D7 record exists but whose file
  does not; its node's current attachment is present). To be looked at in the inventory.
- File names include spaces, `&` and other punctuation, so the migration and any URL or
  shell handling must not assume simple names.
- The extension counts are slightly higher than the tracked-file counts in the Spike 11
  audit (3,227 / 1,881 / 136 / 28) because these include the 41 never-processed files and
  the 68 current replacements.

## What closes it

1. Build the inventory: existence is done (above); for every transcript and the 41
   unprocessed nodes, confirm the file parses to the stored units; follow the existing
   `drush mandala:missing-file-audit` pattern and make it re-runnable.
2. Get Than's decisions on the 41, the 19 and the 3.
3. For the 68, report any parse-versus-stored differences; do not rewrite migrated units.
4. Record the final unrevertable list in this note.

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): reconciliation, F1 and question 5
- [Sprint 4: AV transcripts](../sprints/sprint-04-av-transcripts.md): T7
- [Transcript bad timecodes](transcript-bad-timecodes-investigate-and-fix.md)
