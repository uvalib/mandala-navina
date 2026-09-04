# Sources Pre-Work Audit: `biblio`/`shanti_biblio_modules` D7 Content Model

**Audience:** Developers (Sprint 2 Workstream C2 — audit only, no migration code)
**Date:** 2026-09-01
**Source:** Legacy D7 contrib module `biblio` (+ `biblio_search_api`, `biblio_zotero`),
custom module family `shanti_biblio_modules`
(`mandala-drupal/docroot/sites/all/{modules/contrib/biblio*,modules/custom/shanti_biblio_modules}`)
**Relates to:** [Migration Complexity Comparison](av-sources-texts-migration-complexity-comparison.md)
(scores this site against AV/Texts), [ADR 009](../adr/009-migration-sequencing-strategy.md) (Sources/Texts
fork after Images), [Sprint 2 backlog](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-c--content-model-audits-av-sources-texts--audit-only)
(C2), [[project-spike-5-bibcite]] (bibcite type-mapping spike this audit extends),
[Images Content-Model Audit](images-content-model-audit.md) and
[AV Content-Model Audit](av-content-model-audit.md) (methodology template and points of
comparison throughout)

> **Scope.** This is a data/field/entity-graph inventory only. No migration code, no
> `mandala_migrations` scaffolding, and no D11 content-type is created as part of this
> audit — per the Sprint 2 backlog's explicit constraint on Workstream C.

---

## Purpose

Inventory the real D7 field model behind the Sources site, extending Spike 5's
citation-type mapping (which explicitly left "field-level compatibility... not
compared" as an open item) into the full structural audit the sprint backlog calls for.
Unlike Images (referenced nodes) and AV (field_collection), Sources' primary content
type predates Drupal's Field API entirely for its core data — a materially different,
and more consequential, storage shape than either sibling audit encountered.

## Key finding: `biblio` is a hybrid — ~50 flat legacy columns plus a real Field-API layer, not a Field-API content type

`biblio` is not modeled as Drupal Field API fields on a node. It is a **custom node
type backed by its own fixed-column table**, with a parallel junction-table system that
controls per-type form *presentation* only, not schema:

- **`{biblio}`** — one row per node revision, with **~50 fixed columns present on every
  row regardless of type** (title variants, publication metadata, identifiers,
  abstract/notes, 7 generic custom slots — full list below).
- **`{biblio_types}`** — the 36 publication types (Journal Article, Book, …).
- **`{biblio_fields}`** — registry of the ~50 possible logical field names.
- **`{biblio_field_type}`** (PK `tid, fid`) — **the type-variance mechanism**: for a
  given type+field pair, controls `required`/`visible`/`weight`/`vtab`. A "Journal
  Article" and a "Report" write to the *same* `{biblio}` columns; only the edit form's
  visible/required fields differ by type.
- **`{biblio_field_type_data}`** — the human-readable label/hint text for a field,
  joined in when building the type-specific edit form.
- **`{biblio_contributor}`**/**`{biblio_contributor_data}`** — authors as a genuine
  many-to-many relation (unlimited, ranked, role-typed per publication type), **not** a
  flat column, with a name-dedup mechanism (`aka`/`alt_form`/`merge_cid`).
- **`{biblio_keyword}`**/**`{biblio_keyword_data}`** — free-text keyword tagging,
  many-to-many, unlimited.
- **`{biblio_collection}`**/**`{biblio_collection_type}`** — biblio's *own*, separate,
  legacy parent/child collection system, distinct from the site's real
  `collection`/`subcollection` node types. **Confirmed empty (0 rows) in production —
  dead code, out of migration scope** (see Data profile).

**On top of this legacy system, real Field-API fields are also attached to the `biblio`
bundle** by the site-specific layer — KMaps taxonomy fields, Zotero integration fields,
and the OG collection/access fields (see Field inventory below). **This makes `biblio`
a genuine hybrid**: the legacy ~50-column portion needs a custom-table source plugin (no
standard D7 node/field Migrate source applies), while the Field-API portion can use
ordinary D7 field migration tooling. This is a materially different — and larger —
remodeling question than either Images (satellite nodes → Paragraphs) or AV
(field_collection → Paragraphs, a much shorter hop) faced: there is no existing
Field-API structure to inherit for roughly half of the content.

**Correcting Spike 5's field-level open item:** every D7 biblio type shares the
*identical* column set — `biblio_field_type` varies only labels/required/visible/weight,
not the schema. So "field-level compatibility to bibcite's shipped types" is not a
differing-field-sets question the way it might read from the spike's phrasing; it is a
label/required-ness mapping question. The 94.2%-row/26-type match from Spike 5 is about
type *taxonomy and conventions*, not differing field sets.

## The entity graph

```
biblio (node, ~50 legacy columns + Field-API layer below)
├── biblio_contributor / biblio_contributor_data   → authors (unlimited, ranked, role-typed;
│                                                      relational, NOT a field; name-dedup exists but ~unused, see data profile)
├── biblio_keyword / biblio_keyword_data           → keywords (unlimited, relational)
├── field_kmaps_subjects / _places / _terms,
│   field_biblio_language / _long_language          → shanti_kmaps_fields_default (same KMaps pattern as Images/AV)
├── field_zotero_tags                               → taxonomy_term_reference (zotero_tags vocab)
├── field_zotero_collections                        → taxonomy_term_reference (collections vocab — see Zotero section;
│                                                      NOT the same as the biblio_zotero_collections DB table, which is unused)
├── field_zotero_attachment_links / _canonical_url
│   / _fetch_url / _attachment / _files              → link fields, sparsely used (see data profile)
├── group_content_access                             → OG Visibility (Field-API-backed, ~91% filled)
└── (collection membership)                          → tracked via the `og_membership` table under
                                                         field_name = 'field_og_collection_ref' —
                                                         **NOT** via the field_sql_storage value table for
                                                         that field, which is empty in production (see below)

asset_link (separate node type, custom module `asset_link`)
├── field_asset_link_uid                             → identifies the linked external asset
└── (collection membership)                           → same og_membership pattern as biblio

collection / subcollection (node types, shared `shanti_collections`/`shanti_collections_admin`
modules — the SAME reused pattern already confirmed on Images and AV)
├── subcollection → collection nesting                → og_membership, field_name = 'field_og_parent_collection_ref'
                                                          (52 rows, one per subcollection)

zotero_feed (real node type, defined by biblio_zotero_node_info() in contrib biblio_zotero)
— a Feeds-importer subscription/config carrier ("Subscribe to a zotero user or group.
  Creates nodes of content type 'biblio' from feed content"), NOT a citation record.
  Its count of 1 is expected/normal, not a data-quality gap.
```

**A real, data-verified correction to what static code analysis alone would suggest:**
`field_og_collection_ref` is a genuine `field_sql_storage` Field-API field attached to
both `biblio` and `asset_link` — but its value table (`field_data_field_og_collection_ref`)
is **empty (0 rows)** in production for both bundles. The actual collection-membership
relationship lives entirely in the **`og_membership` table** (26,762 rows total: 21,104
biblio + 2,808 asset_link memberships tagged `field_name = 'field_og_collection_ref'`,
plus 52 subcollection→collection memberships tagged
`field_name = 'field_og_parent_collection_ref'`). This means **a migration reading only
the Field API value table would see zero collection memberships** — the canonical
source is `og_membership`, not the field-storage table its own name implies. This is
the single most consequential migration-shape finding in this audit and would not have
surfaced from code reading alone; it only appeared once real data was queried (see
Data profile).

## Field inventory

### `{biblio}` core columns (all ~50 present on every row; visibility/required gated by `biblio_field_type`, not schema)
**Title variants:** `biblio_sort_title`, `biblio_secondary_title`, `biblio_tertiary_title`,
`biblio_short_title`, `biblio_alternate_title`, `biblio_translated_title`.
**Publication metadata:** `biblio_type` (FK), `biblio_edition`, `biblio_publisher`,
`biblio_place_published`, `biblio_year`, `biblio_volume`, `biblio_pages`, `biblio_date`,
`biblio_issue`, `biblio_type_of_work`, `biblio_number_of_volumes`,
`biblio_original_publication`, `biblio_reprint_edition`, `biblio_section`,
`biblio_refereed`.
**Identifiers:** `biblio_isbn`, `biblio_issn`, `biblio_doi`, `biblio_accession_number`,
`biblio_call_number`, `biblio_citekey`, `biblio_coins` (COinS metadata), `biblio_md5`
(dedup hash, indexed), `biblio_number`, `biblio_other_number`.
**Abstract/notes:** `biblio_abst_e` (English), `biblio_abst_f` (foreign-language),
`biblio_full_text`, `biblio_notes`, `biblio_research_notes`.
**Generic custom slots:** `biblio_custom1`–`biblio_custom7` (7 unlabeled slots, meaning
assigned per-type via `biblio_field_type_data`).
**Remote/import metadata:** `biblio_remote_db_name`, `biblio_remote_db_provider`,
`biblio_access_date`, `biblio_formats` (serialized), `biblio_auth_address`,
`biblio_label`, `biblio_lang`, `biblio_url`.

### Relational (not columns)
- **Authors** — `biblio_contributor`/`biblio_contributor_data`: unlimited, ranked,
  role-typed per publication type (role labels themselves vary by type via
  `biblio_contributor_type`/`_data`, e.g. "Editor" only offered for some types); identity
  fields `name`/`lastname`/`firstname`/`affiliation`/`drupal_uid`, plus dedup pointers
  `aka`/`alt_form`/`merge_cid`.
- **Keywords** — `biblio_keyword`/`biblio_keyword_data`: unlimited, free-text.

### Field-API layer (Sources-specific, layered by `shanti_biblio_modules`)
| Field | Type | Source feature |
|---|---|---|
| `field_kmaps_subjects` / `field_kmaps_places` / `field_kmaps_terms` | `shanti_kmaps_fields_default` | `biblio_long_fields` |
| `field_biblio_language` / `field_biblio_long_language` / `field_language_kmaps` | `shanti_kmaps_fields_default` | `biblio_long_fields` |
| `field_biblio_long_title` | text | `biblio_long_fields` |
| `field_featured_image` | image | `sources_misc_config` |
| `field_og_collection_ref` | entityreference (OG group_audience) | `sources_misc_config` — **field_sql_storage table unused in production; real data is in `og_membership`, see above** |
| `group_content_access` | OG Visibility | `sources_misc_config` — Field-API-backed, ~91% filled |
| `field_zotero_attachment_links` | link, unlimited | `biblio_zotero` |
| `field_zotero_canonical_url` / `field_zotero_fetch_url` | link, required by field config (see data profile for real fill rate) | `biblio_zotero` |
| `field_zotero_attachment` / `field_zotero_files` | link | `csc_zotero_importer` |
| `field_zotero_collections` | taxonomy_term_reference (`collections` vocab) | `csc_zotero_importer` |
| `field_zotero_tags` | taxonomy_term_reference (`zotero_tags` vocab) | `csc_zotero_importer` |

No 5th custom biblio type or additional custom entity beyond the 4 already known from
Spike 5 (`Review`, `Dictionary`, `Block Print`, `Obituary`) was found in the code — the
site-specific layer's substantive additions are the fields above, not new content types.

## `shanti_biblio_modules` — the customization layer

Six cooperating sub-features/modules, not one module:
- **`biblio_long_fields`** — the KMaps + long-title/language fields above.
- **`csc_zotero_importer`** — Zotero Feeds importer config + `field_zotero_*` fields +
  the `collections`/`zotero_tags` vocabularies.
- **`sources_biblio_search`** — Search API/Solr facets (author, place published, type,
  publisher, year, title) — the discovery layer, analogous to AV's `mb_solr`.
- **`sources_collection_views_links`** — collection-related menu links/blocks and the
  `biblio_list` view.
- **`sources_misc_config`** — attaches `field_og_collection_ref` and
  `group_content_access` to `biblio` (the same OG pattern used by Images/AV).
- **`sources_misc`** (plain module, ~930 lines) — the real logic layer:
  `sources_misc_node_access()` implements a custom private-node rule; `sources_misc_*`
  functions implement `node_json`/`ris_export`/`node_embed` — the citation/embed/export
  machinery behind Spike 6's confirmed `/sources-api/ajax/{nid}/cite/{style}` route;
  `sources_misc_coll_index*` handles bulk multi-collection assignment.
- **`biblio_import_mods`** — OG-aware bulk-import mapping (lets an import target a
  specific Collection).

## Zotero integration

Two layers: **`biblio_zotero`** (contrib) is the generic Feeds-based Zotero→biblio
importer engine; **`csc_zotero_importer`** (site-specific) configures it.

- **Zotero collections are modeled as taxonomy terms**, not the contrib module's own
  `biblio_zotero_collections` mapping table — that table is **confirmed empty (0 rows)**
  in production. The real data lives in `field_zotero_collections`
  (`taxonomy_term_reference`, 6,212 field-value rows, matching Spike 5's "~6.2k
  collection rows" figure — but from a different table than the spike's phrasing might
  suggest).
- **Zotero tags** — `field_zotero_tags`, 12,817 rows against a 2,827-term `zotero_tags`
  vocabulary, matching Spike 5's "~12.8k tag rows" figure exactly.
- **Attachment/URL fields are sparsely used**: `field_zotero_attachment_links` has only
  12 rows; `field_zotero_canonical_url` has 0. Most of the "attachment" surface appears
  effectively unused despite being modeled as required-by-field-config in the code —
  worth confirming with the group whether these fields are still meaningfully in use.
- All Zotero data (tags, collections, attachments) attaches directly to `biblio` nodes
  as fields — it migrates as part of the biblio node, not as a separate dataset.

## Data profile (production dump, 2026-06-11)

Verified against the production Sources DB (`mandala-prod-sources-db_2026-06-11.sql.gz`,
38.6MB, `gzip -t` passed — **intact**, unlike the AV dump this session found corrupted),
loaded into DDEV's `d7_sources` database for this audit.

**Node counts:** 25,627 `biblio` · 2,862 `asset_link` · 56 `subcollection` · 52
`collection` · 1 `zotero_feed` · 1 stray `basic_page`. Matches Spike 5's biblio/asset_link
counts exactly.

**Biblio type distribution (top entries):** Journal Article 14,302 · Book 4,382 ·
Website 1,880 · Conference Paper 812 · Book Chapter 781 · Review 599 · Miscellaneous 500
· Dictionary 375 · Magazine Article 313 · Audiovisual 300 · Book (single author) 247 ·
Block Print 140 · Obituary 63 — confirms all 4 custom types from Spike 5 are real,
populated types, not schema-only.

**Legacy `biblio_collection`/`biblio_collection_type` tables: 0 rows each — confirmed
dead, out of migration scope.**

**Collection membership — the field-storage vs. `og_membership` gap, quantified:**
`field_data_field_og_collection_ref` has 0 rows for both `biblio` and `asset_link`
despite being `field_sql_storage`-backed. The real membership data is in
`og_membership`: 21,104 of 25,627 biblio nodes (82.3%) and 2,808 of 2,862 asset_link
nodes (98.1%) have a collection membership recorded there. **4,523 biblio nodes (17.7%)
have no collection membership at all** — worth a follow-up check on whether these are
intentionally uncollected or an import gap.

**Access field:** `group_content_access` (Field-API-backed, unlike collection
membership) is populated on 23,367 of 25,627 biblio nodes (91.2%); values are
overwhelmingly `0` (23,348), with 16 at `1` and 3 at `2` — semantics of the non-zero
values not resolved by this audit (likely OG Visibility's private/pending states, not
confirmed).

**Authors:** 65,329 contributor-node links resolving to 36,151 distinct people. The
`aka`/alternate-form dedup mechanism exists in the schema but is used on **only 5** of
36,151 contributor records — effectively unused in practice, meaning the "author
identity resolution" concern flagged from static code reading is a much smaller real
issue than the schema alone suggested.

**Keywords:** 67,936 rows (many-to-many, unlimited).

**Identifier fill rates (of 25,627 biblio):** ISBN present on 57.9% (10,771 null), DOI
on 32.3% (17,352 null), ISSN on 4.7% (24,426 null), year present on 94.5% (1,399 null) —
consistent with a type mix dominated by journal articles/books/websites where ISSN in
particular is expected to be sparse.

**KMaps tagging:** `field_kmaps_subjects` populated on only 5,358 of 25,627 biblio nodes
(20.9%) — KMaps subject tagging is a minority-use field on Sources, unlike its role on
Images/AV; worth noting as a difference in how thoroughly Sources content was
KMaps-classified.

## What this audit establishes

- `biblio` is a **hybrid** content type: ~50 flat legacy columns (own custom tables, no
  standard D7 Migrate field source applies) plus a real Field-API layer (KMaps, Zotero,
  OG access) that ordinary D7 field migration tooling can handle.
- The per-type variance mechanism (`biblio_field_type`) controls presentation, not
  schema — closing Spike 5's open "field-level compatibility" question: there are no
  differing field sets to reconcile against bibcite's types, only differing
  labels/required-ness.
- The real entity graph, including the corrected understanding that Zotero collections
  live in a Field-API taxonomy-reference field, not the contrib module's own mapping
  table (which is unused).
- **The most consequential single finding:** collection membership for `biblio`/
  `asset_link` is not readable from the Field API value table at all in production — it
  must be read from `og_membership`. Any migration source plugin built against
  `field_data_field_og_collection_ref` alone would silently migrate zero collection
  memberships.
- Production-data validation for identifier/author/KMaps fill rates, confirming the
  legacy `biblio_collection` system is dead weight and the contributor dedup mechanism
  is effectively unused.

## What this audit does NOT establish (still open)

1. **The D11 target model for the ~50-column legacy portion of `biblio`.** Given every
   type shares the same columns, a flat Field-API content type (one field per current
   column) is the most obvious candidate, but that is a recommendation, not a decision
   made here — needs its own ADR-010-style scope note when Sources' migration turn
   comes up (ADR 009 sequences it after Images, alongside/before Texts).
2. **Whether to preserve the author-identity dedup graph** (`aka`/`alt_form`/`merge_cid`)
   given it is used on only 5 of 36,151 records — likely safe to drop, but not decided
   here.
3. **Semantics of `group_content_access` values `1`/`2`** (16 and 3 rows respectively) —
   not resolved; likely OG Visibility's private/pending states but unconfirmed against
   code.
4. **Why 4,523 biblio nodes (17.7%) have no collection membership** — intentional
   (top-level content not organized into a collection) vs. an import gap. Not
   distinguished by this audit.
5. **Whether the sparsely-used Zotero attachment fields** (`field_zotero_attachment_links`
   12 rows, `field_zotero_canonical_url` 0 rows) are still meaningfully in use or dead
   weight like `biblio_collection` — flagged, not resolved.
6. **OG → D11 Group mapping** for Sources' access model, including the
   `og_membership`-not-field-storage finding above — this needs to inform whatever
   proxy-auth/access design work covers Images' and AV's equivalent OG mappings, since a
   generic "read the Field API collection-reference field" migration approach would
   silently fail for Sources specifically.

## Recommended next step

No modeling decision is made here — per the Sprint 2 Workstream C scope, this audit
stops at data/field/entity-graph facts. When Sources' migration turn comes up:
- Scope the flat-column-to-Field-API remodeling (open question #1) as its own explicit
  decision, informed by how cleanly the ~50 columns map to sensible D11 fields (most
  already look like natural 1:1 candidates from the inventory above).
- **Build the collection-membership migration source against `og_membership`, not
  `field_data_field_og_collection_ref`** — this is the one finding in this audit that
  would silently break a migration if missed.
- Revisit bibcite's type/label mapping (Spike 5) now that field-level compatibility is
  confirmed clean — the spike's remaining open items (zotero_feed liveness, CSL
  custom-type-id support, output comparison) are the real remaining risks, not field
  structure.
- Any new AJAX/JSON/download endpoint Sources needs should follow the
  `_entity_access: 'node.view'` convention documented in
  [`entity-access-endpoint-convention.md`](entity-access-endpoint-convention.md) —
  Images hit and fixed the same D7 blanket-permission gap three separate times this
  sprint; don't reopen it here.
