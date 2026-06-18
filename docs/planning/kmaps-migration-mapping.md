# KMaps Field Migration Mapping (D7 → D11)

**Status:** Specification for [Sprint 1 task 1a.7](../sprints/sprint-01-images-implementation.md) — the pattern-setting image migration. Not implementation code.
**Relates to:** [Spike 1](../spikes/spike-01-kmaps-field.md) (KMaps field on D11), [Images Content-Model Audit](images-content-model-audit.md), [ADR 008](../adr/008-mvp-migrate-not-improve.md) (faithful migration)

> **Why this doc.** The KMaps field type was ported in Spike 1 with the D7
> schema preserved verbatim (raw / id / header / domain / path / defids). That
> means the row-level mapping is straightforward, but the migration still has
> three non-trivial decisions (path freshness, header freshness, missing-term
> handling) that should be settled before 1a.7 is written, not improvised
> mid-implementation.

---

## In-scope fields

Six KMaps field instances move from D7 to D11:

| D7 field | D11 entity / bundle | kmap_domain | search_root_kmapid |
|---|---|---|---|
| `field_subjects` | `node:shanti_image` | subjects | — |
| `field_places` | `node:shanti_image` | places | — |
| `field_kmap_terms` | `node:shanti_image` | terms | — |
| `field_kmap_collections` | `node:shanti_image` | **subjects** | 2823 (Collections) |
| `field_agent_place` | `paragraph:image_agent` | places | — |
| `field_language` | `paragraph:image_descriptions` | **subjects** | 301 (Language Tree) |

The two bolded `subjects` entries are corrections to the prior D11 baseline
(both were `terms`); the live `kmterms` Solr index confirms 2823 = "Collections"
in the subjects tree and 301 = "Language Tree" in the subjects tree.

## Schema invariant

D11 stores the same six columns as D7:

| Column | Type | Notes |
|---|---|---|
| `raw` | text, not null | `id\|header\|domain\|path\|defids` |
| `id` | int, not null, default 0 | KMaps term ID (integer suffix of the Solr doc id) |
| `header` | varchar(255), not null | Term's display name at the time it was saved |
| `domain` | varchar(20), not null | One of: `subjects`, `places`, `terms` |
| `path` | text, not null | Slash-delimited ancestor IDs (e.g. `6403/272/282/2610`) |
| `defids` | text, nullable | Pipe-delimited definition UIDs |

Migration is therefore a **column-for-column copy** for any field that is
present in the D7 source — no transformation required at the data layer.

## Source: D7 storage

D7 `shanti_kmaps_fields` stores values in standard Field API tables. For a
field named `field_NAME` on the `node` entity type:

| Table | Purpose |
|---|---|
| `field_data_field_NAME` | Current values per entity |
| `field_revision_field_NAME` | Per-revision history (migration uses `field_data_*`) |

Each row has six columns matching the D11 schema — `field_NAME_value`,
`field_NAME_id`, `field_NAME_header`, `field_NAME_domain`, `field_NAME_path`,
`field_NAME_defids` — plus `delta`, `entity_id`, `revision_id`, `language`,
`bundle`.

The standard Migrate Drupal source plugin (`d7_field`) yields these rows
shaped as one record per `(entity_id, delta)` with the six column values
already destructured under their original keys.

## Process plugin chain (per field instance)

A migrate process for any KMaps field is essentially a six-key copy:

```yaml
process:
  field_NAME:
    plugin: sub_process
    source: field_NAME       # supplied by d7_field source for this instance
    process:
      raw:    value          # D7 stored 'value' which IS the pipe-delimited raw
      id:     id
      header: header
      domain: domain
      path:   path
      defids: defids
```

Notes:

- D7's `*_value` column maps to D11's `raw` (both contain the pipe-delimited
  composite). The other five columns map 1:1 by name.
- Cardinality (`-1` / unlimited on every KMaps field) is handled by
  `sub_process` iterating the delta list automatically.
- Translations: D11 baseline marks these fields `translatable: true`; D7
  Images had no translations, so all rows migrate into the default langcode.

## Three policy decisions

### 1. Path freshness — **preserve D7-stored paths verbatim** (recommended)

D7 resolved the ancestor path at save time against the live KMaps API and
cached it in the row. KMaps taxonomy paths rarely change post-publication.

Options considered:

| Option | Behaviour | Trade-off |
|---|---|---|
| **A. Preserve (recommended)** | Copy `path` as-is from D7 | Fast; faithful to ADR 008; staleness risk minimal |
| B. Re-resolve at migration | Call `KmapsPathResolver` for every row | Slower (`~111k images × N KMaps refs`); fresher data |
| C. Hybrid | Preserve at migration; queue a refresh job afterward | Defers cost; can run on cadence |

A is the MVP choice. If path drift later becomes a problem, the
`KmapsPathResolver` service supports an offline refresh pass (already built;
permanent cache backs it).

### 2. Header (display name) freshness — **preserve D7-stored headers** (recommended)

Same reasoning as path. Headers are user-facing strings; preserving them keeps
displayed values stable through the migration and avoids surprising editorial
changes. The cached header will refresh naturally on the next node re-save.

### 3. Orphaned-term handling — **migrate as-is, don't validate against live KMaps**

If a D7 row references a KMaps term that no longer exists in the live
taxonomy, the D7 row still has cached `header` / `path` and renders fine. The
migration should preserve the row; validating against the live KMaps API at
migration time would (a) couple migration speed to network availability and
(b) silently drop legitimate historical references.

The post-migration audit can produce a report of orphan refs as a separate
data-quality pass (out of scope for 1a.7).

## What the migration does NOT need to do

- **Re-resolve paths via the new `KmapsPathResolver` service.** That service
  exists for code paths that have an ID but no path (programmatic saves, future
  re-resolve passes). The migration source already has the path.
- **Validate the search-root constraint.** `search_root_kmapid` is D11 field
  config, not row data. D7 data that pre-dates the constraint (e.g. a
  `field_kmap_collections` reference outside the 2823 subtree) still migrates;
  it just won't be reachable via the picker on subsequent edits. The audit can
  flag such rows as data quality issues.
- **Recompute `raw`.** D7's `*_value` is already the pipe-delimited composite
  the D11 field expects.

## Validation gates (in 1a.7)

Pre-migration counts to capture from the D7 dump for reconciliation:

```sql
-- Per-field row counts across all images
SELECT 'field_subjects'         AS field, COUNT(*) FROM field_data_field_subjects
UNION ALL SELECT 'field_places',           COUNT(*) FROM field_data_field_places
UNION ALL SELECT 'field_kmap_terms',       COUNT(*) FROM field_data_field_kmap_terms
UNION ALL SELECT 'field_kmap_collections', COUNT(*) FROM field_data_field_kmap_collections
UNION ALL SELECT 'field_agent_place',      COUNT(*) FROM field_data_field_agent_place
UNION ALL SELECT 'field_language',         COUNT(*) FROM field_data_field_language;
```

Post-migration acceptance:

- Row counts match per field, modulo the orphan-satellite skip the audit already
  prescribes for `image_agent` / `image_descriptions` (sourced via the parent
  image's reference field, not the raw bundle table).
- Domain distribution per field matches the D7 mix (e.g. `field_kmap_collections`
  should be ~100% `subjects` post-migration — if any `terms` rows appear,
  they're D7-source data quality issues to flag).
- Spot-check the Spike 1 fixtures survive: a node that had subjects-2610
  ("Buddhism") in D7 retains `id=2610`, `domain=subjects`,
  `path=6403/272/282/2610`, `header=Buddhism`.

## Out of scope

- The shadow `kmasset` write path (the D7 pattern that wrote enriched KMaps
  docs to the `kmassets` Solr index on node save) — that's
  [task 1a.8 / Spike 8](../spikes/spike-08-reindeer-x-consolidation.md), and
  is implemented separately via `reindeer_x`, not in this migration.
- Search-quality / Tibetan tokenization concerns — see
  [tibetan-search-quality.md](../deferred/tibetan-search-quality.md), out of
  MVP per [ADR 008](../adr/008-mvp-migrate-not-improve.md).
