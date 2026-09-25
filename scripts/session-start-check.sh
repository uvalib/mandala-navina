#!/usr/bin/env bash
#
# Session-start health check for Mandala Navina.
#
# Implements CLAUDE.md's "Session startup" steps 1 and 3:
#   1. git sync (status + pull --ff-only, stop if it doesn't fast-forward)
#   3. local DDEV DB/config vs dev-0 parity (config:status, content/identity
#      counts, config/sync pushed to GitHub, and an advisory spot-check for
#      D11 id drift between environments -- see step 3d's own comment)
#
# Step 2 (reading docs/adr, docs/spikes, docs/deferred, docs/session-logs)
# is a judgment task -- this script only prints pointers to make that faster,
# it does not attempt to replace the reading.
#
# ── KEEP IN SYNC WITH CLAUDE.md ──────────────────────────────────────────
# This script is the executable form of CLAUDE.md's "Session startup"
# section. If that checklist changes, update this script to match (and if
# you change this script's checks, update the checklist text too). They are
# two views of one procedure, not independent -- CLAUDE.md is public/
# narrative, this is the automated/objective subset of it. See the
# "Session startup" section itself for the cross-reference note.
# ──────────────────────────────────────────────────────────────────────────
#
# Usage:
#   ./scripts/session-start-check.sh                # full check
#   ./scripts/session-start-check.sh --local-only    # skip dev-0 SSH (no VPN)
#
# Exit code: 0 if everything is clean, 1 if any check found drift, failed,
# or needs a human decision (e.g. diverged git history).

set -uo pipefail  # not -e: run every check and report all of them, don't bail on the first

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

LOCAL_ONLY=0
[ "${1:-}" = "--local-only" ] && LOCAL_ONLY=1

DEV0_SSH_USER="${DEV0_SSH_USER:-$(whoami)}"
DEV0_SSH_HOST="${DEV0_SSH_HOST:-mandala-drupal-dev-0.internal.lib.virginia.edu}"
DEV0_SSH_KEY="${DEV0_SSH_KEY:-$HOME/.ssh/id_rsa}"
DEV0_CONTAINER="${DEV0_CONTAINER:-mandala-drupal-0}"
DEV0_DOCROOT="${DEV0_DOCROOT:-/opt/drupal/app/drupal}"

OVERALL_FAIL=0
fail() { echo "FAIL: $*"; OVERALL_FAIL=1; }
warn() { echo "WARN: $*"; }
pass() { echo "PASS: $*"; }

# ── 1. Git sync ────────────────────────────────────────────────────────────
echo "=== 1. Git sync ==="
git status --short
if git pull --ff-only; then
  pass "git pull --ff-only (up to date or fast-forwarded cleanly)"
else
  fail "git pull --ff-only did not fast-forward -- stop and investigate by hand, do not resolve automatically (per CLAUDE.md)"
fi
echo

# ── 2. Orientation pointers (not a check -- just saves lookup round-trips) ─
echo "=== 2. Orientation (read these, judgment task, not scripted) ==="
echo "  docs/adr/README.md"
echo "  docs/spikes/README.md"
echo "  docs/deferred/README.md"
LATEST_LOG="$(ls -t docs/session-logs/*.md 2>/dev/null | head -1)"
if [ -n "$LATEST_LOG" ]; then
  echo "  Most recent session log: $LATEST_LOG"
else
  echo "  (no session logs found)"
fi
echo

# ── 3a. Local DDEV config:status ───────────────────────────────────────────
echo "=== 3a. Local DDEV config:status ==="
if ! ddev describe >/dev/null 2>&1; then
  warn "ddev project not found/started -- starting it now"
fi
ddev start >/dev/null 2>&1
LOCAL_CONFIG_STATUS="$(ddev drush config:status 2>&1)"
echo "$LOCAL_CONFIG_STATUS"
if echo "$LOCAL_CONFIG_STATUS" | grep -q "No differences between DB and sync directory"; then
  pass "local config:status clean"
else
  fail "local config:status shows drift -- see output above"
fi
echo

# ── 3b. Local vs dev-0 content/identity counts ─────────────────────────────
echo "=== 3b. Local vs dev-0 content/identity counts ==="

# Single source of truth for the comparison query -- used against BOTH
# environments so the two sides are guaranteed to be asking the same
# question. Node type counts are queried dynamically (not hardcoded) so
# this doesn't go stale as Texts/Sources/etc. land.
read -r -d '' COUNT_QUERY_PHP <<'PHP' || true
$db = \Drupal::database();
$rows = [];
foreach ($db->query("SELECT type, COUNT(*) c FROM node_field_data GROUP BY type ORDER BY type")->fetchAllKeyed() as $type => $c) {
  $rows["node:$type"] = $c;
}
$rows['users'] = (int) $db->query("SELECT COUNT(*) FROM users_field_data")->fetchField();
$rows['groups'] = (int) $db->query("SELECT COUNT(*) FROM groups_field_data")->fetchField();
$rows['authmap'] = (int) $db->query("SELECT COUNT(*) FROM authmap")->fetchField();
$rows['group_relationship'] = (int) $db->query("SELECT COUNT(*) FROM group_relationship_field_data")->fetchField();
ksort($rows);
foreach ($rows as $k => $v) {
  echo "$k|$v\n";
}
PHP

PHP_B64="$(printf '%s' "$COUNT_QUERY_PHP" | base64 | tr -d '\n')"

LOCAL_COUNTS="$(ddev drush eval "eval(base64_decode('$PHP_B64'));" 2>/dev/null)"

if [ "$LOCAL_ONLY" -eq 1 ]; then
  warn "--local-only given -- skipping dev-0 comparison. Local counts:"
  echo "$LOCAL_COUNTS"
else
  # Wrap the whole remote command in its own base64 blob so nothing has to
  # survive local-bash -> ssh -> remote-sh -> docker-exec-sh -> drush-eval
  # quote nesting. drush eval on dev-0 requires this route (not sql:query)
  # because that hits a self-signed-cert TLS error against the RDS backend
  # -- eval() runs through Drupal's already-bootstrapped connection instead.
  REMOTE_SCRIPT="cd $DEV0_DOCROOT && vendor/bin/drush eval 'eval(base64_decode(\"$PHP_B64\"));'"
  REMOTE_B64="$(printf '%s' "$REMOTE_SCRIPT" | base64 | tr -d '\n')"

  DEV0_COUNTS="$(ssh -i "$DEV0_SSH_KEY" -o ConnectTimeout=10 -o BatchMode=yes \
    "$DEV0_SSH_USER@$DEV0_SSH_HOST" \
    "sudo docker exec $DEV0_CONTAINER sh -c \"echo $REMOTE_B64 | base64 -d | sh\"" 2>/dev/null)"

  if [ -z "$DEV0_COUNTS" ]; then
    warn "could not reach dev-0 (VPN off? host down?) -- skipping comparison. Local counts:"
    echo "$LOCAL_COUNTS"
  else
    DIFF_OUT="$(diff <(echo "$LOCAL_COUNTS") <(echo "$DEV0_COUNTS"))"
    if [ -z "$DIFF_OUT" ]; then
      pass "local counts match dev-0 exactly:"
      echo "$LOCAL_COUNTS" | sed 's/^/  /'
    else
      fail "local counts DIFFER from dev-0 (< local / > dev-0):"
      echo "$DIFF_OUT" | sed 's/^/  /'
      echo "  -> if local is behind, run: ./scripts/update-db-from-remote.sh dev"
      echo "     (destructive -- snapshot first: ddev snapshot)"
    fi
  fi
fi
echo

# ── 3c. config/sync pushed to GitHub, not just local ───────────────────────
echo "=== 3c. drupal/config/sync vs origin/main ==="
git fetch origin main -q
SYNC_STATUS="$(git status --short drupal/config/sync/)"
SYNC_DIFF="$(git diff origin/main -- drupal/config/sync/)"
if [ -n "$SYNC_STATUS" ]; then
  fail "drupal/config/sync has uncommitted local changes:"
  echo "$SYNC_STATUS" | sed 's/^/  /'
elif [ -n "$SYNC_DIFF" ]; then
  fail "drupal/config/sync differs from origin/main (committed locally but not pushed?)"
else
  pass "drupal/config/sync matches origin/main, nothing uncommitted"
fi
echo

# ── 3d. ID-drift spot-check: does a D11 node id mean the same node in ──────
#        both environments? (advisory, not pass/fail) ──────────────────────
echo "=== 3d. Same-node-id spot-check (local vs dev-0) ==="
echo "Not a gate -- drift here is EXPECTED once you've re-run migrations"
echo "locally (each environment's D11 ids are an artifact of its own"
echo "migration/rollback history, never a portable identifier). This is a"
echo "standing reminder, not a failure: application code must resolve"
echo "content via field_legacy_site + field_legacy_nid (ADR 017), never a"
echo "hardcoded D11 id. Confirmed incident + fix: PRs #238/#239, and"
echo "docs/deferred/migration-legacy-nid-required-convention.md."

if [ "$LOCAL_ONLY" -eq 1 ]; then
  echo "(skipped -- --local-only)"
else
  read -r -d '' DRIFT_SAMPLE_PHP <<'PHP' || true
$db = \Drupal::database();
$rows = $db->query("SELECT n.entity_id AS nid, n.field_legacy_nid_value AS lnid, s.field_legacy_site_value AS site
  FROM {node__field_legacy_nid} n
  JOIN {node__field_legacy_site} s ON s.entity_id = n.entity_id
  ORDER BY RAND() LIMIT 25")->fetchAll();
foreach ($rows as $r) {
  echo $r->site . '|' . $r->lnid . '|' . $r->nid . "\n";
}
PHP
  DRIFT_B64="$(printf '%s' "$DRIFT_SAMPLE_PHP" | base64 | tr -d '\n')"
  LOCAL_SAMPLE="$(ddev drush eval "eval(base64_decode('$DRIFT_B64'));" 2>/dev/null)"

  if [ -z "$LOCAL_SAMPLE" ]; then
    warn "no field_legacy_nid rows found locally -- skipping drift spot-check"
  else
    PAIRS="$(echo "$LOCAL_SAMPLE" | awk -F'|' '{print $1":"$2}' | tr '\n' ',' | sed 's/,$//')"
    read -r -d '' DRIFT_CHECK_TEMPLATE <<'PHP' || true
$pairs = explode(',', 'PAIRS_PLACEHOLDER');
$db = \Drupal::database();
foreach ($pairs as $p) {
  if (!str_contains($p, ':')) { continue; }
  [$site, $lnid] = explode(':', $p);
  $row = $db->query("SELECT n.entity_id AS nid FROM {node__field_legacy_nid} n
    JOIN {node__field_legacy_site} s ON s.entity_id = n.entity_id
    WHERE n.field_legacy_nid_value = :lnid AND s.field_legacy_site_value = :site",
    [':lnid' => $lnid, ':site' => $site])->fetchField();
  echo $site . '|' . $lnid . '|' . ($row ?: 'MISSING') . "\n";
}
PHP
    DRIFT_CHECK_PHP="${DRIFT_CHECK_TEMPLATE/PAIRS_PLACEHOLDER/$PAIRS}"
    DRIFT_CHECK_B64="$(printf '%s' "$DRIFT_CHECK_PHP" | base64 | tr -d '\n')"
    REMOTE_SCRIPT="cd $DEV0_DOCROOT && vendor/bin/drush eval 'eval(base64_decode(\"$DRIFT_CHECK_B64\"));'"
    REMOTE_B64="$(printf '%s' "$REMOTE_SCRIPT" | base64 | tr -d '\n')"

    DEV0_SAMPLE="$(ssh -i "$DEV0_SSH_KEY" -o ConnectTimeout=10 -o BatchMode=yes \
      "$DEV0_SSH_USER@$DEV0_SSH_HOST" \
      "sudo docker exec $DEV0_CONTAINER sh -c \"echo $REMOTE_B64 | base64 -d | sh\"" 2>/dev/null)"

    if [ -z "$DEV0_SAMPLE" ]; then
      warn "could not reach dev-0 for drift spot-check"
    else
      DRIFT_DIFF="$(diff <(echo "$LOCAL_SAMPLE" | sort) <(echo "$DEV0_SAMPLE" | sort))"
      if [ -z "$DRIFT_DIFF" ]; then
        pass "sampled $(echo "$LOCAL_SAMPLE" | grep -c .) legacy-identity pairs -- same D11 nid in both environments"
      else
        warn "D11 nid MISMATCH for the same content between local and dev-0 (< local / > dev-0):"
        echo "$DRIFT_DIFF" | sed 's/^/  /'
        echo "  -> NOT a bug by itself -- expected after local migration re-runs."
        echo "     If you're writing code that references specific content (a demo"
        echo "     list, a hardcoded reference node, anything), resolve it via"
        echo "     field_legacy_site + field_legacy_nid, never the raw id shown above."
      fi
    fi
  fi
fi
echo

# ── Summary ──────────────────────────────────────────────────────────────
echo "=== Summary ==="
if [ "$OVERALL_FAIL" -eq 0 ]; then
  echo "All automated checks clean. Read the orientation docs above, then proceed."
else
  echo "One or more checks found drift -- see FAIL lines above before doing any work."
fi
exit "$OVERALL_FAIL"
