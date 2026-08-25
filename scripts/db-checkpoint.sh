#!/bin/bash
# Logical DB checkpoints for the migration cycle — save / list / restore.
#
# WHY THIS EXISTS
#   The deploy now runs a fail-loud full `updb` + `cim` on every run, and a
#   1a.9 acceptance cycle imports 111k nodes. Neither has a rollback point of
#   its own. The RDS instance has daily automated snapshots, but those are
#   INSTANCE-WIDE and up to ~24h stale, which makes them impractical for
#   routine use: restoring one to recover a single database means standing up
#   a whole replacement instance, and nobody will realistically do that
#   mid-development. In practice that means the safety net does not exist.
#
#   So we take our own logical dumps at defined checkpoints. A restore is then
#   a targeted reload of ONE database, in minutes, by whoever is running the
#   cycle. This does not replace the RDS snapshots — those remain the
#   disaster-recovery story. It covers the "I want to undo the last hour"
#   case they are bad at.
#
#   Decision: Yuji, 2026-08-25. See
#   docs/deferred/pre-deploy-rds-snapshot-gate.md.
#
# HOW IT TALKS TO THE DATABASE
#   Through drush, using the SAME $DRUSH override that migration-cycle.sh
#   takes — so it inherits the site's own credentials and needs no MYSQL_*
#   plumbing, no mysqldump client, and no separate secret. If drush can reach
#   the database, so can this.
#
# USAGE
#   ./scripts/db-checkpoint.sh save <label>        # dump the D11 DB
#   ./scripts/db-checkpoint.sh list                # show saved checkpoints
#   ./scripts/db-checkpoint.sh restore <label>     # DESTRUCTIVE — needs --yes
#
#   Suggested labels around an acceptance run:
#       pre-import      before migration-cycle.sh cycle
#       post-import     after import, before validate
#       post-validate   the known-good state worth keeping
#
#   On dev-0, point both the drush invocation and the checkpoint directory at
#   the container, with the directory on a PERSISTENT bind mount:
#
#     export DRUSH="docker exec mandala-drupal-0 /opt/drupal/app/drupal/vendor/bin/drush"
#     export CHECKPOINT_DIR=/opt/drupal/app/drupal/../checkpoints   # under /mnt/data
#
#   ⚠ CHECKPOINT_DIR is a path INSIDE the container (drush resolves it there).
#     If it is not on a bind mount, the checkpoints die with the container —
#     which is exactly the failure the OAuth2 signing keys hit in August.
#
# NOT COMPRESSED BY DEFAULT
#   `drush sql:dump --gzip` would be smaller, but restoring it needs a shell
#   inside the container to gunzip first, which this script deliberately does
#   not assume it has. Uncompressed keeps restore a single drush call. Gzip the
#   files yourself if disk is tight; `restore` will refuse a .gz and say so.

set -euo pipefail

DRUSH="${DRUSH:-ddev drush}"
CHECKPOINT_DIR="${CHECKPOINT_DIR:-/var/www/html/.db-checkpoints}"

log()  { printf "\n\033[1m== %s ==\033[0m\n" "$*"; }
info() { printf "   %s\n" "$*"; }
die()  { printf "\n\033[31mERROR: %s\033[0m\n" "$*" >&2; exit 1; }

# Labels become filenames and are interpolated into shell/drush arguments.
# Constrain them rather than trusting the caller.
validate_label() {
  local l="${1:-}"
  [ -n "$l" ] || die "a label is required (e.g. pre-import)"
  case "$l" in
    *[!A-Za-z0-9._-]*) die "label '$l' has characters outside [A-Za-z0-9._-]" ;;
    -*|.*)             die "label '$l' may not start with '-' or '.'" ;;
  esac
}

# Ask drush which database it is actually pointed at, so every destructive
# action can name its target. Never assume the environment from the label.
current_db() {
  $DRUSH php:eval 'echo \Drupal::database()->getConnectionOptions()["database"] ?? "UNKNOWN";' 2>/dev/null || echo UNKNOWN
}

phase_save() {
  local label="$1" target
  validate_label "$label"
  target="$CHECKPOINT_DIR/$label.sql"

  log "CHECKPOINT SAVE — $label"
  info "database:   $(current_db)"
  info "written to: $target  (container-side path)"

  # Drush creates the parent directory itself; --result-file makes the dump a
  # file rather than stdout, which keeps the bytes off the docker exec pipe.
  $DRUSH sql:dump --result-file="$target"

  log "SAVED — $label"
  info "Restore with:  ./scripts/db-checkpoint.sh restore $label --yes"
}

phase_list() {
  log "CHECKPOINTS in $CHECKPOINT_DIR"
  # ls runs inside the container, so go through drush's own shell-less path:
  # a php:eval glob avoids assuming `sh` exists in the exec context.
  $DRUSH php:eval '
    $d = getenv("MANDALA_CHECKPOINT_DIR") ?: "'"$CHECKPOINT_DIR"'";
    if (!is_dir($d)) { echo "   (no checkpoint directory yet: $d)\n"; return; }
    $f = glob("$d/*.sql");
    if (!$f) { echo "   (none)\n"; return; }
    foreach ($f as $p) {
      printf("   %-28s %8.1f MB   %s\n", basename($p, ".sql"),
        filesize($p) / 1048576, date("Y-m-d H:i", filemtime($p)));
    }
  '
}

phase_restore() {
  local label="$1" confirm="${2:-}" source db
  validate_label "$label"

  case "$label" in
    *.gz) die "restore does not decompress. gunzip '$label' first, then restore the .sql." ;;
  esac

  source="$CHECKPOINT_DIR/$label.sql"
  db="$(current_db)"

  log "CHECKPOINT RESTORE — $label"
  info "This DROPS EVERY TABLE in '$db' and reloads it from:"
  info "  $source"

  if [ "$confirm" != "--yes" ]; then
    die "refusing without explicit confirmation. Re-run with: restore $label --yes"
  fi

  # Fail before dropping anything if the dump is missing — a drop followed by
  # a failed reload leaves an empty site, which is worse than doing nothing.
  $DRUSH php:eval '
    $p = "'"$source"'";
    if (!is_file($p) || filesize($p) === 0) {
      fwrite(STDERR, "missing or empty checkpoint: $p\n");
      exit(1);
    }
  ' || die "checkpoint '$label' not found or empty at $source"

  info "dropping current schema…"
  $DRUSH sql:drop -y
  info "reloading…"
  $DRUSH sql:query --file="$source"

  log "RESTORED — $label"
  info "Caches are stale after a reload. Run:  $DRUSH cache:rebuild"
}

case "${1:-}" in
  save)    shift; phase_save "${1:-}" ;;
  list)    phase_list ;;
  restore) shift; phase_restore "${1:-}" "${2:-}" ;;
  *)
    echo "Usage: $0 {save <label>|list|restore <label> --yes}" >&2
    echo "Env:   DRUSH, CHECKPOINT_DIR  (see header)" >&2
    exit 2
    ;;
esac
