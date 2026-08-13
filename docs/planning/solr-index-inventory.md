# Solr Index Inventory (dev / staging / production)

**Status:** Measured fact, 2026-08-13. Every number below was taken from the live clusters
and hosts, not read from configuration. Re-measure before relying on the figures — one core
is deliberately about to change (see §6).
**Method:** logged into `mandala-drupal-dev-0` (D11), `mandala-drupal-dev-1` (D7 staging)
and `mandala-drupal-0` (D7 production), and queried both Solr clusters directly.
**Relates to:** [KMasset Solr Doc Contract](kmasset-solr-doc-contract.md),
[ADR 004](../adr/004-solr-source-of-truth.md),
[ADR 014](../adr/014-hybrid-solr-proxy-design.md),
[Spike 2](../spikes/spike-02-solr-integration.md).

> **Scope note.** One aspect of the legacy D7 Solr *routing* is tracked privately pending
> remediation and is deliberately absent here — see §7. Everything else is below.

---

## 1. Topology

**Two independent clusters.** Solr **7.7.3**, classic master–replica replication (not
SolrCloud). They share nothing; production is untouched by staging work and vice versa.

| Cluster | Master | Replica |
|---|---|---|
| staging | `mandala-solr-master-staging-private` (10.130.112.97) | `mandala-solr-replica-staging.private.staging` (10.130.112.100) |
| production | `mandala-solr-master-production-private` | `mandala-solr-replica-production.private.production` |

Both listen on **:8080**. Writes go to the master, reads to the replica; replication was
in sync at the time of measurement (kmassets generation 15217 on both staging nodes).

**Correction to an earlier note:** the master **is readable** over HTTP — `admin/cores`,
`select` and `replication` all answer. Documentation previously described it as
write-only.

## 2. Cores — seven per cluster

| Core | Staging: docs · last write | Production: docs · last write |
|---|---|---|
| **kmassets** | 572,150 · 2026-07-07 | 557,483 · **2025-08-11** |
| **kmterms** | 4,495,231 · 2026-06-24 | 4,488,953 · **2026-08-11** |
| mandala-av | 255,854 · 2025-02-14 | 256,975 · 2026-07-21 |
| mandala-sources | 53,386 · 2025-02-13 | 21,976 · 2026-02-18 |
| mandala-images | 112,296 · 2023-02-01 | **0 docs** |
| mandala-texts | 14,692 · 2023-08-23 | **0 docs** |
| mandala-visuals | 1 · 2022-05-31 | **0 docs** |

"Last write" is the core's `lastModified`. Three production cores are **empty**; several
staging cores are years stale and are effectively fossils of an old clone.

**`kmassets` and `kmterms` are the two that matter.** The `mandala-*` cores are per-site
legacy indexes, largely superseded.

### kmassets composition (production)

| `asset_type` | Docs |
|---|---|
| terms | 325,341 |
| images | 111,326 |
| places | 65,212 |
| sources | 28,158 |
| audio-video | 11,537 |
| subjects | 8,746 |
| texts | 5,381 |
| visuals | 946 |
| collections | 576 |
| mandala | 249 |
| projects | 3 |

The KMaps types (`terms`/`places`/`subjects`, ~399k of 557k) are the flattened shadow
documents described in [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md), not
site content.

## 3. Freshness — production kmassets is frozen

Newest document per type, by the `timestamp` field:

| `asset_type` | Production | Staging |
|---|---|---|
| images | 2025-05-22 | 2025-04-10 |
| audio-video | 2025-08-04 | 2025-04-10 |
| sources | 2025-07-21 | 2025-04-10 |
| texts | 2025-08-11 | 2025-04-15 |
| terms | **2024-05-24** | 2024-05-16 |
| subjects | **2024-05-20** | 2024-01-10 |
| places | **2024-05-20** | 2024-01-10 |

Two independent signals agree that **nothing has written to production kmassets since
2025-08-11**, and that the KMaps shadows have been idle since **2024-05**. `kmterms` by
contrast is alive (written 2026-08-11) — it is owned by the Rails KMaps applications, which
are still writing normally. Tracked in
[kmassets-production-index-frozen](../deferred/kmassets-production-index-frozen.md), where
it is recorded as an observation rather than a diagnosis.

## 4. Read path

| Environment | Consumer | Reads via |
|---|---|---|
| production | D7 sites (server-side) | per-site backends, §5 |
| production | filtered visibility proxy | `mandala-index.internal.lib.virginia.edu` → proxy container :8765 → production replica |
| dev | D11 `search_api.server.kmassets` | `mandala-index-dev.internal.lib.virginia.edu` → dev proxy → **staging** replica |
| dev | solr-proxy container | staging replica directly (`SOLR_BASEURL`) |

The proxy is the only endpoint that applies visibility filtering, and it is **read-only** —
it 404s `/update` and `/admin/system` by design. Anonymous callers get
`visibility_i:1 OR asset_type:(places subjects terms)` applied; on production that is
**549,312** of 557,483 documents. See [ADR 014](../adr/014-hybrid-solr-proxy-design.md).

**D11 note:** the `search_api` connector was repointed to `mandala-index-dev` on
2026-08-13. Expect the Search API admin UI to report the server as *unavailable* — the
hardened proxy 404s `/admin/system`, so `isAvailable()` fails while queries work. Do not
"fix" that by repointing.

## 5. D7 per-site backends — a patchwork, not a system

Each production site does something different. Same configuration on the `dev-1` staging
clones.

| Site | Mechanism | Backend | State |
|---|---|---|---|
| Images | `search_api` `ms_solr_server` | SearchStax `/solr/images_solrapi` | ❌ service gone |
| Texts | `search_api` `solr_search` | SearchStax `/solr/texts_prod` | ❌ service gone; errors every cron run |
| Visuals | `search_api` `shiva_solr_server` | SearchStax `/solr/visuals` | ❌ gone; no index bound |
| Visuals | `search_api` `mandala_library_rw` | **staging** master `/solr/mandala-visuals` | ⚠ cross-environment |
| AV | `apachesolr` `mandala_library_rw` | production master `/solr/mandala-av` | ✅ |
| AV | `apachesolr` `solr` | SearchStax `/solr/av` | ❌ service gone |
| Sources | `search_api` `solr` | production master `/solr/mandala-sources` | ✅ |
| Texts | `apachesolr` `solr` | `localhost:8983` | ❌ dead |
| Mandala Home | — | none | — |

Two consequences, each with its own note:

- **SearchStax is defunct**, but still enabled — see
  [searchstax-defunct-external-solr-config](../deferred/searchstax-defunct-external-solr-config.md).
  A shared credential sits in cleartext in three places per site and should be treated as
  burned.
- **Environments cross** — D7 staging writes to the production master; production Visuals
  writes to the staging master. See
  [solr-cross-environment-write-targets](../deferred/solr-cross-environment-write-targets.md).

No site has a kmassets *write* endpoint configured (`shanti_kmaps_admin_server_solr_write`
is empty everywhere), which is consistent with §3.

## 6. Write path

| Writer | Target | State |
|---|---|---|
| Rails KMaps apps | production `kmterms` | ✅ active |
| `reindeer_x` (kmterms → kmassets shadows) | production `kmassets` | ⚠ running, zero jobs created |
| legacy ingest (S3 → ECS) | production `kmassets` | ❓ unverified; nothing has landed since 2025-08-11 |
| D7 `search_api` / `apachesolr` | per-site `mandala-*` cores | partial, see §5 |
| **D11 `mandala_kmassets_sync`** | **staging master** | ⏳ `solr_master_url` unset — this is the next action |

When the D11 writer runs, staging `kmassets` will hold D11 documents alongside the existing
D7 ones. They do not collide (uid `images-11-{id}` vs `images-{d7nid}`), but the same asset
appears twice to anything reading staging search. Production is unaffected.

## 7. What is not in this document

The **client-side KMaps Solr endpoint configuration** for the D7 sites — which endpoint each
site's browser-side widgets query for `kmassets` and `kmterms` — is tracked privately
pending remediation, along with the routing issue behind it. **Ask Yuji.** See
[non-public documentation](../non-public-documentation.md) for why and where.

Nothing else from the inventory is withheld.

## 8. How to re-measure

All read-only. From a host on the private network (see
[How-to: Access Mandala nodes](../dev-notes/howto-access-mandala-nodes.md)):

```bash
U=http://mandala-solr-replica-production.private.production:8080

# cores, doc counts, last write
curl -s "$U/solr/admin/cores?action=STATUS&wt=json"

# composition
curl -s "$U/solr/kmassets/select?q=*:*&rows=0&facet=true&facet.field=asset_type&wt=json"

# freshness for one type
curl -s "$U/solr/kmassets/select?q=asset_type:images&rows=1&sort=timestamp+desc&fl=uid,timestamp&wt=json"

# what the anonymous filter yields
curl -s --get "$U/solr/kmassets/select" \
  --data-urlencode 'q=visibility_i:1 OR asset_type:(places subjects terms)' \
  --data-urlencode 'rows=0' --data-urlencode 'wt=json'
```

Swap `production` for `staging` in the hostname for the other cluster. D7 site
configuration comes from `search_api_server`, `apachesolr_environment` and the `variable`
table via `drush @<alias> sqlq`.
