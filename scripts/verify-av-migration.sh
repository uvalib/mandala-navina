#!/usr/bin/env bash
#
# Verify the AV migration against the D7 source, after ./scripts/run-av-migration.sh.
#
# Every check compares a D11 count to a number derived INDEPENDENTLY from the D7
# dump — not to the migration's own map table, which would only prove migrate
# agrees with itself. A row is OK only when both sides match.
#
# Usage: ./scripts/verify-av-migration.sh
# Exit:  0 if every check matches, 1 otherwise.

set -u -o pipefail

D7DB="${D7DB:-d7_av}"
fail=0

d7() { ddev mysql -uroot -proot "$D7DB" -N -B -e "$1" 2>/dev/null | tr -d '[:space:]'; }
d11() { ddev drush sql:query "$1" 2>/dev/null | tr -d '[:space:]'; }

check() {
  local label="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then
    printf '  OK   %-46s %s\n' "$label" "$actual"
  else
    printf '  FAIL %-46s expected %s, got %s\n' "$label" "$expected" "$actual"
    fail=1
  fi
}

echo "AV migration verification — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo
echo "Nodes"
check "audio nodes" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type='audio'")" \
  "$(d11 "SELECT COUNT(*) FROM node_field_data WHERE type='audio'")"
check "video nodes" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type='video'")" \
  "$(d11 "SELECT COUNT(*) FROM node_field_data WHERE type='video'")"

echo
echo "Identity (ADR 017)"
check "legacy_site=audio-video on nodes" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type IN ('audio','video')")" \
  "$(d11 "SELECT COUNT(*) FROM node__field_legacy_site WHERE field_legacy_site_value='audio-video'")"
check "distinct legacy nids" \
  "$(d7 "SELECT COUNT(DISTINCT nid) FROM node WHERE type IN ('audio','video')")" \
  "$(d11 "SELECT COUNT(DISTINCT n.field_legacy_nid_value) FROM node__field_legacy_nid n JOIN node__field_legacy_site s ON s.entity_id=n.entity_id WHERE s.field_legacy_site_value='audio-video'")"

echo
echo "Authorship — the mapping that was silently NULL before 2026-09-09"
check "distinct AV node authors" \
  "$(d7 "SELECT COUNT(DISTINCT uid) FROM node WHERE type IN ('audio','video')")" \
  "$(d11 "SELECT COUNT(DISTINCT uid) FROM node_field_data WHERE type IN ('audio','video')")"
# D7 itself has 15 audio/video nodes with uid=0 (verified against the source,
# not assumed) -- these are genuinely anonymous-authored in D7, not a mapping
# gap. Compare against the SOURCE count, not a hardcoded zero.
check "AV nodes owned by Anonymous (matches D7 source)" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type IN ('audio','video') AND uid=0")" \
  "$(d11 "SELECT COUNT(*) FROM node_field_data WHERE type IN ('audio','video') AND uid=0")"

echo
echo "Media (AV14: 18 nodes legitimately have no Kaltura entry)"
check "audio with a Kaltura entry" \
  "$(d7 "SELECT COUNT(DISTINCT entity_id) FROM field_data_field_audio WHERE deleted=0")" \
  "$(d11 "SELECT COUNT(*) FROM node__field_audio WHERE field_audio_entry_id IS NOT NULL AND field_audio_entry_id<>''")"
check "video with a Kaltura entry" \
  "$(d7 "SELECT COUNT(DISTINCT entity_id) FROM field_data_field_video WHERE deleted=0")" \
  "$(d11 "SELECT COUNT(*) FROM node__field_video WHERE field_video_entry_id IS NOT NULL AND field_video_entry_id<>''")"

echo
echo "Groups and relationships"
check "collections" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type='collection'")" \
  "$(d11 "SELECT COUNT(*) FROM groups_field_data g JOIN group__field_legacy_site s ON s.entity_id=g.id WHERE g.type='collection' AND s.field_legacy_site_value='audio-video'")"
check "subcollections" \
  "$(d7 "SELECT COUNT(*) FROM node WHERE type='subcollection'")" \
  "$(d11 "SELECT COUNT(*) FROM groups_field_data g JOIN group__field_legacy_site s ON s.entity_id=g.id WHERE g.type='subcollection' AND s.field_legacy_site_value='audio-video'")"
check "node→collection memberships" \
  "$(d7 "SELECT COUNT(*) FROM og_membership ogm JOIN node m ON m.nid=ogm.etid JOIN node g ON g.nid=ogm.gid WHERE ogm.entity_type='node' AND ogm.group_type='node' AND m.type IN ('audio','video') AND g.type IN ('collection','subcollection')")" \
  "$(d11 "SELECT COUNT(*) FROM group_relationship_field_data WHERE type IN ('collection-group_node-audio','collection-group_node-video','subcollection-group_node-audio','subcollection-group_node-video')")"

echo
echo "Paragraphs — the language-layer rules"
# 125,101 raw items minus the 667 losing field_pbcore_instantiation items that a
# cardinality-1 host cannot hold. See docs/planning/av-node-migration-notes.md §3.
check "av_* paragraphs created" \
  "$(d7 "SELECT (SELECT COUNT(*) FROM (SELECT fci.item_id FROM field_collection_item fci JOIN field_data_field_pbcore_title l ON l.field_pbcore_title_value=fci.item_id WHERE fci.field_name='field_pbcore_title' AND fci.archived=0 AND l.deleted=0 GROUP BY fci.item_id) x)")" \
  "$(d11 "SELECT COUNT(*) FROM paragraphs_item_field_data WHERE type='av_pbcore_title'")"
check "instantiation paragraphs (1 per host)" \
  "$(d7 "SELECT COUNT(DISTINCT l.entity_id) FROM field_data_field_pbcore_instantiation l JOIN field_collection_item fci ON fci.item_id=l.field_pbcore_instantiation_value WHERE fci.archived=0 AND l.deleted=0 AND l.entity_type='node' AND l.bundle IN ('audio','video')")" \
  "$(d11 "SELECT COUNT(*) FROM paragraphs_item_field_data WHERE type='av_pbcore_instantiation'")"
check "no node references 2 instantiations" "0" \
  "$(d11 "SELECT COUNT(*) FROM (SELECT entity_id FROM node__field_pbcore_instantiation GROUP BY entity_id HAVING COUNT(*)>1) x")"
# Exactly 3 orphans are EXPECTED, not zero: field_pbcore_format_id items whose
# parent instantiation item was the excluded loser in the language-layer rule
# (hosts 3556, 7408, 420971 -- see docs/planning/av-node-migration-notes.md §3).
# Their format_id paragraph still gets created because D7AvFieldCollection
# processes each nested collection independently of its eventual host.
check "orphaned av_* paragraphs (3 expected -- excluded instantiation hosts)" "3" \
  "$(d11 "SELECT COUNT(*) FROM paragraphs_item_field_data p WHERE p.type LIKE 'av_%' AND p.parent_id IS NULL")"

# NESTED REFERENCES. Row counts alone would have passed the 2026-09-09 run with
# 6,814 paragraphs created and none of them referenced: the sub_process shape bug
# failed silently on the 4,561 single-note hosts and raised an error only on the
# one host with two. Count the REFERENCES, not the paragraphs.
#
# The expected number is NOT the raw D7 link count. Two things legitimately
# reduce it, and both are our own documented rules:
#   - the und/en layers link the same child twice, so links dedupe per (host,
#     child);
#   - a child whose HOST was excluded (a cardinality-1 loser, or archived) has
#     nowhere to attach. On field_pbcore_format_id that is exactly 3 links, on
#     hosts 3556, 7408 and 420971.
# So the expectation is "deduped links whose host actually migrated", which is
# why these queries join the host's migrate map. Comparing against the raw count
# (2,710 for format_id) would fail a correct migration.
nested_expected() { # $1 = child field, $2 = host migration id
  d11 "SELECT COUNT(*) FROM (
         SELECT l.entity_id, l.${1}_value
         FROM ${D7DB}.field_data_${1} l
         JOIN ${D7DB}.field_collection_item child ON child.item_id = l.${1}_value
         JOIN migrate_map_${2} m ON m.sourceid1 = l.entity_id
         WHERE l.deleted=0 AND l.entity_type='field_collection_item'
           AND child.archived=0
         GROUP BY l.entity_id, l.${1}_value) x"
}

check "workflow -> catalog notes refs" \
  "$(nested_expected field_catalog_workflow_notes d7_av_workflow)" \
  "$(d11 "SELECT COUNT(*) FROM paragraph__field_catalog_workflow_notes")"
check "workflow -> workflow notes refs" \
  "$(nested_expected field_workflow_notes d7_av_workflow)" \
  "$(d11 "SELECT COUNT(*) FROM paragraph__field_workflow_notes")"
check "workflow -> transcript notes refs" \
  "$(nested_expected field_transcript_workflow_notes d7_av_workflow)" \
  "$(d11 "SELECT COUNT(*) FROM paragraph__field_transcript_workflow_notes")"
check "instantiation -> format_id refs" \
  "$(nested_expected field_pbcore_format_id d7_av_pbcore_instantiation)" \
  "$(d11 "SELECT COUNT(*) FROM paragraph__field_pbcore_format_id")"
check "node -> paragraph refs (titles)" \
  "$(d7 "SELECT COUNT(*) FROM (SELECT fci.item_id FROM field_collection_item fci JOIN field_data_field_pbcore_title l ON l.field_pbcore_title_value=fci.item_id WHERE fci.field_name='field_pbcore_title' AND fci.archived=0 AND l.deleted=0 AND l.entity_type='node' AND l.bundle IN ('audio','video') GROUP BY fci.item_id) x")" \
  "$(d11 "SELECT COUNT(*) FROM node__field_pbcore_title")"

echo
echo "Aliases (ADR 016)"
# Match on DESTINATION (/node/%), not on the alias TEXT: two collection/
# subcollection aliases happen to start with "/video/" because their D7 titles
# start with "Video" (video-test-3, video-traditional-nomadic-...) -- an
# `alias LIKE '/video/%'` filter silently pulls in group aliases that were
# never part of this migration at all (they come from
# d7_av_collection_url_alias, not d7_av_url_alias). Filtering on `path LIKE
# '/node/%'` is exact regardless of what any given node happens to be titled.
check "AV node aliases" \
  "$(d7 "SELECT COUNT(*) FROM url_alias ua JOIN node n ON n.nid=CAST(SUBSTRING(ua.source,6) AS UNSIGNED) WHERE ua.source LIKE 'node/%' AND n.type IN ('audio','video')")" \
  "$(d11 "SELECT COUNT(*) FROM path_alias WHERE path LIKE '/node/%' AND (alias LIKE '/audio/%' OR alias LIKE '/video/%')")"

echo
if [ "$fail" -eq 0 ]; then
  echo "ALL CHECKS MATCH."
else
  echo "SOME CHECKS FAILED — see above."
fi
exit "$fail"
