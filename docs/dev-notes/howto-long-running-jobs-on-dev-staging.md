# How-To: Run long jobs on dev/staging safely (nightly shutdown window)

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-07-18

## Goal

Know about — and plan around — the nightly cost-saving shutdown of the dev
and staging EC2 instances before kicking off anything that runs for hours
(migrations, long imports, batch jobs).

## The constraint

**Dev and staging Mandala instances are shut down every night from 11pm to
6am** to save AWS cost. This is a full EC2 instance stop, not just a container
restart — everything running on the box stops, including any `docker exec`'d
process (e.g. a `drush migrate:import`), regardless of restart policies.

This explains a previously-observed but unexplained pattern: `docs/planning/
dev-0-drift-capture.md` (2026-07-14/15) found the legacy Aegir containers
racing `mandala-drupal-0` for port 8080 after "dev-0's nightly reboot" and
fixed their restart policies (`--restart=no`) to survive it — that reboot
*is* this shutdown/startup cycle.

## What survives the shutdown, and what doesn't

| | Survives? |
|---|---|
| Your SSH connection / VPN dropping | ✅ Yes — the remote process is independent of the launching connection (see `docs/deferred/migrate-group-import-aborts-on-partial-failure.md`) |
| This Claude Code session ending | ✅ Yes — same reason |
| The container itself (`mandala-drupal-0` etc.) — if it's set `unless-stopped` | ✅ Comes back up automatically when the instance restarts at 6am |
| **A `docker exec`'d process running inside the container** (e.g. a migration) | ❌ **No** — it is not part of the container's own entrypoint/CMD, so it does not auto-resume when the container comes back. It's just gone. |

## What this means for a long-running job

If you kick off something that will run past 11pm:

1. **Expect it to be cut off, and plan for a manual resume the next morning.**
   Don't assume it'll "just be done" when you check back — it likely stopped
   at 11pm and sat idle until 6am.
2. **If the job is resumable** (e.g. Drupal Migrate API, which tracks
   progress per-row in its map tables), this is a non-event: nothing is lost,
   you just need to re-launch it. See `docs/deferred/
   migrate-large-migration-oom-and-resume-behavior.md` for exactly what a
   resume looks like for `migrate:import`, including the `migrate:reset-status`
   step needed if the job was mid-migration (not cleanly idle) when it stopped.
3. **If the job is NOT resumable**, don't start it on dev/staging in the
   evening — either run it on production-adjacent infrastructure that doesn't
   shut down, or make sure it can finish before 11pm, or ask whether the
   nightly shutdown can be paused for that instance for one night (touches
   shared cost-control infrastructure — confirm with the team before changing
   it; the specific mechanism enforcing this schedule hasn't been documented
   here yet).

## Related

- `docs/deferred/d11-dev-database-bootstrap-and-migration-source.md` — the 2026-07-17/18 migration this constraint was discovered during
- `docs/deferred/migrate-large-migration-oom-and-resume-behavior.md` — resuming an interrupted migration
- `docs/planning/dev-0-drift-capture.md` — the container restart-policy fix this explains
