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
#   ./scripts/migration-cycle.sh baseline      # print current counts in EXPECT_LIST format (recalibrate)
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

# Drush invocation for the two MEMORY-HEAVY phases (import, audit). Defaults to
# $DRUSH so nothing changes locally, but exists because the stock 128M CLI
# memory_limit has now killed a long run TWICE, in the same way both times:
#
#   2026-07-17/18  migrate:import        OOM ~48,900 of 111,340 in
#   2026-08-13     kmassets:index-all    OOM at the same point, same crash
#
# Both die inside CacheTagsChecksumTrait, and both need migrate:reset-status
# before they can resume — and a resume re-iterates the FULL source count, so
# it is no faster than starting over. Budget an hour to lose, or set this.
#
# The limit CANNOT be raised by prefixing `php -d` to the `drush` wrapper — it
# is a shell script, so the flag never reaches the PHP that matters. It must
# target drush.php directly. On dev-0:
#
#   DRUSH_HEAVY="docker exec mandala-drupal-0 php -d memory_limit=1024M \
#     /opt/drupal/app/drupal/vendor/bin/drush.php"
#
# See docs/deferred/migrate-large-migration-oom-and-resume-behavior.md.
DRUSH_HEAVY="${DRUSH_HEAVY:-$DRUSH}"

# ---------------------------------------------------------------------------
# Baseline reconciliation targets — staging Images dump 2026-07-07.
# Verified 1:1 against the D7 source (d7_images) this session: every count below
# equals its D7 source count exactly, so the migration is faithful and these are
# the correct targets for THIS dump. These are DUMP-SPECIFIC; a newer dump means
# new expected values — regenerate them with
#   ./scripts/migration-cycle.sh baseline
# then paste the output over this list (and update the runbook table).
#
# History: supersedes the 2026-06-11 production-dump baseline. The largest change
# was field_kmap_terms 61668 -> 55553 — a real source-data change between the two
# dumps, NOT a migration defect (D7src == D11 migrated confirmed for every KMaps
# field). See docs/planning/migration-cycle-runbook.md.
#
# Plain "key<space>count" lines (not an associative array) so the script runs
# on the stock macOS /bin/bash 3.2 that teammates invoke it with.
# ---------------------------------------------------------------------------
EXPECT_LIST="
node:shanti_image 111343
paragraph:image_agent 111350
paragraph:image_descriptions 55112
paragraph:external_classification 9
term:external_classification_scheme 2
field:field_subjects 79174
field:field_places 68790
field:field_kmap_terms 55553
field:field_kmap_collections 83493
entity:path_alias 111304
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
printf("entity:path_alias\t%d\n", $q("SELECT COUNT(*) FROM path_alias WHERE path LIKE :p", [":p" => "/node/%"]));
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

  # Bound placeholder, not a SQL string literal: Drupal's MySQL connection runs
  # in ANSI_QUOTES mode, so a double-quoted "shanti_image" is parsed as an
  # identifier (column) and errors. Matches the COUNT_EVAL pattern below.
  remaining=$($DRUSH php:eval 'echo (int) \Drupal::database()->query("SELECT COUNT(*) FROM node_field_data WHERE type = :t", [":t" => "shanti_image"])->fetchField();')
  if [ "$remaining" -eq 0 ]; then
    info "clean: 0 shanti_image nodes remain."
  else
    info "WARNING: $remaining shanti_image nodes still present after rollback."
    return 1
  fi
}

phase_import() {
  log "IMPORT — $GROUP"
  [ "$DRUSH_HEAVY" = "$DRUSH" ] && info "NOTE: DRUSH_HEAVY unset — running at the default PHP memory_limit. See the header if this OOMs."
  $DRUSH_HEAVY migrate:import --group="$GROUP"
}

phase_validate() {
  log "VALIDATE — reconcile counts vs configured EXPECT_LIST baseline"
  # Actual counts as "key<TAB>count" lines, captured once.
  local actual fail=0 key want got
  actual=$($DRUSH php:eval "$COUNT_EVAL")

  # Feed the expected list via here-doc (not a pipe) so the loop runs in this
  # shell and `fail` survives — bash 3.2 has no lastpipe.
  while read -r key want; do
    [ -z "$key" ] && continue
    # Allow '#' comments in EXPECT_LIST — without this a comment line parses as
    # key='#', want=<word> and reports a spurious FAIL.
    case "$key" in \#*) continue ;; esac
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
  [ "$DRUSH_HEAVY" = "$DRUSH" ] && info "NOTE: DRUSH_HEAVY unset — running at the default PHP memory_limit. See the header if this OOMs."
  $DRUSH_HEAVY kmassets:index-all shanti_image
  $DRUSH kmassets:audit --check-stale
}

phase_baseline() {
  # Emit the current counts in EXPECT_LIST format, for recalibrating the baseline
  # after loading a new dump. Run against a known-good, fully-imported dataset,
  # then paste the output over EXPECT_LIST above and into the runbook table.
  log "BASELINE — current counts in EXPECT_LIST format (paste over EXPECT_LIST)"
  $DRUSH php:eval "$COUNT_EVAL" | awk -F'\t' 'NF==2 {printf "%s %s\n", $1, $2}'
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
  baseline) phase_baseline ;;
  *)
    echo "Unknown phase: ${1:-}" >&2
    echo "Phases: validate | import | rollback | audit | cycle | baseline" >&2
    exit 2
    ;;
esac
