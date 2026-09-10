# How-To: Run long jobs on dev/staging safely (nightly shutdown window)

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-09-09

## Goal

Know about — and plan around — the nightly cost-saving shutdown that affects
some Mandala EC2 instances, before kicking off anything that runs for hours
(migrations, long imports, batch jobs). **Verify current status before
relying on it** — see "This can change without warning" below.

## The constraint (and who it currently applies to)

A shared UVA Library scheduler stops a batch of staging-tier EC2 instances at
**~23:00** and starts them again at **~06:00**, across many apps, not just
Mandala. This is a full EC2 instance stop, not just a container restart —
everything running on the box stops, including any `docker exec`'d process
(e.g. a `drush migrate:import`), regardless of restart policies.

**As of 2026-09-09, verified directly (not assumed):**

| Host | On the nightly schedule? |
|---|---|
| `staging` (dev-1, `uva-mandala-drupal-staging-1`) | **Yes** |
| `dev-0` (`uva-mandala-drupal-staging-0`) | **No** — deliberately pulled from it at the team's request (asked of Dave Goldstein) and never put back |

This explains a previously-observed pattern from 2026-07-14/15
(`docs/planning/dev-0-drift-capture.md`): legacy Aegir containers racing
`mandala-drupal-0` for port 8080 after "dev-0's nightly reboot" — at the time,
dev-0 **was** on the schedule; it no longer is.

## ⚠ This can change without warning — verify, don't assume

**Membership in the nightly-stop batch is controlled entirely outside this
repo**, by an infra request to Dave Goldstein. There is no commit, PR, Slack
thread, or config file in this project that records which instances are
currently on the schedule — it can be added or removed at any time with
nothing here reflecting it. That is exactly how the table above went stale
once already (dev-0 was on the schedule in July, then wasn't, and nothing
here said so until this was re-verified in September).

**Before relying on overnight behavior for anything consequential, re-check
with `scripts/check-nightly-shutdown-status.sh`:**

```bash
./scripts/check-nightly-shutdown-status.sh <instance-id> [ssh-host]

# dev-0:
./scripts/check-nightly-shutdown-status.sh i-0e44bb9d8ea864ff3 \
  ys2n@mandala-drupal-dev-0.internal.lib.virginia.edu

# staging (dev-1):
./scripts/check-nightly-shutdown-status.sh i-07a252a1c22c9464e
```

It runs two independent checks, because either alone can mislead:

1. **The list of actually rebooted machines (CloudTrail).** Did
   `StopInstances`/`StartInstances`/`RebootInstances` actually fire for this
   instance ID in the last ~30 hours? This is the most direct evidence of the
   scheduler's current intent.
2. **Host uptime.** EC2 `LaunchTime` (reset by a Stop+Start cycle) and the
   guest OS's own boot time via `uptime -s` (reset by *any* reboot, including
   one outside the scheduler entirely). If a host has been up longer than one
   stop/start cycle would allow, it wasn't stopped — independent of what
   CloudTrail says.

Together they're hard to both get wrong the same way: CloudTrail proves the
scheduler's intent directly; uptime proves the outcome independently, and
catches the case where a host was rebooted by something else instead (as
dev-0's OS in fact was, once, without any matching `RebootInstances` API call
— see the script's own findings for that unresolved detail).

## What survives the shutdown, and what doesn't (for a host that IS on it)

| | Survives? |
|---|---|
| Your SSH connection / VPN dropping | ✅ Yes — the remote process is independent of the launching connection (see `docs/deferred/migrate-group-import-aborts-on-partial-failure.md`) |
| This Claude Code session ending | ✅ Yes — same reason |
| The container itself (`mandala-drupal-0` etc.) — if it's set `unless-stopped` | ✅ Comes back up automatically when the instance restarts at 6am |
| **A `docker exec`'d process running inside the container** (e.g. a migration) | ❌ **No** — it is not part of the container's own entrypoint/CMD, so it does not auto-resume when the container comes back. It's just gone. |

## What this means for a long-running job

1. **Verify the target host's current status first** (see above) — don't
   assume from this doc's table, which is a snapshot dated 2026-09-09.
2. **If the host IS on the schedule and the job will run past ~23:00, expect
   it to be cut off.** Don't assume it'll "just be done" when you check back.
3. **If the job is resumable** (e.g. Drupal Migrate API, which tracks
   progress per-row in its map tables), a cutoff is a non-event: nothing is
   lost, you just need to re-launch it. See `docs/deferred/
   migrate-large-migration-oom-and-resume-behavior.md` for exactly what a
   resume looks like for `migrate:import`, including the `migrate:reset-status`
   step needed if the job was mid-migration (not cleanly idle) when it stopped.
4. **If the job is NOT resumable and the host IS on the schedule**, don't
   start it on dev/staging in the evening — either run it on a host confirmed
   off the schedule, make sure it can finish before ~23:00, or ask whether the
   schedule can be paused for that instance for one night.

## Related

- `docs/deferred/d11-dev-database-bootstrap-and-migration-source.md` — the 2026-07-17/18 migration this constraint was discovered during
- `docs/deferred/migrate-large-migration-oom-and-resume-behavior.md` — resuming an interrupted migration
- `docs/planning/dev-0-drift-capture.md` — the container restart-policy fix the (then-current) dev-0 nightly reboot explains
- `scripts/check-nightly-shutdown-status.sh` — the verification script above
