# Readiness: the dev-0 database + the user migration

**Raised during:** Session 2026-07-16 (right after the dev-database decision landed)
**Depends on:** [dev-database decision](../deferred/d11-dev-database-bootstrap-and-migration-source.md) (DECIDED 2026-07-16),
[D7 shared user database](../deferred/d7-shared-user-database.md),
[staging migration prerequisites](../deferred/staging-migration-execution-prerequisites.md),
[kmassets sync hook fires during migration](../deferred/kmassets-sync-hook-fires-during-migration.md)

---

## Purpose

Before we stand up the de-facto development database on dev-0 **and** tackle the
user migration, this is the honest state of what is built vs. what is not. Two
things are genuinely ready — the drush execution path (proven green 2026-07-15)
and the credential/host story (fully resolved 2026-07-16). The rest is
small-but-real build work, and two items gate everything.

**Verdict: not yet ready to run.** One code change unblocks both tracks; one
unbuilt guard is a landmine; the user migration is greenfield.

---

## The one change that blocks BOTH tracks

**There is no migrate DB connection outside DDEV.** `drupal/web/sites/default/settings.php`
defines `$databases['migrate']['default']` **inside** the
`if (getenv('IS_DDEV_PROJECT') == 'true')` block, hardcoded to the DDEV
`d7_images` / `db` / `db` values. On dev-0 (not DDEV) that block is skipped, so
the connection does not exist and `migrate:import` has nothing to read. Both the
content migration and the user migration read through Drupal `migrate`
connections, so nothing runs on dev-0 until this is env-driven.

Resolved by the decision work, the inputs are known and shared: host
`rds-mysql8-staging`, user `mandala_drupal`, password from Secrets Manager
`${env}/rds/standard/mandala_drupal` (as the app already resolves it) — the
source differs from the primary DB **only in database name**. Draft of the
settings.php change accompanies this doc (see the Track-1 checklist, item 1).

## ⚠ The landmine (Track 1)

**On dev-0 the kmassets sink fires and writes to the real index — the DDEV
safety does not apply.** The only guard today is
`_mandala_kmassets_sync_is_configured()` (`mandala_kmassets_sync.module`), which
just checks whether `solr_master_url` is empty. In DDEV it is empty, so the sink
no-ops. But the **committed** `mandala_kmassets_sync.settings.yml` sets
`solr_master_url` to the real staging master, and dev-0 is not DDEV — so the
sink is live, and a 111k-node migration writes ~111k docs into the production
kmassets index. The recommended migration-in-progress gate (a
`MigrateEvents::PRE_IMPORT` / `POST_IMPORT` flag) is **not implemented**. Build
it, or blank `solr_master_url` on dev during the migrate, **before** the first
dev migration. An RDS snapshot (decision B) does not cover this — it is Solr.

---

## Track 1 — stand up the dev-0 database

| # | Item | State | Owner |
|---|---|---|---|
| 1 | Env-driven `migrate` connection (the shared blocker) | **Not built** — DDEV-only; draft attached | Xiaoming |
| 2 | kmassets sink migration guard (the landmine) | **Not built** — only the empty-URL check exists | — |
| 3 | `deploy_install.yml` bootstrap playbook (decision A) | **Not built** — sequence known | Yuji / DevOps |
| 4 | The actual D7 dev DB **name** (must match `mandala%`) | **Unknown** — read from the Aegir vhost confs | — |
| 5 | `container_0.env` on dev: add `MIGRATE_SOURCE_DATABASE` (+ the value from #4) | **Not built** — terraform/ansible side | Yuji |
| 6 | `rebuild.sh` `--existing-config` fix (drops the uuid/shortcut wart) | Optional follow-on | — |

**Bootstrap sequence** (decision A, from the deferred note): create DB →
`drush site:install` → `config:set system.site uuid dfc3f060-…` →
`entity:delete shortcut` + `shortcut_set` → `config:import`. Decision B adds a
pre-`cim` RDS snapshot of `mandala_drupal_0` and, on dev, runs `updb` + **full**
`cim` (a deliberate divergence from dsf).

## Track 2 — the user migration (greenfield)

| # | Item | State |
|---|---|---|
| 7 | A user migration definition | **Does not exist** — config/sync has only `d7_images_*`; this is cross-cutting, belongs outside the images group |
| 8 | Second source connection to `mandala_shared` | **Not built** — same host/user/password, DB = `mandala_shared`(`_dev`); confirm which server/name |
| 9 | SAML/NetBadge account mapping | Committed SP maps `uid→username`; the match-existing path works only if **D7 usernames are UVA computing ids** — confirm against `mandala_shared` |
| 10 | Design calls: phpass hashes (likely fine), `realname` field, `mandala_shared` cardinality/filtering | Open — see the deferred note |
| 11 | Re-runs gated on users: `d7_images_collection_memberships` (211/249 skipped), the 174 `uid:1`-forced groups | Waiting on 7–10 |

The shared user base means **one** user migration serves all five site tracks;
the D11 side should be unified too (not a per-site `d7_images_*`-style
migration). See [D7 shared user database](../deferred/d7-shared-user-database.md).

---

## Recommended order

1. **Env-driven migrate connection** (#1) — one settings.php change, unblocks
   both tracks.
2. **kmassets sink guard** (#2) — before *any* dev migration; the one true
   landmine.
3. **Confirm DB names** (#4, #8) from the Aegir vhost confs while pulling the
   connection details (`/var/aegir/config/server_master/apache/vhost.d/`).
4. **`deploy_install.yml`** (#3) + the dev env var (#5) → bootstrap dev off
   `/core/install.php`.
5. First **content** migration on dev (Images) — proves the plumbing end to end.
6. Then the **user migration** (#7–#11) as its own cross-cutting track.

## Already ready

- **Drush execution path** — `docker exec mandala-drupal-0 …/drush …`, proven
  green on dev-0 (2026-07-15).
- **Credential / host story** — the D7 source reuses dev's own
  host/user/password; no new secret, nothing secret committed (2026-07-16).
