#!/usr/bin/env bash
#
# Pre-commit check for drupal/config/sync: would an export change it?
#
# Compares what is committed in config/sync against what Drupal would write
# on `drush config:export`, in DDEV. Catches, before a PR rather than at
# deploy time:
#   - the DB and the sync directory disagreeing (semantic drift)
#   - config that exists in the DB but not in config/sync (never exported)
#   - comments in config/sync YAML, which an export silently strips
#   - formatting differences (key order, quoting) an export would rewrite
#
# See docs/deferred/config-export-drift-hand-edited-yaml.md (the practice:
# change in a live Drupal, then export) and
# docs/deferred/config-export-not-scoped-strips-comments.md (why comments
# cannot live in config/sync, and how this check identifies them).
#
# Usage:
#   ./scripts/config-check.sh            # drift and unexported config FAIL;
#                                        # comments and formatting WARN
#   ./scripts/config-check.sh --strict   # comments and formatting FAIL too
#                                        # (use after the existing comments
#                                        # have been relocated)
#
# Requires DDEV running with the local DB in step with config/sync. Writes the
# export to a temp dir inside the container and removes it afterwards; it does
# not touch config/sync or the DB.
#
# Exit code: 0 if no FAIL, 1 otherwise.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

STRICT=0
for arg in "$@"; do
  case "$arg" in
    --strict) STRICT=1 ;;
    -h|--help) sed -n '2,29p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown argument: $arg (try --help)" >&2; exit 2 ;;
  esac
done

SYNC_DIR="${CONFIG_SYNC_DIR:-/var/www/html/drupal/config/sync}"   # path inside the DDEV container
TMP_DIR="/tmp/config-check.$$"                                    # inside the container
DIFF_FILE="$(mktemp)"

cleanup() {
  ddev exec "rm -rf '$TMP_DIR'" >/dev/null 2>&1
  rm -f "$DIFF_FILE"
}
trap cleanup EXIT

OVERALL_FAIL=0
fail() { echo "FAIL: $*"; OVERALL_FAIL=1; }
warn() { echo "WARN: $*"; }
pass() { echo "PASS: $*"; }
# A finding that is a warning by default and a failure under --strict.
soft() { if [ "$STRICT" -eq 1 ]; then fail "$*"; else warn "$*"; fi; }

# ── 0. DDEV is up ───────────────────────────────────────────────────────
echo "=== 0. DDEV ==="
if ! ddev describe >/dev/null 2>&1; then
  fail "DDEV is not running -- start it with 'ddev start'"
  exit 1
fi
pass "DDEV is running"

# ── 1. DB vs sync directory (semantic) ──────────────────────────────────
echo
echo "=== 1. config:status (DB vs sync directory) ==="
STATUS="$(ddev drush config:status 2>&1)"
if echo "$STATUS" | grep -q "No differences between DB and sync directory"; then
  pass "no differences between DB and sync directory"
else
  echo "$STATUS"
  fail "DB and config/sync disagree -- resolve this first (the export diff below would be polluted by it)"
  exit 1
fi

# ── 2. Export to a temp dir and diff ────────────────────────────────────
echo
echo "=== 2. Export diff (what 'config:export' would change) ==="
if ! ddev drush config:export --destination="$TMP_DIR" -y >/dev/null 2>&1; then
  fail "drush config:export to a temp dir failed"
  exit 1
fi
ddev exec "diff -r '$SYNC_DIR' '$TMP_DIR'" >"$DIFF_FILE" 2>&1
DIFF_RC=$?
if [ "$DIFF_RC" -ge 2 ]; then
  cat "$DIFF_FILE"
  fail "diff itself failed (exit $DIFF_RC); is CONFIG_SYNC_DIR right? (now: $SYNC_DIR)"
  exit 1
fi

# Classify. Lines starting "< " are in config/sync only, "> " are in the export only.
#   COMMENTS <file> <n>   removed lines that are YAML comments
#   FORMAT   <file>       other non-blank differences (reordering, quoting)
#   ONLY_EXPORT <name>    config in the DB that is not in config/sync
#   ONLY_SYNC   <name>    file in config/sync the export would not write
# .htaccess is Drupal's own deny-all file in a config dir; an export never writes it.
SUMMARY="$(awk -v tmp="$TMP_DIR" '
  /^diff -r /      { f = $3; sub(".*/", "", f); next }
  /^Only in /      { dir = $3; sub(":$", "", dir); name = $4
                     if (name == ".htaccess") next
                     print ((dir == tmp) ? "ONLY_EXPORT " : "ONLY_SYNC ") name; next }
  /^< /            { l = substr($0, 3)
                     if (l ~ /^[ \t]*$/) next
                     if (l ~ /^[ \t]*#/) { c[f]++; next }
                     fmt[f] = 1; next }
  /^> /            { l = substr($0, 3)
                     if (l ~ /^[ \t]*$/) next
                     fmt[f] = 1; next }
  END { for (k in c)   print "COMMENTS " k " " c[k]
        for (k in fmt) print "FORMAT " k }
' "$DIFF_FILE" | sort)"

ONLY_EXPORT="$(echo "$SUMMARY" | awk '$1=="ONLY_EXPORT"{print "  " $2}')"
ONLY_SYNC="$(echo "$SUMMARY"   | awk '$1=="ONLY_SYNC"{print "  " $2}')"
COMMENTS="$(echo "$SUMMARY"    | awk '$1=="COMMENTS"{print "  " $3 "\t" $2; n+=$3} END{if(n) print "TOTAL " n}')"
FORMAT="$(echo "$SUMMARY"      | awk '$1=="FORMAT"{print "  " $2}')"

if [ -n "$ONLY_EXPORT" ]; then
  echo "$ONLY_EXPORT"
  fail "config exists in the DB but is not in config/sync -- run the export for it (and review the diff) before committing"
fi
if [ -n "$ONLY_SYNC" ]; then
  echo "$ONLY_SYNC"
  fail "files in config/sync that Drupal would not write -- stale or hand-created config"
fi

if [ -n "$COMMENTS" ]; then
  NFILES="$(echo "$COMMENTS" | grep -vc '^TOTAL')"
  NLINES="$(echo "$COMMENTS" | awk '/^TOTAL/{print $2}')"
  echo "$COMMENTS" | grep -v '^TOTAL'
  soft "$NLINES comment line(s) in $NFILES file(s) would be stripped by the next export -- move the rationale out of config/sync (docs/deferred/config-export-not-scoped-strips-comments.md)"
else
  pass "no comments in config/sync that an export would strip"
fi

if [ -n "$FORMAT" ]; then
  echo "$FORMAT"
  soft "$(echo "$FORMAT" | wc -l | tr -d ' ') file(s) an export would reformat (key order or quoting; the config itself is the same)"
else
  pass "no formatting differences"
fi

echo
if [ "$OVERALL_FAIL" -eq 0 ]; then
  echo "=== OK: no FAIL findings ==="
else
  echo "=== config/sync needs attention: see FAIL lines above ==="
fi
exit "$OVERALL_FAIL"
