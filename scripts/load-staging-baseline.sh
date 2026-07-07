#!/bin/bash
# Load a STAGING D11 baseline dump into DDEV's default `db` database, so the
# local migration cycle (Sprint 1 1a.9) rehearses against the same starting
# point AND the same DB engine (MySQL 8.4) as staging.
#
# This REPLACES the current local site DB. It is the companion to
# load-d7-source.sh — two different databases, do not conflate:
#   - staging D11 baseline  -> default `db`   (this script)
#   - D7 migrate source     -> `d7_images`    (load-d7-source.sh)
# The migration reads `d7_images` and writes into `db`.
#
# After import it asserts the DB is a genuine PRE-migration baseline
# (0 shanti_image nodes, 0 image paragraphs, no populated migrate_map_d7_images_*).
# This matters because `migrate:rollback` only cleans map-tracked entities:
# Images content present WITHOUT its migrate_map rows would be STRANDED on
# rollback and skew every subsequent `validate`. Exit status reflects this so
# the script is gate-friendly.
#
# Usage:
#   ./scripts/load-staging-baseline.sh <path-to-staging-db.sql.gz> [--sanitize]
#
# e.g.
#   ./scripts/load-staging-baseline.sh \
#       ~/Desktop/Mandala/mandala-staging-images-db_20260707.sql.gz --sanitize
#
#   --sanitize   run `drush sql:sanitize` after import (scrub user PII).
#                Recommended whenever the dump carries real accounts.
#
# EXIT STATUS
#   0  imported and the DB is a clean pre-migration baseline
#   3  imported but the DB is NOT a clean baseline (see the warning) — the data
#      is loaded; decide whether to proceed or obtain a cleaner dump
#   1  usage / precondition error (dump missing, wrong DB engine, …)
#
# PREREQUISITE: DDEV must be running MySQL 8 (see .ddev/config.yaml → mysql 8.4).
# The staging dump carries utf8mb4_0900_ai_ci; importing it into MariaDB would
# fail or silently degrade collation, defeating the fidelity this switch buys.
# This script refuses to run on anything but MySQL 8.

set -euo pipefail

# ---- args (order-independent) ---------------------------------------------
DUMP_FILE=""
SANITIZE=0
for a in "$@"; do
  case "$a" in
    --sanitize) SANITIZE=1 ;;
    -*) echo "Unknown option: $a" >&2; exit 1 ;;
    *) [ -z "$DUMP_FILE" ] && DUMP_FILE="$a" ;;
  esac
done

if [ -z "$DUMP_FILE" ]; then
  echo "Usage: ./scripts/load-staging-baseline.sh <path-to-staging-db.sql.gz> [--sanitize]" >&2
  exit 1
fi
if [ ! -f "$DUMP_FILE" ]; then
  echo "ERROR: dump not found: $DUMP_FILE" >&2
  echo "The staging dump is gitignored — obtain it out-of-band and pass its path." >&2
  exit 1
fi

# ---- guardrail: DDEV must be MySQL 8 --------------------------------------
VER=$(ddev mysql -N -e "SELECT VERSION();" 2>/dev/null | tr -d '[:space:]' || true)
case "$VER" in
  8.*)
    echo "DB engine OK: MySQL $VER" ;;
  *MariaDB*)
    echo "ERROR: DDEV is on MariaDB ($VER), not MySQL 8." >&2
    echo "Switch .ddev/config.yaml database to 'mysql / 8.4', then:" >&2
    echo "  ddev delete -Oy && ddev start" >&2
    exit 1 ;;
  "")
    echo "ERROR: cannot reach the DDEV database. Is 'ddev start' healthy?" >&2
    exit 1 ;;
  *)
    echo "ERROR: unexpected DB version '$VER' — expected MySQL 8.x." >&2
    exit 1 ;;
esac

# ---- import ---------------------------------------------------------------
echo
echo "Loading staging baseline into DDEV default 'db' (this REPLACES the local site DB)..."
echo "  source: $DUMP_FILE"
ddev import-db --file="$DUMP_FILE"   # ddev handles gzip + drops existing tables

echo "Rebuilding caches..."
ddev drush cr

if [ "$SANITIZE" -eq 1 ]; then
  echo "Sanitizing user PII (drush sql:sanitize)..."
  ddev drush sql:sanitize -y
else
  echo "NOTE: skipping PII sanitize. If the dump carries real accounts, run:"
  echo "  ddev drush sql:sanitize -y"
fi

# ---- baseline assertion ---------------------------------------------------
# Single-quoted SQL literals are always strings in MySQL (the double-quote /
# ANSI_QUOTES gotcha does not apply here), so these queries are safe as-is.
q() { ddev mysql -N -e "$1" 2>/dev/null | tr -d '[:space:]' || echo 0; }

img=$(q "SELECT COUNT(*) FROM node_field_data WHERE type='shanti_image';")
para=$(q "SELECT COUNT(*) FROM paragraphs_item_field_data WHERE type IN ('image_agent','image_descriptions','external_classification');")

# Sum rows across any migrate_map_d7_images_* tables (absent tables => 0).
mm_total=0
for t in $(ddev mysql -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'migrate_map_d7_images_%';" 2>/dev/null || true); do
  n=$(q "SELECT COUNT(*) FROM \`$t\`;")
  mm_total=$((mm_total + n))
done

echo
echo "== Baseline check =="
printf "   shanti_image nodes           : %s  (expect 0)\n" "$img"
printf "   image paragraphs             : %s  (expect 0)\n" "$para"
printf "   migrate_map_d7_images_* rows  : %s  (expect 0)\n" "$mm_total"

if [ "$img" = "0" ] && [ "$para" = "0" ] && [ "$mm_total" = "0" ]; then
  echo
  echo "CLEAN pre-migration baseline. Next steps:"
  echo "  ./scripts/load-d7-source.sh <d7-dump.sql.gz>     # load the migrate source"
  echo "  ./scripts/migration-cycle.sh cycle               # rollback -> import -> validate"
  exit 0
fi

echo
echo "WARNING: this is NOT a clean pre-migration baseline."
echo "  Images content is present. migrate:rollback only removes entities tracked"
echo "  in migrate_map_d7_images_*; any content WITHOUT matching map rows will be"
echo "  stranded on rollback and skew every validate. Either obtain a pre-migration"
echo "  staging dump, or confirm the migrate_map rows fully cover the content before"
echo "  relying on the cycle's rollback."
exit 3
