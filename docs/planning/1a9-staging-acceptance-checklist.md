# 1a.9 Staging Acceptance Checklist

**Task:** Sprint 1, 1a.9 — execute the migrate → validate → rollback cycle against a
copy of the production Images DB **on dev-0**, and evidence the acceptance criteria.

> **Target environment settled 2026-08-25 (Yuji): `dev-0`.** This checklist says "staging"
> throughout; read it as dev-0. There is no D11 staging environment — the terraform
> workspace named `staging` holds `…-staging-0` (D11, = `mandala-drupal-dev-0`) and
> `…-staging-1` (still legacy **D7**, the migration source). Rationale and what the
> substitution does not prove: see the sprint doc's acceptance-criteria preamble.
**Runbook (mechanics):** [Migration Cycle Runbook](migration-cycle-runbook.md)
**Script:** `scripts/migration-cycle.sh`
**Acceptance criteria source:** [sprint doc](../sprints/sprint-01-images-implementation.md) §Acceptance criteria

> **Scheduling note (2026-07-08):** This staging run has been deferred to **end of
> Sprint 1 (after Step 1b)**. The security criterion (§D) is 1b-gated, the local
> MySQL 8.4 rehearsal has de-risked migration quality, and the DevOps prerequisites
> (§A) are needed for 1b staging work in any case. This checklist is unchanged —
> execute it as written once 1b is complete and the prerequisites are resolved.

> This is an execution checklist for a real staging run, meant to be copied into a
> session log / PR and ticked off with evidence. The mechanics (phases, baseline
> counts) live in the runbook; this doc is the *staging-specific* wrapper —
> prerequisites, safety, per-criterion evidence, and sign-off.

---

## Scope

1a.9 closes the **migration/validation/rollback** half of Step 1a. This run evidences:
migration completes + counts reconcile, NFC fidelity, KMaps round-trip, retrievability,
IIIF rendering, and the repeatable cycle itself.

**Out of scope here (Step 1b):** the *security* acceptance criterion (restricted item
non-retrievable by an unauthorized user) depends on Group collections + proxy visibility
filtering, which land in 1b.2/1b.3. Note it as **1b-gated** — do not block 1a.9 on it.

---

## A. Prerequisites — resolve BEFORE the run

These two are genuine gaps in the current repo, not just steps. Resolve/confirm first.
Tracked as a deferred item:
[staging-migration-execution-prerequisites](../deferred/staging-migration-execution-prerequisites.md).

- [ ] **⚠ RE-BASELINE `EXPECT_LIST` FIRST — the committed values do not match dev-0.**
      Measured live on dev-0, 2026-08-25, against the committed baseline in
      `scripts/migration-cycle.sh`:

      | key | EXPECT_LIST | dev-0 | diff |
      |---|---:|---:|---:|
      | `node:shanti_image` | 111,343 | 111,341 | −2 |
      | `paragraph:image_agent` | 111,350 | 111,345 | −5 |
      | `paragraph:image_descriptions` | 55,112 | 55,041 | −71 |
      | `paragraph:external_classification` | 9 | 9 | ✓ |
      | `field:field_subjects` | 79,174 | 79,338 | +164 |
      | `field:field_places` | 68,790 | 68,755 | −35 |
      | `field:field_kmap_terms` | 55,553 | **61,668** | **+6,115** |
      | `field:field_kmap_collections` | 83,493 | 83,494 | +1 |

      **Seven of eight keys differ, and the data is not wrong** — the two environments are
      running *different D7 source dumps*. The committed baseline was calibrated on the
      2026-07-07 staging dump (local DDEV `d7_images`: 288,023 nodes); dev-0's source was
      loaded 2026-07-17 from production (`mandala_d7_images`: 287,939 nodes). The
      `field_kmap_terms` figure is the tell: **61,668 is exactly the value the script's own
      header records as the superseded 2026-06-11 baseline**, which the newer local dump
      moved to 55,553.

      `migration-cycle.sh` says this in its header — *"These are DUMP-SPECIFIC; a newer dump
      means new expected values"* — but nothing enforces it, so `validate` would report
      **seven spurious FAILs** on dev-0 and send someone hunting migration defects that do
      not exist.

      Before running: `./scripts/migration-cycle.sh baseline` against dev-0's imported data,
      paste the output over `EXPECT_LIST`, and **commit it as a dev-0-specific baseline** —
      noting the two environments cannot share one until they share a dump.

      The same applies to the two alias keys added 2026-08-25 (`entity:path_alias 111304`,
      `entity:group_path_alias 174`): both were measured against the *local* dump. dev-0
      currently has **0** node aliases (the migration is not deployed there yet) and **171**
      groups against the local 174 — 55 collections either way, but 116 subcollections vs
      119, another symptom of the differing dumps.

- [ ] **⚠ DECIDE FIRST: does the `url_alias` migration land before this run?**
      [ADR 016](../adr/016-public-url-structure-single-host.md) decision 7 makes preserving
      D7's pathauto paths a requirement, and no `url_alias` migration exists yet for any
      site. This cycle is `rollback → import → validate` — it deletes and re-imports every
      Images node — so if that migration is in the `mandala_images` group **before** the
      run, the aliases are produced by the normal import and the run validates them too.
      If it lands **after**, Images needs another full import to pick them up.

      Not a backfill decision: `migrate:rollback` does not reset `AUTO_INCREMENT`, so
      re-imports assign different nids and any aliases written against today's nids would
      be invalidated by the next import anyway.

      If you take the "before" branch, add a `path_alias` count to `EXPECT_LIST` in
      `scripts/migration-cycle.sh` in the same change, or the cycle will reconcile
      everything except the aliases.

- [x] **Migrate source DB — ✅ RESOLVED.** Both halves are done, and the note above is
      stale on this point. *App side:* `settings.php` has defined env-driven `migrate` and
      `migrate_users` connections **outside** the DDEV conditional since 2026-07-16, taking
      only the DB names and falling back to the primary `MYSQL_*` vars for host/user/password.
      *Data side:* `mandala_d7_images` (287,939 nodes) and `mandala_d7_shared` (1,543 users)
      were loaded onto the staging RDS 2026-07-17, row-count verified.
      *Config side:* `MIGRATE_SOURCE_DATABASE` / `MIGRATE_USERS_DATABASE` were being passed
      ad-hoc per `docker exec`; now persisted in `container_0.env.managed`
      (`terraform-infrastructure` `eabf068c6`, 2026-08-25).
      **⚠ That commit does not reach dev-0 until the next app deploy** — the pipeline pulls
      `terraform-infrastructure` at deploy time. Confirm the vars are in the container
      before running, or pass them ad-hoc for this run.
- [x] **Drush execution path — ✅ RESOLVED 2026-07-15.** Plain `docker exec`; the deploy is
      Ansible over SSH onto EC2, not ECS, and `deploy_backend.yml` already does it. No ECS
      `execute-command`, no one-off task, no new IAM.
- [ ] dev-0 deployed at the target commit (merged `main`).
- [ ] `drush cim -y` applied on dev-0 so the content model matches committed config
      (installs `field_iiif_*`, `field_description_title`, the KMaps fields, scheme vocab).
      Since 2026-08-17 the deploy runs a full `updb` + `cim` and fails the build on drift,
      so this should already hold — verify with `drush config:status` rather than assume.
- [x] **D7 Images dump loaded — ✅ done 2026-07-17** (see the migrate-source row above).
- [x] **`base_url` — N/A for this run, and not a no-op to "fix".** The committed
      `shanti_image` URL templates are absolute production URLs with no `__BASE_URL__`
      token, so `base_url` substitutes nothing and setting it changes nothing. The docs this
      run writes will carry known-wrong URLs; that is no worse than the 111,340 already
      indexed, they are namespaced `images-11-*` and cleanable, and **no acceptance
      criterion covers URL correctness**. The real fix is
      [ADR 016](../adr/016-public-url-structure-single-host.md) (Proposed), which is a
      coordinated change with `mandala-om` — deliberately not folded into this run.
- [x] `solr_master_url` confirmed → staging kmassets master and reachable from the
      container. **Correction (2026-08-13):** this was NOT already the config default on
      dev-0/staging — the committed `config/sync` export predated the key and only carried
      `bundles:`, so it stayed unset until PR #113 added it and it was applied via a
      targeted `cim`/`cset` (deploy still doesn't run a full `cim`). See
      [d11-dev-database-bootstrap-and-migration-source.md](../deferred/d11-dev-database-bootstrap-and-migration-source.md)
      item 1.

---

## B. Safety — before importing 111k rows

**Non-destructive & reversible — the guarantee (full detail + evidence in the
[runbook](migration-cycle-runbook.md#non-destructive--reversibility-guarantees)):**

- **Solr:** D11 content indexes under the versioned `images-11-{nid}` namespace;
  since `uid` is the Solr `uniqueKey` and that namespace never overlaps the D7-era
  `images-{nid}` entries (**111,506** live docs as of 2026-07-07), D11 writes
  **cannot overwrite** any existing entry. `kmassets:delete "uid:images-11-*"`
  removes only our docs.
- **DB:** the migration creates **new** entities only (map-tracked) and skips
  already-mapped rows; `migrate:rollback` deletes exactly what it created.
- **⚠ Caveat:** rollback does **not** reset `AUTO_INCREMENT`, so a re-import
  assigns different (higher) nids → `images-11-{nid}` uids differ run-to-run.
  Reversible to *clean*, not to *identical*. Harmless per-run; it's why cutover is
  a full reindex.

- [ ] **Take a `pre-import` checkpoint** — a full import loads 111,340 nodes into dev-0's
      content DB. dev-0 is a development environment, not disposable: it carries the
      migrated users, Group memberships and ADR 015 access config.

      ```bash
      ./scripts/db-checkpoint.sh save pre-import
      ```

      Use this rather than an RDS snapshot. RDS backups are **instance-wide and up to ~24h
      stale**, so recovering one database from one means standing up a replacement instance
      — impractical mid-run (decision 2026-08-25; see
      [pre-deploy-rds-snapshot-gate.md](../deferred/pre-deploy-rds-snapshot-gate.md)).
      ⚠ `CHECKPOINT_DIR` must be on a **persistent bind mount**, or the checkpoint dies with
      the container. ⚠ The script is **untested against a real DB** — exercise `restore` on
      something disposable before relying on it.
- [ ] Confirm the kmassets staging index is the agreed target and that dumping D11 test
      docs into it (namespace `images-11-*`, cleanable) is acceptable this run.

---

## C. Execution — the cycle

Run from a shell with dev-0 drush access. Two overrides are needed — `DRUSH` to target
dev-0 instead of `ddev drush`, and `DRUSH_HEAVY` to survive the import:

```bash
export DRUSH="docker exec mandala-drupal-0 /opt/drupal/app/drupal/vendor/bin/drush"

# The 128M CLI memory_limit has killed a long run TWICE (migrate:import
# 2026-07-17/18 at ~48,900 of 111,340; kmassets:index-all 2026-08-13). A resume
# re-iterates the FULL source count, so an OOM costs the whole run, not the
# remainder. The flag must target drush.php directly -- `drush` is a shell
# script, so `php -d` on the wrapper never reaches the PHP that matters.
export DRUSH_HEAVY="docker exec mandala-drupal-0 php -d memory_limit=1024M \
  /opt/drupal/app/drupal/vendor/bin/drush.php"

./scripts/migration-cycle.sh cycle
```

`migration-cycle.sh` prints a warning if `DRUSH_HEAVY` is unset, so an unset limit is
visible before the hour is lost rather than after.

- [ ] **`cycle`** completes: rollback (clean) → import → validate. Capture the full
      output. (Or run phases individually: `rollback`, `import`, `validate`.)
- [ ] **Counts reconcile** — `validate` prints PASS for all 9 keys and exits 0. Paste the
      table. *(criterion: full migration run + per-type reconciliation)*
- [ ] Note import wall-clock time (staging is the slow path; budget for it).

---

## D. Per-criterion evidence

Each maps to an acceptance checkbox in the sprint doc. Capture concrete evidence (query
output / screenshot / node URL), not just a tick.

- [ ] **NFC diacritic fidelity.** Pick a known Tibetan/transliterated node; verify the
      title + transliteration render with combining diacritics intact (NFC), no mojibake,
      through Drupal *and* in its Solr doc. *(This passed in 1a.7 locally — re-confirm on
      staging's MySQL collation + Solr.)*
- [ ] **KMaps round-trip.** For a few nodes, verify all four KMaps fields
      (`field_subjects`, `field_places`, `field_kmap_terms`, `field_kmap_collections`)
      display correctly, and spot-check that term IDs resolve against the **live KMaps
      API**.
- [ ] **IIIF rendering.** Open a migrated image; confirm it renders via the existing IIIF
      server with `i3fid` linkage intact (URL shape `/mandala/{i3fid}/full/...`).
- [ ] **Retrievability.** Query the staging kmassets index via existing query patterns
      and confirm migrated content returns (retrievability, not search quality).
- [ ] **kmassets sync (audit).** Bulk-index + drift check:
      ```bash
      DRUSH="<staging drush>" ./scripts/migration-cycle.sh audit
      ```
      Confirm `kmassets:audit --check-stale` reports **0 missing / 0 stale / 0 orphaned**
      after the bulk index. Paste the summary.
- [ ] **Security (1b-gated).** Restricted-item non-retrievability — record as **deferred
      to 1b**; do not gate 1a.9 close on it.

---

## E. Post-run cleanup

- [ ] **Rollback to clean:** `./scripts/migration-cycle.sh rollback` — confirm it asserts
      0 `shanti_image` nodes remaining.
- [ ] **Clean kmassets test docs:** `drush kmassets:delete "uid:images-11-*"` (the
      versioned uid cannot touch D7-era `images-{nid}` entries).
- [ ] Restore the DB snapshot if one was taken and the environment is shared.

---

## F. Repeatability (the actual 1a.9 gate)

- [ ] Run `cycle` a **second time** and confirm identical validate results — this is what
      "repeatable" in the acceptance criterion means. A clean second pass is the evidence
      that closes the criterion.

---

## G. Sign-off

| Criterion | Result | Evidence (link/paste) | Who / date |
|---|---|---|---|
| Config installs via CMI | ☐ | | |
| Migration completes + counts reconcile | ☐ | | |
| NFC diacritic fidelity | ☐ | | |
| KMaps round-trip + live term IDs | ☐ | | |
| Indexed + retrievable | ☐ | | |
| IIIF render + i3fid | ☐ | | |
| kmassets audit clean (0/0/0) | ☐ | | |
| Cycle repeatable (2× identical) | ☐ | | |
| Security | 1b-gated | deferred to 1b.3 | — |

**On close:** update the sprint doc progress row, save a session log, and kick off the
[Jira issue-tracking integration](../deferred/jira-issue-tracking-integration.md)
(backfill open deferred notes as tickets).
