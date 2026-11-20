#!/usr/bin/env bash
#
# Check whether a deploy has reached dev-0, without AWS credentials: shows
# how long the Drupal container has been up (a restart a few minutes after a
# merge means the new build is live) and dev-0's config:status. Read-only.
# Needs the VPN and SSH access to dev-0.
#
# Expected: config:status lists only simplesamlphp_auth.settings, a known,
# harmless dev-0-specific difference (NetBadge setup). Anything else is drift.
#
# Usage: ./scripts/dev0-status.sh

set -u

DEV0_SSH_USER="${DEV0_SSH_USER:-$(whoami)}"
DEV0_SSH_HOST="${DEV0_SSH_HOST:-mandala-drupal-dev-0.internal.lib.virginia.edu}"
DEV0_SSH_KEY="${DEV0_SSH_KEY:-$HOME/.ssh/id_rsa}"
DEV0_CONTAINER="${DEV0_CONTAINER:-mandala-drupal-0}"
DEV0_DOCROOT="${DEV0_DOCROOT:-/opt/drupal/app/drupal}"

dev0() {
  ssh -i "$DEV0_SSH_KEY" -o ConnectTimeout=10 "$DEV0_SSH_USER@$DEV0_SSH_HOST" "$@"
}

echo "=== Uptime ($DEV0_CONTAINER) ==="
dev0 "sudo docker ps --filter name=$DEV0_CONTAINER --format '{{.Status}}'" \
  || { echo "Could not reach dev-0 (VPN off?)"; exit 1; }

echo
echo "=== Sync Differences (config:status) ==="
dev0 "sudo docker exec $DEV0_CONTAINER sh -c 'cd $DEV0_DOCROOT && vendor/bin/drush config:status'"
