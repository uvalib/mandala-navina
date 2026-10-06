#!/usr/bin/env bash
#
# Shared helper: config:status drift that is expected locally and never belongs
# in config/sync.
#
# `stage_file_proxy` is a dev-only module (composer require --dev, enabled on
# DDEV by session-start-check.sh, absent from the production image). Its
# enablement can never be exported to config/sync, so a bare `drush
# config:status` on any DDEV reports two entries forever:
#   stage_file_proxy.settings   Only in DB
#   core.extension              Different   (module list = sync list + stage_file_proxy)
# See docs/deferred/local-dev-files-provisioning-mechanism.md.
#
# Sourced by scripts/session-start-check.sh and scripts/config-check.sh so the
# two cannot disagree about what is "expected". Requires DDEV and jq.
#
# Only exactly that drift is discounted: core.extension counts as explained
# only when the module-list difference is precisely {stage_file_proxy}, so a
# real, unrelated module left enabled locally still fails.

LOCAL_ONLY_MODULE="stage_file_proxy"

# Print the config:status entries NOT explained by the local-only module, one
# per line. Empty output means clean once the expected drift is discounted.
# Returns 2 if the status could not be read or classified (no jq, bad JSON).
config_status_unexplained() {
  local json db_modules sync_modules only_in_db discount
  command -v jq >/dev/null 2>&1 || return 2
  json="$(ddev drush config:status --format=json 2>/dev/null)" || return 2
  echo "$json" | jq -e . >/dev/null 2>&1 || return 2

  db_modules="$(ddev drush pm:list --status=enabled --type=module --field=name 2>/dev/null | sort)"
  sync_modules="$(ddev drush php:eval "echo implode(PHP_EOL, array_keys(\Drupal::service('config.storage.sync')->read('core.extension')['module'] ?? []));" 2>/dev/null | sort)"
  only_in_db="$(comm -23 <(echo "$db_modules") <(echo "$sync_modules"))"

  discount="${LOCAL_ONLY_MODULE}.settings"
  if [ "$only_in_db" = "$LOCAL_ONLY_MODULE" ]; then
    discount="$discount
core.extension"
  fi

  echo "$json" | jq -r --arg discount "$discount" '
    ($discount | split("\n")) as $d
    | to_entries | map(select(.key as $k | ($d | index($k)) | not)) | .[].key
  '
}
