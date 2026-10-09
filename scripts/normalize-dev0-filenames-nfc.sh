#!/bin/bash
# One-time pass: rename dev-0's on-disk Unicode filenames from NFD (decomposed)
# to NFC (composed), to match how D11's file_managed rows already store them.
#
# Background: docs/deferred/dev0-unicode-filenames-need-nfc-normalization.md.
# D11's DB is NFC; a subset of dev-0's files on disk are NFD (likely from a
# macOS-backed upload/migration path at some point). Same visible name,
# different bytes -- any exact-path fetch (stage_file_proxy,
# mandala:missing-file-audit, curl/rsync) 404s on the literal NFC path.
# Disk-only operation: no file_managed rows need to change, since the DB is
# already correct.
#
# New uploads are unaffected going forward -- PR #314's mandala_file_hygiene
# module normalizes to NFC at save time on every environment that has it
# installed. This script is only for the backlog already on dev-0's disk.
#
# NO BASTION -- see update-db-from-remote.sh; a developer on the VPN reaches
# dev-0 directly.
#
# Runs on the dev-0 HOST, not inside the app container: the files tree is a
# bind mount (`docker inspect mandala-drupal-0` -> host
# /mnt/data/mandala-drupal-0/sites/default/files is bound to the container's
# .../web/sites/default/files), so host and container see the same bytes on
# disk. The host has python3; the container image does not (no python3,
# no PHP intl/Normalizer either -- confirmed 2026-10-09). Files are owned by
# uid 33 (www-data) in the container, not the SSH login user on the host, so
# renaming needs sudo on the host side too.
#
# Usage:
#   ./scripts/normalize-dev0-filenames-nfc.sh            # dry run (default)
#   ./scripts/normalize-dev0-filenames-nfc.sh --execute   # actually rename
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
