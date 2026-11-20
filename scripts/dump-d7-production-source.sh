#!/bin/bash
# Dump a D7 asset database from live production into a local, dated repository
# of dumps. Read-only on production.
#
# Why: the D7 sites are the migration sources. Reloading them (onto staging RDS
# with refresh-d7-staging-source.sh, or into DDEV with load-d7-source.sh) used to
# start from a one-off manual dump. This script makes the dump repeatable and
# keeps every dated copy, so a migration can be re-run against the exact data it
# was developed on, and a fresh pull never overwrites the last known-good one.
#
# Usage:
#   ./scripts/dump-d7-production-source.sh texts            # one site
#   ./scripts/dump-d7-production-source.sh av images        # several
#   ./scripts/dump-d7-production-source.sh all              # the six asset sites (not shared)
#   ./scripts/dump-d7-production-source.sh mandalatextslibv # a raw database name
#   ./scripts/dump-d7-production-source.sh --list           # show the repository
#   ./scripts/dump-d7-production-source.sh --check texts    # connect only, no dump
#   ./scripts/dump-d7-production-source.sh --keep 3 texts   # dump, then keep newest 3
#   ./scripts/dump-d7-production-source.sh --push dev-0 av  # dump, then copy up to dev-0
#
# Sites (production database on rds-mysql8-production):
#   images=mandalaimageslib  av=mandalaavlibvirg  sources=mandalasourcesli
#   texts=mandalatextslibv   visuals=mandalavisualsli  home=mandalalibvirgin
#   shared=mandala_shared    (the user/role base all five sites share: REAL PII;
#                             never part of `all`, name it explicitly)
#
# Repository layout (default $HOME/mandala-d7-dumps, override with D7_DUMP_DIR):
#   <site>/mandala-prod-<site>-db_YYYY-MM-DD.sql.gz         the dump
#   <site>/mandala-prod-<site>-db_YYYY-MM-DD.sql.gz.meta    sha256, size, source db,
#                                                           skipped tables, row counts
#   <site>/latest.sql.gz                                    symlink to the newest dump
# A second dump on the same day gets a -HHMM suffix; nothing is ever overwritten.
# The file name matches what load-d7-source.sh expects, and the dump has no
# CREATE DATABASE / USE, so it loads into any database name.
#
# The repository is OUTSIDE this git repo on purpose (this repo is public, and
# *.sql.gz is gitignored as a second guard). Directories are mode 700 and files
# 600. The `shared` dump carries real user data: keep it on an encrypted disk and
# do not copy it anywhere shared.
#
# --push HOST copies each new dump and its .meta to HOST after dumping, then
# checks the sha256 over there against the .meta. HOST is `dev-0` or user@host.
# Destination: PUSH_DIR (default /home/dumps) on the host, same <site>/ layout.
# Asset sites land root:admin mode 2770 (group-writable, new files inherit the
# group); `shared` lands in <PUSH_DIR>/shared owned root:root, directory 700 and
# files 600. The file is staged in your home directory on the host (not /tmp,
# which is a small tmpfs) and installed with sudo. A `latest.sql.gz` symlink is
# kept there too. dev-0's root volume is not backed up: it is a convenience copy.
#
# What is skipped: every cache* table (runtime cache; 64% of the AV dump) and the
# log and runtime-state tables watchdog, accesslog, feeds_log, flood and sessions.
# None is read by a migrate source plugin, and `sessions` holds live session ids.
# Matching is exact on purpose: a `%log%` pattern would also catch real content
# such as field_data_field_catalog_workflow_notes. Override the exact-name list
# with SKIP_TABLES="a b c"; the cache* prefix always applies. The list is queried
# per database, and the skipped names are recorded in each .meta file.
#
# How it connects (same method as refresh-d7-staging-source.sh, which explains
# the traps): the `mandala_sites` password is read over SSH from the live
# production vhost config, held only in a shell variable, and handed to
# `docker run -e MYSQL_PWD` so it never appears in argv or on disk. mysql and
# mysqldump run in a mysql:8.0 container because Homebrew's mysql 9.x client
# cannot authenticate to these RDS hosts. `--no-tablespaces` is needed because
# the production user lacks the PROCESS privilege.
#
# Requires: docker (with VPN routing to *.internal.lib.virginia.edu), and SSH
# access to the production node as your own user with ~/.ssh/id_rsa.

set -euo pipefail

PROD_SSH_HOST="${PROD_SSH_HOST:-$(whoami)@mandala-drupal-0.internal.lib.virginia.edu}"
PROD_SSH_KEY="${PROD_SSH_KEY:-$HOME/.ssh/id_rsa}"
PROD_VHOST_FILE="${PROD_VHOST_FILE:-mandala-images.lib.virginia.edu}"
PROD_DB_HOST="${PROD_DB_HOST:-rds-mysql8-production.internal.lib.virginia.edu}"
PROD_DB_USER="${PROD_DB_USER:-mandala_sites}"
DUMP_DIR="${D7_DUMP_DIR:-$HOME/mandala-d7-dumps}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"

# site -> production database
site_db() {
  case "$1" in
    images) echo mandalaimageslib ;;
    av) echo mandalaavlibvirg ;;
    sources) echo mandalasourcesli ;;
    texts) echo mandalatextslibv ;;
    visuals) echo mandalavisualsli ;;
    home) echo mandalalibvirgin ;;
    shared) echo mandala_shared ;;
    *) return 1 ;;
  esac
}
ALL_SITES=(images av sources texts visuals home)

# Log and runtime-state tables skipped by exact name (cache* is skipped by prefix).
SKIP_TABLES="${SKIP_TABLES:-watchdog accesslog feeds_log flood sessions}"

# Tables whose exact row counts go in the .meta file, for checking a later load.
COUNT_TABLES=(node users url_alias og_membership field_collection_item)

usage() {
  sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//' | sed -n '1,22p'
}

list_repo() {
  if [ ! -d "$DUMP_DIR" ]; then
    echo "No repository yet at $DUMP_DIR"
    return 0
  fi
  echo "Repository: $DUMP_DIR"
  local found=0 f
  for f in "$DUMP_DIR"/*/mandala-prod-*-db_*.sql.gz; do
    [ -e "$f" ] || continue
    found=1
    printf '  %-9s %-46s %8s\n' "$(basename "$(dirname "$f")")" "$(basename "$f")" \
      "$(du -h "$f" | cut -f1)"
  done
  [ "$found" = 1 ] || echo "  (empty)"
}

KEEP=""
PUSH=""
PUSH_DIR="${PUSH_DIR:-/home/dumps}"
CHECK=0
SITES=()
while [ "$#" -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --list) list_repo; exit 0 ;;
    --check) CHECK=1 ;;
    --keep)
      shift
      KEEP="${1:-}"
      [[ "$KEEP" =~ ^[1-9][0-9]*$ ]] || { echo "ERROR: --keep needs a positive number" >&2; exit 2; }
      ;;
    --push)
      shift
      PUSH="${1:-}"
      [ -n "$PUSH" ] || { echo "ERROR: --push needs a host (dev-0 or user@host)" >&2; exit 2; }
      [ "$PUSH" != "dev-0" ] || PUSH="$(whoami)@mandala-drupal-dev-0.internal.lib.virginia.edu"
      ;;
    -*) echo "ERROR: unknown option $1" >&2; usage >&2; exit 2 ;;
    all) SITES=("${ALL_SITES[@]}") ;;
    *) SITES+=("$1") ;;
  esac
  shift
done
[ "${#SITES[@]}" -gt 0 ] || { usage >&2; exit 2; }

command -v docker >/dev/null || { echo "ERROR: docker not found" >&2; exit 1; }

mysql_prod() {
  MYSQL_PWD="$PROD_PW" docker run --rm -e MYSQL_PWD "$MYSQL_IMAGE" \
    mysql -h "$PROD_DB_HOST" -u "$PROD_DB_USER" --connect-timeout=8 -N "$@"
}

echo "==> Fetching production ($PROD_DB_USER) password from the live vhost config..."
PROD_PW="$(ssh -i "$PROD_SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o BatchMode=yes "$PROD_SSH_HOST" "
  sudo docker exec dockerfiles-hostmaster-1 sh -c \"grep -E '^[[:space:]]*SetEnv[[:space:]]+db_passwd' /var/aegir/config/server_master/apache/vhost.d/$PROD_VHOST_FILE\" | awk '{print \$NF}'
")"
[ -n "$PROD_PW" ] || { echo "ERROR: empty production password; check PROD_VHOST_FILE / SSH access" >&2; exit 1; }

echo "==> Verifying production connectivity..."
mysql_prod -e "SELECT 1" >/dev/null
echo "    OK."

umask 077
mkdir -p "$DUMP_DIR"
chmod 700 "$DUMP_DIR"

push_dump() {
  local site="$1" out="$2" owner_group mode dirmode remote_dir name stage
  name="$(basename "$out")"
  remote_dir="$PUSH_DIR/$site"
  if [ "$site" = "shared" ]; then
    owner_group="root:root"; dirmode=700; mode=600
  else
    owner_group="root:admin"; dirmode=2770; mode=660
  fi
  stage=".d7push.$$"
  echo "    Pushing to $PUSH:$remote_dir ..."
  ssh -i "$PROD_SSH_KEY" -o ConnectTimeout=15 -o BatchMode=yes "$PUSH" "mkdir -m 700 -p \"\$HOME/$stage\""
  scp -q -i "$PROD_SSH_KEY" -o ConnectTimeout=15 -o BatchMode=yes "$out" "$out.meta" "$PUSH:$stage/"
  local want got
  want="$(grep '^sha256=' "$out.meta" | cut -d= -f2)"
  got="$(ssh -i "$PROD_SSH_KEY" -o ConnectTimeout=15 -o BatchMode=yes "$PUSH" "
    set -e
    sudo install -d -o root -g admin -m 2770 '$PUSH_DIR' 2>/dev/null || true
    sudo install -d -o ${owner_group%%:*} -g ${owner_group##*:} -m $dirmode '$remote_dir'
    sudo install -o ${owner_group%%:*} -g ${owner_group##*:} -m $mode \"\$HOME/$stage/$name\" \"\$HOME/$stage/$name.meta\" '$remote_dir/'
    sudo ln -sfn '$name' '$remote_dir/latest.sql.gz'
    rm -rf \"\$HOME/$stage\"
    sudo sha256sum '$remote_dir/$name' | cut -d' ' -f1
  ")"
  if [ "$got" = "$want" ]; then
    echo "    Pushed and verified (sha256 ${got:0:12}...)."
  else
    echo "ERROR: checksum mismatch on $PUSH for $name (local ${want:0:12}, remote ${got:0:12})" >&2
    return 1
  fi
}

PARTIAL=""
trap '[ -z "$PARTIAL" ] || rm -f "$PARTIAL"' EXIT

dump_site() {
  local site="$1" db
  if db="$(site_db "$site")"; then :; else db="$site"; site="$db"; fi

  # Refuse a name production does not have, so a typo is an error, not an empty dump.
  local present
  present="$(mysql_prod -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$db'")"
  [ "$present" = "1" ] || { echo "ERROR: database '$db' not found on $PROD_DB_HOST" >&2; return 1; }

  echo "==> $site ($db)"
  if [ "$CHECK" = 1 ]; then
    echo "    --check: reachable, not dumping."
    return 0
  fi

  local dir="$DUMP_DIR/$site" stamp base out
  mkdir -p "$dir"
  chmod 700 "$dir"
  stamp="$(date +%Y-%m-%d)"
  base="mandala-prod-${site}-db_${stamp}"
  out="$dir/$base.sql.gz"
  [ ! -e "$out" ] || { base="${base}-$(date +%H%M)"; out="$dir/$base.sql.gz"; }

  local names="" t in_list
  for t in $SKIP_TABLES; do
    [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || { echo "ERROR: bad table name in SKIP_TABLES: $t" >&2; return 1; }
    in_list="${in_list:+$in_list,}'$t'"
  done
  local skip_found
  skip_found="$(mysql_prod -e \
    "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND (table_name LIKE 'cache%' OR table_name IN (${in_list:-''})) ORDER BY table_name")"
  local ignore=() skipped=0
  for t in $skip_found; do
    ignore+=(--ignore-table="${db}.${t}")
    names="${names:+$names }$t"
    skipped=$((skipped + 1))
  done
  echo "    Skipping $skipped cache/log table(s)."

  # Exact counts first (the site is disabled in production, so they stay put).
  local counts="" n
  for t in "${COUNT_TABLES[@]}"; do
    if [ "$(mysql_prod -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_name='$t'")" = "1" ]; then
      n="$(mysql_prod -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`")"
      counts="${counts}rows.${t}=${n}"$'\n'
    fi
  done

  echo "    Dumping to $out ..."
  PARTIAL="$out.partial"
  MYSQL_PWD="$PROD_PW" docker run --rm -e MYSQL_PWD "$MYSQL_IMAGE" \
    mysqldump -h "$PROD_DB_HOST" -u "$PROD_DB_USER" --single-transaction --quick \
      --routines --triggers --set-gtid-purged=OFF --no-tablespaces \
      "${ignore[@]}" "$db" \
    | gzip -c > "$PARTIAL"
  gzip -t "$PARTIAL"
  mv "$PARTIAL" "$out"
  PARTIAL=""

  local sum size
  sum="$(shasum -a 256 "$out" | cut -d' ' -f1)"
  size="$(wc -c < "$out" | tr -d ' ')"
  {
    echo "source_db=$db"
    echo "source_host=$PROD_DB_HOST"
    echo "dumped_at=$(date -u +%FT%TZ)"
    echo "dumped_by=$(whoami)"
    echo "sha256=$sum"
    echo "bytes=$size"
    echo "tables_skipped=$skipped"
    echo "tables_skipped_names=$names"
    printf '%s' "$counts"
  } > "$out.meta"
  ln -sfn "$(basename "$out")" "$dir/latest.sql.gz"

  echo "    Done: $(du -h "$out" | cut -f1), sha256 ${sum:0:12}..."
  [ -z "$counts" ] || printf '    %s\n' $(printf '%s' "$counts" | tr '\n' ' ')

  if [ -n "$PUSH" ]; then
    push_dump "$site" "$out" || return 1
  fi

  if [ -n "$KEEP" ]; then
    local old
    # Newest first; drop everything past $KEEP (dump + meta together).
    # shellcheck disable=SC2012
    for old in $(ls -1t "$dir"/mandala-prod-"${site}"-db_*.sql.gz | tail -n +"$((KEEP + 1))"); do
      echo "    Pruning $(basename "$old")"
      rm -f "$old" "$old.meta"
    done
  fi
}

status=0
for s in "${SITES[@]}"; do
  dump_site "$s" || status=1
done

echo
if [ "$CHECK" = 1 ]; then
  echo "Check finished. Nothing was written."
else
  echo "Dumps are in $DUMP_DIR (mode 700). Row counts are in each .meta file: compare them"
  echo "with the loaded copy after refresh-d7-staging-source.sh or load-d7-source.sh."
  echo "No credential was written to disk."
fi
exit "$status"
