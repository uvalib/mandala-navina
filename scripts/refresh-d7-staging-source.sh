#!/bin/bash
# Refresh the D7 migration source databases on rds-mysql8-staging from live
# production (rds-mysql8-production), so dev-0's `migrate`/`migrate_users`
# connections (see drupal/web/sites/default/settings.php) have something to
# read. This is the "upstream" half of the D7 pipeline — it does NOT touch
# DDEV or any local database. For local dev, see load-d7-source.sh instead.
#
# Background (see docs/deferred/d11-dev-database-bootstrap-and-migration-source.md
# and docs/adr — Decision C, corrected 2026-07-17):
#   - Live D7 production runs on rds-mysql8-production (user `mandala_sites`),
#     NOT rds-standard-* (that estate is stopped/retired) and NOT any local
#     Aegir container.
#   - dev-0 / staging-0 CANNOT reach rds-mysql8-production directly (network
#     isolation) — only rds-mysql8-staging, which is dev's own DB host.
#   - So the migration source must be dumped from production and loaded onto
#     staging as a separate DB (name must satisfy the `mandala%` grant used by
#     the `mandala_drupal` user).
#   - The per-site `mandala_sites` password is NOT in Secrets Manager — Aegir
#     injects it via `SetEnv db_passwd` in the site's Apache vhost conf, read
#     off the (idle) Aegir hostmaster container on the production node. Each
#     vhost has a STALE commented-out line (old rds-standard credential)
#     directly above the live one — always match on the *uncommented*
#     `SetEnv db_passwd` line, or you'll silently grab the wrong password.
#   - The shared D7 user/role/authmap database is named `mandala_shared` in
#     production (confirmed via live `SHOW DATABASES` — NOT `mandala_shared_dev`,
#     despite that being what `sites/all/platform.settings.php`'s `$shared`
#     variable says; that file value is stale/inert while the site is disabled.
#     Trust the live DB list over the config file).
#   - Local Homebrew `mysql` 9.x client cannot auth against these RDS instances
#     (`mysql_native_password` plugin was dropped from the build) — this script
#     runs `mysql`/`mysqldump` inside a `mysql:8.0` Docker container instead,
#     which bundles it. Requires Docker Desktop and its VPN routing to reach
#     the *.internal.lib.virginia.edu hosts (confirmed working over the UVA VPN).
#
# Credential handling: passwords are held ONLY in shell variables (process
# memory), never written to a file — no option file (--defaults-extra-file),
# no tmpdir, no on-disk secret of any kind. Each is handed to `docker run` via
# a command-scoped `MYSQL_PWD=... docker run -e MYSQL_PWD ...` prefix: this
# sets MYSQL_PWD only in that one docker-CLI invocation's environment (the
# `-e MYSQL_PWD` bare form tells docker to forward whatever value is already
# in ITS OWN env, so the secret never appears as a literal in a command's
# argv/`ps` listing, and never persists past that single invocation).
#
# The dump payloads themselves (not credentials) are streamed directly from
# the source `mysqldump` container into the target `mysql` container via a
# shell pipe — no intermediate .sql file on disk either. This matters because
# the shared-user dump carries real PII; nothing about this run touches disk
# except Docker's own image cache.
#
# Usage:
#   ./scripts/refresh-d7-staging-source.sh [source_db:target_db ...]
#
# Defaults to the two DBs needed for the user migration prerequisite:
#   mandalaimageslib:mandala_d7_images mandala_shared:mandala_d7_shared
#
# Other site source DBs (for later site tracks), all on rds-mysql8-production:
#   AV=mandalaavlibvirg  Sources=mandalasourcesli  Texts=mandalatextslibv
#   Visuals=mandalavisualsli  Home=mandalalibvirgin
#
# Requires: docker, ssh access to the production node as your personal
# computing-id user (~/.ssh/id_rsa; NOT the terraform-infra .pem, NOT `centos`
# — see reference-mandala-node-access memory), and the `staging` aws-vault
# profile (reaches both staging and production per its documented scope).

set -euo pipefail

PROD_SSH_HOST="${PROD_SSH_HOST:-ys2n@mandala-drupal-0.internal.lib.virginia.edu}"
PROD_SSH_KEY="${PROD_SSH_KEY:-$HOME/.ssh/id_rsa}"
# Any live production site vhost works as the credential source — they all
# share the same `mandala_sites` DB user/password.
PROD_VHOST_FILE="${PROD_VHOST_FILE:-mandala-images.lib.virginia.edu}"
PROD_DB_HOST="${PROD_DB_HOST:-rds-mysql8-production.internal.lib.virginia.edu}"
PROD_DB_USER="${PROD_DB_USER:-mandala_sites}"

STAGING_DB_HOST="${STAGING_DB_HOST:-rds-mysql8-staging.internal.lib.virginia.edu}"
STAGING_DB_USER="${STAGING_DB_USER:-mandala_drupal}"
STAGING_SECRET_ID="${STAGING_SECRET_ID:-staging/rds/standard/mandala_drupal}"
AWS_VAULT_PROFILE="${AWS_VAULT_PROFILE:-staging}"

if [ "$#" -gt 0 ]; then
  PAIRS=("$@")
else
  PAIRS=(mandalaimageslib:mandala_d7_images mandala_shared:mandala_d7_shared)
fi

echo "==> Fetching production ($PROD_DB_USER) password from the live vhost config..."
PROD_PW="$(ssh -i "$PROD_SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o BatchMode=yes "$PROD_SSH_HOST" "
  sudo docker exec dockerfiles-hostmaster-1 sh -c \"grep -E '^[[:space:]]*SetEnv[[:space:]]+db_passwd' /var/aegir/config/server_master/apache/vhost.d/$PROD_VHOST_FILE\" | awk '{print \$NF}'
")"
[ -n "$PROD_PW" ] || { echo "ERROR: empty production password — check PROD_VHOST_FILE / SSH access" >&2; exit 1; }

echo "==> Fetching staging ($STAGING_DB_USER) password from Secrets Manager ($STAGING_SECRET_ID)..."
STAGING_PW="$(aws-vault exec "$AWS_VAULT_PROFILE" -- aws secretsmanager get-secret-value \
  --secret-id "$STAGING_SECRET_ID" --query SecretString --output text)"
[ -n "$STAGING_PW" ] || { echo "ERROR: empty staging secret — check STAGING_SECRET_ID / aws-vault profile" >&2; exit 1; }

echo "==> Verifying source + target connectivity..."
MYSQL_PWD="$PROD_PW" docker run --rm -e MYSQL_PWD mysql:8.0 \
  mysql -h "$PROD_DB_HOST" -u "$PROD_DB_USER" --connect-timeout=8 -N -e "SELECT 1" >/dev/null
MYSQL_PWD="$STAGING_PW" docker run --rm -e MYSQL_PWD mysql:8.0 \
  mysql -h "$STAGING_DB_HOST" -u "$STAGING_DB_USER" --connect-timeout=8 -N -e "SELECT 1" >/dev/null
echo "    OK."

for pair in "${PAIRS[@]}"; do
  SRC_DB="${pair%%:*}"
  TGT_DB="${pair##*:}"
  echo "==> $SRC_DB (production) -> $TGT_DB (staging)"

  echo "    Creating $TGT_DB on staging (if not present)..."
  MYSQL_PWD="$STAGING_PW" docker run --rm -e MYSQL_PWD mysql:8.0 \
    mysql -h "$STAGING_DB_HOST" -u "$STAGING_DB_USER" -e \
      "CREATE DATABASE IF NOT EXISTS $TGT_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

  echo "    Streaming dump -> load (no dump file touches disk)..."
  MYSQL_PWD="$PROD_PW" docker run --rm -e MYSQL_PWD mysql:8.0 \
    mysqldump -h "$PROD_DB_HOST" -u "$PROD_DB_USER" --single-transaction --quick \
      --routines --triggers --set-gtid-purged=OFF "$SRC_DB" \
  | MYSQL_PWD="$STAGING_PW" docker run --rm -i -e MYSQL_PWD mysql:8.0 \
    mysql -h "$STAGING_DB_HOST" -u "$STAGING_DB_USER" "$TGT_DB"

  echo "    Done: $TGT_DB"
done

echo
echo "All done. No credential or dump-content file was ever written to disk."
echo "Next: point dev-0's MIGRATE_SOURCE_DATABASE / MIGRATE_USERS_DATABASE at these"
echo "new DB names (see settings.php's env-driven migrate connections), and confirm"
echo "the 1a.8 kmassets direct sink is disabled before the first migrate:import."
