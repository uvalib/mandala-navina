#!/usr/bin/env bash
#
# Session-close checklist runner for Mandala Navina.
#
# Implements the objectively-checkable parts of CLAUDE.md's "Session end
# ritual". Two of its four steps are judgment calls (flushing decisions to
# docs/, refreshing Claude memory) and stay manual -- this script only
# prints them as reminders. The other two are real, mechanical, easy-to-
# forget gaps this script actually checks for:
#   - a new doc under docs/adr, docs/spikes, or docs/deferred that never
#     got added to that directory's .pages nav file (invisible in mkdocs
#     until listed) or to its README.md index table
#   - uncommitted or unpushed work left behind at session end
#
# ── KEEP IN SYNC WITH CLAUDE.md ──────────────────────────────────────────
# This script is the executable form of CLAUDE.md's "Session end ritual".
# If that checklist changes, update this script to match (and vice versa).
# See the cross-reference note in that section.
# ──────────────────────────────────────────────────────────────────────────
#
# Usage:
#   ./scripts/session-close-check.sh
#
# Exit code: 0 if nothing mechanical was missed, 1 otherwise. A nonzero
# exit does NOT mean don't close the session -- it means read the FAIL/WARN
# lines before you do.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

OVERALL_FAIL=0
fail() { echo "FAIL: $*"; OVERALL_FAIL=1; }
warn() { echo "WARN: $*"; }
pass() { echo "PASS: $*"; }

# ── 1. Docs added but not indexed ───────────────────────────────────────
echo "=== 1. New docs missing from .pages / README.md index ==="
for dir in docs/adr docs/spikes docs/deferred; do
  PAGES="$dir/.pages"
  README="$dir/README.md"
  MISSING=0
  for f in "$dir"/*.md; do
    base="$(basename "$f")"
    [ "$base" = "README.md" ] && continue
    if [ -f "$PAGES" ] && ! grep -qF "$base" "$PAGES"; then
      fail "$f not listed in $PAGES"
      MISSING=1
    fi
    if [ -f "$README" ] && ! grep -qF "$base" "$README"; then
      fail "$f not linked from $README"
      MISSING=1
    fi
  done
  [ "$MISSING" -eq 0 ] && pass "$dir -- every doc is indexed"
done
echo

# ── 2. Working tree / push status ───────────────────────────────────────
echo "=== 2. Uncommitted or unpushed work ==="
DIRTY="$(git status --short)"
if [ -n "$DIRTY" ]; then
  fail "uncommitted changes present:"
  echo "$DIRTY" | sed 's/^/  /'
else
  pass "working tree clean"
fi

git fetch origin main -q 2>/dev/null || true
AHEAD="$(git rev-list --count origin/main..HEAD 2>/dev/null || echo 0)"
if [ "$AHEAD" -gt 0 ]; then
  fail "local main is $AHEAD commit(s) ahead of origin/main -- push or open a PR"
else
  pass "local main matches origin/main (nothing unpushed)"
fi
echo

# ── 3. Open PRs (reminder, not a failure) ───────────────────────────────
echo "=== 3. Open PRs ==="
if command -v gh >/dev/null 2>&1; then
  OPEN_PRS="$(gh pr list --state open 2>/dev/null)"
  if [ -n "$OPEN_PRS" ]; then
    warn "open PRs remain -- merge or explicitly leave open on purpose:"
    echo "$OPEN_PRS" | sed 's/^/  /'
  else
    pass "no open PRs"
  fi
else
  warn "gh not available -- skipped open-PR check"
fi
echo

# ── 4. Manual steps -- judgment calls, not scripted ─────────────────────
echo "=== 4. Manual steps (per CLAUDE.md -- not automatable) ==="
cat <<'EOF'
  [ ] Flush decisions to docs/adr/, findings to docs/spikes/, deferred
      notes to docs/deferred/ (content, not just indexing -- section 1
      above only catches docs that exist but aren't linked).
  [ ] For long planning/spike sessions: run scripts/save-session-log.py
      against this session's own transcript.
  [ ] Refresh local Claude memory: update project-mandala-state (sprint/
      spike/ADR status, dates) and add/revise topic memories for anything
      decided this session. Memory is per-machine/per-driver -- committed
      docs/ remains the team source of truth.
EOF
echo

# ── Summary ──────────────────────────────────────────────────────────────
echo "=== Summary ==="
if [ "$OVERALL_FAIL" -eq 0 ]; then
  echo "Mechanical checks clean. Work through the manual checklist above, then close."
else
  echo "One or more mechanical checks failed -- see FAIL lines above before closing."
fi
exit "$OVERALL_FAIL"
