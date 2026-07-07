# Sprint 1: Mandala Images implementation (pilot)

**Status:** ◐ In progress
**Phase:** [Roadmap](../roadmap.md) Phase 1 — mob-build the Images pilot (the spine)
**Lead:** Yuji Shinozaki
**Mode:** Mob-build (whole team), then individuals replicate the pattern
**Relates to:** [ADR 008](../adr/008-mvp-migrate-not-improve.md) (migrate, not improve),
[ADR 009](../adr/009-migration-sequencing-strategy.md) (Images is the pilot),
[ADR 010](../adr/010-adr-008-scope-clarification.md) (remodeling permitted),
[Images Content-Model Audit](../planning/images-content-model-audit.md)

---

## Goal

Build and migrate **Mandala Images** end-to-end on Drupal 11 as the first vertical
slice of the 5→1 consolidation — proving the shared migration pattern (Migrate API
consolidation, KMaps field productionization, Solr sync via reindeer_x, proxy auth, and
a rollback story) **once, together**, before the codebase forks into per-site tracks.

A green Images pilot is the early demonstrable win. It deliberately isolates the hard
*content* risks (footnotes, Kaltura, bibcite, Tibetan script) — those live in Texts and
AV and are retired in their own Phase 2/3 tracks — but it **does** include the
proxy-auth access-control foundation, because Images has substantial proxy-auth-gated
content.

## Scope boundary

Inherited from [ADR 008](../adr/008-mvp-migrate-not-improve.md) /
[ADR 010](../adr/010-adr-008-scope-clarification.md): faithful migration of *user-facing*
behavior is the floor; internal data remodeling is permitted where it reduces risk.

| In scope (Sprint 1) | Out of scope (later phases) |
|---|---|
| Full `shanti_image` entity graph (primary + 3 satellites + scheme lookup) | Texts footnotes, AV/Kaltura, Sources/bibcite |
| 4 KMaps fields wired to kmassets (Spike 1 productionized) | Improving Tibetan/Solr **search quality** ([deferred](../deferred/tibetan-search-quality.md)) |
| Transliteration diacritic fidelity (NFC/NFD round-trip) | New IIIF stack or `shanti-image-NNN` scheme change (IIIF stays as-is) |
| Content indexes and is **retrievable via existing query patterns** | JSON/AJAX API parity (`api/json/{nid}`, `api/ajax/{nid}`) — Phase 5 |
| Proxy-auth access path + Solr-proxy visibility filtering | Auth **redesign** (wire into the existing contract, per ADR 004) |
| OG → Group collection membership for Images | Sub-subcollection nesting beyond one level ([deferred](../deferred/group-subgroup-nesting-approach.md)) |

> "Docs land in Solr" (✓) is **not** "Tibetan search works well" (deferred). The Solr
> success criterion is written narrowly — retrievable via existing query patterns, not
> search quality.

## Two-step structure (per ADR 009)

- **Step 1a — public plumbing.** Migrate the Images public subset; prove consolidation
  + KMaps + Solr sync + retrieval end-to-end. The win decoupled from auth risk.
- **Step 1b — auth increment.** Wire D11 into the *existing* proxy-auth contract and
  prove the security path. Access-control coherence (Solr-proxy visibility vs.
  node/Group access) is an explicit integration concern — search-visible results and
  node-level access must agree on "who can see what."

## Backlog

The Images content model is settled by the
[audit](../planning/images-content-model-audit.md): the D7 "five content types" become a
`shanti_image` content type with the three satellites as **Paragraph** types and
`external_classification_scheme` as a **taxonomy vocabulary**. Production-data validation
(111,340 images) confirms required fields are 0-missing / 0-out-of-list and agents are
99.8% per-image. Tasks are in dependency order.

### Progress

| Date | Landed |
|---|---|
| 2026-06-17 | **1a.1–1a.3 ✅** — `shanti_image` (48 fields), the three paragraph types, and the `external_classification_scheme` vocabulary built as committed CMI config (382-file baseline, zero-diff round-trip). Prereqs cleared: **core bumped D10.6 → D11** ([ADR 002](../adr/002-drupal-11.md)) + paragraphs/ERR added; `settings.php` committed env-driven with `config_sync_directory` ([drupal-dsf convention](../deferred/images-prod-packaging-monorepo-pass.md)); Dockerfile → D11. New deferred notes: [agent-name mapping](../deferred/images-agent-name-paragraph-title-mapping.md), [prod packaging](../deferred/images-prod-packaging-monorepo-pass.md). KMaps search-roots punted to 1a.4. |
| 2026-06-18 | **1a.4 ✅ (on branch `sprint-01/kmaps-search-root`)** — KMaps field productionized as the Spike 1 tail: new `search_root_kmapid` field setting + config schema; widget + autocomplete controller plumb the root through as an `ancestor_id_path` `fq`; field_kmap_collections wired to root 2823, field_language to root 301. New `KmapsPathResolver` service (Drupal cache.default, batched, Solr-fallback-safe) for non-widget code paths (migrations, programmatic save). [KMaps D7→D11 migration mapping doc](../planning/kmaps-migration-mapping.md) pins three policy decisions (preserve paths/headers verbatim; don't validate orphans at migration time) — feeds 1a.7. **Live-Solr finding:** the prior build's `kmap_domain: terms` on both fields was wrong; Solr probes proved both roots live in `subjects`, fix applied. Verified end-to-end against live `kmterms` index. "Wire to kmassets" remains the 1a.8 / reindeer_x handoff. |
| 2026-06-22 | **1a.6 ✅** — First migration shipped. Establishes the Migrate API plumbing for everything that follows. New `mandala_migrations` module hosts a `mandala_images` migration group and the `d7_images_external_classification_scheme` migration (D7 `external_classification_scheme` nodes → D11 taxonomy terms in the 1a.3 vocabulary). Composer adds `drupal/migrate_plus ^6.0` + `drupal/migrate_tools ^6.1`; enabled core `migrate` + `migrate_drupal` + plus/tools. **Source DB**: D7 prod images dump (`data/mandala-prod-images-db_2026-06-11.sql.gz`, 70MB) loaded into secondary DDEV database `d7_images`; Drupal `migrate` connection key wired in `settings.php` inside the DDEV-conditional include block, portable across teammates. **Migration result**: 2 source rows (LCSH + TGN — the only two schemes in the production dump) → 2 D11 terms (tid=1, tid=2), all 5 custom fields + name + description (with format preserved) populated; entity-reference from the `external_classification` paragraph's `field_external_class_scheme` finds both terms, so 1a.7 image migrations will be able to reference them. **Surprise:** production has only 2 schemes, not the 5–10 we'd assumed. New deferred note: [migrate_drupal noise on `drush ms`](../deferred/migrate-drupal-noise-site-specific-dump.md) (Low — workaround is `--group=mandala_images`). |
| 2026-06-23 | **1a.7 ✅** — The pattern-setting migration shipped: D7 `shanti_image` nodes → D11 nodes with the satellite graph transformed to **owned paragraphs**, run end-to-end against the full production dump. Two custom source plugins: `D7ImageSatellite` (sources satellites *per reference* so shared agents fan out one-paragraph-per-image and orphan satellites are skipped; `mapJoinable()` disabled for computed ids) and `D7ShantiImage` (joins the `shanti_images` sidecar for `i3fid`/dims and emits `[{nid,delta}]` lookup keys per satellite). Four migrations (`d7_images_image_agent` / `_image_descriptions` / `_external_classification` / `_shanti_image`) in `mandala_migrations`; node refs wire to fanned-out paragraphs via `sub_process` + `migration_lookup` (`@_para/0`,`@_para/1`) with a `skip_on_empty` guard for dangling refs. **Full-run reconciliation (0 failures):** 111,340/111,340 images; 111,194 agent paragraphs (= 111,345 refs − 151 dangling); 55,038 description paragraphs (3 oversized summaries — see below); 9 classifications; node-level KMaps **exact** to baseline (subjects 79,337 · places 68,755 · terms 61,668 · collections 83,494); description `field_language` 7,303 = exactly the referenced-description count (orphans correctly skipped). Tibetan title + transliteration diacritics verified **NFC-preserved**. Four data-model decisions landed as committed config: added `field_description_title` (89% of referenced descriptions carry a meaningful caption) + `field_external_class_label` carrier fields; empty agent names → `"Unknown"`; `field_iiif_id` (36 imageless records) and `field_image_agents` (151 dangling-only images) relaxed to optional. **Found + fixed:** the D7 KMaps `raw` column is angle-bracket ancestors, not the pipe composite the D11 field needs — copying it silently dropped all KMaps; migration now rebuilds `raw` via `concat` ([deferred note](../deferred/kmaps-raw-format-rebuild-on-migration.md)). **Model fix:** `field_summary` was `string(255)` but D7 summaries reach 750 chars → widened to 1024 (applies on fresh install; the 3 affected rows migrate cleanly in the staging run). File binaries deferred (99.5% display via IIIF; [note](../deferred/images-field-image-binary-migration.md)). The full prod run remains a **staging** activity per the acceptance criteria (1a.9). |
| 2026-06-22 | **1a.5 ✅** — IIIF wiring proven and shipped as new `shanti_iiif` module (`IiifUrlBuilder` service + `IiifImageFormatter` field formatter + admin settings at `/admin/config/media/shanti-iiif`). Four storage fields added to `shanti_image` for the `i3fid` linkage: `field_iiif_id` (required), `field_iiif_mms_id`, `field_iiif_width`, `field_iiif_height`. `field_image` view display swapped from stock `image` formatter to `iiif_image`. **Reachability gate passed** — DDEV web → `iiif.lib.virginia.edu/mandala/{i3fid}/info.json` returns HTTP 200 in ~150ms warm. **URL contract verified byte-identical to D7** for any siid (`/mandala/{i3fid}/full/!W,H/0/default.jpg`). End-to-end smoke test: a `shanti_image` node with `field_iiif_id = shanti-image-680687` renders the same IIIF derivative URL the D7 site uses. Upload/delete path explicitly out of scope (covered by 1a.7 migration which carries `i3fid` forward verbatim, and by post-MVP user-upload). Two deferred notes filed: [Cantaloupe 404 info disclosure](../deferred/iiif-cantaloupe-404-information-disclosure.md) (Medium); [`/mandala/` vs canonical `/iiif/2/` prefix alignment](../deferred/iiif-prefix-alignment-mandala-vs-canonical.md) (Low). |

| 2026-07-01 | **1a.8 ✅** — `mandala_kmassets_sync` module: kmassets doc-builder (fixture-validated, 114-match, 0 defects) + direct-to-master Solr sink (`KmassetDirectSink` — synchronous POST, IP-gated, no creds) + node lifecycle hooks (`hook_node_insert/update/delete`, errors logged/never crash) + Drush commands (`kmassets:index`, `kmassets:index-all`, `kmassets:delete`). uid format: `{service}-11-{nid}` (frozen generation marker — not the Drupal version, survives D12/D13 upgrades). `__BASE_URL__` token in URL templates with per-environment `$config[]` override in `settings.php` (DDEV → `https://mandala.ddev.site`). Direct writes to Solr master confirmed always acceptable (IP-gated from VPC, no HTTP credentials needed). File/S3 sink explicitly deferred — direct sink covers Sprint 1 scope. Deferred notes filed: [kmassets uid identity across migration](../deferred/kmassets-uid-identity-across-migration.md), [kmassets uid consumer analysis](../deferred/kmassets-uid-consumer-analysis.md), [kmassets sync error-management](../deferred/kmassets-sync-error-management.md). **First task of 1a.9:** build `kmassets:audit` command (missing/orphaned/stale detection + `--fix` flag) before anything else. |
| 2026-07-07 | **1a.9 ◐ started** ([PR #19](https://github.com/uvalib/mandala-navina/pull/19), branch `feat/1a9-rollback-story`) — first task done: **`kmassets:audit`** drift detector, the validation tool the rollback cycle needs. New `KmassetAuditor` service + `kmassets:audit [bundle] [--check-stale] [--fix] [--batch-size]`. Detects three drift classes: **missing** (published node, no doc), **stale** (doc `node_changed` lags node — opt-in `--check-stale`, the expensive pass), **orphaned** (doc for a deleted/unpublished node). Two passes: Drupal→Solr batched `uid:(…)` lookups (missing/stale) + Solr→Drupal `cursorMark` sweep (orphaned); `--fix` reuses the 1a.8 index/delete primitives. `KmassetDirectSink` gained a read path (`select()`). **Verified end-to-end against the staging Solr master** (111,339 published images, rigged-then-cleaned scenario): detection reconciled exactly (missing 111,336 + present 3), stale/orphan repair primitives corrected `node_changed` and removed a ghost doc. **Found + fixed:** cursorMark must sort on `uid` — the schema API confirms `uid` (not `id`) is the kmassets `uniqueKey`; Solr 400s otherwise. Follow-ups flagged in PR: confirmation guard for large `--fix` runs; Pass A speed-up (loads full node entities for `id`+`changed`, ~1.5 min/111k). **Remaining 1a.9:** the repeatable migrate → audit → rollback cycle against a prod-DB copy in staging (closes Step 1a). |

> **Next-session blocker:** ~~Group collections inheritance decision~~ — **resolved 2026-06-18** by [ADR 011](../adr/011-group-collections-inheritance.md) (Option D: entity-reference + custom hooks). Inheritance hooks fold into task **1b.2** below; no collections work lands in Step 1a.

### Step 1a — public plumbing

| # | Task | Depends on | Owner |
|---|------|-----------|-------|
| 1a.1 | Build `shanti_image` content type: 50-field inventory, types, cardinalities, required flags (per audit) | audit | mob |
| 1a.2 | Build agents / descriptions / classifications as **Paragraph** types embedded on `shanti_image` (owned, per-image, cascade-deleted) | 1a.1 | mob |
| 1a.3 | Build `external_classification_scheme` as a **taxonomy vocabulary** referenced from the classification paragraph | 1a.2 | mob |
| 1a.4 | Productionize the KMaps field (Spike 1 tail): write path, ancestor-path resolution, migration mapping; wire `field_subjects` / `field_places` / `field_kmap_terms` / `field_kmap_collections` to kmassets | [Spike 1](../spikes/spike-01-kmaps-field.md) | mob |
| 1a.5 | Wire image display to the **existing** IIIF server: confirm endpoints/credentials reachable from D11; port the upload/display path (`shanti_images_*_url`, `shanti_image_formatter`); preserve `i3fid` / `mmsid` / `field_other_ids` linkage | 1a.1 | mob |
| 1a.6 | Migrate scheme nodes → taxonomy terms (no deps, migrate first) | 1a.3 | mob |
| 1a.7 | Migrate `shanti_image` with node→paragraph transform for satellites; **source satellites via the image reference field, not the raw node table** (skips ~12k orphan agents, ~17k orphan descriptions); expect shared-agent fan-out (~111k agent paragraphs) | 1a.6 | **mob (the pattern-setting migration)** |
| 1a.8 | Solr write/sync via reindeer_x (Spike 8 parts A/B), informed by the Phase 0 cost/architecture conversation | [Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) | mob |
| 1a.9 | Rollback story: repeatable test-run → validate → rollback cycle against a prod-DB copy in staging | 1a.7 | mob |

After 1a.7 establishes the pattern + test + rollback story, individuals replicate it for
the remaining field/paragraph types.

### Step 1b — auth increment

| # | Task | Depends on | Owner |
|---|------|-----------|-------|
| 1b.1 | Wire D11 into the existing proxy-auth contract (do **not** redesign auth) | [Spike 2](../spikes/spike-02-solr-integration.md) deferred (blocking) | mob |
| 1b.2 | Build Group `collection`/`subcollection` bundles + `group_node:shanti_image` relation as CMI config; implement custom inheritance module ([ADR 011](../adr/011-group-collections-inheritance.md) Option D — visibility + membership hooks, `visibility_overridden` flag, sub-only retention); migrate OG → Group: `group_content_access` (Visibility) + `field_og_collection_ref` → Group membership | [Spike 3](../spikes/spike-03-group-collections.md), [ADR 011](../adr/011-group-collections-inheritance.md) | mob |
| 1b.3 | Solr-proxy visibility filtering; prove access-control coherence (search results agree with node/Group access) | 1b.1, 1b.2 | mob |
| 1b.4 | Confirm paragraph access inheritance: a private image's satellite paragraphs are not independently retrievable | 1b.3 | mob |

## Acceptance criteria

Sprint 1 closes when, against a copy of the production Images DB in staging:

- [ ] `shanti_image` + the three paragraph types + the scheme vocabulary install via CMI config.
- [ ] A full migration run completes; per-type counts reconcile against the
      [data profile](../planning/images-content-model-audit.md#data-profile-production-dump-2026-06-11)
      (111,340 images; orphan satellites excluded; shared-agent fan-out as expected).
- [ ] **Transliteration diacritic normalization is preserved** (NFC/NFD fidelity) through
      Migrate API → MySQL collation → Solr — verified, not assumed.
- [ ] The 4 KMaps fields round-trip (save → reload → correct display) and term IDs match the live KMaps API.
- [ ] Content indexes and is **retrievable via existing query patterns** (not search quality).
- [ ] Images render through the existing IIIF server with `i3fid` linkage intact.
- [ ] **Security:** a restricted Images item is non-retrievable by an unauthorized user
      via the D11 search path, and retrievable by an authorized one.
- [ ] The test-run → validate → rollback cycle is documented and repeatable.

**On close:** kick off the [Jira issue-tracking integration](../deferred/jira-issue-tracking-integration.md)
(sequenced to start after this sprint; backfill open deferred notes as tickets).

## References

- **Decisions:** [ADR 008](../adr/008-mvp-migrate-not-improve.md),
  [ADR 009](../adr/009-migration-sequencing-strategy.md),
  [ADR 010](../adr/010-adr-008-scope-clarification.md),
  [ADR 004](../adr/004-solr-source-of-truth.md) (IIIF/Solr stay as-is)
- **Audit / plan:** [Images Content-Model Audit](../planning/images-content-model-audit.md),
  [Critical Path](../planning/critical-path.md), [Roadmap](../roadmap.md)
- **Spikes:** [1 (KMaps)](../spikes/spike-01-kmaps-field.md),
  [2 (Solr / proxy auth)](../spikes/spike-02-solr-integration.md),
  [3 (Group collections)](../spikes/spike-03-group-collections.md),
  [8 (reindeer_x)](../spikes/spike-08-reindeer-x-consolidation.md)
- **Deferred:** [KMaps widget UX](../deferred/kmaps-widget-ux.md),
  [Group access inheritance](../deferred/group-access-inheritance-subcollections.md),
  [Solr sync architecture](../deferred/solr-sync-architecture-d11.md),
  [Tibetan search quality](../deferred/tibetan-search-quality.md) (out of scope)
