# How does dev's database get populated? Bootstrap + migration source

**Area:** deployment / database / migration / dev environment
**Raised during:** Session 2026-07-15 (1b.1 part 4 — first green pipeline run)
**Jira:** (add when available)
**Priority:** High — dev serves `/core/install.php` until this is decided; blocks 1a.9 staging execution and part-4 item 7 validation

**Status: DECIDED — 2026-07-16 team session** (Yuji, Xiaoming, Than). Raised by Yuji.
The verified findings below still stand; the open decisions are now resolved — see
**Decisions (2026-07-16)** immediately below. Implementation of the `deploy_install.yml`
playbook is deferred to a session driven by someone with terraform-infrastructure access.

## Decisions (2026-07-16)

**A — Bootstrap is a playbook, not a runbook.** A new `deploy_install.yml` (in
terraform-infrastructure, `mandala/drupal/staging/ansible/`, modeled on
`deploy_backend.yml`) runs the create-DB → `site:install` → set-uuid → delete-shortcuts →
`config:import` sequence. Two properties make it different from `deploy_backend.yml` and
are mandatory: (1) a **hard idempotency guard** that refuses/skips if `mandala_drupal_0`
already bootstraps, so a re-run never wipes canonical dev; (2) it is **kept out of the
deployspec `build` phase** — invoked by hand once at bootstrap, never per-deploy.
*Implementation deferred* to a terraform-infrastructure-access driver (Yuji/Xiaoming).
Steps 3–4 (uuid set, shortcut delete) exist only because `site:install --existing-config`
is broken on the standard profile — which motivates fixing `rebuild.sh` so laptop and
server share one honest path.

**B — dev's deploy DOES run `updb` + full `cim`, snapshot-guarded.** A deliberate
divergence from the dsf house pattern (which abstains as a *prod* default; prod keeps
`updb`/`cim` as deliberate, backed-up, human steps). On dev it is wanted: it enforces
config-is-code, kills drift, and makes a merged branch's new config (e.g. migration YAMLs)
go live without hand-running. The one real risk is that dev now holds the **canonical
migrated content**, and `updb` / a field-dropping `cim` will mutate or destroy it
automatically on a bad commit — so the deploy **takes a DB snapshot (RDS snapshot or
`drush sql:dump`) immediately before the mutating steps**. That guard is what makes
auto-`updb`/`cim` safe on a content-bearing dev.

**C — the D7 source DBs live on the dev RDS instance; user-migration dev happens ON DEV.**
Load both sources onto the (already-up, otherwise-idle) staging RDS: `mandala_d7_images`
(image content; renamed from the DDEV `d7_images` to satisfy the `mandala%` grant) and
`mandala_shared_dev` (users). The authoritative migration runs on dev. The shared user DB
is **real PII and is never replicated to a laptop** — the user migration is developed on
dev against the RDS source, not locally. Since images migration is already done, laptops
no longer need a local `d7_images` at all; they pull the migrated result from dev. Draft
user migration: branch `feat/user-migration` (held, unpushed). Loaders need a non-DDEV
path to reach RDS.

**Standing prerequisite (unchanged, now sharper):** before the FIRST dev migration, the
1a.8 direct-to-master kmassets sink must be disabled/redirected — an on-dev image run
would otherwise write ~111k docs into the real kmassets index. See
`kmassets-sync-hook-fires-during-migration.md`.

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

## Open decisions (resolved 2026-07-16 — see Decisions above; original framing kept for history)

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
