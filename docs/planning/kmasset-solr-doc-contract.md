# KMasset Solr Document Contract (the "golden doc")

**Status:** Foundation / discovery — captures the kmassets Solr document format as an
*informal contract* so any D11 media manager can reproduce it faithfully. The
Solr **topology** below is settled fact; the D11 **write transport** (§3) is an
**OPEN decision**. The Images field-by-field inventory (§7) is a **Phase 1 TODO**.
**Relates to:** [Sprint 1 task 1a.8](../sprints/sprint-01-images-implementation.md)
(Solr write/sync) and [1b.3](../sprints/sprint-01-images-implementation.md) (proxy
visibility), [ADR 004](../adr/004-solr-source-of-truth.md) (Solr is source of truth;
match the existing contract), [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md)
(shadow pattern), [ADR 007](../adr/007-reindeer-x-independent-service.md) (reindeer_x),
[Spike 2](../spikes/spike-02-solr-integration.md) (Solr read integration),
[Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) (reindeer_x consolidation),
[solr-sync-architecture-d11](../deferred/solr-sync-architecture-d11.md),
[solr-pipeline-cost-discussion](../deferred/solr-pipeline-cost-discussion.md).

> **Why this doc.** The `kmassets` Solr index is shared by five media types
> (av / images / sources / texts) **plus** the KMaps-derived shadow entries
> (subjects / places / terms). Every producer must emit the *same* document
> shape or the index stops being uniformly queryable by its consumers (the
> mandala-om React app and the legacy D7 JS clients). That shape is an
> **informal contract** — it lives in no single spec, only in producer code,
> consumer queries, and the deployed schema. This document makes it explicit so
> the D11 Images media manager (task 1a.8) can be **validated against a golden
> document** rather than reverse-engineered and hoped-correct. The same contract
> is reused by Sources / Texts / AV in later sprints.

---

## 1. Scope

- **In scope:** the `kmassets` content-asset document format for **Images**
  (`asset_type:images`), expressed generally enough to cover the other media
  managers. The deployed Solr topology and the write/read separation.
- **Out of scope (separate concerns):** the **AV transcript index** (different
  ancestry — D7 `transcripts` module; not kmassets); the `kmterms` index itself
  (written upstream by KMaps Rails); and the *decision* of which write transport
  D11 adopts (framed here as open in §3, decided elsewhere).

---

## 2. Deployed Solr topology (SETTLED)

Verified against `uvalib/terraform-infrastructure/mandala/solr` (last changed
Oct 2022) and confirmed by the team.

- **Solr `7.7.3-slim`** (`docker/library/solr:7.7.3-slim`), **production and
  staging**. The authoritative schema is therefore the **Solr 7.x** configset:
  `solr-shanti-configsets/solr7.3.x/production/kmassets/conf/schema.xml`
  (classic `schema.xml`, *not* SolrCloud managed-schema).
  - The `solr9.10.11/` configset in that repo is **not deployed** — it is
    forward-prep. A 7→9 schema diff is a *future* migration concern, not part of
    the MVP contract.
- **Master–slave (leader/follower) replication, not SolrCloud.** Replicas run
  `-Denable.slave=true -Dmaster.url=http://mandala-solr-master-…:8080/solr`.

```
   WRITE path                                   READ path
   ──────────                                   ─────────
   <a writer>  ── write ─▶  MASTER ──replication──▶ REPLICAS
                          (write-only,             (read-only
                           NOT read)                followers)
                                                        ▲
                                                        │ read-only
                                                     PROXY  ◀── asserts public/private
                                                        ▲        visibility on every query
                                                        │
                                              CLIENTS see ONLY this
                                            (mandala-om React, D7 JS)
```

**Invariants (by design — security and isolation):**

1. **Writes go to the master only.** The master is configured for replication,
   not reading; we do **not** read from it.
2. **Reads come from the replicas.**
3. A **proxy** sits in front of the replicas and is the **only** endpoint
   clients ever see. It is **READ-ONLY** and **asserts visibility rules**
   (public/private) on every incoming query. This isolates the real index from
   direct queries/attacks and is where access control is enforced. **The proxy
   never routes writes** — a writer reaches the master directly over the internal
   network, never through the proxy.
4. Consequence: the `spike_solr_demo` search_api connection (which points at the
   proxy) is correct for **reading** but a D11 **writer cannot use it** — a
   writer needs a separate master write-connection. This mirrors the
   AUTH/UNAUTH split reindeer_x already has (`kmassets_write_client` = master,
   `kmassets_read_client` = proxy/replica; `queueConfigs.js:60-62`).

The proxy and its visibility enforcement are the subject of **Sprint 1 Step 1b**.

---

## 3. The D11 write transport (OPEN DECISION)

The topology above fixes the *destination* (the master) and the *document format*
(the rest of this doc). It does **not** decide **how** a D11 Drupal content save
reaches the master. reindeer_x + the ECS ingest pipeline are the **inherited D7
path, not a chosen D11 design.** Candidate options, still under investigation:

| Option | Path to master | Reuses | Open risk |
|---|---|---|---|
| **A — S3 → ECS** (D7 model) | Drupal → S3 → SQS → ECS transform → master | existing ECS transform already emits the correct format | no shared FS in D11; the Part-A file-watcher is legacy multisite; 4-hop fire-and-forget visibility gap |
| **B — via reindeer_x** | Drupal → HTTP POST → reindeer_x → master | reindeer_x's existing `kmassets_write_client` | needs a new content-doc handler + transform; couples to reindeer_x productionization |
| **C — direct Drupal write** | Drupal queue worker → master (direct) | simplest; debuggable; no ECS/S3 | Drupal must emit the exact flat doc format itself (§5); schema changes are deploy-coordinated (see below) |

All three **write to the master**, never the proxy. The choice is gated on the
[cost/architecture conversation with Dave Goldstein](../deferred/solr-pipeline-cost-discussion.md).
Note also (ADR 007 context): reindeer_x's *own* job — the kmterms→kmassets shadow
sync (Population 1) — is a **separate** concern from Images content indexing;
"refactor reindeer_x out of D11 Mandala" is a stated future goal that, when
pursued, warrants its own successor ADR.

**Schema changes are file-deploys.** Because this is classic `schema.xml` (no
managed-schema API), any field the Images doc needs that the current `kmassets`
schema lacks requires a **coordinated configset + Terraform/Ansible deploy with
infra**, not a Drupal-side change. The static core + dynamic grammar (§6) is
broad enough that most fields already have a home.

---

## 4. The source authorities (the contract chain)

No single source is canonical; the contract is pinned where producer output and
both consumer generations agree. The chain:

```
kmaps_engine (Rails, Andres)  ── defines the naming GRAMMAR + writes kmterms (nested)
   └▶ reindeer_x              ── shadows kmterms → kmassets Pop.1 (same naming grammar, FLATTENED)
   └▶ Drupal media managers   ── write kmassets Pop.2 (av/images/sources/texts) as FLAT docs
          consumed by ↓
   D7 AjaxSolr clients (legacy)  +  mandala-om React (current)  +  kmassets schema.xml (types)
```

| Authority | Location | Side | Defines |
|---|---|---|---|
| `kmaps_engine` `Feature#document_for_rsolr` / `nested_documents_for_rsolr` | `mandala-legacy/kmaps_engine/app/models/feature.rb` | **producer (upstream — kmterms)** | the **`kmterms`** nested block-join structure + the `{prefix}_{lang}_{id}` naming grammar that kmassets reuses (flattened) |
| `shanti_images` (Images), `mediabase` (**A/V only**), `shanti_kmaps_solr` (KMaps Solr jQuery plugin) | `mandala-legacy/mandala-drupal/.../modules/custom/` | **producer (Pop.2)** | per-type flat asset fields + derivations. **`shanti_images` is the Images producer; `mediabase` is A/V, not images** **← Phase 1 TODO (§7)** |
| `kmassets` Solr schema | `solr-shanti-configsets/solr7.3.x/production/kmassets/conf/schema.xml` | **types** | field types, multivalue, dynamic-field grammar |
| D7 AjaxSolr clients (`kmaps_views_solr`, `shanti_kmaps_faceted_search`, `kmaps_integrated_search`, `kmaps_explorer`) | `mandala-legacy/mandala-drupal/.../modules/custom/` | **consumer (legacy)** | original field vocabulary; *may still be live* on un-migrated sites |
| mandala-om React | `mandala-legacy/mandala-om/kmaps-app/src/{hooks,model,catalog}` | **consumer (current)** | load-bearing **flat** asset fields (`solr_urls.assets`); its `[child]` retrieval targets **kmterms** (`solr_urls.terms`), not assets |
| reindeer_x `kmassetSync` | `uvalib/mandala-reindeer_x/sync/kmassetSync.js` | cross-check (Pop.1 producer) | the shared core fields |

---

## 5. Two structures, one naming grammar — the kmasset writer contract is FLAT

> **Correction (2026-06-25):** An earlier version of this section attributed the
> nested block-join structure to kmasset documents and stated "any D11 writer must
> reproduce this parent/child shape." That is wrong. The block-join structure
> belongs to the **`kmterms`** index. **kmasset documents are flat** — including
> images and the flattened KMaps-taxonomy shadows. Verified against live Solr in
> [Spike 2 §7/§12](../spikes/spike-02-solr-integration.md): querying `block_type`
> on `kmassets` returns "undefined field."

Two distinct Solr structures are in play, and they must not be conflated:

**(a) `kmterms` — nested block-join (NOT this writer's target).** `kmaps_engine`
(`feature.rb` `document_for_rsolr` / `nested_documents_for_rsolr`) writes the
**kmterms** index as nested docs: a parent (`block_type: ['parent']`) owns a
`_childDocuments_` array of `block_type: ['child']` docs, each tagged by
`block_child_type` (`related_names`, etc.). Consumers retrieve children with Solr
child transformers — mandala-om `useKmap.js:24` (`fl: '*,[child parentFilter=block_type:parent …]'`)
issues this **against `solr_urls.terms`**, and queries with
`{!child of=block_type:parent}` (`feature.rb:385`). ~472K parents / ~4M children.
This is the KMaps tree-browse contract, not the asset contract.

**(b) `kmassets` — flat.** Every kmasset doc is a single flat document. This holds
for media assets (images, A/V, sources, texts) **and** for the KMaps-taxonomy
shadow entries (`subjects`/`places`/`terms` asset types) that reindeer_x projects
in from kmterms — reindeer_x **flattens** the nested term into a flat asset doc; it
does not copy the block-join structure. mandala-om reads assets via
`solr_urls.assets` (`useKmap.js:49,63`) with **no** `[child]` transformer.
**A D11 image — or any kmasset — writer produces flat docs: no parent/child, no
`_childDocuments_`.** The schema retains `_root_` / `parent_uid` (§6) for block-join
support, but the deployed kmassets index does not populate them.

What the kmasset writer *does* inherit from `kmaps_engine` is the **naming grammar**,
not the structure:

1. **Field names are generated, not fixed.** Built by interpolation:
   `caption_#{language.code}_#{id}`, `summary_#{language.code}_#{id}`,
   `code_#{geo_code_type.code}`. So the contract is a **naming grammar**
   (`{prefix}_{langcode}` / `{prefix}_{suffix}`), which is why consumers query
   with wildcards (`names_txt:…*`). These are flat fields on a flat doc.
2. **Language-analyzer suffix typing** (§6 dynamic grammar) — where NFC/diacritic
   fidelity lives.
3. **Tagging logic is spread across models** (in the kmterms producer) —
   `rsolr_document_tags` / `…_for_notes` on `Note` / `Citation` attach prefixed
   fields; the kmassets projection lands these as flat fields.

The golden doc must therefore capture **the flat kmasset doc** + the naming/suffix
grammar — and explicitly *not* the kmterms block-join shape — then the concrete
Images instance of it.

---

## 6. The kmassets schema — type layer (Solr 7.x)

From `solr7.3.x/production/kmassets/conf/schema.xml`.

### Static core (the fixed fields every kmasset doc may use)

| Field | Type | MV | Role |
|---|---|---|---|
| `id`, `ids`, `uid` | string (required) | ids/uid mv | identity |
| `parent_uid`, `_root_` | string | | block-join linkage (schema supports it; **unused in the live kmassets index** — see §5) |
| `asset_type`, `asset_subtype` | string | **single** | faceting spine — one type per doc |
| `kmapid`, `kmapid_strict` | string | mv | KMaps linkage |
| `name`, `names` | string | names mv | names |
| `names_txt` | **`text_kw`** | mv | keyword-analyzed name search ⚠ (see §8) |
| `title`, `titles` | text_kw | mv | titles |
| `name_autocomplete` | text_autocomplete | mv | typeahead |
| `caption`, `summary` | text_general | | display text |
| `description`, `descriptions`, `subject`, `author`, `creator(s)`, `keywords`, `content` | text_general / text_kw | mixed | description/search |
| `node_user` | string | single | **access control (1b)** |
| `collection_uid_s` | string | single | **access control (1b)** |
| `collection_uid_path_ss` | string | mv | **collection hierarchy path (1b)** |
| `collection_nid`, `collection_title` | string | single | collection metadata |
| `node_lang`, `node_created`, `node_changed`, `bundle`, `service` | string/date | | node metadata |
| `url`, `thumbnail_url`, `content_type`, `resourcename`, `links` | text_general/string | links mv | media |
| `date_start`, `date_end`, `last_modified`, `timestamp` | date | | dates (`timestamp` required, default NOW) |

### Dynamic grammar (the `_suffix` decoder ring)

| Pattern | Type | Notes |
|---|---|---|
| `*_i / *_is` | int | `…s` = multivalued (applies throughout) |
| `*_s / *_ss` | string | |
| `*_l / *_ls` | long | |
| `*_t / *_txt` | text_general | |
| `*_b / *_f / *_d / *_dt` (+ `…s`) | bool / float / double / date | |
| `*_ti / *_tl / *_tf / *_td / *_tdt` | trie numeric/date | |
| **`*_tibt`, `*_bo`** | text_kw / text_bo | **Tibetan** |
| **`*_sa`** | text_hi | **Sanskrit/Hindi** |
| **`*_zh`** | text_cjk | **Chinese** |
| **`*_en`** | text_en | English |
| **`*_latin`** (+ `_sort`) | string | transliteration |
| `name_*` | text_general | |
| `url_* / *_url` | text_general (single) | |
| `*_idfacet` | idfacet | |
| `*_rptgeom / *_grptgeom` | geo | |

The **language-analyzer suffixes are where NFC/diacritic fidelity lives** — the
`{prefix}_{langcode}` grammar maps onto these analyzers, directly relevant to the
Sprint 1 transliteration-fidelity acceptance criterion.

---

## 7. Images-specific field inventory — **Phase 1 TODO**

Not yet extracted. Source: the D7 **Images** media-manager doc builder in
`shanti_images` (the Images producer), with the shared `shanti_kmaps_solr` KMaps
Solr plugin (`mandala-legacy/mandala-drupal/docroot/sites/all/modules/custom/`).
Note: `mediabase` is the **A/V** producer (`package = Mandala Audio-Video`), not an
Images source — the A/V field inventory is a separate, later task. Phase 1
will produce a field-by-field table:

`solr field · type (schema §6) · cardinality · D7 source/derivation · transform notes · example`

…split into **common-core** (shared by all asset types) vs. **image-specific**,
reconciled against the consumers (§4) so any field touched by only one side is
flagged optional/uncertain rather than guessed. Deliverable also includes
**annotated golden JSON fixtures** (a representative set: public, restricted,
multi-agent, all-four-KMaps-domains, and a Tibetan/diacritic doc) committed as
**test fixtures** — the acceptance check for the D11 writer (build writer → diff
output vs. golden).

---

## 8. Known pitfalls

- **`names_txt` is `text_kw`, not tokenized `text_general`.** It behaves
  string-like; consumers search it with prefix wildcards (`names_txt:${q}*^70`,
  mandala-om `useSearch.js:75`). A D11 writer/query must respect this.
- **`language_field` handling** can silently drop KMaps taxonomy (prior project
  note) — verify against the live index when sampling.
- **Language-suffix resolution is unconfirmed.** The D7 `caption_#{lang}_#{id}`
  builder yields e.g. `caption_eng_123`, but the schema dynamic rule is `*_en`
  (not `_eng`), and the `_123` id-suffix matches no dynamic rule cleanly. How
  these resolve (explicit fields? a default? a copyField?) is a **Phase 1
  reconciliation item** — pin it from the schema + a live doc.

---

## 9. The 1a.8 ↔ 1b seam: the document is also the security contract

The write path (1a.8) and read path (1b) are separate by design but meet in the
document itself:

- **1a.8 (writer)** must *populate* `node_user`, `collection_uid_s`,
  `collection_uid_path_ss`.
- **1b (proxy)** *reads and enforces* those exact fields to assert public/private
  visibility on client queries.

If the D11 Images writer gets these wrong, the proxy's visibility rules silently
over- or under-expose content — which is precisely the Sprint 1b security
acceptance criterion. The golden doc is therefore not just a rendering contract;
it is the **access-control contract**, and the two steps are coupled through
these fields.

---

## 10. Open questions

1. **Write transport (§3)** — A (S3→ECS), B (via reindeer_x), or C (direct
   Drupal→master)? Gated on the Dave Goldstein cost/architecture conversation.
2. **Proxy write isolation** — confirmed read-only; a writer needs a *separate*
   master write-connection/credentials. What is the master write endpoint +
   auth for a D11 writer?
3. **Language-suffix resolution** (§8) — how `{prefix}_{lang}_{id}` fields land
   in the 7.x schema.
4. **Solr 7 → 9** — the deployed index is 7.7.3; the 9.x configset is prep. When
   does the 7→9 contract diff become in-scope?
5. **Legacy consumer liveness** — are the D7 AjaxSolr clients still serving any
   production site (constraining what we can change), or fully superseded by
   mandala-om?
