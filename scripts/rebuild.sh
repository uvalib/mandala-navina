#!/bin/bash
# Quick local rebuild — drops and reinstalls Drupal

set -e

echo "Rebuilding Mandala local environment..."

ddev drush site:install --yes \
  --account-name=admin \
  --account-pass=admin \
  --site-name="Mandala" \
  --locale=en

ddev drush config:import --yes
ddev drush cache:rebuild

echo "Done. Site available at https://mandala.ddev.site"
