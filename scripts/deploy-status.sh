#!/usr/bin/env bash
#
# Snapshot the CURRENT status of a Mandala CodePipeline: which execution is
# newest, what triggered it (PR/commit), and each stage's status -- printed
# once and exits, no polling. Use this to answer "what's the deploy status
# right now"; use watch-deploy.sh to babysit one through to done.
#
# WHY THIS EXISTS, NOT JUST `aws codepipeline get-pipeline-state`: the raw
# CLI answer requires knowing the pipeline name, filtering stage state by
# the newest execution id (get-pipeline-state alone can show a stale mix
# once a new execution starts -- see watch-deploy.sh's header for the full
# trap), and decoding the trigger/revision JSON. This script does all of
# that in one call so it doesn't get re-derived by hand every session. See
# feedback-codepipeline-poll-by-execution-id memory.
#
# Usage:
#   ./scripts/deploy-status.sh              # drupal pipeline (default)
#   ./scripts/deploy-status.sh drupal
#   ./scripts/deploy-status.sh ingest
#   ./scripts/deploy-status.sh solr-proxy
#   ./scripts/deploy-status.sh <full-pipeline-name>   # any other pipeline
#
# Credentials: tries plain `aws` first (works when the shell already has
# credentials, e.g. an aws-vault-exec'd shell or SSO session); falls back
# to `aws-vault exec staging --` if that fails. Override the profile with
# AWS_VAULT_PROFILE. The `staging` profile also reaches production -- see
# reference-terraform-local-invocation memory.
#
# Exit code: 0 if the newest execution Succeeded, 1 if InProgress, 2 if
# Failed/Stopped/Superseded or the pipeline/execution can't be found.

set -uo pipefail

case "${1:-drupal}" in
  drupal)      PIPELINE="uva-mandala-drupal-codepipeline" ;;
  ingest)      PIPELINE="uva-mandala-ingest-production-deploy-codepipeline" ;;
  solr-proxy)  PIPELINE="uva-mandala-solr-proxy-codepipeline" ;;
  *)           PIPELINE="$1" ;;
esac

AWS_VAULT_PROFILE="${AWS_VAULT_PROFILE:-staging}"

if command aws sts get-caller-identity >/dev/null 2>&1; then
  aws() { command aws "$@"; }
else
  aws() { aws-vault exec "$AWS_VAULT_PROFILE" -- command aws "$@"; }
fi

LATEST_JSON="$(aws codepipeline list-pipeline-executions --pipeline-name "$PIPELINE" --max-items 1 --output json 2>&1)"
if [ $? -ne 0 ]; then
  echo "Could not list executions for pipeline '$PIPELINE':"
  echo "$LATEST_JSON"
  exit 2
fi

EXEC_ID="$(echo "$LATEST_JSON" | python3 -c "import json,sys; d=json.load(sys.stdin); s=d['pipelineExecutionSummaries']; print(s[0]['pipelineExecutionId'] if s else '')")"
if [ -z "$EXEC_ID" ]; then
  echo "No executions found for pipeline '$PIPELINE'."
  exit 2
fi

echo "$LATEST_JSON" | python3 -c "
import json, sys
d = json.load(sys.stdin)['pipelineExecutionSummaries'][0]
print(f\"Pipeline:  $PIPELINE\")
print(f\"Execution: {d['pipelineExecutionId']}\")
print(f\"Status:    {d['status']}\")
print(f\"Started:   {d.get('startTime', '?')}\")
print(f\"Updated:   {d.get('lastUpdateTime', '?')}\")
trig = d.get('trigger', {})
print(f\"Trigger:   {trig.get('triggerType', '?')}\")
for rev in d.get('sourceRevisions', []):
    summary = json.loads(rev.get('revisionSummary', '{}'))
    msg = summary.get('CommitMessage', '').split(chr(10))[0]
    print(f\"Commit:    {rev.get('revisionId', '?')[:12]}  {msg}\")
"

echo "Stages:"
aws codepipeline get-pipeline-state --name "$PIPELINE" \
  --query "stageStates[?latestExecution.pipelineExecutionId=='$EXEC_ID'].[stageName,latestExecution.status]" \
  --output text 2>&1 | sed 's/^/    /'

OVERALL="$(echo "$LATEST_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)['pipelineExecutionSummaries'][0]['status'])")"
case "$OVERALL" in
  Succeeded) exit 0 ;;
  InProgress) exit 1 ;;
  *) exit 2 ;;
esac
