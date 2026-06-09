#!/bin/bash
# Pull database from a remote environment
# Usage: ./scripts/update-db-from-remote.sh [staging|production]
# Follows drupal-dsf pattern

set -e

ENVIRONMENT=${1:-staging}
DUMP_FILE="drupal/dumps/${ENVIRONMENT}-$(date +%Y%m%d).sql.gz"

echo "Fetching database from $ENVIRONMENT..."
# TODO: configure remote DB access via Ansible/SSH bastion
# ssh -J bastion mandala-drupal-${ENVIRONMENT}-0 "drush sql:dump --gzip" > $DUMP_FILE

echo "Importing into local DDEV..."
ddev import-db --file=$DUMP_FILE

echo "Running post-import steps..."
ddev drush config:import --yes
ddev drush cache:rebuild

echo "Done."
