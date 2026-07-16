# How does dev's database get populated? Bootstrap + migration source

**Area:** deployment / database / migration / dev environment
**Raised during:** Session 2026-07-15 (1b.1 part 4 — first green pipeline run)
**Jira:** (add when available)
**Priority:** High — dev serves `/core/install.php` until this is decided; blocks 1a.9 staging execution and part-4 item 7 validation

**Status: DECIDED — 2026-07-16 (Yuji).** Worked through the three open decisions
below. Verified findings unchanged. Decision C points at the **shared** D7 dev DB (a
team resource, not anyone's personal copy); its connection details are self-contained
on dev-0 (see below), so execution is not gated on any one person — though a heads-up
to Than as the original D7 dev is still worthwhile. Execution still pending; the ⚠
kmassets-sink prerequisite gates the first migration regardless.

## Decisions (2026-07-16)

**A — Bootstrap: `deploy_install.yml` playbook.** Codify the create-DB → `site:install`
→ uuid set → shortcut delete → `config:import` sequence as an Ansible playbook so dev
is rebuildable on demand and the sequence stays honest, rather than a one-off runbook.
(Fixing `rebuild.sh`'s broken `site:install --existing-config` so laptop + dev share
one path is the follow-on that removes the uuid/shortcut workaround steps.)

**B — dev's deploy runs `updb` + full `cim`, gated on a fresh RDS snapshot.** A
deliberate divergence from dsf (which only does the partial SimpleSAMLphp `cim`), so
that config shipped in a commit actually reaches dev. The mitigation (Yuji): take a
full **RDS snapshot of `mandala_drupal_0`** before the run — self-serve, cheap on
`rds-mysql8-staging`, gives a rollback point each deploy. Fold the snapshot in as a
pre-`cim` task in `deploy_install.yml`. **Scope limit:** the snapshot only protects the
Drupal DB; it does **not** roll back the kmassets Solr index (see the ⚠ below), so the
two risks need two separate mitigations.

**C — Point migration at the shared, stable D7 dev DB (option b, reframed).** *Not*
the `dockerfiles-database-1` container stopped on dev-0 (that stays retired), *not* a
laptop dump, and *not* anyone's personal copy — the **shared** D7 dev database, a team
resource, on the **staging RDS estate** (`rds-standard-staging` /
`rds-mysql8-staging.internal.lib.virginia.edu`), the same VPC as dev's own
`mandala_drupal_0`, so the D11 dev container can reach it. This satisfies the "stable,
reproducible, non-laptop source" principle without the RDS-load work of option (a). The
Aegir-recoupling and moving-target objections to (b) do **not** apply: the source is a
stable RDS DB, decoupled from the stopped container.

Execution details for C:
- **Host = `rds-mysql8-staging.internal.lib.virginia.edu` — the SAME server as dev's own
  `mandala_drupal_0`.** `rds-standard-staging` (MySQL 5) is the **OLD** server the mandala
  DBs were moved off; do not point at it. This means the D7 source is reachable on the
  exact host/subnet dev already talks to — **no cross-host question**.
- **Password source = AWS Secrets Manager `${env}/rds/standard/mandala_drupal`**, reused
  as-is. The secret *name* keeps "standard" for historical reasons, but it holds the
  current `mandala_drupal` user password, and that same user/password was recreated on
  the mysql8 server. Proof: the live D11 app reads exactly this secret
  (`mandala/drupal/staging/ansible.tf` →
  `data "aws_secretsmanager_secret_version" "database_password" { secret_id =
  "${var.environment}/rds/standard/mandala_drupal" }`) while connecting to the mysql8
  host (`container_0.env.generated: MYSQL_HOST: rds-mysql8-staging…`). So **no new
  secret and no literal in the repo** — resolve at runtime as the app already does.
- **User = `mandala_drupal`** (`ALL on mandala%`). The migrate source is therefore just a
  **second Drupal DB connection with the SAME host/user/password as dev's own DB,
  differing only in the database name.** That D7 dev DB name must match `mandala%` for
  the existing grant to cover it (finding 4) — confirm the actual name (visible in the
  stopped Aegir `hostmaster` container's per-app Apache vhost confs under
  `/var/aegir/config/server_master/apache/vhost.d/`, retrievable via `docker cp` without
  starting it), and parameterise it — not the DDEV-hardcoded `d7_images`.
- **Users don't come with the content.** The D7 `mandala_shared` prefix kludge
  (`build/files/platform.settings.php`) keeps user/role/authmap/session tables in a
  separate shared DB (on `rds-standard-production`), so the image migration brings
  content but **no real users** — dev can only test the auto-provision path of part 4's
  matrix until the user migration is unblocked. See [[project-d7-shared-user-database]]
  and `d7-shared-user-database.md`.

## Why now

The pipeline reached its first fully green run on 2026-07-15 and the D11 container is
up on dev-0 — but it redirects to `/core/install.php`, because `mandala_drupal_0` does
not exist. Everything around the database now works; the database itself is the gap.

Yuji's framing: he and Than each have a working local database, "probably in slightly
different states". The question is whether either seeds dev, and whether migrations run
there at all.

## Verified findings (not opinions)

**1. Nothing automates any of this.** `deploy_backend.yml` runs exactly two Drupal
commands: `drush cr`, and `drush cim -y --partial --source=/var/simplesamlphp/drupal-config`
— the SimpleSAMLphp settings only. There is no DB creation, no `site:install`, no full
`config:import`, no `updb`, no migrate. **dsf's `deploy_backend_0.yml` is identical**, so
this is the house pattern, not an omission in ours.

Consequence beyond bootstrap: **config shipped in a commit does not get applied by a
deploy.** Someone must run `drush updb && drush cim` by hand.

**2. The drush execution path is SOLVED — `staging-migration-execution-prerequisites.md`
is half stale.** That note lists two blockers: a drush path in staging, and the source
DB. The green deploy runs `docker exec mandala-drupal-0 {{ drupal_home }}/vendor/bin/drush cr`
successfully, so the drush path exists and is proven. Only the source DB remains.

**3. The D7 source is DDEV-only today.** `scripts/load-d7-source.sh` uses `ddev mysql`
and loads into a secondary database named `d7_images`;
`migrate_plus.migration_group.mandala_images.yml` documents the source as *"the secondary
`d7_images` DDEV database"*. Neither survives contact with RDS.

**4. The local source DB name is not grantable on RDS.** The `mandala_drupal` account
holds `ALL on mandala%` (the Aegir-era grant —
`staging/datastores/databases/mandala_drupal.tf`). A database called **`d7_images` does
not match `mandala%`**, so the RDS source would have to be named e.g.
`mandala_d7_images`, and the source DB name / connection key must become a parameter
rather than the hardcoded DDEV name.

**5. Dev's DB config** (`container_0.env.generated`): host
`rds-mysql8-staging.internal.lib.virginia.edu`, database `mandala_drupal_0`, user
`mandala_drupal`. The DB is self-serve — the existing `mandala%` grant already covers it,
no DBA, no new secret (see the 2026-07-14 correction to scope-doc §5.2).

## The principle to argue about first

The design already answers "what is a Mandala D11 site": **config is code**
(`drupal/config/sync`, committed) and **content comes from migration** (from a D7 source,
via `scripts/migration-cycle.sh`). Both are reproducible from the repo.

A dump of anyone's laptop is neither. Seeding dev from a local DB does not resolve the
Yuji/Than drift — it promotes one of them, produces a third state nobody can reproduce,
and bakes in local hand-fiddling: the manual `config:set system.site uuid`, the
`shortcut`/`shortcut_set` deletions, test content, and whatever that machine's migration
run happened to produce. It would also hide rot in the documented rebuild path.

So the proposed bootstrap for dev is the same sequence a laptop uses:

1. create `mandala_drupal_0`
2. `drush site:install`
3. `drush config:set system.site uuid dfc3f060-3fa3-4a1e-b081-dbc07bdc4323`
4. `drush entity:delete shortcut` + `shortcut_set`
5. `drush config:import`

Steps 3–4 exist only because `site:install --existing-config` is broken (the standard
profile has a `hook_install`) — dev inherits that wart, which is an argument for fixing
`rebuild.sh` rather than hand-running the workaround on a server.

## Open decisions — RESOLVED 2026-07-16 (see "Decisions" section above)

The analysis below is retained for the record; the outcomes are A = playbook,
B = yes (snapshot-gated), C = separate stable D7 dev DB.

**A. Bootstrap: runbook or playbook?** A `deploy_install.yml` makes dev rebuildable on
demand and forces the sequence to stay honest; a runbook is faster today. Related: fixing
`rebuild.sh` would let both share one path.

**B. Should dev's deploy run `updb` + full `cim`?** It does not today, so config in a
commit never reaches the site. Reasonable for dev; dangerous as a production default,
which is presumably why dsf abstains. If dev diverges from dsf here, say so deliberately.

**C. Where does dev's D7 source live?**

- **(a) Load the D7 dump into `mandala_d7_images` on the staging RDS.** Mirrors local
  exactly and is repeatable; needs a non-DDEV loader and the parameterisation from
  finding 4.
- **(b) Point at the live D7 dev database.** A moving target, and it re-couples us to the
  Aegir stack being decommissioned (stopped on dev-0 2026-07-15 —
  `dev-0-drift-capture.md`).
- **(c) Do not migrate on dev at all**; keep it config-only.

*Recommendation (Claude, not agreed):* **(a)**. "What actually closes Step 1a" is running
the cycle against a prod/staging D-copy **in staging, not just DDEV** — dev is where that
rehearsal becomes real. (b) reverses a decision made the same day; (c) leaves a Sprint 1
exit criterion untested.

## ⚠ Decide BEFORE the first dev migration — this one can do real damage

`kmassets-sync-hook-fires-during-migration.md` is filed Medium on the strength of DDEV
behaviour. **On dev it is sharper:** with the 1a.8 direct-to-master sink
(`KmassetDirectSink`), a 111k-node migration fires the per-node sync and would write
~111k documents into the **real kmassets index**. It must be disabled, or pointed
somewhere safe, before the first dev migration — not discovered after.

## Also constrains part 4

`d7-shared-user-database.md` still blocks the user migration, so dev will have **no real
users**. Part 4's 5-row provisioning matrix needs both a match-existing-account path and
an auto-provision path; with no accounts to match, only auto-provision is testable until
the user migration is unblocked.
