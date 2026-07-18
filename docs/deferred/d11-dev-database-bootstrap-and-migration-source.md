# How does dev's database get populated? Bootstrap + migration source

**Area:** deployment / database / migration / dev environment
**Raised during:** Session 2026-07-15 (1b.1 part 4 — first green pipeline run)
**Jira:** (add when available)
**Priority:** High — dev serves `/core/install.php` until this is decided; blocks 1a.9 staging execution and part-4 item 7 validation

**Status: DECIDED 2026-07-16, Decision C's source location CORRECTED and EXECUTED
2026-07-17 (Yuji).** The migration source dump+load is done; dev bootstrap (A) is
still outstanding. See the correction block immediately below before reading
Decision C's original text — several of its host/credential claims turned out
to be wrong when checked against the live systems.

## 🔴 HANDOFF — Yuji is out starting 2026-07-18; Than/Xiaoming picking up Monday

A `mandala_images` migration is running on dev-0 right now, unattended, kicked
off 2026-07-17. **Read this whole section before touching anything.**

**⚠ dev-0 shuts down every night 11pm–6am (cost saving) — this migration will
almost certainly get cut off by that, sit idle overnight, and need a manual
restart.** It does not resume on its own when the instance comes back up. See
`docs/dev-notes/howto-long-running-jobs-on-dev-staging.md` and the "no error at
all, log just stops" case below — that's very likely what you'll find if
you're reading this after an overnight gap, not a new bug.

**Check status first:** see `docs/dev-notes/howto-access-mandala-nodes.md` for
how to SSH in. Then:

```bash
sudo docker exec mandala-drupal-0 sh -c "tail -60 /tmp/migrate_import3.log"
```

(Note the file name — it's `migrate_import3.log`, not `migrate_import2.log`;
the earlier log was superseded after a crash-and-resume, see below.)

**What you'll likely find, as of 2026-07-18 ~10:04 EDT:**
- `d7_images_collections`, `d7_images_subcollections`,
  `d7_images_external_classification_scheme`, `d7_images_external_classification`,
  `d7_images_image_agent` (111,194 rows), `d7_images_image_descriptions`
  (55,041 rows) — **all done, 100% clean.**
- `d7_images_collection_memberships` — **36/246, 210 "failed."** This is
  **expected**, not a bug — it maps D7 users to D11 users, and the user
  migration (PR #45) hasn't landed. Do not "fix" this.
- `d7_images_shanti_image` (111,340 rows) — **crashed once on a PHP memory
  limit, was reset and resumed**, running as of last check. See
  `migrate-large-migration-oom-and-resume-behavior.md` for exactly what
  happened and how it was fixed, in case it happens again (it very well might
  — a resume re-processes the full row count, so it's a multi-hour operation
  each time).
- `d7_images_image_collection_membership` (~111,304 rows) — queued after
  `shanti_image`, not yet started as of the last check. Should be clean (it's
  image↔collection, not user-dependent — don't confuse it with
  `d7_images_collection_memberships` above; see
  `migrate-group-import-aborts-on-partial-failure.md` for the full
  disambiguation).

**If you find an `Exception` with no forward progress:** check which kind first:

1. **`drush migrate:reset-status`-shaped problem** (e.g. `is busy with another
   operation: Importing`, or a memory-exhaustion fatal error above it) — it's
   very likely the same OOM pattern. The fix is documented step by step in
   `migrate-large-migration-oom-and-resume-behavior.md` — short version:
   `drush migrate:reset-status <migration_id>`, then re-run with
   `php -d memory_limit=1024M vendor/bin/drush.php migrate:import <migration_id>`
   (note: `vendor/bin/drush.php`, **not** `vendor/bin/drush` — the latter is a
   bash wrapper, not a PHP file, and silently no-ops if you pass `-d` flags to it).
2. **No error at all, log just stops mid-progress-bar with no `Exception` and
   no `ALL_DONE`** — the instance almost certainly went through its **nightly
   11pm–6am shutdown** mid-run (see `docs/dev-notes/
   howto-long-running-jobs-on-dev-staging.md`). This is a full EC2 stop, not
   just a container restart — the `docker exec`'d migration process does
   **not** auto-resume when the instance comes back, even though the
   container itself does. **Expect this to have happened at least once** if
   you're reading this Monday — the ETA when this was written (2026-07-18
   ~10am) had the run finishing well past 11pm that same night. Same recovery
   as above: check `migrate:status` for the migration's actual state
   (`Importing` needs `reset-status` first; `Idle` with rows still
   `unprocessed` can just be re-run directly), then re-launch.

**Either way, nothing is lost** — Migrate API tracks progress per-row in its
map tables, so any interruption (OOM, nightly shutdown, or otherwise) is
resumable, never a data-loss event. It just needs a human to notice and
re-launch it — neither failure mode resolves itself.

**Once the Images migration is done — next steps:**

1. Run `drush kmassets:index-all` + `drush kmassets:audit` (the per-node Solr
   sync was deliberately suppressed during migration — see
   `kmassets-sync-hook-fires-during-migration.md`).
2. Merge PR #45 only after Than has rebased it per the comment on that PR
   (see `migrate-shared-vs-migrate-users-connection-duplication.md` — it
   currently duplicates a connection PR #49 already built).
3. **Run the user migration (`mandala_users` group) the same way as Images**
   — directly on dev-0 via `drush migrate:import`, same drill as everything
   above. It's small (~1,543 users, ~3,300 rows total including roles and
   authmap) and will **not** hit the multi-hour scale problems Images did —
   no special handling, no landmark/laptop approach needed, just run it.
4. Once real users exist, **re-run `d7_images_collection_memberships`** to
   pick up the ~210 rows that failed earlier for lack of a matching D11 user
   (`drush migrate:import d7_images_collection_memberships` — Migrate API
   will only reprocess what's still unresolved).

All four deferred docs referenced above, plus the full session transcript, are
on `main` as of PR #55 (or on its branch if not yet merged when you read this —
check).

## ⚠ CORRECTION + EXECUTION UPDATE (2026-07-17)

Decision C below claims the D7 source is reachable "on the exact host/subnet dev
already talks to — no cross-host question" (same host as `mandala_drupal_0`,
i.e. `rds-mysql8-staging`). **That's wrong.** Checked live by SSHing both the
production and dev-0 nodes:

- **Live D7 production (all 5 sites + the shared user DB) actually runs on
  `rds-mysql8-production`** (user `mandala_sites`), not on `rds-mysql8-staging`
  and not on `rds-standard-production` (that estate is **stopped/retired** —
  every site vhost already has it commented out in favor of mysql8).
- **dev-0 cannot reach `rds-mysql8-production`** (network/SG isolation) — only
  a VPN-connected workstation, or dev/staging themselves, can reach
  `rds-mysql8-staging`. So there genuinely IS a cross-host question, and the
  source has to be dumped from production and loaded onto staging — it was
  never "the same DB dev already talks to."
- The shared user DB (finding below, §"Users don't come with the content")
  said it lives on `rds-standard-production`. **Corrected: it's `mandala_shared`
  on `rds-mysql8-production`** (1,543 rows in `.users`, confirmed via a live
  query — do not trust `sites/all/platform.settings.php`'s
  `$shared = 'mandala_shared_dev.'`, which is stale/inert on the disabled site).

### RDS instances (`aws rds describe-db-instances`, 2026-07-17)

| Identifier | Engine | Status | Role |
|---|---|---|---|
| `rds-mysql8-production` | MySQL 8.4.8 | available | Live D7 production (all 5 sites + `mandala_shared`) |
| `rds-mysql8-staging` | MySQL 8.4.8 | available | dev-0's own D11 DB (`mandala_drupal_0`) + the loaded D7 migration source DBs |
| `rds-standard-production` | MySQL 5.7.44 | **stopped** | RETIRED — do not use for anything new |

### Network reachability matrix — confirmed by direct testing, 2026-07-17

**This is the fact that drives the whole dump/load design — don't re-derive it, it cost real time to pin down:**

| From ↓ / To → | `rds-mysql8-production` | `rds-mysql8-staging` |
|---|---|---|
| **production node** (`mandala-drupal-0`) | ✅ reachable (its own DB) | ❌ UNREACHABLE — confirmed via TCP test from the node's mariadb container (silent SG drop/timeout, not a fast refusal) |
| **dev-0 / staging-0 nodes** | ❌ UNREACHABLE — confirmed via TCP test from dev-0 | ✅ reachable (dev's own DB host) |
| **VPN-connected workstation** | ✅ reachable — confirmed via direct TCP test | ✅ reachable — confirmed via direct TCP test |

`rds-mysql8-staging` is deliberately scoped to be reachable **only from dev,
staging, and the VPN** (confirmed live, matches the SG intent). Practical
consequence: **any prod↔staging data transfer must run from a VPN-connected
workstation** — there is no direct node-to-node path, so don't try to relay
the dump through `ssh`+`docker exec` on either box.

**✅ Dump + load executed 2026-07-17, row counts verified source==target:**

| Source (rds-mysql8-production) | Target (rds-mysql8-staging) | Rows |
|---|---|---|
| `mandalaimageslib` | `mandala_d7_images` | 287,939 (`node`) |
| `mandala_shared` | `mandala_d7_shared` | 1,543 (`users`) |

The verified run above used **manual, tmpdir-based commands** (since cleaned
up). Those were then rewritten into **`scripts/refresh-d7-staging-source.sh`**
for repeat refreshes, per a security requirement to never write credentials to
disk: passwords are held only in shell variables (never a file, not even a
restricted tmpdir), and the dump is streamed directly `mysqldump | mysql`
through a pipe rather than landing as a `.sql` file on disk, since the
shared-user dump carries real PII. **⚠ The script itself is UNTESTED** — only
`bash -n` syntax-checked, never run end-to-end. Treat it as a draft: run it
once for real and re-confirm the row counts above before relying on it for a
production-data refresh. See the script's header comment for the full account
of gotchas hit along the way (stale duplicate `db_passwd` lines in the Aegir
vhosts; Homebrew's `mysql` 9.x client can't auth to these RDS accounts, worked
around via a `mysql:8.0` Docker image). Staging RDS already held several
older, unidentified `mandala*` DBs from prior sessions (`mandala_images_dev`,
`mandala_shared_dev`, etc.) — deliberately left untouched; new non-colliding
names were used instead.

**✅ Decision A (bootstrap) EXECUTED 2026-07-17** — dev-0 no longer serves
`/core/install.php`. Ran by hand (the documented `deploy_install.yml` playbook
itself is still not built — see Decision A below): fresh `drush site:install
standard` (`--existing-config` is still broken, now errors on the flag syntax
too) → `config:set system.site uuid dfc3f060-3fa3-4a1e-b081-dbc07bdc4323` →
`cache:rebuild` (needed before `entity:delete shortcut`/`shortcut_set` would
recognize the entity types, even though `pm:list` already showed the module
enabled) → `config:import`. Result: `Drupal bootstrap: Successful`, DB
connected, HTTP 200, zero `config:status` drift.

**Bonus find while verifying:** `core.entity_form_display.user.user.default`
kept re-diverging after every `config:import`. Root cause: live config had a
`simplesamlphp_auth_user_enable` form-display component (added when that
module was enabled for 1b.1 part 4) that had **never been captured in the
committed YAML** — a real config/sync gap, not an install artifact. Fixed by
adding the component to the file (verified against live `drush config:get`
first) — see that file's diff in this same commit.

**Still open:** wire `MIGRATE_SOURCE_DATABASE=mandala_d7_images` /
`MIGRATE_USERS_DATABASE=mandala_d7_shared` (host `rds-mysql8-staging`, user/pass
= dev's own `mandala_drupal` — no new secret) onto dev-0's container env;
disable the kmassets sink before the first `migrate:import` (unchanged
prerequisite, see below); still no `deploy_install.yml` playbook (Decision A's
automation), so this bootstrap won't survive a rebuild without being re-run
by hand or the playbook getting built.

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
  separate shared DB ~~(on `rds-standard-production`)~~ **[CORRECTED 2026-07-17: on
  `rds-mysql8-production`, see the correction block above]**, so the image migration
  brings content but **no real users** — dev can only test the auto-provision path of part 4's
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
