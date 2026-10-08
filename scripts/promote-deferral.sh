#!/usr/bin/env bash
#
# Promote a docs/deferred note to a GitHub Issue (label 'deferred').
#
# Any deferral may be promoted at any time, with or without an owner: an
# unassigned issue is a legitimate state ("free to pick up" or "needs a group
# decision"). See docs/deferred/README.md, "Tracking: GitHub Issues".
#
# What it does:
#   1. Opens an issue titled from the note's H1, linking to the note on main.
#      The body deliberately does NOT copy note content: the repo and its
#      issues are public, and the note is the place for detail.
#   2. Writes '**Issue:** #N' into the note's header (replacing '**Issue:**
#      none' or the legacy '**Jira:** (add when available)' line).
#   You then commit that header change.
#
# Usage:
#   ./scripts/promote-deferral.sh <note> [--assignee LOGIN] [--decision] [--title TEXT] [--yes]
#     <note>        file in docs/deferred (with or without .md), or a path
#     --assignee    GitHub login to assign (omit to leave unassigned)
#     --decision    mark the issue "needs a group decision" (default for
#                   unassigned issues is "free to pick up")
#     --title       override the title (use if the note's H1 says too much)
#     --yes         skip the confirmation prompt
#
# Prints the title/body and asks for confirmation first, since issues are public.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

NOTE="" ASSIGNEE="" DECISION=0 TITLE="" YES=0
while [ $# -gt 0 ]; do
  case "$1" in
    --assignee) ASSIGNEE="${2:?--assignee needs a value}"; shift 2 ;;
    --decision) DECISION=1; shift ;;
    --title)    TITLE="${2:?--title needs a value}"; shift 2 ;;
    --yes)      YES=1; shift ;;
    -h|--help)  sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*)         echo "unknown option: $1" >&2; exit 2 ;;
    *)          NOTE="$1"; shift ;;
  esac
done
[ -n "$NOTE" ] || { echo "usage: $0 <note> [--assignee LOGIN] [--decision] [--title TEXT] [--yes]" >&2; exit 2; }

if   [ -f "$NOTE" ];                      then FILE="$NOTE"
elif [ -f "docs/deferred/$NOTE" ];        then FILE="docs/deferred/$NOTE"
elif [ -f "docs/deferred/$NOTE.md" ];     then FILE="docs/deferred/$NOTE.md"
else echo "note not found: $NOTE" >&2; exit 1; fi
BASENAME="$(basename "$FILE")"

if grep -qE '^\*\*Issue:\*\* *#[0-9]+' "$FILE"; then
  echo "already promoted: $(grep -m1 -E '^\*\*Issue:\*\*' "$FILE")" >&2; exit 1
fi

[ -n "$TITLE" ] || TITLE="$(grep -m1 '^# ' "$FILE" | sed 's/^# //')"
[ -n "$TITLE" ] || { echo "no H1 in $FILE; pass --title" >&2; exit 1; }

if [ -n "$ASSIGNEE" ]; then
  STATUS="**Owner:** $ASSIGNEE"
elif [ "$DECISION" -eq 1 ]; then
  STATUS="**Unassigned: needs a group decision** (agenda item for the next group meeting, not a pickup)."
else
  STATUS="**Unassigned: free to pick up.** Assign yourself and say so."
fi
BODY="$STATUS
**Note:** [$BASENAME](https://github.com/uvalib/mandala-navina/blob/main/docs/deferred/$BASENAME)

Detail lives in the note. Keep this issue to status, owner and links (public repo: say that a problem exists and who to ask, never what it is)."

echo "--- Title: $TITLE"; echo "--- Assignee: ${ASSIGNEE:-(none)}"; echo "--- Body:"; echo "$BODY"; echo
if [ "$YES" -ne 1 ]; then
  read -r -p "Open this PUBLIC issue? [y/N] " ans
  [ "$ans" = "y" ] || [ "$ans" = "Y" ] || { echo "aborted"; exit 1; }
fi

ARGS=(--label deferred --title "$TITLE" --body "$BODY")
[ -n "$ASSIGNEE" ] && ARGS+=(--assignee "$ASSIGNEE")
URL="$(gh issue create "${ARGS[@]}")"
NUM="${URL##*/}"
echo "$URL"

python3 - "$FILE" "$NUM" <<'PY'
import re, sys
path, num = sys.argv[1], sys.argv[2]
lines = open(path).read().split("\n")
new = f"**Issue:** #{num}"
for i, l in enumerate(lines):
    if re.match(r"\*\*Issue:\*\*", l) or re.match(r"\*\*Jira:\*\*", l):
        lines[i] = new
        break
else:
    # no header line: put it after the H1 + blank line
    at = next((i for i, l in enumerate(lines) if l.startswith("# ")), -1) + 1
    lines[at:at] = ["", new] if not (at < len(lines) and lines[at].strip()) else [new]
open(path, "w").write("\n".join(lines))
PY
echo "Updated $FILE header -> $(grep -m1 -E '^\*\*Issue:\*\*' "$FILE"). Commit this change."
