#!/bin/bash
# Pull the canonical database from a remote Mandala environment into local DDEV.
#
# WORKFLOW: the remote environment (dev first, later staging) is the CANONICAL
# shared state. Before starting FEATURE (non-migration) branch work on your
# laptop, run this to rebase your local DB onto the canonical one — this is what
# keeps everyone from drifting into slightly-different local states. (Migration
# DEVELOPMENT is the other mode: there you load a D7 source via
# load-d7-source.sh / load-d7-users-source.sh and run migrate; that DB is
# scratch — its product is the committed migration YAML, not a dump.)
#
# NO BASTION. The SSH bastion exists only to bridge staging<->production, which
# cannot talk to each other directly. A developer on the VPN reaches the dev /
# staging host directly, so we do NOT use `ssh -J bastion` here.
#
# Usage: ./scripts/update-db-from-remote.sh [dev|staging|production]
#        (defaults to dev)

set -e

ENVIRONMENT="${1:-dev}"

# Per-environment remote host + container. Hostnames must resolve on the VPN
# (adjust to your ~/.ssh/config aliases or the internal DNS names if different).
case "$ENVIRONMENT" in
  dev)
    REMOTE_HOST="mandala-dev"
    CONTAINER="mandala-drupal-0"
    ;;
  staging)
    REMOTE_HOST="mandala-staging"
    CONTAINER="mandala-drupal-0"
    ;;
  production)
    REMOTE_HOST="mandala-production"
    CONTAINER="mandala-drupal-0"
    ;;
  *)
    echo "ERROR: unknown environment '$ENVIRONMENT' (expected dev|staging|production)" >&2
    exit 1
    ;;
esac

# drush lives inside the app container on the remote host (the deploy proved the
# `docker exec <container> <drupal_home>/vendor/bin/drush` path). DRUPAL_HOME is
# overridable in case the image layout changes.
DRUPAL_HOME="${DRUPAL_HOME:-/opt/drupal/app}"
REMOTE_DRUSH="docker exec ${CONTAINER} ${DRUPAL_HOME}/vendor/bin/drush"

mkdir -p drupal/dumps
DUMP_FILE="drupal/dumps/${ENVIRONMENT}-$(date +%Y%m%d-%H%M%S).sql.gz"

echo "Dumping ${ENVIRONMENT} DB from ${REMOTE_HOST} (direct over VPN, no bastion)..."
ssh "$REMOTE_HOST" "$REMOTE_DRUSH sql:dump --gzip --extra-dump=--no-tablespaces" > "$DUMP_FILE"

if [ ! -s "$DUMP_FILE" ]; then
  echo "ERROR: dump is empty — check VPN connectivity, the SSH host alias, and the container name." >&2
  rm -f "$DUMP_FILE"
  exit 1
fi
echo "Wrote $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))."

echo "Importing into local DDEV..."
ddev import-db --file="$DUMP_FILE"

echo "Reasserting committed config on top of the pulled DB (drops any remote-only active config drift)..."
ddev drush config:import --yes
ddev drush cache:rebuild

echo "Done — local DB now matches ${ENVIRONMENT}. Branch away."
