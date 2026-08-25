# Pre-deploy RDS snapshot gate for the unattended full updb + cim

**Area:** deployment / Ansible / RDS / database safety
**Raised during:** Session 2026-08-17 (following up on the config-sync deploy fix)
**Jira:** (add when available)
**Priority:** Medium now, **High before production rollout** — deliberately not
solving this during active development; must be resolved before this deploy path
is trusted against production data.

## What changed that makes this matter

`deploy_backend.yml` (terraform-infrastructure commit `5904a3684`) now runs a full
`drush updb` + `drush cim` on every deploy that touches `drupal/**`/`package/**`/
`pipeline/**`, and fails the build on any error or remaining config drift. See
[deploy-never-imports-config-sync.md](deploy-never-imports-config-sync.md). Before
this, neither command ran automatically, so there was no unattended, code-triggered
path that could mutate or destroy dev-0's database. Now there is: a bad update hook
or a config change with an unintended side effect (e.g. a field deletion) runs
unattended against the real database — currently the 111,340-node Images migration,
Group/collection membership data, and the user migration.

Decision B (2026-07-16, [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md))
already called for gating this on a fresh RDS snapshot; that half of the decision
was never built. This note captures why it still matters and what was learned
checking the live instance, so it doesn't need to be re-derived later.

## What a naive "just take a snapshot" mitigation would miss

Checked live 2026-08-17 (`aws rds describe-db-instances` / `describe-db-snapshots`
against `rds-mysql8-staging`, `staging` aws-vault profile):

1. **There's already a baseline.** `rds-mysql8-staging` has 7-day automated backups
   — both native RDS daily snapshots and AWS Backup jobs running roughly daily. This
   isn't a "zero backups" situation; a pre-deploy snapshot only needs to close the
   gap the daily cadence leaves.
2. **Staleness is the real gap.** A deploy can land any time between two daily
   snapshots. If `updb`/`cim` corrupts something mid-day and nobody notices until
   the next day, the nearest automated snapshot could be up to ~24h stale relative
   to the moment right before the bad deploy.
3. **Restore blast radius is bigger than it looks.** `rds-mysql8-staging` is a
   **shared 200GB instance**, not mandala-dedicated — it also holds
   `mandala_d7_images`, `mandala_d7_shared`, and likely other teams' staging
   databases. RDS snapshots are instance-level, not per-database. "Roll back
   `mandala_drupal_0`" via snapshot restore is not a clean rewind: it means
   restoring the whole instance to a **new temporary instance**, then extracting
   and re-importing just `mandala_drupal_0` by hand — the same manual
   dump-and-load mechanics as the D7 source refresh (`scripts/refresh-d7-staging-source.sh`),
   not a fast undo button. A pre-deploy snapshot narrows step 1 (staleness) but does
   **not** solve step 2 (extraction mechanics) — worth being honest about that gap
   in whatever gets built.
4. **There's already a working precedent** for a manual, purpose-tagged snapshot:
   `mandala-preusermigration-20260812-1151`, taken by hand before the user
   migration. The proposed gate just automates that pattern per-deploy.

## What has zero mitigation at all: Solr

A DB snapshot/restore — however well built — does not undo whatever a corrupted
deploy already pushed to the kmassets Solr index via `KmassetDirectSink`'s
node-save hooks. Restoring the DB to a pre-deploy state while Solr keeps whatever
it was already sent leaves the two systems disagreeing with each other, and
nothing currently detects or fixes that divergence. This needs its own mitigation,
separate from the RDS snapshot — not scoped or designed yet.

## What needs to happen (before production rollout, not now)

1. Decide whether a pre-`updb` snapshot task belongs in `deploy_backend.yml`
   itself (the routine per-deploy path, since that's what actually runs `updb`/
   `cim` now) rather than `deploy_install.yml` as Decision B's original text
   said — `deploy_install.yml` is the one-time bootstrap playbook and doesn't run
   on routine deploys.
2. Decide on naming/retention convention for these snapshots (they're
   instance-level and shared storage — need a cleanup policy so they don't
   accumulate indefinitely on a shared 200GB instance).
3. Decide whether the deploy should block waiting for the snapshot to reach
   `available` before proceeding (safer, slower every deploy) or proceed once
   the snapshot is requested (faster, but the point-in-time cut needs to be
   verified as reliable without waiting for full completion).
4. Design and document the actual restore runbook (temp-instance restore +
   single-DB extraction) — so it exists and is tested *before* it's ever needed
   under pressure, not written during an incident.
5. Design a separate mitigation for the Solr-side divergence problem above —
   currently no plan exists.

## Why this is fine to leave open right now

Deploys only fire on `drupal/**`/`package/**`/`pipeline/**` changes (docs-only
merges are filtered by `trigger_paths`), so actual `updb`/`cim` runs are
infrequent, and the project is still in active development — dev-0's data is
reproducible from migration, not irreplaceable production content. The urgency
changes materially once this deploy path (or its production equivalent) runs
against real production data.

## Partial mitigation DECIDED and built (2026-08-25, Yuji): our own logical dumps

**The instance-wide snapshots are not a usable routine safety net.** RDS automated
backups are per-INSTANCE, not per-database, and up to ~24h stale. Recovering one
database from one means standing up a whole replacement instance — which nobody will
realistically do mid-development, so in practice the net does not exist for the
"undo the last hour" case, which is the case that actually keeps arising.

**Decision: take our own logical dumps at defined checkpoints** rather than relying
on the snapshot for routine work. Built as `scripts/db-checkpoint.sh`
(`save` / `list` / `restore`). It goes through drush, reusing the same `$DRUSH`
override `scripts/migration-cycle.sh` already takes, so it inherits the site's own
credentials and needs no `MYSQL_*` plumbing, no mysqldump client and no separate
secret. Restore is a targeted reload of one database, in minutes.

Suggested checkpoints around an acceptance run: `pre-import`, `post-import`,
`post-validate`.

**This does not close this note.** Scope of what is and is not covered:

- ✅ Covers the operator-initiated case — an acceptance cycle, a risky manual `cim`,
  any "let me be able to undo this" moment.
- ❌ Does **not** cover the case this note was raised for: the *unattended,
  code-triggered* `updb`/`cim` on deploy. Nothing invokes the checkpoint script from
  `deploy_backend.yml`, so a bad update hook still runs with no rollback point. Wiring
  a pre-deploy checkpoint into the playbook is the remaining work, and it needs a
  retention policy so deploys do not fill the volume.
- ⚠ The checkpoint directory must sit on a **persistent bind mount**. If it does not,
  the checkpoints die with the container — the same failure mode the OAuth2 signing
  keys hit in August.
- ⚠ **`scripts/db-checkpoint.sh` is UNTESTED against a real database.** Syntax and its
  label/confirmation guards are verified; the dump and restore paths are not. Exercise
  it on something disposable before trusting it. (Same caveat as
  `scripts/refresh-d7-staging-source.sh`.)

RDS snapshots remain the disaster-recovery story; this covers what they are bad at.

## Cross-references

- [deploy-never-imports-config-sync.md](deploy-never-imports-config-sync.md) — the fix that created this exposure
- [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md) — Decision B, the original (unbuilt) mitigation
- [production-migration-planning.md](production-migration-planning.md) — where this should get picked back up as rollout nears
