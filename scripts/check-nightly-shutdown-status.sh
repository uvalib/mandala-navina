#!/usr/bin/env bash
#
# Check whether a given EC2 instance is ACTUALLY subject to the shared
# UVA Library nightly shutdown schedule (~23:00-06:00 stop/start), rather than
# assuming from documentation.
#
# WHY THIS SCRIPT EXISTS. Membership in the nightly-stop batch is enforced by
# a scheduler that lives outside this project (a shared control host running
# under the `docker-staging-instance-role`, stopping/starting a per-night list
# of instance IDs across many UVA Library apps' staging tiers) and can be
# changed out-of-band -- an instance can be added to or pulled from that list
# by an infra request to Dave Goldstein with NO commit, PR, or doc update
# anywhere in this repo. That is exactly what happened to dev-0: the team
# asked for it to be pulled from the schedule at some point, and nothing here
# recorded that it happened. A doc that says "X shuts down nightly" can go
# stale silently and nobody would know until a job mysteriously survived (or
# died) overnight.
#
# ⚠ TREAT THE RESULT AS A SNAPSHOT, NOT A PERMANENT FACT. Re-run this before
# relying on overnight behavior for anything consequential -- the schedule can
# change again, in either direction, without this repo being touched.
#
# TWO INDEPENDENT CHECKS, because either can mislead alone:
#   1. CloudTrail -- did StopInstances/StartInstances actually fire for this
#      instance in the recent nightly window? This is the most direct
#      evidence of the SCHEDULER'S intent, but CloudTrail management-event
#      retention is limited (90 days) and an event could theoretically be
#      missed by lookup/pagination.
#   2. Host uptime -- EC2 LaunchTime (reset by Stop+Start) and the guest OS's
#      own boot time (reset by ANY reboot, including one outside the
#      scheduler, e.g. SSM or someone SSHing in and rebooting manually). If
#      the host has been up longer than one full stop/start cycle would
#      allow, it wasn't stopped, independent of what CloudTrail says.
# Together they're much harder to both be wrong in the same direction.
#
# Usage:
#   ./scripts/check-nightly-shutdown-status.sh <instance-id> [ssh-host]
#
# Example (dev-0, as verified 2026-09-09):
#   ./scripts/check-nightly-shutdown-status.sh i-0e44bb9d8ea864ff3 \
#     ys2n@mandala-drupal-dev-0.internal.lib.virginia.edu
#
# Requires: aws-vault (profile `staging` by default), and SSH access to the
# host for the uptime half (skipped if ssh-host is omitted).

set -euo pipefail

INSTANCE_ID="${1:?Usage: $0 <instance-id> [ssh-host]}"
SSH_HOST="${2:-}"
AWS_VAULT_PROFILE="${AWS_VAULT_PROFILE:-staging}"
LOOKBACK_HOURS="${LOOKBACK_HOURS:-30}"

aws() { aws-vault exec "$AWS_VAULT_PROFILE" -- command aws "$@"; }

echo "=== Instance identity ==="
aws ec2 describe-instances --instance-ids "$INSTANCE_ID" \
  --query "Reservations[].Instances[].{Name:Tags[?Key=='Name']|[0].Value,State:State.Name,LaunchTime:LaunchTime,PrivateIp:PrivateIpAddress}" \
  --output table

echo
echo "=== Check 1: CloudTrail — was Stop/StartInstances called on this instance"
echo "    in the last ${LOOKBACK_HOURS}h (covers at least one nightly cycle)? ==="
START="$(date -u -v-${LOOKBACK_HOURS}H +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -d "${LOOKBACK_HOURS} hours ago" +%Y-%m-%dT%H:%M:%SZ)"
FOUND=0
for evt in StopInstances StartInstances RebootInstances; do
  # Emits one "TIME <timestamp>" line per match, then a final "COUNT <n>" line
  # -- avoids `head -n -1`, which is a GNU-ism BSD/macOS head doesn't support.
  RESULT="$(aws cloudtrail lookup-events \
    --lookup-attributes AttributeKey=EventName,AttributeValue="$evt" \
    --start-time "$START" --output json \
    | python3 -c "
import json,sys
d=json.load(sys.stdin)
n=0
for e in d.get('Events',[]):
    ct=json.loads(e['CloudTrailEvent'])
    ids=[i.get('instanceId') for i in ct.get('requestParameters',{}).get('instancesSet',{}).get('items',[])]
    if '$INSTANCE_ID' in ids:
        n+=1
        print('TIME', e['EventTime'])
print('COUNT', n)
" )"
  echo "$RESULT" | { grep '^TIME ' || true; } | sed 's/^TIME /   /'
  N="$(echo "$RESULT" | { grep '^COUNT ' || true; } | awk '{print $2}')"
  if [ "${N:-0}" -gt 0 ]; then
    echo "  -> $N × $evt found. This instance IS (or recently was) cycled by a scheduler."
    FOUND=1
  else
    echo "  -> 0 × $evt in the lookback window."
  fi
done
if [ "$FOUND" -eq 0 ]; then
  echo "RESULT (check 1): no Stop/Start/Reboot events found -- consistent with NOT being on a nightly schedule."
fi

if [ -n "$SSH_HOST" ]; then
  echo
  echo "=== Check 2: host uptime (via SSH) ==="
  ssh -o BatchMode=yes -o ConnectTimeout=15 "$SSH_HOST" 'echo "OS last boot: $(uptime -s 2>/dev/null || who -b)"; echo "Current time: $(date -u)"' 2>&1 || \
    echo "  (SSH check skipped/failed -- host unreachable)"
  echo "  Compare 'OS last boot' to LaunchTime above and to now: if both predate"
  echo "  the last ~24h by more than one stop/start cycle would allow, this host"
  echo "  has NOT been stopped/rebooted recently."
else
  echo
  echo "=== Check 2 skipped (no ssh-host given) ==="
fi

echo
echo "Reminder: this is a SNAPSHOT. Re-run before relying on it for anything"
echo "that matters -- see docs/dev-notes/howto-long-running-jobs-on-dev-staging.md."
