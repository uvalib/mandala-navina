#!/usr/bin/env bash
#
# List the D7 transcripts whose tracking row names a file that no longer exists,
# while the node now carries a different file (T5 question 8: "the 68").
# Plan: docs/planning/t5-transcript-migration-plan.md, open question 8 and the
# "68 replaced-file transcripts" row. Source note:
# docs/deferred/transcript-source-file-accounting.md.
#
# Definition (all from the D7 dump, read-only):
#   a tracked transcript (transcripts_apachesolr_transcript, module
#   'transcripts_node', type 'node') whose `fid` has no file_managed row AND whose
#   node has a current field_transcript file. Tracked rows with a missing fid and
#   NO current file are the other 3 ("no file at all"); `--include-no-file` adds
#   them (current_fid, filename and ext are then empty).
#
# Output: TSV on stdout, one row per transcript, ordered by trid:
#   trid  d7_nid  old_fid  current_fid  current_filename  ext  filesize  units
#   d11_nid  d11_has_edits  d11_node_title
# The d11_* columns come from av_transcript and the D11 node, and are empty if D11
# has no row for the trid (e.g. T5 not run locally). d11_nid is local to the
# database it was read from; use d7_nid (field_legacy_nid) to find a node elsewhere. The output names content but quotes no
# transcript text; still, do not commit it.
#
# Usage: ./scripts/list-av-transcripts-replaced-file.sh > replaced-68.tsv
#        D7DB=d7_av_fresh ./scripts/list-av-transcripts-replaced-file.sh
# Exit:  0 if the row count matches the expected 68 (or 71 with
#        --include-no-file), 1 otherwise; the count goes to stderr either way.

set -u -o pipefail

D7DB="${D7DB:-d7_av}"
D11DB="${D11DB:-db}"
include_no_file=0
[ "${1:-}" = "--include-no-file" ] && include_no_file=1

JOIN=LEFT
[ "$include_no_file" -eq 0 ] && JOIN=INNER
expected=68
[ "$include_no_file" -eq 1 ] && expected=71

sql="
SELECT t.trid, t.id AS d7_nid, t.fid AS old_fid,
       IFNULL(c.field_transcript_fid, '') AS current_fid,
       IFNULL(f.filename, '') AS current_filename,
       IFNULL(LOWER(SUBSTRING_INDEX(f.filename, '.', -1)), '') AS ext,
       IFNULL(f.filesize, '') AS filesize,
       (SELECT COUNT(*) FROM $D7DB.tcu u WHERE u.trid = t.trid) AS units,
       IFNULL(a.node, '') AS d11_nid,
       IFNULL(a.has_edits, '') AS d11_has_edits,
       IFNULL(n.title, '') AS d11_node_title
FROM $D7DB.transcripts_apachesolr_transcript t
LEFT JOIN $D7DB.file_managed old ON old.fid = t.fid
$JOIN JOIN $D7DB.field_data_field_transcript c
       ON c.entity_type = 'node' AND c.entity_id = t.id AND c.deleted = 0
LEFT JOIN $D7DB.file_managed f ON f.fid = c.field_transcript_fid
LEFT JOIN $D11DB.av_transcript a ON a.id = t.trid
LEFT JOIN $D11DB.node_field_data n ON n.nid = a.node
WHERE t.module = 'transcripts_node' AND t.type = 'node' AND old.fid IS NULL
ORDER BY t.trid"

out="$(ddev mysql -uroot -proot -B -e "$sql" 2>/dev/null)" || { echo "query failed" >&2; exit 1; }
printf '%s\n' "$out"
rows=$(printf '%s\n' "$out" | tail -n +2 | grep -c .)
if [ "$rows" -eq "$expected" ]; then
  echo "OK: $rows rows (expected $expected)" >&2
else
  echo "FAIL: $rows rows, expected $expected" >&2
  exit 1
fi
