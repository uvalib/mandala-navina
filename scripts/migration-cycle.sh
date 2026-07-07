#!/bin/bash
# Repeatable migrate → validate → rollback cycle for the Images pilot.
#
# Sprint 1, task 1a.9 (the "rollback story"): the acceptance gate is that the
# test-run → validate → rollback cycle is *documented and repeatable*. This
# script is that cycle. It wraps the existing Migrate API plumbing (1a.6/1a.7,
# the `mandala_images` group) plus the kmassets audit (1a.9) into named phases.
#
# Runbook + rationale: docs/planning/migration-cycle-runbook.md
#
# PREREQUISITES
#   - DDEV up; D11 content model matches committed config (`ddev drush cim -y`).
#   - The D7 source dump is loaded into the `d7_images` DB:
#       ./scripts/load-d7-source.sh <dump.sql.gz>
#   - For the `audit` phase only: network line-of-sight to the Solr master
#     (VPN / VPC), since solr_master_url points at the staging master.
#
# USAGE
#   ./scripts/migration-cycle.sh validate    # read-only: reconcile counts vs baseline
#   ./scripts/migration-cycle.sh import       # migrate:import the group
#   ./scripts/migration-cycle.sh rollback     # migrate:rollback the group, verify clean
#   ./scripts/migration-cycle.sh audit        # index to kmassets + kmassets:audit (needs VPN)
#   ./scripts/migration-cycle.sh cycle         # rollback → import → validate (a clean test run)
#
# A full round-trip that returns the DB to clean is: `cycle` then `rollback`.
# "Repeatable" means `cycle` is safe to run again — it rolls back first.
#
# EXIT STATUS: non-zero if any validation count fails to reconcile, so the
# script is CI/gate-friendly.

set -euo pipefail

GROUP="mandala_images"
# Drush invocation. Defaults to `ddev drush`; override for non-DDEV contexts
# (e.g. staging/CI or a box without mkcert) by exporting DRUSH, e.g.
#   DRUSH="docker exec ddev-mandala-web bash -lc 'cd /var/www/html/drupal && ./vendor/bin/drush'"
DRUSH="${DRUSH:-ddev drush}"

# ---------------------------------------------------------------------------
# Baseline reconciliation targets — production Images dump 2026-06-11.
# Source of truth: docs/planning/images-content-model-audit.md (data profile)
# and the 1a.7 full-run reconciliation. These are DUMP-SPECIFIC; a newer dump
# means new expected values (update here and in the runbook together).
#
# Plain "key<space>count" lines (not an associative array) so the script runs
# on the stock macOS /bin/bash 3.2 that teammates invoke it with.
# ---------------------------------------------------------------------------
EXPECT_LIST="
node:shanti_image 111340
paragraph:image_agent 111194
paragraph:image_descriptions 55038
paragraph:external_classification 9
term:external_classification_scheme 2
field:field_subjects 79337
field:field_places 68755
field:field_kmap_terms 61668
field:field_kmap_collections 83494
"

# A single php:eval that emits "key<TAB>count" lines for every actual count.
# Uses bound placeholders (no SQL string-literal quotes) — MariaDB treats
# double-quoted SQL literals as identifiers, and this script's bash layer is
# single-quoted, so placeholders keep both layers clean.
COUNT_EVAL='
$db = \Drupal::database();
$q = function($sql, $args = []) use ($db) { return (int) $db->query($sql, $args)->fetchField(); };
printf("node:shanti_image\t%d\n", $q("SELECT COUNT(*) FROM node_field_data WHERE type = :t", [":t" => "shanti_image"]));
foreach (["image_agent", "image_descriptions", "external_classification"] as $t) {
  printf("paragraph:%s\t%d\n", $t, $q("SELECT COUNT(*) FROM paragraphs_item_field_data WHERE type = :t", [":t" => $t]));
}
printf("term:external_classification_scheme\t%d\n", $q("SELECT COUNT(*) FROM taxonomy_term_field_data WHERE vid = :v", [":v" => "external_classification_scheme"]));
foreach (["field_subjects", "field_places", "field_kmap_terms", "field_kmap_collections"] as $f) {
  $tbl = "node__" . $f;
  $n = $db->schema()->tableExists($tbl) ? $q("SELECT COUNT(*) FROM {" . $tbl . "}") : 0;
  printf("field:%s\t%d\n", $f, $n);
}
'

log()  { printf "\n\033[1m== %s ==\033[0m\n" "$*"; }
info() { printf "   %s\n" "$*"; }

# ---------------------------------------------------------------------------

phase_rollback() {
  log "ROLLBACK — $GROUP"
  # reset-status first in case a prior run left a migration locked as Importing.
  for m in $($DRUSH migrate:status --group="$GROUP" --field=id 2>/dev/null); do
    $DRUSH migrate:reset-status "$m" >/dev/null 2>&1 || true
  done
  $DRUSH migrate:rollback --group="$GROUP"

  # Verify the graph is actually clean — rollback of computed/derived rows can
  # leave stragglers, so assert zero rather than trust the exit code.
  local remaining
  remaining=$($DRUSH php:eval 'echo (int) \Drupal::database()->query("SELECT COUNT(*) FROM node_field_data WHERE type=\"shanti_image\"")->fetchField();')
  if [ "$remaining" -eq 0 ]; then
    info "clean: 0 shanti_image nodes remain."
  else
    info "WARNING: $remaining shanti_image nodes still present after rollback."
    return 1
  fi
}

phase_import() {
  log "IMPORT — $GROUP"
  $DRUSH migrate:import --group="$GROUP"
}

phase_validate() {
  log "VALIDATE — reconcile counts vs 2026-06-11 baseline"
  # Actual counts as "key<TAB>count" lines, captured once.
  local actual fail=0 key want got
  actual=$($DRUSH php:eval "$COUNT_EVAL")

  # Feed the expected list via here-doc (not a pipe) so the loop runs in this
  # shell and `fail` survives — bash 3.2 has no lastpipe.
  while read -r key want; do
    [ -z "$key" ] && continue
    got=$(printf '%s\n' "$actual" | awk -F'\t' -v k="$key" '$1==k {print $2}')
    [ -z "$got" ] && got=MISSING
    if [ "$got" = "$want" ]; then
      printf "   \033[32mPASS\033[0m  %-40s %s\n" "$key" "$got"
    else
      printf "   \033[31mFAIL\033[0m  %-40s got=%s want=%s\n" "$key" "$got" "$want"
      fail=1
    fi
  done <<EOF
$EXPECT_LIST
EOF

  if [ "$fail" -ne 0 ]; then
    log "VALIDATE: FAILED — counts do not reconcile."
    return 1
  fi
  log "VALIDATE: OK — all counts reconcile."
}

phase_audit() {
  log "AUDIT — index shanti_image to kmassets, then detect drift"
  info "Requires VPN/VPC (solr_master_url → staging master). Bulk-indexing 111k docs."
  $DRUSH kmassets:index-all shanti_image
  $DRUSH kmassets:audit --check-stale
}

phase_cycle() {
  # A clean, repeatable test run: guarantee clean → import → validate.
  # Leaves the data imported for follow-on checks (IIIF, KMaps round-trip,
  # audit). Run `rollback` afterwards to close the loop back to clean.
  phase_rollback
  phase_import
  phase_validate
  log "CYCLE complete — data is imported and reconciled."
  info "To close the loop back to clean:  ./scripts/migration-cycle.sh rollback"
}

# ---------------------------------------------------------------------------

case "${1:-cycle}" in
  rollback) phase_rollback ;;
  import)   phase_import ;;
  validate) phase_validate ;;
  audit)    phase_audit ;;
  cycle)    phase_cycle ;;
  *)
    echo "Unknown phase: ${1:-}" >&2
    echo "Phases: validate | import | rollback | audit | cycle" >&2
    exit 2
    ;;
esac
