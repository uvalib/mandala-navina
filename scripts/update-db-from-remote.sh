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

# Per-environment remote host + container. The default is the internal DNS name,
# which resolves for anyone on the VPN without needing a ~/.ssh/config alias --
# alias names are per-developer (mandala-dev is one person's, not everyone's), so
# they cannot be the default. Override with REMOTE_HOST if you prefer your alias:
#   REMOTE_HOST=mandala-dev ./scripts/update-db-from-remote.sh dev
case "$ENVIRONMENT" in
  dev)
    REMOTE_HOST="${REMOTE_HOST:-mandala-drupal-dev-0.internal.lib.virginia.edu}"
    CONTAINER="mandala-drupal-0"
    ;;
  staging)
    REMOTE_HOST="${REMOTE_HOST:-mandala-drupal-dev-1.internal.lib.virginia.edu}"
    CONTAINER="mandala-drupal-0"
    ;;
  production)
    REMOTE_HOST="${REMOTE_HOST:-mandala-drupal-0.internal.lib.virginia.edu}"
    CONTAINER="mandala-drupal-0"
    ;;
  *)
    echo "ERROR: unknown environment '$ENVIRONMENT' (expected dev|staging|production)" >&2
    exit 1
    ;;
esac

# drush lives inside the app container on the remote host. The Drupal root is
# /opt/drupal/app/drupal (NOT /opt/drupal/app -- /var/www/html -> /opt/drupal/web
# is a different stub tree), so drush is at
# /opt/drupal/app/drupal/vendor/bin/drush. DRUPAL_HOME is overridable in case the
# image layout changes.
DRUPAL_HOME="${DRUPAL_HOME:-/opt/drupal/app/drupal}"

# Docker needs sudo on these hosts: personal computing-id logins are not in the
# docker group (passwordless sudo is granted instead). sudo is harmless for an
# account that IS in the group, so it is the safe default. Override if needed.
DOCKER_CMD="${DOCKER_CMD:-sudo docker}"

# mariadb-dump verifies the RDS server certificate against the container's CA
# bundle, which does not carry the RDS CA -- it fails with "TLS/SSL error:
# self-signed certificate in certificate chain". Drupal's own PDO connection is
# unaffected, so this only bites the dump path. The connection stays encrypted;
# only chain verification is skipped.
DUMP_OPTS="${DUMP_OPTS:---no-tablespaces --ssl-verify-server-cert=0}"

REMOTE_DRUSH="${DOCKER_CMD} exec ${CONTAINER} ${DRUPAL_HOME}/vendor/bin/drush"

mkdir -p drupal/dumps
DUMP_FILE="drupal/dumps/${ENVIRONMENT}-$(date +%Y%m%d-%H%M%S).sql.gz"

echo "Dumping ${ENVIRONMENT} DB from ${REMOTE_HOST} (direct over VPN, no bastion)..."
ssh "$REMOTE_HOST" "$REMOTE_DRUSH sql:dump --gzip --extra-dump='${DUMP_OPTS}'" > "$DUMP_FILE"

# Validate the artifact, NOT the exit code. drush's SQL commands are wrappers
# around mysqldump and have historically reported success while writing nothing;
# a non-empty check is not enough either, because a failed dump still leaves a
# short file (a TLS failure produced a 20-byte file that passed `-s` and would
# have been imported over the local DB). Check that the gzip stream is intact
# AND that mysqldump wrote its completion trailer, which it only does on a clean
# finish -- that is what catches truncation.
if [ ! -s "$DUMP_FILE" ]; then
  echo "ERROR: dump is empty — check VPN connectivity, the remote host, and the container name." >&2
  rm -f "$DUMP_FILE"
  exit 1
fi
if ! gzip -t "$DUMP_FILE" 2>/dev/null; then
  echo "ERROR: dump is not a valid gzip stream (truncated or an error message, not a dump)." >&2
  echo "       First bytes:" >&2; head -c 200 "$DUMP_FILE" >&2; echo >&2
  rm -f "$DUMP_FILE"
  exit 1
fi
if ! gzip -cd "$DUMP_FILE" | tail -5 | grep -q "Dump completed"; then
  echo "ERROR: dump has no '-- Dump completed' trailer — it is truncated. Refusing to import." >&2
  rm -f "$DUMP_FILE"
  exit 1
fi
echo "Wrote $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1)) — gzip intact, dump complete."

echo "Importing into local DDEV..."
ddev import-db --file="$DUMP_FILE"

echo "Reasserting committed config on top of the pulled DB (drops any remote-only active config drift)..."
ddev drush config:import --yes
ddev drush cache:rebuild

echo "Done — local DB now matches ${ENVIRONMENT}. Branch away."
