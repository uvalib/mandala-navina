# Texts Pre-Work Audit: `shanti_texts`/`shanti_texts_features`/`shanti_footnotes` D7 Content Model

**Audience:** Developers (Sprint 2 Workstream C3 — audit only, no migration code)
**Date:** 2026-09-01
**Source:** Legacy D7 custom modules `shanti_texts`, `shanti_texts_features`,
`shanti_texts_splitter`, `shanti_texts_search_settings`, `shanti_footnotes`
(`mandala-drupal/docroot/sites/all/modules/custom/{shanti_texts,shanti_texts_features,shanti_texts_splitter,shanti_texts_search_settings,shanti_footnotes}`)
**Relates to:** [ADR 009](../adr/009-migration-sequencing-strategy.md) (Texts/Sources
fork after Images), [Sprint 2 backlog](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-c--content-model-audits-av-sources-texts--audit-only)
(C3), [[project-spike-4b-ckeditor-footnotes]] (CLOSED — the footnotes cross-page
transform is already fully spiked; this audit does not re-investigate it), [Images
Content-Model Audit](images-content-model-audit.md), [AV Content-Model
Audit](av-content-model-audit.md), [Sources Content-Model
Audit](sources-content-model-audit.md) (methodology template and points of comparison
throughout)

> **Scope.** This is a data/field/entity-graph inventory only. No migration code, no
> `mandala_migrations` scaffolding, and no D11 content-type is created as part of this
> audit — per the Sprint 2 backlog's explicit constraint on Workstream C. The footnotes
> cross-page reference pattern is **out of scope here** — it was fully investigated and
> closed by Spike 4b (`docs/deferred/texts-footnotes-production-transform.md`); this
> audit only confirms `shanti_footnotes` exists and its role, not its mechanics.

---

## Purpose

Inventory the real D7 field model behind the Texts site — the one audit of the three
where the primary content type turns out to be **D7 core's own Book module**, not a
site-invented bundle, which changes the shape of the D11 remodeling question compared
to Images/AV/Sources. Extends Spike 4b's already-closed footnotes investigation (which
only looked at the body/footnote fields) into the full field/entity-graph inventory the
sprint backlog calls for.

## Key finding: `book` is D7 core's Book content type, not a custom bundle — the outline is core mechanism, not a shanti_texts invention

Unlike every other site audited this sprint, `book` is **not** a site-defined content
type. Confirmed in `shanti_texts_features.features.inc`:

```php
function shanti_texts_features_node_info() {
  $items = array(
    'book' => array(
      'name' => t('Book page'),
      'base' => 'node_content',
      'description' => t('<em>Books</em> have a built-in hierarchical navigation. Use for handbooks or tutorials.'),
```

`base => 'node_content'` and that exact description string are Drupal **core's own
stock defaults** for the Book module's content type — the Features export here
re-asserts the core bundle plus attaches custom fields to it, it does not invent a new
type. `field_book_mlid` (a **computed, non-stored** field — `module => computed_field`,
`store => 0`) confirms this further: it derives its value live from core's
`menu_links` table (`db_select('menu_links','ml')->condition('link_path', 'node/'.$nid)`)
rather than storing anything itself — the outline position lives entirely in core's
`{book}`/`{menu_links}` tables (`bid`/`plid`/`mlid`/`weight`), not in any
shanti_texts-owned schema.

**Migration implication:** the page-outline hierarchy is a **known, core-supported
migration path** (D11's Book module uses the same `bid`/`plid`/`weight` shape), not a
bespoke data model to reverse-engineer the way Images' satellite graph or Sources'
flat-column table were. This narrows what would otherwise be the single biggest
open question for Texts.

**A second, unrelated source of the multi-page-per-book pattern:**
`shanti_texts_splitter` is an **editorial convenience tool, not an import or migration
mechanism** — see the Splitter section below. It means many books' page trees were
machine-generated from one pasted document rather than hand-authored, but produces
ordinary core Book-outline nodes either way; D11 does not need to distinguish
splitter-generated pages from hand-authored ones structurally (though no field
currently flags which is which — see Open questions).

## The entity graph

```
book (D7 core Book content type + custom field instances)
├── field_book_content                    → text_long (body — the field the footnotes spike already covers)
├── field_book_mlid                        → COMPUTED, not stored — derived live from core menu_links
├── field_book_date                        → date
├── field_split_headings / field_split_text → consumed by shanti_texts_splitter (editorial tool, not migration-relevant)
├── field_book_author / _editor / _translator → text (255), free-text
├── field_admin_status                     → text, internal status tag
├── field_dc_description                   → text_with_summary
├── field_dc_lang_code                     → list (ISO-639), the PRIMARY language field in practice (62.8% filled)
├── field_dc_language_original              → free text, sparse (3.2% filled)
├── field_language_kmap                     → KMaps taxonomy, sparse (2.3% filled) — a THIRD, largely-unreconciled language field
├── field_dc_date_orginial_year / _publication_year → datetime (year granularity; note "orginial" typo is in the real machine name)
├── field_dc_rights_creativecommons         → list (CC license picklist)
├── field_dc_rights_general                 → text_long
├── field_kmap_term (label "Subjects") / field_kmap_places / field_kmap_terms → shanti_kmaps_fields_default (same KMaps pattern as Images/AV/Sources)
├── field_general_featured_image            → image (shared cross-site field, same as Images/AV/Sources)
├── field_pdf_version                       → file_generic, sparse (2.8% filled)
├── field_og_collection_ref                 → entityreference (og_subgroups handler) — field-storage table EMPTY in
│                                              production; real membership lives in `og_membership`, same bug as AV/Sources (see Data profile)
└── group_content_access (required)         → OG Visibility, 100% filled

collection / subcollection / asset_link — NOT defined by any shanti_texts* module;
all three come from the shared `shanti_collections`/`asset_link` modules, the SAME
cross-site bundles already confirmed reused by Images, AV, and Sources.
```

## Field inventory

### Identity / title metadata
| Field | Type | Notes |
|---|---|---|
| `field_book_author` | text (255) | free-text |
| `field_book_editor` | text (255) | free-text |
| `field_book_translator` | text (255) | free-text |
| `field_admin_status` | text | internal status tag, not a workflow/moderation field |

### Body / outline mechanics
| Field | Type | Notes |
|---|---|---|
| `field_book_content` | text_long | the body (covers footnote markup, already spiked) |
| `field_book_mlid` | computed, not stored | derived live from core `menu_links` |
| `field_book_date` | date | cardinality 1 |
| `field_split_headings` | list (options_buttons) | which `<h1>`–`<h6>` levels the splitter breaks on |
| `field_split_text` | boolean | "split on save" toggle for `shanti_texts_splitter` |

### Descriptive / Dublin Core metadata
| Field | Type | Notes |
|---|---|---|
| `field_dc_description` | text_with_summary | |
| `field_dc_lang_code` | list, ISO-639 | **primary language field in practice** — see Data profile |
| `field_dc_language_original` | text (255) | free-text, sparse |
| `field_dc_date_orginial_year` | datetime (year) | machine name has the typo verbatim |
| `field_dc_date_publication_year` | datetime (year) | |
| `field_dc_rights_creativecommons` | list | CC license picklist |
| `field_dc_rights_general` | text_long | free-text rights statement |

### KMaps taxonomy (same pattern as Images/AV/Sources)
| Field | Label | Cardinality |
|---|---|---|
| `field_kmap_term` | Subjects | -1 |
| `field_kmap_places` | Places | -1 |
| `field_kmap_terms` | Terms | -1 |
| `field_language_kmap` | Language (KMaps taxonomy — third language field, see Open questions) | -1 |

A vestigial plural-named pair (`field_language_kmaps`/`field_terms_kmaps`) exists in
the same field-base file but is **not instantiated on `book`** — noted, not chased
further; likely dead or used on a bundle outside this audit's scope.

### Access / collections
| Field | Type | Notes |
|---|---|---|
| `field_og_collection_ref` | entityreference (`og_subgroups` handler), cardinality 1 | field-storage table empty in production, see Data profile |
| `group_content_access` | options_select, **required** | 100% filled |

### Media / attachments
| Field | Type | Notes |
|---|---|---|
| `field_general_featured_image` | image | shared cross-site field (same base as Images/AV/Sources) |
| `field_pdf_version` | file_generic | sparse |

## `shanti_texts_splitter` — an editorial tool, not a migration mechanism

Reading the code directly rather than guessing from the name: this is a **node-save-time
convenience feature**, not an import or runtime rendering mechanism. When
`field_split_text = 1` on save, `shanti_texts_splitter_node_presave()` regex-splits
`field_book_content`'s HTML on the heading levels chosen in `field_split_headings`
(also extracting a trailing `<section class="footnotes">`/`<div class="endnotes">`
block if present), then `shanti_texts_splitter_node_postinsert()` clones the parent
node once per fragment via `shanti_texts_splitter_make_page()` — assigning each new
page's `book['bid']`/`['plid']`/`['weight']` to slot it into the outline, stripping
inherited KMap terms, and **explicitly resetting `field_og_collection_ref` to empty on
each generated child** (the code comment reads: *"Without reseting collection for
pages, site crashes"*).

In short: paste a Word-exported document into one book node, flip a toggle, and the
module auto-generates the full multi-page outline tree from heading boundaries. This
explains why many books have deep trees of many small pages — a second, independent
source of the shared-`bid` page-cluster pattern beyond ordinary hand-authoring — but is
irrelevant to D11 migration logic itself (it produces ordinary Book-outline nodes
either way). **Notably, the splitter's own code distrusts `field_og_collection_ref`'s
field storage enough to explicitly clear it on every generated page** — a strong hint,
independent of the data profile below, that this field was never treated as reliable
storage even by the D7 codebase itself.

## `shanti_footnotes` and the cross-page footnote pattern — out of scope here (already spiked)

Confirmed present at `docroot/sites/all/modules/custom/shanti_footnotes` — a custom
CKEditor 4 plugin producing paired inline-citation/definition markup, frequently split
across different pages of the same book (definitions collected on a dedicated "Notes"
page). **This was already fully investigated and closed by Spike 4b**
([[project-spike-4b-ckeditor-footnotes]]) — direction chosen (per-citation transform +
Notes-list aggregation), team sign-off merged (PR #76), production-build details
tracked separately at
[`texts-footnotes-production-transform.md`](../deferred/texts-footnotes-production-transform.md).
This audit does not re-derive any of that; it only confirms the module's existence and
role for completeness of the field/module inventory.

## `shanti_texts_search_settings`

Solr/search indexing configuration for Texts, analogous to AV's `mb_solr` and Sources'
`sources_biblio_search` — not separately inventoried here (out of scope: it configures
discovery/facets, not content fields).

## Data profile (production dump, loaded 2026-09-01)

Verified against `d7_texts`, already loaded in DDEV from a prior spike session
(`mandala-prod-texts-db_20260710.sql.gz`, 90MB, previously verified as complete — see
[[project-spike-4b-ckeditor-footnotes]]'s own note about a prior silently-truncated
download of this same dump, caught by checking table/line counts before use).

**Node counts:** 7,633 `book` · 65 `collection` · 57 `subcollection` · 8 `asset_link` —
matches the counts already established by Spike 4b exactly.

**The `og_membership` bug found on both AV and Sources also affects Texts — now
confirmed on all three sites checked this sprint.** `field_data_field_og_collection_ref`
is **empty (0 rows)** for `book`/`asset_link`, despite the field being a real,
field-storage-backed `entityreference`. The actual membership data lives in
`og_membership`: 7,419 node memberships tagged `field_og_collection_ref` (7,411 book +
8 asset_link — 97.1% of book nodes), plus 57 subcollection→collection memberships
tagged `field_og_parent_collection_ref` (matching the subcollection count exactly).
**This is now a cross-site pattern, not a per-site quirk** — worth treating as a single
platform-wide migration-tooling fix rather than three separate site-specific notes (see
Recommended next step).

**Required field: clean.** `group_content_access` is filled on all 7,633 book nodes
(100%) — no gap, matching AV's clean result and unlike Sources' 91.2%.

**The three-language-fields question, resolved with real numbers:**
| Field | Books filled | % |
|---|---:|---:|
| `field_dc_lang_code` (ISO list) | 4,791 | 62.8% |
| `field_dc_language_original` (free text) | 246 | 3.2% |
| `field_language_kmap` (KMaps taxonomy) | 176 | 2.3% |

`field_dc_lang_code` is clearly the primary/authoritative language field in practice;
the other two are minority-use. Its value distribution: `en` 3,650 (76.2% of filled
rows), `bo` 1,138 (23.7% — matches Spike 4b's known Tibetan count exactly), `dz` 2,
`id` 1.

**Other field coverage:** `field_kmap_term`/Subjects on 593 books (7.8%); `field_pdf_version`
on 211 books (2.8%); `field_book_author` on 3,316 books (43.5%). `field_split_text` has
2,887 rows, **all currently value `0`** — no books are mid-flagged for splitting in
this snapshot, consistent with it being a one-time transient authoring toggle rather
than persistent state.

## What this audit establishes

- `book` is **D7 core's Book content type**, not a custom bundle — its outline
  hierarchy is core Drupal mechanism (`bid`/`plid`/`mlid`/`weight`), a known-supported
  migration path rather than a bespoke shanti_texts data model.
- The full field inventory for `book`, including one computed/non-stored field
  (`field_book_mlid`) that any migration tooling correctly won't find a storage table
  for.
- `shanti_texts_splitter` is an editorial convenience tool (auto-generates a page tree
  from one pasted document at save time), not a migration-relevant mechanism — but its
  own code independently distrusts `field_og_collection_ref` storage, foreshadowing the
  data-profile finding below.
- The footnotes cross-page pattern is confirmed present but is **out of scope here** —
  fully resolved by the already-closed Spike 4b.
- **Data-verified, cross-site pattern (now confirmed on Texts, AV, and Sources
  alike):** collection membership is not readable from
  `field_data_field_og_collection_ref` — it lives in `og_membership`. Required fields
  (`group_content_access`) are 100% clean. The three language fields are resolved:
  `field_dc_lang_code` is primary (62.8% filled, matches known Tibetan counts exactly),
  the other two are minority-use.

## What this audit does NOT establish (still open)

1. **Whether `field_dc_language_original` and `field_language_kmap` should be preserved
   in D11** given their sparse (2–3%) use, or folded away in favor of
   `field_dc_lang_code` — a recommendation candidate, not a decision made here.
2. **Whether splitter-generated vs. hand-authored page provenance matters for D11.** No
   field currently flags which pages were machine-split; not decided whether that
   distinction needs preserving.
3. **The vestigial `field_language_kmaps`/`field_terms_kmaps` field-base pair** (plural
   forms, not instantiated on `book`) — not chased down to confirm what bundle, if any,
   actually uses them; flagged only for completeness.
4. **OG → D11 Group mapping for the collection-membership `og_membership` finding** —
   same open item as AV/Sources; needs to inform whatever proxy-auth/access design work
   covers all three sites' equivalent gap, ideally as one shared fix rather than three
   separate migration-plugin patches.
5. **Whether the core-Book-migration path is actually sufficient as-is**, or whether
   D11's book outline needs any Texts-specific adjustment (e.g. interaction with
   Collections/OG membership at the page level) — this audit confirms the *mechanism*
   is core-supported, not that zero migration work remains.

## Recommended next step

No modeling decision is made here — per the Sprint 2 Workstream C scope, this audit
stops at data/field/entity-graph facts. When Texts' migration turn comes up (ADR 009
sequences Texts/Sources after Images, before AV):
- Confirm the core Book-module migration path handles `bid`/`plid`/`weight` as
  expected for D11's own Book module — expected to be the most standard modeling step
  of any site audited this sprint.
- **This is the third and final site this sprint to independently hit the
  `og_membership`-vs-`field_data_field_og_collection_ref` gap** (after AV and Sources).
  Recommend treating this as a single shared migration-source-plugin fix — read
  `og_membership` for collection membership across all OG-collection-participating
  bundles — rather than reimplementing the same workaround three times.
- Resolve the three-language-field question (open question #1) alongside whatever
  faceting/search design work touches `field_dc_lang_code`, since it's already the
  clear practical winner.
- No action needed on footnotes — Spike 4b already closed that path; production build
  work is tracked separately.
