#!/usr/bin/env bash
#
# Run the Sprint 3 AV migration (AV4) locally, in dependency order.
#
# WHY AN EXPLICIT ORDER rather than `drush migrate:import --group=mandala_av
# --execute-dependencies`: the group form gives no control over where a long run
# stops and resumes, and the four nested paragraph migrations must complete
# before their hosts or migrate refuses them outright ("did not meet the
# requirements. Missing migrations ..."). Listing the order makes the sequence
# reviewable and lets a failed stage be re-run on its own.
#
# ⛔ Do NOT reorder casually. Two constraints are load-bearing:
#   - the four INNER paragraph migrations precede their outer hosts;
#   - every paragraph migration precedes the node migrations, or the nodes'
#     migration_lookup finds nothing and 125,101 paragraphs are left orphaned.
#
# Usage: ./scripts/run-av-migration.sh [logfile]
#
# Resumable: migrate skips rows already in the map, so re-running after an
# interruption continues rather than duplicating. Note that a resumed migration
# still re-reads every source row.

set -u -o pipefail

LOG="${1:-/tmp/av-migration-$(date +%Y%m%d-%H%M%S).log}"

# Dependency order. Each stage completes before the next begins.
MIGRATIONS=(
  # Referenced entities first -- nodes look these up by D7 id.
  d7_av_tags
  d7_av_files

  # Inner (nested) paragraphs, before the hosts that reference them.
  d7_av_pbcore_format_id
  d7_av_catalog_workflow_notes
  d7_av_transcript_workflow_notes
  d7_av_workflow_notes

  # Outer paragraphs.
  d7_av_pbcore_instantiation
  d7_av_workflow
  d7_av_kmap_annotation
  d7_av_pbcore_contributor
  d7_av_pbcore_coverage
  d7_av_pbcore_creator
  d7_av_pbcore_description
  d7_av_pbcore_extension
  d7_av_pbcore_identifier
  d7_av_pbcore_publisher
  d7_av_pbcore_relation
  d7_av_pbcore_sponsor
  d7_av_pbcore_title

  # Nodes -- the slow axis.
  d7_av_audio
  d7_av_video

  # Groups, then the relationships that need both groups and nodes.
  d7_av_collections
  d7_av_subcollections
  d7_av_node_collection_membership
  d7_av_user_memberships

  # Aliases last: they look up the migrated node/group ids.
  d7_av_url_alias
  d7_av_collection_url_alias
)

echo "AV migration started $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee "$LOG"
echo "Stages: ${#MIGRATIONS[@]}" | tee -a "$LOG"

failed=()
for m in "${MIGRATIONS[@]}"; do
  start=$(date +%s)
  echo "" | tee -a "$LOG"
  echo "=== ${m} — started $(date -u +%H:%M:%SZ)" | tee -a "$LOG"
  if ddev drush migrate:import "$m" --skip-progress-bar --feedback=2000 2>&1 | tee -a "$LOG" | tail -2; then
    :
  else
    failed+=("$m")
    echo "!!! ${m} FAILED" | tee -a "$LOG"
  fi
  echo "=== ${m} — $(( $(date +%s) - start ))s" | tee -a "$LOG"
done

echo "" | tee -a "$LOG"
echo "AV migration finished $(date -u +%Y-%m-%dT%H:%M:%SZ)" | tee -a "$LOG"
if [ ${#failed[@]} -gt 0 ]; then
  echo "FAILED STAGES: ${failed[*]}" | tee -a "$LOG"
  exit 1
fi

# The kmassets Solr sync is suppressed per-node during migration (see the
# notice each stage prints); indexing is a deliberate, separate step afterwards.
echo "Next: drush kmassets:index-all && drush kmassets:audit" | tee -a "$LOG"
