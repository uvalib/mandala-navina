# KMasset Solr Document Contract (the "golden doc")

**Status:** Foundation / discovery — captures the kmassets Solr document format as an
*informal contract* so any D11 media manager can reproduce it faithfully. The
Solr **topology** below is settled fact; the D11 **write transport** now has a
**pragmatic working model** (§3 — Dave Goldstein's small-batch S3 poster for the
authoritative path, plus a direct-to-master sink for incremental updates and
diagnosis), **with coordination still open** — Dave may surface new constraints
about the mechanism. The Images field-by-field inventory (§7) is **complete
(Phase 1, 2026-06-25)**.
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
  (written upstream by KMaps Rails). The D11 write *mechanism* is described in §3
  (working model — Dave's batch poster + a direct sink; coordination with Dave
  still open); the proxy visibility layer is Sprint 1 Step 1b.

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

## 3. The D11 write transport (WORKING MODEL — Dave coordination ongoing)

> **Revision (2026-06-26):** This section previously framed the transport as an
> OPEN decision among three options, one of which (A) assumed an **"ECS transform"**
> that rewrites the producer doc into its final form. From an initial conversation
> with Dave Goldstein (Cloud Infrastructure) we now understand the actual
> mechanism: **there is no transform.** This is a *working model*, **not a closed
> decision** — the discussion with Dave continues and he may surface new
> constraints about the mechanism (see "Open with Dave" below).
> The producer writes the **complete, final add-doc** (this contract, §5–§7) to S3;
> Dave's mechanism is a **batch poster** that collects docs and POSTs them to the
> master unchanged. `copyField` aggregation (§7.2-D) is **Solr's** job at index
> time and happens on any path — it is not a transform step and not the producer's
> to emit.

**What Dave's mechanism actually is — a small-batch S3 poster:**

- **Batches are small (~32 docs) and atomic.** One bad doc fails the whole batch;
  the remedy is to regenerate/resubmit the batch — which the change-detection model
  below makes necessary anyway.
- **Change detection is object-level.** Reprocessing is forced by **(re)writing or
  renaming the file** (one/few docs), or by staging docs under a **"regen directory"
  naming scheme** (larger sets — a proven operational lever). We need **not** track
  the exact internal timestamp Dave keys on: the file *is* the request, and
  rewriting it *is* the resubmit.
- **Failures are logged with the citing document** — so the culprit isn't a mystery
  in principle. But **reaching that log today is involved and taxing**: the errors
  surface only in **CloudWatch**, and must be located and read by hand. And because
  the batch aborts at the first failure, a cited doc can **mask later bad docs** in
  the same batch. (This manual CloudWatch excavation is the single biggest ergonomics
  gap; the automation that addresses it is broken out as a downstream capability —
  see below.)
- **Reconciliation bookkeeping is ours.** Dave's side won't account per-doc; we
  track which nodes changed / need (re)writing. Drupal's own changed-timestamps
  largely cover this — no separate heavyweight ledger required.

**One builder, two sinks.** Build the contract doc once (§5–§7); emit it two ways,
both writing **to the master, never the proxy** (§2 invariant):

| Sink | Path to master | Role | Force-resubmit lever |
|---|---|---|---|
| **File sink** | Drupal → S3 inbound → Dave's batch poster → master | the **authoritative** path: bulk migrations + steady-state | rewrite/rename a file (few) · "regen directory" (many) |
| **Direct sink** | Drupal queue worker → POST add-doc → master | **incremental** day-to-day updates **+** fast **diagnostic** loop | n/a — synchronous |

The **direct sink** is the pragmatic win. A synchronous POST returns Solr's exact
error on the cited doc immediately, so it (a) gives day-to-day saves low latency and
visible success/failure — fixing D7's fire-and-forget invisibility — and (b) is the
fast inner loop for diagnosing batch failures: replay suspect docs to surface *all*
problems (de-masking the serial failures the atomic batch hides) and to test whether
a fix is **systematic**, *before* doing the authoritative whole-batch regenerate via
the file sink. **Diagnose fast with direct; land authoritatively via the batch.**
Both carry the identical contract doc — the only difference is the courier and the
latency.

**Error-management automation is a separate downstream capability — not Sprint 1.**
Batch failures today require manual **CloudWatch** excavation (bullet above), and the
direct sink is the manual workaround that shortens that loop. A full
error-management & resubmit *automation* layer on top of these two sinks — a single
managed failure queue, batch errors normalized to the direct-sink error shape, managed
resubmit, **batch de-masking via direct replay** (replay a failed batch's members
doc-by-doc to surface *all* failures at once), **provenance metadata** (source channel
+ batch identity as first-class, decision-driving fields), and a **systemic-error
circuit-breaker** (group by signature + threshold so a field/schema change doesn't
dump ~100k near-identical errors or hammer the master) — is **out of Sprint 1 scope**
and broken out as its own downstream effort, possibly its own sprint:
[kmassets-sync-error-management.md](../deferred/kmassets-sync-error-management.md).
The observability/reporting ideas in
[solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md) (SNS
events) and [Spike 8 Part C](../spikes/spike-08-reindeer-x-consolidation.md) fold
into it.

**Getting Drupal's doc to S3 (file-sink mechanics).** D11 has no shared filesystem
(Aegir is gone), so the D7 `clsync`/`synchandler` file-watch is retired. The file
sink reaches S3 either by Drupal writing directly via the AWS SDK, or by relaying
through reindeer_x's HTTP `/post` (the Node.js consolidation proven in
[Spike 8 Part A](../spikes/spike-08-reindeer-x-consolidation.md)). That sub-choice
is an implementation detail of the file sink, **not** a transport fork.

A writer reaches the master over a **master write-connection**, never the
proxy-pointed read connection (§2). Note (ADR 007 context): reindeer_x's *own* job —
the kmterms→kmassets shadow sync (Population 1) — is a **separate** concern from
Images content indexing; "refactor reindeer_x out of D11 Mandala" is a stated future
goal that, when pursued, warrants its own successor ADR.

**Schema changes are file-deploys.** Because this is classic `schema.xml` (no
managed-schema API), any field the Images doc needs that the current `kmassets`
schema lacks requires a **coordinated configset + Terraform/Ansible deploy with
infra**, not a Drupal-side change. The static core + dynamic grammar (§6) is
broad enough that most fields already have a home.

**Open with Dave (coordination not yet closed).** The working model above is from
an initial conversation; these are unresolved and Dave may add to them:

- **Direct-to-master sink** — is a second writer to the master acceptable, and how
  does Drupal reach it? **Likely no credentials** — the master is internal-network
  isolated and access control is enforced at the read proxy, not the master — so
  write access is probably just network reachability. **To be verified by testing**,
  not assumed. This is the newest piece and the most likely to draw constraints.
- **Batch cadence / "triggered when needed"** — how promptly is a freshly-written
  S3 file processed? (Governs how much we lean on the direct sink for latency.)
- **Regen-directory scheme** — still supported by the current mechanism?
- **Failure surface** — do the cited-failure logs come back reliably, and are failed
  batch members preserved anywhere, or do we regenerate from the node?
- **Timestamp field** — which one Dave keys on (low priority; file-rewrite forces
  reprocessing regardless).

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

## 7. Images-specific field inventory (Phase 1 — extracted 2026-06-25)

Extracted from the D7 producer chain (`mandala-legacy/mandala-drupal/docroot/sites/all/modules/custom/`)
and reconciled against **live kmassets docs sampled via the proxy on VPN** (read path;
`numFound` = 111,326 image docs). A representative live image doc carries **61 fields**.

### 7.1 The Images doc is built by a 3-module chain, not one builder

The kmasset doc for an image is assembled in order, then normalized:

1. **`shanti_kmaps_fields`** — `_shanti_kmaps_fields_get_solr_doc()` (`shanti_kmaps_fields.module:1039`).
   The **common-core base** every asset type shares: identity, KMaps linkage,
   visibility, URLs, collection title/nid, plus a site-configured `extra_fields`
   map (`asset_subtype`, `creator`, `caption`, `summary`, `date_start/end`,
   `language`).
2. **`drupal_alter('kmaps_fields_solr_doc')`** (`:1268`) fans out to two producers:
   - **`shanti_collections`** — `…_solr_doc_alter()` (`shanti_collections.module:782`)
     writes the **collection hierarchy / access-path fields** — i.e. the §9 ↔ 1b
     security seam. *Not the base builder, not shanti_images.*
   - **`shanti_images`** — `…_solr_doc_alter()` (`shanti_images.module:1625`) writes
     the **image-specific** fields (captions/descriptions from the
     `field_image_descriptions` paragraph, IIIF thumb/dimensions, agent→creator/date).
3. **Post-alter normalization** (`:1270–1352`): `title_sort_s`, `creator_sort_s`,
   `date_start` defaulting (`0000-01-01T…` + `date_start_corrupt_s` on bad input),
   URL `http→https` + prod-alias→canonical-domain rewrite, then
   `_shanti_kmaps_fields_normalize_solrdoc()` (entity-decode of text fields → the
   NFC/Unicode fidelity step). Sets `mogrified_s`.

**A faithful D11 writer must reproduce all three layers** — getting only the
`shanti_images` layer would silently drop the access-control fields (1b breaks).

### 7.2 Field inventory

**Most image fields are realized through dynamic fields, not static schema
declarations.** Every suffixed field below (`*_s`, `*_ss`, `*_i`, `*_is`, `*_txt`,
`*_idfacet`, …) is matched by a `schema.xml` dynamic-field rule (§6), which means:
(a) the **suffix is load-bearing** — it sets the field's type and cardinality, so
the writer must emit the exact suffix the contract specifies; and (b) **a new
suffix-matching field can be added with writer code alone — no schema deploy**
(this is what makes `rotation_s`, §7.3.4, free to add). The only fields that need a
static declaration or `copyField` are the **unsuffixed** ones (table D).

**A. Common-core base** (`shanti_kmaps_fields` — shared by every media manager)

| Field | Type (§6) | Card | D7 source / derivation |
|---|---|---|---|
| `id` | string (req) | 1 | `$node->nid` |
| `uid` | string (req) | 1 | `service-nid` (e.g. `images-1028396`) |
| `service` / `asset_type` | string | 1 | `SHANTI_KMAPS_ADMIN_SERVICE` / site var per type (`images`) |
| `asset_subtype` | string | 1 | `extra_fields` map — `photograph` (110,786), `artwork` (528), … |
| `title` | text_kw | 1 | `$node->title` |
| `node_user` | string | 1 | author **username** — *access field (1b)* |
| `node_user_i` / `node_user_full_s` | int / string | 1 | `$node->uid` / realname |
| `node_lang` | string | 1 | `'en'` default (`und`→`''`) |
| `node_created` / `node_changed` | date | 1 | `gmdate()` from node timestamps |
| `pogrified_s` | string | 1 | producer marker `'fields_module'` |
| `kmapid` | string | mv | **ancestor-expanded** domain-id list |
| `kmapid_strict` / `kmapid_strict_ss` | string | mv | directly-tagged domain-ids / their header names |
| `kmapid_is` | int | mv | encoded `id*100 + domain` (places=1,subjects=2,terms=3) |
| `kmapid_{places,subjects,terms}_idfacet` | idfacet | mv | `header\|domain-id` |
| `visibility_i` / `visibility_s` | int / string | 1 | 1/2/3 ↔ public/private/uva (`inherit`→resolved from group) — *access field (1b)* |
| `solrdoc_ts_i` | int | 1 | `time()` at index |
| `url_html` / `url_ajax` / `url_json` / `url_thumb` | url text | 1 | site URL templates w/ nid; https+canonical-domain normalized |
| `collection_title` / `collection_nid` | string / int | 1 | from collection field |
| `title_sort_s` / `creator_sort_s` | string | 1 | normalized sort keys (post-alter) |
| `date_start` / `date_end` | date | 1 | extra_fields/agent; defaults to `0000-01-01T00:00:00Z` |
| `mogrified_s` | string | 1 | normalization marker |

**B. Collection / access path** (`shanti_collections` alter — **the 1a.8 ↔ 1b seam, §9**)

| Field | Type | Card | Role |
|---|---|---|---|
| `collection_uid_s` | string | 1 | owning collection uid — *access* |
| `collection_uid_path_ss` | string | mv | ancestor collection uid path — *access* |
| `collection_title_path_ss` | string | mv | ancestor title path |
| `collection_nid_path_is` | int | mv | ancestor nid path |
| `collection_idfacet` | idfacet | mv | collection facet (group-access prepended) |
| `subcollection_uid_ss` / `subcollection_idfacet_ss` | string | mv | when the node is itself a (sub)collection |

**C. Image-specific** (`shanti_images` alter)

| Field | Type | Card | D7 source / derivation |
|---|---|---|---|
| `shanti_image_id_s` | string | 1 | `shanti_uid` (e.g. `shanti-image-469626`) — IIIF identifier |
| `creator` | string | 1 | first `field_image_agents` agent title |
| `caption` / `caption_lang_s` | text / string | 1 | first `field_image_descriptions` paragraph label + its language |
| `summary` / `summary_lang_s` | text / string | 1 | first desc summary (or truncated `description` ≤550c) |
| `description` / `description_lang_s` | text / string | 1 | first desc `field_description` safe_value |
| `description_type_s` | string | 1 | literal `'general description'` |
| `description_authors_s` | string | 1 | comma-joined `field_author` |
| `caption_alt_txt` / `summary_alt_txt` / `description_alt_txt` | text | mv | the **remaining** descriptions — **present only on multi-description images** (see §7.3.5) |
| `caption_alt_lang_ss` / `summary_alt_lang_ss` / `description_alt_lang_ss` | string | mv | their languages (conditional, as above) |
| `description_alt_type_ss` / `description_alt_authors_ss` | string | mv | their type/authors (conditional, as above) |
| `rotation_s` | string | 1 | **wanted, not yet emitted** — see §7.3.4 |
| `url_thumb` (override) | url | 1 | IIIF 200×200 thumb |
| `url_thumb_width` / `_height` / `_size` | string | 1 | thumb geometry |
| `img_width_s` / `img_height_s` | string | 1 | source image dimensions |
| `url_iiif_s` | string | 1 | IIIF `info.json` URL |

**D. Schema-derived (unsuffixed) — the writer must NOT emit these** — CONFIRMED against `schema.xml`

Verified in the deployed 7.x configset (`solr7.3.x/{production,staging}/kmassets/conf/schema.xml`
— the two are **byte-identical** for these declarations). These unsuffixed fields fall
outside the dynamic-field grammar; each is a statically-declared aggregation populated
by `copyField`, **not** by the producer chain. A writer that emits them duplicates or
fights the index — omit them:

| Field | Decl | Fed by `copyField` from |
|---|---|---|
| `ids` | `string` mv, required | `uid`, `id`, `*_id_s`, `*_id_ss` |
| `titles` | `text_kw` mv | `title`, `title_*`, `*_title_s`, `caption` |
| `names` | `string` mv | `name`, `name_*`, `names_txt` |
| `creators` | `text_kw` mv | `creator`, `creator_*`, `contributor_*`, `agent_*`, `*_authors_s(s)`, `node_user_*` |
| `descriptions` | `text_general` mv | `caption`, `summary`, `description`, `caption_*`, `summary_*` |
| `str` | `text_kw` mv | the universal catch-all — all of title/name/creator/id/caption variants (incl. the Tibetan `*_bo`/`*_tibt` title/caption fields) |
| `timestamp` | `date`, required, `default="NOW"` | (none — index sets it) |
| `_version_` | `long` | (none — Solr internal) |

**Consequence for the writer (useful, not just a prohibition):** the aggregations are
*automatic*. Fields the producer **does** emit are swept in for free — e.g. the
image-specific `description_authors_s` and `node_user_full_s` flow into `creators`;
`caption_alt_txt`/`summary_*` flow into `descriptions`; `shanti_image_id_s` flows into
`ids`. So the writer only emits the **source** fields (tables A–C); search-spanning
fields like `str`/`titles`/`creators` build themselves. (Note `caption` is copied into
**`titles`, `descriptions`, and `str`** — intentional, since a caption is both a
title-ish and a description-ish value.)

### 7.3 Findings that sharpen the contract

1. **Images carry no Tibetan-script fields.** Verified live: `title_tibt`,
   `title_bo`, `caption_bo`, `caption_tibt`, `summary_bo` all return **0** image
   docs. **`names_txt` is also 0 for images** — it is a kmterms/taxonomy field, not
   an image field. So for Images, NFC/diacritic fidelity lives in the **plain
   Unicode** `title`/`caption`/`summary`/`description` text fields, *not* in the
   `*_tibt`/`*_bo` analyzers. This narrows §8's language-suffix worry (below).
2. **Images do NOT use the `{prefix}_{lang}_{id}` grammar.** That grammar is
   `kmaps_engine`/kmterms (§5). The Images producer encodes language as a separate
   **`*_lang_s` companion string** (`caption_lang_s`, `summary_lang_s`,
   `description_lang_s`, `*_alt_lang_ss`). This **resolves the §8/§10 Q3 open
   question for the Images contract** — there are no `caption_eng_123`-style
   fields in image docs.
3. **`*_lang_s` values are dirty.** The `caption_lang_s` facet is heterogeneous —
   `en` (41,561) **and** `English` (6,541), `Tibetan` (21) **and** `Standard
   Tibetan` (1), `Chinese Languages` (5). Cause: the base builder defaults `'en'`
   while the image alter copies `field_language->header` (a KMaps name like
   `"English"`). The D11 writer should decide whether to normalize or preserve
   bug-for-bug (ADR 008 leans preserve for retrieval parity; flag for the search
   phase).
4. **`rotation_s` — wanted, but a D7 typo means it has never been written.**
   `shanti_images.module:1747` assigns `$sdog['rotation_s']` (a typo for `$sdoc`),
   so the value is silently dropped — confirmed absent in all 111k live docs. This
   field **is desired going forward**: the D11 writer should populate it correctly
   from the image rotation (D7 source value: `$siimg->rotation`). Because
   `rotation_s` matches the **`*_s` dynamic-field rule** (§6), adding it requires
   **no `schema.xml` change/deploy** — only writer code. Tracked in
   [`docs/deferred/images-rotation-field-support.md`](../deferred/images-rotation-field-support.md).
5. **The `*_alt_*` description family is presence-conditional.** It is written
   **only when an image has ≥2 descriptions** (`shanti_images.module:1705` — the
   first description fills `caption`/`summary`/`description`; the remainder fill the
   `*_alt_*` arrays). Single-description images (the common case, e.g. baseline
   fixture 1028396) carry **none** of these fields; fixture 1087551 carries the full
   set. A golden-diff harness must treat them as optional, not missing.

### 7.4 Golden fixtures captured (this session)

Three live image docs pulled via the proxy, committed under
[`kmasset-fixtures/`](kmasset-fixtures/) (interim home until the D11 writer module
exists, at which point they move there as its test fixtures):

| Fixture | id | Why representative |
|---|---|---|
| `sample-image-doc-1.json` | 1028396 | baseline public photograph; multi-domain KMaps (places + subjects); diacritic creator names |
| `sample-image-multidesc.json` | 1087551 | exercises the alt-description arrays |
| `sample-image-tibetan.json` | 1243616 | `caption_lang_s:Tibetan` + diacritics (`Orgyen Sinzhutsé Script`) — NFC fidelity check |

These become the **acceptance check** for the D11 writer: build writer → diff
output vs. golden (excluding the §7.2-D schema-derived fields and volatile
`solrdoc_ts_i`/`timestamp`/`_version_`).

---

## 8. Known pitfalls

- **`names_txt` is `text_kw`, not tokenized `text_general`.** It behaves
  string-like; consumers search it with prefix wildcards (`names_txt:${q}*^70`,
  mandala-om `useSearch.js:75`). A D11 writer/query must respect this.
- **`language_field` handling** can silently drop KMaps taxonomy (prior project
  note) — verify against the live index when sampling.
- **Language-suffix resolution — RESOLVED for Images (Phase 1, §7.3).** The
  `caption_#{lang}_#{id}` grammar is `kmaps_engine`/kmterms, **not** the Images
  producer. Image docs encode language as a separate `*_lang_s` companion string,
  and live image docs carry **no** `*_tibt`/`*_bo`/`caption_eng_123` fields. The
  `_eng`-vs-`_en` reconciliation only matters for the kmterms/taxonomy producers,
  not the Images media manager.
- **`*_lang_s` values are inconsistent** (§7.3): `en` vs `English`, `Tibetan` vs
  `Standard Tibetan`. A query/normalization decision for the search phase.

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

1. **Write transport (§3)** — superseded. The old A/B/C framing assumed an ECS
   transform that doesn't exist. Now a **working model** (§3): Dave's small-batch
   S3 poster (authoritative) + a direct-to-master sink (incremental + diagnosis).
   Coordination with Dave is still open — see §3 "Open with Dave."
2. **Master write endpoint + auth** — the proxy is read-only, so a writer needs a
   *separate* master write-connection. **Probably no credentials**: the master is
   internal-network isolated and access control lives at the read proxy, so write
   access is likely network reachability only. **Verify by testing** rather than
   assuming an auth scheme. (Open with Dave — §3.)
3. **Language-suffix resolution** (§8) — RESOLVED for Images (§7.3: `*_lang_s`
   companions, no script-suffixed fields); still open for the kmterms/taxonomy
   producers (`_eng` vs `_en`).
4. **Solr 7 → 9** — the deployed index is 7.7.3; the 9.x configset is prep. When
   does the 7→9 contract diff become in-scope?
5. **Legacy consumer liveness** — are the D7 AjaxSolr clients still serving any
   production site (constraining what we can change), or fully superseded by
   mandala-om?
6. **Does the Mandala Drupal app read through the proxy?** — Leaning **no**. The
   proxy enforces visibility for *untrusted, end-user-driven* clients (mandala-om
   React, D7 JS) that hit Solr directly. Drupal is a **trusted tier** with its own
   node/Group access control; routing it through the proxy pre-filters results to a
   single visibility context — wrong for a tier that must see content across
   visibility levels (editorial/admin, access-aware per-user rendering, index
   verification) and would duplicate Drupal's access logic in the proxy. Symmetric
   with the write path (Drupal→master directly): the trusted tier reads the
   **replicas directly**; the proxy is the *external-client* boundary.
   - **Caveat:** if Drupal serves any end-user-facing search itself, it then **owns**
     the access filtering for that surface, which must agree with the proxy's
     filtering (the Sprint **1b.3** coherence criterion). So the answer depends partly
     on whether end-user search is served by Drupal or delegated entirely to
     mandala-om.
   - **Exception:** Drupal may query *through* the proxy to **verify** it enforces
     correctly — proxy as system-under-test, essentially the 1b.3 acceptance check.
   - **ADR-worthy** once decided (read-path access-control architecture).
