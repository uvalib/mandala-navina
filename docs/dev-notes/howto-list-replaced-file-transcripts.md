# How-To: List the 68 replaced-file transcripts (T5 Q8 review)

**Audience:** developers working in the monorepo; hand the output to whoever does the T5 source-file review
**Last reviewed:** 2026-11-13

## Goal

Produce the list of D7 transcripts whose stored units came from a file that no longer exists, so the Q8
spot-check against source files can include them. Background: [T5 plan, open question 8](../planning/t5-transcript-migration-plan.md)
and [Transcript source files: account for every original](../deferred/transcript-source-file-accounting.md).

## What "replaced file" means

D7 keeps two records of a transcript's file:

- the node's `field_transcript`, the file attached **now**;
- the tracking row in `transcripts_apachesolr_transcript`, whose `fid` is the file D7 turned into units.

For 68 transcripts the tracking row's `fid` has no `file_managed` row (that file is gone) and the node carries a
different file. The units were parsed from the old upload; D7 may never have processed the current one, so units
and file can disagree. The decision of 2026-10-02 is to treat the current file as the original of record
(`source_file`), so these are the transcripts where a comparison is most likely to find a difference.
Three more tracked transcripts have a missing `fid` and no current file at all; they are not in the 68.

## Prerequisites

- DDEV running (`ddev start`).
- The D7 AV dump loaded as the `d7_av` database (the T5 verification script uses the same one). Set `D7DB` if yours
  has another name.
- Optional: T5 run locally, to fill the `d11_*` columns. Without it they are empty and the list is still correct.

## Steps

1. Write the list to a file (output goes to stdout, the count check to stderr):
   ```bash
   ./scripts/list-av-transcripts-replaced-file.sh > replaced-68.tsv
   ```
   Add `--include-no-file` to include the 3 with no file at all (71 rows; their `current_*` columns are empty).
   Use `D7DB=<name>` for a differently named D7 database.
2. Do not commit the file. An ids-only copy (no filenames or titles) is committed at [t5-replaced-file-transcripts-68.md](../planning/t5-replaced-file-transcripts-68.md). The full output names content (node titles), so send it to the reviewer directly.

## Columns

| Column | Meaning |
|---|---|
| `trid` | D7 transcript id, which is also the D11 `av_transcript` id |
| `d7_nid` | D7 node id; the legacy key (`field_legacy_nid`) for finding the node in any environment |
| `old_fid` | The file the tracking row names (not in `file_managed`) |
| `current_fid`, `current_filename`, `ext`, `filesize` | The node's current file |
| `units` | Units D7 holds for the trid (from `tcu`) |
| `d11_nid` | D11 node id **in the database that was read**. Never portable between environments (ADR 017); use `d7_nid` elsewhere |
| `d11_has_edits` | D11 `av_transcript.has_edits`; an edited transcript's units are the more valuable to get right |
| `d11_node_title` | The D11 node's title |

## Reviewing one row

1. Find the current file on dev-0 in `/opt/drupal/app/drupal/web/sites/default/files/transcripts` (inside the
   `mandala-drupal-0` container; see [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md)). File names can contain
   spaces and punctuation, so quote them.
2. Compare the file's content with the units for that `trid` (D7 `tcu` and `tcu_tier`, or the migrated D11 `av_tcu`).
3. Report differences. Do not rewrite migrated units; T7's inventory will do the same comparison and also reports only.

## Verify

Success is the stderr line `OK: 68 rows (expected 68)` and exit status 0. A local run on 2026-11-13 gave 68 rows:
49 xml, 15 vtt, 2 srt, 2 txt. If the count is different the script prints `FAIL` and exits 1; the dump probably
changed, so check `D7DB` and re-read the definition above before trusting the list.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `query failed` | DDEV is not running or the `d7_av` database is missing | `ddev start`; load the D7 dump, or set `D7DB` |
| `FAIL: N rows, expected 68` | A different or newer D7 dump | Confirm the dump, then compare against the definition; update the expected count in the script and the plan if the new number is right |
| `d11_*` columns empty | T5 has not been run in this database | Run the migrations locally, or ignore those columns |

## Related

- [T5 transcript migration plan](../planning/t5-transcript-migration-plan.md)
- [Spike 11, question 5](../spikes/spike-11-av-transcript-replication.md)
- `scripts/verify-av-transcript-migration.sh`, which checks D11 against D7's tables (not against the files)
