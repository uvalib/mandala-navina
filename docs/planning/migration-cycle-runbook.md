# Migration Cycle Runbook (Images pilot)

**Task:** Sprint 1, 1a.9 — the "rollback story"
**Script:** [`scripts/migration-cycle.sh`](https://github.com/uvalib/mandala-navina/blob/main/scripts/migration-cycle.sh)
**Related:** [1a.7 migration](../sprints/sprint-01-images-implementation.md), [Images content model audit](images-content-model-audit.md), [kmassets sync](../deferred/kmassets-uid-identity-across-migration.md)

---

## Why this exists

The Sprint 1 acceptance gate requires that, against a copy of the production
Images DB in staging, **the test-run → validate → rollback cycle is documented
and repeatable**. This runbook + `scripts/migration-cycle.sh` are that cycle.

"Repeatable" is the point: we do not trust a single migration pass. We run the
cycle, reconcile counts against a known baseline, roll back to clean, and can
run it again identically — as many times as it takes to trust the migration
before cutover.

---

## Prerequisites

1. **DDEV up** and the D11 content model synced to committed config:
   ```bash
   ddev drush cim -y
   ```
   This installs the fields the migration targets (`field_iiif_*`,
   `field_description_title`, the KMaps fields, etc.). A mismatch here is the
   most common cause of a failed run.

2. **Source dump loaded** into the secondary `d7_images` DB the Migrate API
   reads from:
   ```bash
   ./scripts/load-d7-source.sh <path-to-dump.sql.gz>
   ```
   The dump is gitignored (~70MB of production data) — obtain it out-of-band.

3. **For the `audit` phase only:** network line-of-sight to the Solr master
   (UVA VPN or in-VPC), because `solr_master_url` points at the staging master.

---

## The phases

```bash
./scripts/migration-cycle.sh validate    # read-only: reconcile counts vs baseline
./scripts/migration-cycle.sh import       # migrate:import the mandala_images group
./scripts/migration-cycle.sh rollback     # migrate:rollback, then assert the graph is clean
./scripts/migration-cycle.sh audit        # bulk-index to kmassets + kmassets:audit (needs VPN)
./scripts/migration-cycle.sh cycle        # rollback → import → validate  (default)
```

- **`cycle`** is the repeatable test run: it rolls back first (so it is safe to
  re-run), imports, then validates. It leaves the data imported so you can run
  follow-on checks (IIIF rendering, KMaps round-trip, `audit`). To close the
  loop back to clean, run `rollback` afterwards.
- **`validate`** exits non-zero if any count fails to reconcile, so it doubles
  as a CI/gate check.

### Running outside DDEV (staging/CI, or a box without `mkcert`)

The script calls `ddev drush` by default. Override with the `DRUSH` env var:

```bash
DRUSH="drush" ./scripts/migration-cycle.sh cycle          # staging, drush on PATH
```

The script is written for the stock macOS `/bin/bash` 3.2 (no associative
arrays, no `lastpipe`), so it runs on teammates' laptops unchanged.

---

## Validation baseline

`validate` reconciles these counts against the **2026-06-11 production dump**
(source of truth: [Images content model audit](images-content-model-audit.md)
data profile + the 1a.7 full-run reconciliation):

| Key | Expected |
|---|---|
| `node:shanti_image` | 111,340 |
| `paragraph:image_agent` | 111,194 |
| `paragraph:image_descriptions` | 55,038 |
| `paragraph:external_classification` | 9 |
| `term:external_classification_scheme` | 2 |
| `field:field_subjects` | 79,337 |
| `field:field_places` | 68,755 |
| `field:field_kmap_terms` | 61,668 |
| `field:field_kmap_collections` | 83,494 |

These are **dump-specific**. A newer dump means new expected values — update the
`EXPECT_LIST` in the script and this table together.

> Counts are necessary but not sufficient. The full acceptance criteria also
> require NFC diacritic fidelity, KMaps round-trip, IIIF rendering, and the
> security check — see the sprint doc. Those are verified separately; several
> already passed in 1a.5/1a.7.

---

## kmassets audit inside the cycle

Once imported, `audit` bulk-indexes every `shanti_image` to the kmassets Solr
master and then runs [`kmassets:audit --check-stale`](../deferred/kmassets-uid-identity-across-migration.md)
to prove the index reconciles with Drupal (missing / stale / orphaned = 0 on a
clean run). Cleanup afterwards:

```bash
ddev drush kmassets:delete "uid:images-11-*"
```

Because D11 docs use the versioned `images-11-{nid}` uid, this delete cannot
touch the D7-era `images-{nid}` entries in the shared staging index.

---

## Rollback verification

`migrate:rollback` alone is trusted less than a post-check. The `rollback` phase
runs the drush rollback, then asserts zero `shanti_image` nodes remain (derived
paragraph rows can otherwise leave stragglers). If a prior run was interrupted
and a migration is locked as `Importing`, the phase resets it first. Manual
equivalent:

```bash
ddev drush migrate:reset-status d7_images_shanti_image
ddev drush migrate:rollback --group=mandala_images
```

---

## Known caveats

- **Full `--fix`/`audit` writes ~111k docs** to the shared staging Solr index.
  That is the deliberate bulk-index, not a smoke test — run it intentionally.
- **`validate` loads no entities** (pure count queries), so it is fast. A full
  `import` of 111k is the slow phase; budget accordingly on staging.
