# 1a.9 Staging Acceptance Checklist

**Task:** Sprint 1, 1a.9 — execute the migrate → validate → rollback cycle against a
copy of the production Images DB **in staging**, and evidence the acceptance criteria.
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

- [ ] **⚠ Migrate source DB in staging.** The `migrate` DB connection is wired *only*
      inside the DDEV block of `settings.php` (`$databases['migrate']['default']` →
      local `d7_images`). Staging has **no** migrate-source provision. Decide + implement:
      where the D7 dump is loaded (secondary schema on the staging DB server vs. a
      throwaway RDS) and add the `migrate` connection to the staging settings (outside
      the DDEV conditional, env-driven). **Owner: Xiaoming + Yuji/Dave.** Related:
      [prod packaging pass](../deferred/images-prod-packaging-monorepo-pass.md).
- [ ] **⚠ Drush execution path in staging.** The CodePipeline (`pipeline/buildspec.yml`,
      `deployspec.yml`) runs **no** migrate/drush steps — migrations are manual. Confirm
      how drush is run against the staging container (ECS `execute-command` / one-off
      task / Ansible), and that whoever runs it has that access. **Owner: DevOps/Yuji.**

Then the ordinary prerequisites:

- [ ] Staging D11 deployed at the target commit (PR #19 branch or merged `main`).
- [ ] `drush cim -y` applied on staging so the content model matches committed config
      (installs `field_iiif_*`, `field_description_title`, the KMaps fields, scheme vocab).
- [ ] D7 Images dump obtained out-of-band and loaded into the staging migrate source
      (analogue of `scripts/load-d7-source.sh`; the dump is gitignored ~70MB).
- [ ] `base_url` set to the staging site URL (per-env `$config[...]['base_url']`).
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

- [ ] Confirm the target D11 site is a **dedicated / disposable migration-test
      environment**, OR take a DB snapshot first — a full import loads 111,340 nodes into
      the staging content DB.
- [ ] Confirm the kmassets staging index is the agreed target and that dumping D11 test
      docs into it (namespace `images-11-*`, cleanable) is acceptable this run.

---

## C. Execution — the cycle

Run from a shell with staging drush access. Use the `DRUSH` override so the script
targets staging drush rather than `ddev drush`:

```bash
DRUSH="<staging drush invocation>" ./scripts/migration-cycle.sh cycle
```

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
