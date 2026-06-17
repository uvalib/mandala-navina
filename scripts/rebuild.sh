#!/bin/bash
# Quick local rebuild — drops and reinstalls Drupal

set -e

echo "Rebuilding Mandala local environment..."

ddev drush site:install --existing-config --yes \
  --account-name=admin \
  --account-pass=admin
ddev drush cache:rebuild

echo "Done. Site available at https://mandala.ddev.site"
