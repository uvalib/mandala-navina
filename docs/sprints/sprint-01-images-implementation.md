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
| 1b.2 | Migrate OG → Group: `group_content_access` (Visibility) + `field_og_collection_ref` → Group membership | [Spike 3](../spikes/spike-03-group-collections.md) | mob |
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
