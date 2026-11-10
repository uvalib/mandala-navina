#!/usr/bin/env bash
#
# Verify the T5 transcript migration (d7_av_transcript, d7_av_tcu) against the D7
# source. Plan: docs/planning/t5-transcript-migration-plan.md, "Verification".
#
# Every check compares a D11 number to one derived INDEPENDENTLY from the D7
# dump, not to the migration's own map table, which would only prove migrate
# agrees with itself. D7 and D11 are on the same MySQL server locally, so the
# per-transcript and per-unit checks are single cross-database queries.
#
# Usage: ./scripts/verify-av-transcript-migration.sh
#        D7DB=d7_av_fresh ./scripts/verify-av-transcript-migration.sh
# Exit:  0 if every check matches, 1 otherwise.
#
# The text-fidelity check (NFC) is done in PHP by verify-av-transcript-text.php,
# because MySQL cannot normalise Unicode.

set -u -o pipefail

D7DB="${D7DB:-d7_av}"
D11DB="${D11DB:-db}"
fail=0

q() { ddev mysql -uroot -proot -N -B -e "$1" 2>/dev/null | tr -d '[:space:]'; }

check() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then
    printf '  OK   %-58s %s\n' "$label" "$actual"
  else
    printf '  FAIL %-58s expected %s, got %s\n' "$label" "$expected" "$actual"
    fail=1
  fi
}

TRACK="$D7DB.transcripts_apachesolr_transcript"
TRACKED="$TRACK.module = 'transcripts_node' AND $TRACK.type = 'node'"

echo "T5 transcript migration verification ($D7DB -> $D11DB) — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo
echo "Counts"
check "av_transcript rows = D7 tracking rows" \
  "$(q "SELECT COUNT(*) FROM $TRACK WHERE module='transcripts_node' AND type='node'")" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript")"
check "av_transcript distinct nodes" \
  "$(q "SELECT COUNT(DISTINCT id) FROM $TRACK WHERE module='transcripts_node' AND type='node'")" \
  "$(q "SELECT COUNT(DISTINCT node) FROM $D11DB.av_transcript")"
check "av_tcu rows = D7 units of tracked transcripts" \
  "$(q "SELECT COUNT(*) FROM $D7DB.tcu c JOIN $TRACK t ON t.trid=c.trid AND t.module='transcripts_node' AND t.type='node'")" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu")"
check "transcripts or units soft-deleted" "0" \
  "$(q "SELECT (SELECT COUNT(*) FROM $D11DB.av_transcript WHERE deleted=1)+(SELECT COUNT(*) FROM $D11DB.av_tcu WHERE deleted=1)")"

echo
echo "Identity: ids are D7's trid and tcuid, nodes found by legacy key (ADR 017)"
check "transcript ids not among D7 tracked trids" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript a LEFT JOIN $TRACK t ON t.trid=a.id WHERE t.trid IS NULL")"
check "unit ids not among D7 tcuids" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu a LEFT JOIN $D7DB.tcu c ON c.tcuid=a.id WHERE c.tcuid IS NULL")"
check "transcripts whose node's legacy nid differs from D7's" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript a JOIN $TRACK t ON t.trid=a.id LEFT JOIN $D11DB.node__field_legacy_nid n ON n.entity_id=a.node AND n.field_legacy_nid_value=t.id LEFT JOIN $D11DB.node__field_legacy_site s ON s.entity_id=a.node AND s.field_legacy_site_value='audio-video' WHERE n.entity_id IS NULL OR s.entity_id IS NULL")"
check "units whose transcript differs from D7's trid" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu a JOIN $D7DB.tcu c ON c.tcuid=a.id WHERE a.transcript<>c.trid")"
check "transcripts whose unit count differs from D7's" "0" \
  "$(q "SELECT COUNT(*) FROM (SELECT t.trid, (SELECT COUNT(*) FROM $D7DB.tcu c WHERE c.trid=t.trid) d7n, (SELECT COUNT(*) FROM $D11DB.av_tcu u WHERE u.transcript=t.trid) d11n FROM $TRACK t WHERE t.module='transcripts_node' AND t.type='node') x WHERE x.d7n<>x.d11n")"

echo
echo "Timecodes and order (carried as found; units in D7's display order)"
check "units whose start differs from D7 (to the millisecond)" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu a JOIN $D7DB.tcu c ON c.tcuid=a.id WHERE a.start<>CAST(c.start AS DECIMAL(10,3))")"
check "units whose end differs from D7 (to the millisecond)" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu a JOIN $D7DB.tcu c ON c.tcuid=a.id WHERE a.end<>CAST(c.end AS DECIMAL(10,3))")"
check "units with an end_original (none repaired by T5)" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu WHERE end_original IS NOT NULL")"
check "units whose sequence is not their (start, id) rank" "0" \
  "$(q "SELECT COUNT(*) FROM (SELECT sequence, ROW_NUMBER() OVER (PARTITION BY transcript ORDER BY start, id)-1 AS r FROM $D11DB.av_tcu) x WHERE x.sequence<>x.r")"
check "units with no timecode_status" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu WHERE timecode_status IS NULL OR timecode_status=''")"

echo
echo "Edits flag (keep_transcript_edits) and source file"
check "transcripts with has_edits = flagged D7 nodes" \
  "$(q "SELECT COUNT(DISTINCT g.entity_id) FROM $D7DB.flagging g JOIN $D7DB.flag f ON f.fid=g.fid WHERE f.name='keep_transcript_edits' AND g.entity_type='node'")" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript WHERE has_edits=1")"
check "transcripts with a source_file = nodes with a field_transcript" \
  "$(q "SELECT COUNT(*) FROM $TRACK t JOIN $D7DB.field_data_field_transcript f ON f.entity_id=t.id AND f.entity_type='node' AND f.deleted=0 AND f.delta=0 WHERE t.module='transcripts_node' AND t.type='node'")" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript WHERE source_file IS NOT NULL")"
check "source_file differs from the node's current field_transcript" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_transcript a LEFT JOIN $D11DB.node__field_transcript f ON f.entity_id=a.node AND f.delta=0 WHERE NOT (a.source_file <=> f.field_transcript_target_id)")"

echo
echo "Accounted for, not migrated (ADR 019)"
check "units of orphan trids present in D11" "0" \
  "$(q "SELECT COUNT(*) FROM $D11DB.av_tcu a JOIN $D7DB.tcu c ON c.tcuid=a.id LEFT JOIN $TRACK t ON t.trid=c.trid WHERE t.trid IS NULL")"
printf '  INFO %-58s %s\n' "orphan-trid units held back (D7 units with no tracking row)" \
  "$(q "SELECT COUNT(*) FROM $D7DB.tcu c LEFT JOIN $TRACK t ON t.trid=c.trid WHERE t.trid IS NULL")"
printf '  INFO %-58s %s\n' "orphan tier rows (no unit) not migrated" \
  "$(q "SELECT COUNT(*) FROM $D7DB.tcu_tier x LEFT JOIN $D7DB.tcu c ON c.tcuid=x.tcuid WHERE c.tcuid IS NULL")"
printf '  INFO %-58s %s\n' "orphan speaker rows (no unit) not migrated" \
  "$(q "SELECT COUNT(*) FROM $D7DB.tcu_speaker x LEFT JOIN $D7DB.tcu c ON c.tcuid=x.tcuid WHERE c.tcuid IS NULL")"
check "nodes with a transcript file but no tracking row have no transcript" "0" \
  "$(q "SELECT COUNT(*) FROM $D7DB.field_data_field_transcript f LEFT JOIN $TRACK t ON t.id=f.entity_id AND t.module='transcripts_node' WHERE f.entity_type='node' AND f.deleted=0 AND f.delta=0 AND t.trid IS NULL AND EXISTS (SELECT 1 FROM $D11DB.av_transcript a JOIN $D11DB.node__field_legacy_nid n ON n.entity_id=a.node WHERE n.field_legacy_nid_value=f.entity_id)")"

echo
echo "timecode_status distribution (recorded so the number can be watched going down)"
ddev mysql -uroot -proot -B -e "SELECT timecode_status, COUNT(*) AS units FROM $D11DB.av_tcu GROUP BY timecode_status ORDER BY units DESC" 2>/dev/null | sed 's/^/       /'

echo
echo "Text fidelity (NFC), in PHP"
if D7DB="$D7DB" ddev drush php:script scripts/verify-av-transcript-text.php 2>/dev/null | grep -v '^Deprecated\|^$' | tee /tmp/av-transcript-text.out; then :; fi
grep -q '^  FAIL' /tmp/av-transcript-text.out && fail=1
[ -s /tmp/av-transcript-text.out ] || { echo "  FAIL text-fidelity script produced no output"; fail=1; }

echo
if [ "$fail" -eq 0 ]; then echo "ALL CHECKS PASSED"; else echo "FAILURES ABOVE"; fi
exit "$fail"
