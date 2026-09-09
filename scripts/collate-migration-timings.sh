#!/usr/bin/env bash
#
# Collate per-stage timings from one or more AV migration logs into a markdown
# table, ready to paste into docs/planning/av-node-migration-notes.md.
#
# ⚠ THE OUTPUT IS LABELLED AS THE ENVIRONMENT IT RAN IN, AND THAT MATTERS.
# Local DDEV timings do NOT predict dev-0 and must never be compared to the
# dev-0 baselines (the ~243/min `shanti_image` figure is a dev-0 measurement --
# container against RDS, 2026-08-27). The two environments differ in ways that
# pull in opposite directions, so there is no correction factor:
#
#   - D11 database: in-container MySQL (sub-ms) vs RDS over the network. Migrate
#     is chatty per row, so round-trip latency dominates -- this favours local.
#   - File writes: DDEV's mutagen sync amplifies writes; dev-0 writes straight to
#     a volume -- this favours dev-0, heavily, on the 2.9 GB file stage.
#
# A local run proves CORRECTNESS. Only a dev-0 run gives a dev-0 runtime.
#
# Usage: ./scripts/collate-migration-timings.sh [ENV] <logfile> [logfile...]
#        ENV defaults to "local DDEV"; pass "dev-0" when collating a dev-0 run.

set -u

ENV_LABEL="local DDEV"
case "${1:-}" in
  local|ddev|"local DDEV") ENV_LABEL="local DDEV"; shift ;;
  dev-0|dev0)              ENV_LABEL="dev-0";      shift ;;
esac

if [ "$#" -eq 0 ]; then
  echo "Usage: $0 [local|dev-0] <logfile> [logfile...]" >&2
  exit 1
fi

echo "**Environment: ${ENV_LABEL}.** $( [ "$ENV_LABEL" = "local DDEV" ] \
  && echo 'Not a dev-0 projection — see §6 of the AV4 notes.' \
  || echo 'Comparable to the 2026-08-27 Images baselines.' )"
echo
echo "| Stage | Rows | Duration | Rate/min |"
echo "|---|---:|---:|---:|"

total=0
for log in "$@"; do
  # Both log formats: "=== <id> — <n>s" and "=== <id> done in <n>s". The
  # separator is deliberately matched as "anything non-numeric": the first form
  # uses an em-dash, which BSD sed will not reliably match inside an
  # alternation, and silently returning no rows is worse than being loose here.
  # Drop stages this log marks FAILED. A failed stage still logs a duration --
  # a stage that aborts on a bad flag or an unmet dependency "takes" 2s -- and
  # pairing that with a row count from a later successful run produces a rate
  # that is pure fiction (d7_av_video briefly collated as 115,680/min this way).
  failed=$(sed -nE 's/^!!! ([a-z0-9_]+) FAILED.*$/\1/p' "$log" | sort -u | tr '\n' '|')
  failed="${failed%|}"
  sed -nE 's/^.*=== ([a-z0-9_]+)[^0-9]+([0-9]+)s.*$/\1 \2/p' "$log" \
    | { [ -n "$failed" ] && grep -Ev "^(${failed}) " || cat; }
done | awk '
  { secs[$1] = $2 }            # a later run of the same stage supersedes an earlier one
  END { for (m in secs) print m, secs[m] }
' | sort > /tmp/.collate_stages.$$

# Row counts come from the migrate map, which is the authoritative "how many did
# this stage actually process" -- not from the log text.
while read -r id secs; do
  # `< /dev/null` is load-bearing: ddev/drush reads stdin, and inside a
  # `while read ... done < file` loop that swallows the remaining lines, so the
  # table silently comes out with only its first row.
  rows=$(ddev drush sql:query "SELECT COUNT(*) FROM migrate_map_${id}" </dev/null 2>/dev/null | tr -d '[:space:]')
  rows=${rows:-0}
  if [ "$secs" -gt 0 ] && [ "$rows" -gt 0 ]; then
    rate=$(awk -v r="$rows" -v s="$secs" 'BEGIN{printf "%.0f", r*60/s}')
  else
    rate="—"
  fi
  printf '| `%s` | %s | %ss | %s |\n' "$id" "$rows" "$secs" "$rate"
  total=$((total + secs))
done < /tmp/.collate_stages.$$
rm -f /tmp/.collate_stages.$$

printf '| **total** | | **%dm %ds** | |\n' "$((total / 60))" "$((total % 60))"
