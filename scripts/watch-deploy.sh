#!/usr/bin/env bash
#
# Watch a specific CodePipeline execution (by its immutable execution ID,
# not "the pipeline's latest status") through to a terminal state, printing
# stage transitions as they happen.
#
# WHY EXECUTION ID, NOT "LATEST EXECUTION STATUS". A pipeline's own
# `latestExecution.status` lags behind which execution is actually current
# -- polling it right after triggering a fresh run can read the PREVIOUS
# execution's stale "Succeeded", making a brand-new deploy look done before
# it's even started. Every session that's hit this has had to re-derive
# the fix (list-pipeline-executions, match the revision message,
# get-pipeline-execution by id) from scratch, and usually trips over some
# shell-escaping issue doing it inline (e.g. zsh's `status` is a read-only
# reserved variable -- don't name a var that here). This script captures
# the fix once. See feedback-codepipeline-poll-by-execution-id memory.
#
# Usage:
#   ./scripts/watch-deploy.sh                       # auto-detect: newest execution
#   ./scripts/watch-deploy.sh <execution-id>
#   ./scripts/watch-deploy.sh <execution-id> <pipeline-name>
#
# Example, watching a just-triggered dev-0 deploy after merging a PR:
#   ./scripts/watch-deploy.sh e8b3f368-79f2-466a-b4f9-1aeda259e88f
#
# Requires: aws-vault (profile `staging` by default -- reaches production
# too, see reference-terraform-local-invocation memory).
#
# Exit code: 0 on Succeeded, 1 on Failed/Stopped/Superseded or if the
# execution can't be found.

set -uo pipefail

PIPELINE="${2:-uva-mandala-drupal-codepipeline}"
AWS_VAULT_PROFILE="${AWS_VAULT_PROFILE:-staging}"
POLL_SECONDS="${POLL_SECONDS:-15}"

aws() { aws-vault exec "$AWS_VAULT_PROFILE" -- command aws "$@"; }

EXEC_ID="${1:-}"
if [ -z "$EXEC_ID" ]; then
  echo "No execution id given -- using the newest execution on $PIPELINE..."
  EXEC_ID="$(aws codepipeline list-pipeline-executions --pipeline-name "$PIPELINE" --max-items 1 --query "pipelineExecutionSummaries[0].pipelineExecutionId" --output text)"
  REV="$(aws codepipeline list-pipeline-executions --pipeline-name "$PIPELINE" --max-items 1 --query "pipelineExecutionSummaries[0].sourceRevisions[0].revisionSummary" --output text)"
  echo "Using execution $EXEC_ID: $REV"
  echo "(if that's not the execution you meant, pass its id explicitly and Ctrl-C now)"
fi

if [ -z "$EXEC_ID" ] || [ "$EXEC_ID" = "None" ]; then
  echo "Could not find an execution id on $PIPELINE."
  exit 1
fi

echo "Watching $PIPELINE execution $EXEC_ID..."
prev_overall=""
prev_stages=""
while true; do
  overall="$(aws codepipeline get-pipeline-execution --pipeline-name "$PIPELINE" --pipeline-execution-id "$EXEC_ID" --query "pipelineExecution.status" --output text 2>&1)"
  if [ "$overall" != "$prev_overall" ]; then
    echo "$(date +%H:%M:%S) execution: $overall"
    prev_overall="$overall"
  fi

  # Filtered by THIS execution id at the AWS API level (JMESPath), not in
  # shell -- get-pipeline-state always reports the pipeline's current
  # state regardless of which execution asked, so an unfiltered read would
  # show a later execution's stages once one starts.
  cur_stages="$(aws codepipeline get-pipeline-state --name "$PIPELINE" \
    --query "stageStates[?latestExecution.pipelineExecutionId=='$EXEC_ID'].[stageName,latestExecution.status]" \
    --output text 2>/dev/null)"
  if [ -n "$cur_stages" ] && [ "$cur_stages" != "$prev_stages" ]; then
    echo "$(date +%H:%M:%S) stages:"
    echo "$cur_stages" | sed 's/^/    /'
    prev_stages="$cur_stages"
  fi

  case "$overall" in
    Succeeded)
      echo "DONE: deploy succeeded."
      exit 0
      ;;
    Failed|Stopped|Superseded)
      echo "DONE: deploy ended with status $overall -- investigate before assuming the fix is live."
      exit 1
      ;;
  esac
  sleep "$POLL_SECONDS"
done
