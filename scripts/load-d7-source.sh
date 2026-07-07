#!/bin/bash
# Load a D7 source dump into the secondary `d7_images` DDEV database that the
# Migrate API reads from (the `migrate` connection in settings.php).
#
# This codifies the otherwise-manual setup discovered in Sprint 1 1a.7/1a.8:
#   - the prod dump has no CREATE DATABASE / USE, just table DDL
#   - it carries MySQL 8 collation (utf8mb4_0900_ai_ci); DDEV now runs MySQL 8.4
#     (matching the staging/prod RDS), so the dump imports NATIVELY — no
#     collation rewrite. Importing under the source's real collation is what
#     gives 1a.9 its NFC/diacritic/sort/uniqueness fidelity.
#     (Was previously rewritten to utf8mb4_general_ci for DDEV's MariaDB; that
#     downgrade is exactly what the MySQL 8.4 switch removes — do not re-add it.)
#   - the `db` user needs an explicit GRANT on the new database
#
# Usage: ./scripts/load-d7-source.sh <path-to-dump.sql.gz>
#
# MANUAL PREREQUISITE — the dump is NOT in the repo (*.sql.gz is gitignored;
# it is ~70MB of production data). Obtain it out-of-band (shared drive / S3)
# and pass its path. As of 2026-06-29 the Images dump is
# `mandala-prod-images-db_YYYY-MM-DD.sql.gz`.

set -e

DUMP_FILE="${1:?Usage: ./scripts/load-d7-source.sh <path-to-dump.sql.gz>}"
SOURCE_DB="d7_images"

if [ ! -f "$DUMP_FILE" ]; then
  echo "ERROR: dump not found: $DUMP_FILE" >&2
  echo "The D7 dump is gitignored — obtain it out-of-band and pass its path." >&2
  exit 1
fi

echo "Creating secondary database '$SOURCE_DB'..."
ddev mysql -e "CREATE DATABASE IF NOT EXISTS $SOURCE_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

echo "Importing $DUMP_FILE into '$SOURCE_DB' (native MySQL 8.4 — no collation rewrite)..."
gzcat "$DUMP_FILE" \
  | ddev mysql "$SOURCE_DB"

echo "Granting the 'db' user access to '$SOURCE_DB'..."
ddev mysql -e "GRANT ALL PRIVILEGES ON $SOURCE_DB.* TO 'db'@'%'; GRANT ALL PRIVILEGES ON $SOURCE_DB.* TO 'db'@'localhost'; FLUSH PRIVILEGES;"

echo
echo "Done. Source DB loaded. Sanity check:"
ddev mysql "$SOURCE_DB" -e "SELECT type, COUNT(*) AS n FROM node GROUP BY type ORDER BY n DESC LIMIT 6;"

cat <<'NEXT'

Next steps (run these yourself — they depend on the migration you want):

  # 1. Ensure the D11 content model matches committed config FIRST
  #    (installs field_iiif_*, field_description_title, etc.):
  ddev drush config:import -y

  # 2. Run the Images migrations in dependency order. Full migration:
  ddev drush migrate:import --group=mandala_images

  # 3. Or a fast SCOPED import (e.g. the 1a.8 golden fixtures) — set the
  #    nids source filter on the host migration (avoids prepareRow over all
  #    ~111k rows that --idlist alone would still trigger):
  ddev drush migrate:import d7_images_image_agent        --idlist="1028396:0,1087551:0,1243616:0"
  ddev drush migrate:import d7_images_image_descriptions --idlist="1028396:0,1087551:0,1087551:1,1243616:0,1243616:1,1243616:2"
  ddev drush cset migrate_plus.migration.d7_images_shanti_image source.nids '[1028396,1087551,1243616]' --input-format=yaml -y
  ddev drush cr
  ddev drush migrate:import d7_images_shanti_image --force
  ddev drush cdel ... # (or clear source.nids) to remove the local filter afterward

  # If a run is interrupted and the migration locks as "Importing":
  ddev drush migrate:reset-status <migration_id>
NEXT
