# AV4 migration running live on dev-0 — how to check on it, and what NOT to do

**Area:** migration / AV4 / dev-0 operations
**Raised during:** Session 2026-09-09 (AV4 completion and dev-0 launch)
**Priority:** High while the migration is in flight — this note exists specifically so the next session doesn't need the full transcript to pick this up safely.

## ⚠ DO NOT MERGE PR #194 (OR PUSH ANYTHING TO `main`) UNTIL THE MIGRATION IS CONFIRMED DONE

**Merging any PR to `mandala-navina`'s `main` branch triggers the
`uva-mandala-drupal-codepipeline` CodePipeline** (Source → Build → Deploy).
The Deploy stage **recreates dev-0's `mandala-drupal-0` container** — this
was directly observed tonight wiring up `MIGRATE_AV_DATABASE` (see the
session log below).

**The AV4 migration runs as a `docker exec`'d process inside that container.**
A container recreation kills it immediately and without warning — the exact
same failure mode as the nightly-shutdown risk this whole investigation was
about, except self-inflicted by a routine merge. This is precisely why
[PR #194](https://github.com/uvalib/mandala-navina/pull/194) — otherwise
ready — was deliberately **left open and unmerged** tonight.

**Before merging PR #194, any other PR, or pushing to `main`: confirm the
migration below has actually finished (or been stopped deliberately).**

## What's running, and where

- **Host:** dev-0 (`mandala-drupal-dev-0.internal.lib.virginia.edu`)
- **Launched:** 2026-09-09, ~19:52 UTC, via a `setsid`+`nohup`-detached script
  — independent of any SSH session, VPN connection, or Claude Code session.
  Confirmed alive from a fresh SSH connection after launch.
- **Script:** `/home/ys2n/run-av-migration.sh` on dev-0 (mirrors
  `scripts/run-av-migration.sh`'s exact 27-stage dependency order, adapted to
  run via `docker exec` against `mandala-drupal-0` instead of `ddev drush`).
- **Log:** `/home/ys2n/av-migration-dev0-<timestamp>.log` on dev-0 — the
  timestamp in the filename is set at launch; find the current one with:
  ```
  ssh ys2n@mandala-drupal-dev-0.internal.lib.virginia.edu 'ls -t /home/ys2n/av-migration-dev0-*.log | head -1'
  ```

## How to check on it

```bash
ssh ys2n@mandala-drupal-dev-0.internal.lib.virginia.edu '
  pgrep -af run-av-migration.sh
  echo "---"
  tail -30 /home/ys2n/av-migration-dev0-*.log
'
```

- **If `pgrep` shows the process and the log is growing:** still running, healthy. No action needed.
- **If `pgrep` shows nothing and the log ends with `AV migration (dev-0) finished` and no `FAILED STAGES` line:** done, clean. Proceed to verification (below).
- **If `pgrep` shows nothing and the log ends mid-stage, or with a `FAILED STAGES` line:** it stopped unexpectedly. Check the log for the specific error. The script is safe to simply re-run (`bash /home/ys2n/run-av-migration.sh` on dev-0) — each stage checks its own migration status first and runs `migrate:reset-status` if needed, then Migrate API resumes from its per-row map tables rather than starting over.

## When it's done: verify before doing anything else

Run the dev-0 equivalent of `scripts/verify-av-migration.sh` — the local
script's D7-comparison logic is directly portable, just point its `d11()`
helper at `docker exec mandala-drupal-0 drush sql:query` instead of
`ddev drush sql:query`, and its `d7()` helper at the `mandala_d7_av` database
on `rds-mysql8-staging` instead of the local `d7_av` DDEV database. All 24
checks should match, exactly as they did against the local DDEV run
(documented in `docs/planning/av-node-migration-notes.md`).

**Only after verification passes** is it safe to:
1. Merge PR #194 (or anything else) — the resulting redeploy will recreate
   the container, but the migration will already be safely committed to the
   database by then.
2. Run `drush kmassets:index-all && drush kmassets:audit` (per the script's
   own final log line) to bring the Solr index up to date with the newly
   migrated AV content.

## Why this note exists at all

This is exactly the failure mode the whole 2026-09-09 nightly-shutdown
investigation was about — a container recreation silently killing a
`docker exec`'d long-running process — except triggered by the team's own
normal merge workflow instead of an external schedule. Nothing in
CodePipeline, GitHub, or Drupal itself would warn about this; the only
defense is know-how, which is why it's written down here rather than left in
a Claude Code session transcript that ends when the session does.
