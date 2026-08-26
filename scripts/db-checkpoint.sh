#!/bin/bash
# Logical DB checkpoints for the deployed Mandala hosts — save / list / restore.
#
# WHY THIS EXISTS
#   The deploy runs a fail-loud full `updb` + `cim` on every run, and a 1a.9
#   acceptance cycle imports 111k nodes. Neither has a rollback point of its own.
#   RDS automated backups are INSTANCE-WIDE and up to ~24h stale, so recovering
#   one database means standing up a whole replacement instance — nobody does
#   that mid-development, which means for the "undo the last hour" case the
#   safety net does not exist. So we take our own logical dumps.
#   Decision: Yuji, 2026-08-25. See docs/deferred/pre-deploy-rds-snapshot-gate.md.
#
# SCOPE: the deployed hosts (dev-0 etc.), run over SSH on the host itself.
#   NOT for DDEV — use `ddev export-db` / `ddev import-db` locally instead.
#
# WHY NOT `drush sql:dump`  ⚠ THE ORIGINAL VERSION OF THIS SCRIPT USED IT, AND
# IT SILENTLY PRODUCED EMPTY BACKUPS.
#   Drush's SQL commands are wrappers, not native implementations: Sql/SqlMysql.php
#   returns 'mysqldump' and drush execs it. The Drupal container has NO mysql
#   client, so Commands/sql/SqlCommands.php logs a *warning* from a validate hook
#   and returns false — drush exits **0**. A redirect therefore creates a file,
#   the shell reports success, and you own a "backup" that is empty. You find out
#   at restore time. Verified live 2026-08-25.
#   => We shell out to mysqldump inside a mysql:8.0 container instead, and we
#      VERIFY THE ARTIFACT rather than trusting any exit code.
#
# USAGE
#   ./db-checkpoint.sh save <label>              # dump; never overwrites
#   ./db-checkpoint.sh list                      # newest first, with age
#   ./db-checkpoint.sh restore <label|file> --yes  # DESTRUCTIVE
#
#   Labels around an acceptance run: pre-import, post-import, post-validate.
#   Files are named {label}-{UTC}.sql.gz so a save can never clobber an earlier
#   checkpoint — the original version wrote a bare {label}.sql.gz, so running
#   `save pre-import` twice destroyed the very artifact you might need.
#
# ENV
#   APP_CONTAINER   container to read MYSQL_* from   (default mandala-drupal-0)
#   CHECKPOINT_DIR  host dir for dumps               (default /mnt/data/$APP_CONTAINER/checkpoints)
#   DOCKER          docker invocation                (default "sudo -E docker")
#   MYSQL_IMAGE     client image                     (default mysql:8.0)
#
#   CHECKPOINT_DIR is a HOST path, deliberately. Writing from inside the
#   container would need a persistent bind mount, and dev-0 has no suitable one:
#   its only mounts are the SimpleSAMLphp dirs, keys/, and sites/default/files —
#   and that last is WEB-SERVED, so a dump there would be publicly downloadable.
#
# CREDENTIALS
#   Read into shell variables and forwarded to docker via a bare `-e MYSQL_PWD`
#   (value comes from the environment, never argv, so it stays out of `ps`).
#   Never written to a file, not even a mode-600 one.

set -uo pipefail   # deliberately NOT -e: we check outcomes explicitly, because
                   # an aborting shell skips verification and leaves bad artifacts.

APP_CONTAINER="${APP_CONTAINER:-mandala-drupal-0}"
CHECKPOINT_DIR="${CHECKPOINT_DIR:-/mnt/data/$APP_CONTAINER/checkpoints}"
DOCKER="${DOCKER:-sudo -E docker}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
SUDO="${SUDO:-sudo}"

log()  { printf "\n\033[1m== %s ==\033[0m\n" "$*"; }
info() { printf "   %s\n" "$*"; }
die()  { printf "\n\033[31mERROR: %s\033[0m\n" "$*" >&2; exit 1; }

validate_label() {
  local l="${1:-}"
  [ -n "$l" ] || die "a label is required (e.g. pre-import)"
  case "$l" in
    *[!A-Za-z0-9._-]*) die "label '$l' has characters outside [A-Za-z0-9._-]" ;;
    -*|.*)             die "label '$l' may not start with '-' or '.'" ;;
  esac
}

# Populate DB_HOST/DB_USER/DB_NAME and export MYSQL_PWD. Never echoes the password.
read_db_env() {
  DB_HOST=$($DOCKER exec "$APP_CONTAINER" printenv MYSQL_HOST 2>/dev/null)
  DB_USER=$($DOCKER exec "$APP_CONTAINER" printenv MYSQL_USER 2>/dev/null)
  DB_NAME=$($DOCKER exec "$APP_CONTAINER" printenv MYSQL_DATABASE 2>/dev/null)
  MYSQL_PWD=$($DOCKER exec "$APP_CONTAINER" printenv MYSQL_PASSWORD 2>/dev/null)
  export MYSQL_PWD
  [ -n "$DB_HOST" ] && [ -n "$DB_USER" ] && [ -n "$DB_NAME" ] && [ -n "$MYSQL_PWD" ] \
    || die "could not read MYSQL_* from container '$APP_CONTAINER' (is it running, and is \$DOCKER right?)"
}

# The only trustworthy success signal. mysqldump writes the trailer ONLY on clean
# completion, so it catches truncation that a size check would pass.
verify_dump() {
  local f="$1"
  $SUDO gzip -t "$f" 2>/dev/null || return 1
  $SUDO zcat "$f" 2>/dev/null | tail -5 | grep -q -- "-- Dump completed" || return 2
  return 0
}

human_age() {
  local mtime now diff
  mtime=$($SUDO date -u -r "$1" +%s 2>/dev/null) || { echo "unknown age"; return; }
  now=$(date -u +%s); diff=$(( now - mtime ))
  if   [ "$diff" -lt 3600 ];  then echo "$(( diff / 60 )) min ago"
  elif [ "$diff" -lt 86400 ]; then echo "$(( diff / 3600 )) h ago"
  else echo "$(( diff / 86400 )) days ago"; fi
}

phase_save() {
  local label="$1" ts target
  validate_label "$label"
  read_db_env
  ts=$(date -u +%Y%m%dT%H%M%SZ)
  target="$CHECKPOINT_DIR/$label-$ts.sql.gz"

  log "CHECKPOINT SAVE — $label"
  info "database: $DB_NAME on $DB_HOST"
  info "target:   $target"

  $SUDO mkdir -p "$CHECKPOINT_DIR" && $SUDO chmod 700 "$CHECKPOINT_DIR" \
    || die "cannot create $CHECKPOINT_DIR"

  # --single-transaction: consistent snapshot without locking out the running site.
  # --no-tablespaces / --set-gtid-purged=OFF: required for RDS, which withholds
  # the PROCESS privilege and manages GTIDs itself.
  $DOCKER run --rm -e MYSQL_PWD "$MYSQL_IMAGE" \
      mysqldump -h "$DB_HOST" -u "$DB_USER" \
      --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF "$DB_NAME" \
    | gzip | $SUDO tee "$target" >/dev/null

  # Do NOT trust the exit code above; the pipeline hides mysqldump's status and
  # this is exactly how the drush version produced empty "successful" backups.
  verify_dump "$target"
  case "$?" in
    0) : ;;
    1) $SUDO rm -f "$target"; die "dump is not valid gzip — removed. Nothing was saved." ;;
    2) $SUDO rm -f "$target"; die "dump has no '-- Dump completed' trailer (truncated) — removed. Nothing was saved." ;;
  esac

  log "SAVED — $($SUDO ls -lh "$target" | awk '{print $5}')"
  info "verified: gzip integrity + mysqldump completion trailer"
  info "restore:  $0 restore $(basename "$target") --yes"
}

phase_list() {
  log "CHECKPOINTS in $CHECKPOINT_DIR"
  local found=0 f
  # The glob MUST expand as root: CHECKPOINT_DIR is root-owned mode 700, so a
  # caller-shell glob silently matches nothing and `list` reports "(none)" even
  # when checkpoints exist. Caught live on dev-0, 2026-08-25.
  for f in $($SUDO sh -c "ls -1t '$CHECKPOINT_DIR'/*.sql.gz 2>/dev/null"); do
    found=1
    printf "   %-46s %6s  %-12s %s\n" \
      "$(basename "$f")" \
      "$($SUDO ls -lh "$f" | awk '{print $5}')" \
      "$(human_age "$f")" \
      "$(verify_dump "$f" && echo 'verified' || echo '*** INVALID ***')"
  done
  [ "$found" -eq 1 ] || info "(none)"
}

# Accepts an exact filename or a bare label (resolves to the newest match).
resolve_checkpoint() {
  local want="$1" f
  if $SUDO test -f "$CHECKPOINT_DIR/$want"; then echo "$CHECKPOINT_DIR/$want"; return 0; fi
  # Same root-glob requirement as phase_list.
  f=$($SUDO sh -c "ls -1t '$CHECKPOINT_DIR/$want'-*.sql.gz 2>/dev/null" | head -1)
  [ -n "$f" ] && { echo "$f"; return 0; }
  return 1
}

phase_restore() {
  local want="$1" confirm="${2:-}" source
  [ -n "$want" ] || die "a label or filename is required"
  read_db_env

  # TARGET_DB exists so restore can be PROVEN without risking the live database:
  # point it at a scratch schema (the app user holds ALL PRIVILEGES on `mandala%`,
  # so `mandala_restore_test` works) and the whole path runs for real — same user,
  # same RDS, same privileges, same network — with only the target name differing.
  # A DDEV rehearsal cannot substitute: DDEV runs as root on a local MySQL, so it
  # proves the drop/load logic while skipping the thing most likely to fail.
  if [ -n "${TARGET_DB:-}" ] && [ "$TARGET_DB" != "$DB_NAME" ]; then
    info "*** TARGET_DB override: restoring into '$TARGET_DB', NOT '$DB_NAME' ***"
    DB_NAME="$TARGET_DB"
  fi

  source=$(resolve_checkpoint "$want") || die "no checkpoint matching '$want' in $CHECKPOINT_DIR"

  log "CHECKPOINT RESTORE"
  info "from:     $(basename "$source")"
  info "taken:    $(human_age "$source")"
  info "size:     $($SUDO ls -lh "$source" | awk '{print $5}')"
  info "INTO:     $DB_NAME on $DB_HOST   <-- every table is dropped first"

  # Verify BEFORE dropping anything. A drop followed by a failed load leaves an
  # empty site, which is worse than doing nothing.
  verify_dump "$source"
  case "$?" in
    1) die "refusing: '$source' is not valid gzip." ;;
    2) die "refusing: '$source' has no completion trailer — it is truncated." ;;
  esac
  info "verified: gzip integrity + completion trailer"

  if [ "$confirm" != "--yes" ]; then
    die "refusing without explicit confirmation. Re-run with: restore $want --yes"
  fi

  info "dropping existing tables…"
  $DOCKER run --rm -i -e MYSQL_PWD "$MYSQL_IMAGE" \
      mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" <<SQL
SET FOREIGN_KEY_CHECKS = 0;
SET @t := (SELECT IFNULL(GROUP_CONCAT(CONCAT('\`', table_name, '\`')), '')
           FROM information_schema.tables WHERE table_schema = DATABASE());
SET @s := IF(@t = '', 'SET @noop := 1', CONCAT('DROP TABLE IF EXISTS ', @t));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET FOREIGN_KEY_CHECKS = 1;
SQL
  [ $? -eq 0 ] || die "table drop failed — database may be in a partial state. Do NOT deploy; investigate."

  info "loading…"
  $SUDO zcat "$source" | $DOCKER run --rm -i -e MYSQL_PWD "$MYSQL_IMAGE" \
      mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME"
  [ $? -eq 0 ] || die "load FAILED after the drop — the database is EMPTY. Re-run the restore."

  log "RESTORED — $(basename "$source")"
  info "Caches are stale after a reload. Run:"
  info "  $DOCKER exec $APP_CONTAINER /opt/drupal/app/drupal/vendor/bin/drush cache:rebuild"
}

case "${1:-}" in
  save)    shift; phase_save "${1:-}" ;;
  list)    phase_list ;;
  restore) shift; phase_restore "${1:-}" "${2:-}" ;;
  *)
    echo "Usage: $0 {save <label>|list|restore <label|file> --yes}" >&2
    echo "Env:   APP_CONTAINER, CHECKPOINT_DIR, DOCKER, MYSQL_IMAGE  (see header)" >&2
    exit 2
    ;;
esac
