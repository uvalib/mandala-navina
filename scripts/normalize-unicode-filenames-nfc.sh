#!/bin/bash
# Rename on-disk Unicode filenames from NFD (decomposed) to NFC (composed),
# to match how D11's file_managed rows always store them.
#
# Background: docs/deferred/dev0-unicode-filenames-need-nfc-normalization.md.
# D11's DB is NFC; a subset of files on disk can be NFD (seen from a
# macOS-backed upload/migration path). Same visible name, different bytes --
# any exact-path fetch (stage_file_proxy, mandala:missing-file-audit,
# curl/rsync) 404s on the literal NFC path. Disk-only operation: no
# file_managed rows need to change, since the DB is already correct.
#
# NOT a one-time dev-0-only fix -- this is a STANDING CHECK, run:
#   - once against dev-0 to clear the pre-existing backlog (done 2026-10-09,
#     37 paths, all under transcripts/ + one top-level file -- the whole
#     files tree was scanned, covering every asset type, not just AV);
#   - again after EVERY future site migration lands new files on dev-0
#     (Texts, Sources -- see docs/planning/migration-cycle-runbook.md's
#     "Filename normalization check" step) -- each run should report 0
#     affected paths; a nonzero result on a freshly-migrated site means
#     something bypassed mandala_file_hygiene and needs investigating, not
#     silently re-running this script as the fix;
#   - once against a D11 staging environment when one exists, as part of
#     standing it up (see
#     docs/deferred/staging-migration-execution-prerequisites.md) -- no
#     staging environment exists yet (2026-10-09), so nothing to run there
#     today;
#   - once against production before cutover (see
#     docs/deferred/production-migration-planning.md).
#   Each environment has its own independent upload history -- never assume
#   one is clean because another one is.
#
# New file-entity saves are unaffected going forward on any environment with
# PR #314's mandala_file_hygiene module installed -- it normalizes to NFC at
# save time, and migrations create files through the same entity:file
# destination/File::save() path (confirmed by reading the d7_*_files
# migrations' destination plugin and mandala_file_hygiene's own kernel test).
# This script exists for files that land on disk some other way: the
# pre-existing dev-0 backlog, or anything copied outside Drupal's entity API.
#
# NO BASTION -- see update-db-from-remote.sh; a developer on the VPN reaches
# the target host directly.
#
# Runs on the Drupal host's HOST machine, not inside the app container: the
# files tree is a bind mount (confirmed via `docker inspect mandala-drupal-0`
# for dev-0 -- host .../sites/default/files is bound to the container's
# .../web/sites/default/files), so host and container see the same bytes on
# disk. The container image has neither python3 nor PHP's intl/Normalizer
# extension (confirmed 2026-10-09); the host has python3. Files are owned by
# uid 33 (www-data) in the container, not the SSH login user on the host, so
# renaming needs sudo on the host side too.
#
# Usage:
#   REMOTE_HOST=<host> FILES_DIR=<path> \
#     ./scripts/normalize-unicode-filenames-nfc.sh            # dry run (default)
#   REMOTE_HOST=<host> FILES_DIR=<path> \
#     ./scripts/normalize-unicode-filenames-nfc.sh --execute   # actually rename
#
# Defaults to dev-0's host/path if REMOTE_HOST/FILES_DIR are omitted. Override
# both for staging, production, or a rebuilt dev-0 with a different mount path.
#
# Dry run prints every path it WOULD rename; review that list before passing
# --execute. Renames bottom-up (deepest paths first) so a directory whose own
# name is NFD still has a stable path when its contents are renamed, and
# normalizes directory names too, not just filenames.

set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:-mandala-drupal-dev-0.internal.lib.virginia.edu}"
FILES_DIR="${FILES_DIR:-/mnt/data/mandala-drupal-0/sites/default/files}"
SUDO_CMD="${SUDO_CMD:-sudo}"

MODE="dry-run"
case "${1:-}" in
  --execute) MODE="execute" ;;
  "" | --dry-run) MODE="dry-run" ;;
  *) echo "ERROR: unknown argument '$1' (expected --dry-run or --execute)" >&2; exit 1 ;;
esac

echo "Mode: $MODE  |  host: $REMOTE_HOST  |  tree: $FILES_DIR" >&2

ssh "$REMOTE_HOST" "$SUDO_CMD python3 - '$FILES_DIR' '$MODE'" <<'PY'
import os
import sys
import unicodedata

files_dir, mode = sys.argv[1], sys.argv[2]
execute = mode == "execute"

changed = 0
skipped = 0

for dirpath, dirnames, filenames in os.walk(files_dir, topdown=False):
    for name in filenames + dirnames:
        nfc = unicodedata.normalize("NFC", name)
        if nfc == name:
            continue
        old = os.path.join(dirpath, name)
        new = os.path.join(dirpath, nfc)
        if os.path.exists(new):
            print(f"SKIP (target already exists): {old}")
            skipped += 1
            continue
        verb = "RENAME" if execute else "WOULD RENAME"
        print(f"{verb}: {old} -> {new}")
        if execute:
            os.rename(old, new)
        changed += 1

label = "Renamed" if execute else "Would rename"
print(f"\n{label} {changed} path(s); skipped {skipped} (target already existed).")
PY
