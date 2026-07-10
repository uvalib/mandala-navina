# Mandala Refactoring — Critical Path Technical Assessment

**Audience:** Developers  
**Date:** June 2026  
**Related:** [Refactoring Plan](refactoring-plan.md)

---

## Overview

This document details the technical risks, dependencies, and sequencing constraints for the Mandala D11 rebuild. Three items dominate the critical path: the KMaps field type port, the collections architecture decision, and API compatibility for the external React application. Everything else — content types, search UI, migration — is blocked until these are resolved and validated.

---

## 1. KMaps Field Type — Highest Risk Item

### Current D7 Implementation

`shanti_kmaps_fields` defines a custom Drupal 7 field type (`shanti_kmaps_fields_default`) backed by this database schema per field instance:

```php
// From shanti_kmaps_fields.install
'columns' => [
  'raw'    => ['type' => 'text'],           // raw term string
  'id'     => ['type' => 'int'],            // KMaps term ID
  'header' => ['type' => 'varchar', 'length' => 255],  // display label
  'domain' => ['type' => 'varchar', 'length' => 255],  // subjects|places|terms
  'path'   => ['type' => 'text'],           // ancestor path
  'defids' => ['type' => 'text'],           // definition IDs (serialized)
],
```

Each content type carries one or more KMaps field instances (subjects, places, terms separately). The field widget is an autocomplete backed by the KMaps API. The formatter renders a linked term with popover.

Storage uses standard D7 field tables (`field_data_field_*`, `field_revision_field_*`), so the data itself is straightforwardly structured — no exotic storage.

### D11 Port Requirements

The D11 Field API is fully capable of supporting this schema. The port requires:

1. **`FieldType` plugin** (`src/Plugin/Field/FieldType/KmapsItem.php`)  
   Define the column schema via `schema()`, property definitions via `propertyDefinitions()`, and `mainPropertyName()`. The six columns map cleanly to typed properties. The only decision is whether `defids` stays serialized text or becomes a proper multi-value sub-field — the latter is cleaner in D11 but requires a migration plugin change.

2. **`FieldWidget` plugin** (`src/Plugin/Field/FieldWidget/KmapsAutocompleteWidget.php`)  
   The autocomplete widget calls the KMaps API. In D7 this is jQuery-driven; in D11 it should use the core AJAX autocomplete infrastructure. The external API contract (KMaps subjects/places/terms endpoints) is unchanged, so the widget logic is a direct port.

3. **`FieldFormatter` plugin** (`src/Plugin/Field/FieldFormatter/KmapsDefaultFormatter.php`)  
   Renders term label with link and popover. Pure template work — no API risk.

4. **Config schema** (`config/schema/shanti_kmaps_fields.schema.yml`)  
   Field instance settings (which KMaps domain, root term filter, etc.) need a config schema definition. In D7 these were stored as field instance settings; in D11 they become typed config.

### Specific Risks

**Risk 1: `defids` serialization**  
D7 stores definition IDs as serialized PHP in a text column. D11 migration will need to unserialize and decide on final storage. If we keep it as JSON text in D11, the migration is simple. If we normalize it, migration is more work but the data model is cleaner. Recommendation: keep as JSON text initially, normalize in a follow-up.

**Risk 2: Multi-value field behavior**  
A node can have multiple KMaps terms attached (e.g., tagged with three subjects). D7 handles this via the standard field cardinality system. D11 handles it the same way — no risk here, but migration plugins must use `delta` correctly when migrating multi-value instances.

**Risk 3: Autocomplete widget AJAX**  
The D7 widget uses a custom `hook_menu` endpoint to proxy KMaps API calls. In D11 this becomes a `Route` with a `Controller`. The controller logic (query KMaps API, return JSON suggestions) is straightforward, but the KMaps API response format needs to be verified against the current live API before writing the controller — the external API may have changed since the D7 code was written.

**Validation gate before proceeding to Phase 3:**  
- Field type installs cleanly on D11
- A test node can be created with a KMaps field and saved
- The autocomplete widget returns correct suggestions from the live KMaps API
- A saved field value round-trips correctly (save → reload → correct display)
- Field value is indexed correctly in search_api against the existing `kmassets` Solr schema

---

## 2. Solr Index Compatibility

### Current Indexes

Two Solr indexes managed outside the Drupal codebase:
- **`kmassets`** — content assets from all sites; fed by ECS ingest tasks
- **`kmterms`** — KMaps taxonomy terms (owned by the Ruby on Rails KMaps applications)

The `kmassets` index is the primary search and browse index. Its schema is defined in `mandala-scripts/solr-schema/` and is maintained independently of Drupal. The ECS ingest pipeline (already on the modern infrastructure) writes to it directly — Drupal reads from it.

### D11 Integration Approach

`search_api_solr` in D11 connects Drupal to Solr via a Server + Index configuration. The key constraint is: **the Solr schema is the source of truth, not search_api_solr's generated schema.**

The `kmassets` index schema must not be modified. `search_api_solr` must be configured in read-only/query mode against the existing schema rather than managing the schema itself. This means:

1. Configure the Solr server to point at the existing endpoint
2. Define a Search API index with fields mapped to the existing `kmassets` schema field names
3. Disable `search_api_solr`'s schema management (`managed_schema = false`)
4. The KMaps field columns (`domain`, `id`, `path`) must map to the correct Solr field names in the existing schema

### Risk

The existing Solr schema field names for KMaps data need to be audited. If the D7 `apachesolr` module used different field name conventions than `search_api_solr` expects, a field name mapping layer is needed. Pull the current schema from `mandala-scripts/solr-schema/` and map every field before writing any Views integration code.

---

## 3. Collections / Group Module Architecture

### Current D7 Implementation

Collections use Organic Groups (`og`). A collection is an OG group node; content nodes are OG group content. Access control uses `og_access_roles`. Subcollections use `og_subgroups` — **one level only, no sub-subcollections**. The `stand_alone_project` module adds a hub/satellite sync pattern on top.

The `asset_link` content type exists specifically because in multi-site D7, you cannot directly reference a node on a different site. An `asset_link` is a local proxy node that points to a remote asset by its Solr ID. In a single-instance D11, this entire concept goes away — content from all sections lives in one database and can be directly referenced.

### D11 Approach: Group Module

The Group module (3.3.5, D11) uses a fundamentally different architecture than OG:

- **Groups are first-class entities** (not nodes). A collection is a `Group` entity with a bundle type (e.g., `collection`).
- **Group content relationships** are explicit `GroupRelationship` entities. Adding a node to a group creates a `GroupRelationship` record.
- **Group roles and permissions** are per-group-type, not per-group. Access rules are defined at the bundle level.
- **Group types** define what content types can be added as group content.

This is cleaner than OG but requires explicit design decisions up front:

**Decision 1: Group type hierarchy**  
Define group types: `collection` (top-level) and `subcollection`. The one-level-only constraint from D7 is a business rule, not a technical constraint — enforce it via validation, not Group module configuration.

**Decision 2: Which content types are group content**  
In D7 all content was potentially OG content. In D11/Group, each content type must be explicitly registered as eligible group content for specific group types. Define the full matrix before building content types:

| Content Type | Can belong to Collection | Can belong to Subcollection |
|---|---|---|
| Audio | ✓ | ✓ |
| Video | ✓ | ✓ |
| Shanti Image | ✓ | ✓ |
| Book Page | ✓ | ✓ |
| Biblio | ✓ | ✓ |
| Stand-Alone Project | ✓ | — |

**Decision 3: Access control model**  
OG's role-based access mapped to Drupal user roles. Group module has per-group-type roles (Outsider, Member, Administrator by default, plus custom roles). Map current `og_access_roles` permissions to Group roles before building content types.

**Decision 4: `stand_alone_project` replacement**  
The hub/satellite sync pattern in `stand_alone_project` was needed because content lived on separate sites. In a single D11 instance, a "standalone project" is simply a collection with a boolean field or taxonomy tag marking it as a featured/standalone project. The sync mechanism goes away entirely.

**Validation gate before Phase 4:**
- Group types defined and installable via CMI config
- A test group (collection) can be created
- A test node can be added to a group
- Group-based access control works as expected
- Nested subcollections work (one level only)

---

## 4. CKEditor 4 → 5: Texts Site Impact

### Current D7 Implementation

`shanti_texts` uses CKEditor 4 with a custom `shanti_footnotes` plugin. The plugin inserts inline footnote markup and renders a footnote list at the bottom of the page. The module `shanti_texts_splitter` splits book pages at HTML headings — this depends on specific HTML output from CKEditor being parseable. The splitter also accepts Word document uploads, splitting on heading levels.

### D11 Situation

Drupal 11 ships CKEditor 5 as the only supported editor. CKEditor 4 is not available. The `footnotes 4.x` module provides CKEditor 5 footnote support, but **the footnote markup format changed between CKEditor 4 and CKEditor 5**. Existing footnote content in the database uses the CKEditor 4 markup format.

### Risks and Mitigations

**Risk 1: Footnote markup migration**  
Existing texts content contains CKEditor 4 footnote markup. The content migration plugin for texts must transform this markup to the CKEditor 5 format. This requires knowing both markup formats before writing the migration plugin. Pull a sample of current texts content from the D7 database and document the exact footnote markup pattern before writing the migration.

**Risk 2: `shanti_texts_splitter` HTML parsing**  
The book splitter parses CKEditor output HTML to find heading tags. CKEditor 5 produces slightly different HTML (stricter, no legacy attributes). Audit the splitter logic against CKEditor 5 output before assuming it works.

**Risk 3: Tibetan Unicode content**  
Many texts contain Unicode Tibetan script. Verify that CKEditor 5, the D11 database layer (utf8mb4), and the migration pipeline all handle Tibetan Unicode without corruption. Test with actual Tibetan-language nodes during the spike, not just Latin content.

---

## 5. bibcite vs. biblio — Sources Site

### Current D7 Implementation

The Sources site uses the `biblio` contributed module, which defines its own content type, database tables, and citation formatting system. It also has a `zotero_feed` content type for managing Zotero library connections. Search is via `biblio_search_api`. Views are in `sources_views` and `sources_biblio_search`.

### D11 Situation

`biblio` has no D11 port. The recommended replacement is `bibcite` (Bibliography & Citation module), which uses CSL (Citation Style Language) for formatting — the same standard used by Zotero, Mendeley, and most modern reference managers.

### Key Differences

| | D7 biblio | D11 bibcite |
|---|---|---|
| Storage | Custom tables | Standard Drupal entities |
| Citation styles | Custom PHP formatters | CSL styles (8,000+ available) |
| Zotero import | `biblio_zotero` | Via Feeds or REST import |
| Content type | `biblio` node | `bibcite_reference` entity |
| Zotero feed mgmt | `zotero_feed` node type | TBD — may be a config entity or feed |

### Risks

**Risk 1: Feature gap audit**  
Before committing to bibcite, audit the current Sources site usage against bibcite's feature set. Specifically: what reference types are in use (book, article, chapter, thesis, etc.), what citation styles are needed, and whether the Zotero import workflow is compatible. bibcite's D11 release (3.0 beta) is confirmed; D11 compatibility should be verified before the planned D11 upgrade.

**Risk 2: `zotero_feed` content type migration**  
The D7 Sources site has a `zotero_feed` content type for managing Zotero library connections. bibcite's Zotero integration approach may differ — determine where Zotero feed configuration lives in bibcite before designing the migration.

**Risk 3: Content migration**  
`biblio` stores references in custom tables; `bibcite` stores them as Drupal entities. The migration plugin must map biblio's custom schema to bibcite entity types. This is custom migration work with no off-the-shelf plugin.

---

## 6. API Compatibility — React Application

### Current State

Each Mandala site exposes two API types, consumed by an external React application and used internally:

**JSON APIs** (consumed by React app):
- AV: `https://av.mandala.library.virginia.edu/api/v1/media/node/{nid}.json`
- Images: `https://images.mandala.library.virginia.edu/api/json/{nid}`
- Sources: `https://sources.mandala.library.virginia.edu/sources-api/json/{nid}`
- Texts: `https://texts.mandala.library.virginia.edu/shanti_texts/node_json/{nid}`

**AJAX APIs** (used internally):
- AV: `https://av.mandala.library.virginia.edu/services/node/ajax/{nid}`
- Images: `https://images.mandala.library.virginia.edu/api/ajax/{nid}`
- Sources: `https://sources.mandala.library.virginia.edu/sources-api/ajax/{nid}`
- Texts: `https://texts.mandala.library.virginia.edu/shanti_texts/node_embed/{nid}`

### The Problem

In a single D11 instance, the site-specific subdomains (`av.mandala.`, `images.mandala.`, etc.) go away. All content lives under one domain. The React application is currently hardcoded to these per-site URLs. This must be resolved before cutover.

### Options

**Option A: Preserve URL paths, single domain**  
Serve all content from `mandala.library.virginia.edu`. Implement each API endpoint at the same path as before, minus the subdomain. The React app would need updating to use the single domain. Cleanest long-term.

**Option B: Subdomain aliases to single instance**  
Keep the old subdomains as ALB routing aliases all pointing at the single D11 instance. The D11 site responds to all subdomains. Existing React app URLs continue to work with no React changes. More operational complexity but zero React app changes.

**Option C: Redirects**  
301 redirects from old subdomain paths to new single-domain paths. Works for browsers; may break React app depending on how it handles redirects.

### Decision Required

This is a coordination decision between the Drupal team and Than Grove (React app). Needs to be resolved before Phase 5 (APIs) begins. The choice affects ALB routing configuration in Terraform.

### D11 API Implementation

In D11, custom JSON APIs are implemented as REST resources or JSON:API endpoints. The current D7 implementations use `hook_menu` + custom callbacks. The D11 port requires:
- A `Route` per endpoint
- A `Controller` per endpoint returning JSON or HTML
- Response format must match the D7 output exactly (field names, structure, data types)

Run the live D7 API against sample node IDs and document the exact response structure for each content type before writing D11 controllers.

---

## 7. Content Migration Strategy

### Approach

The Drupal `migrate` module (core in D11) with `migrate_drupal` provides a base for D7 → D11 migrations. Standard node migrations exist for core content types. Custom migration plugins are required for:

- KMaps field values (custom field type, not handled by `migrate_drupal`)
- Collections → Group relationships (OG → Group module schema change)
- `shanti_images` custom table
- `biblio` → `bibcite` reference entities
- `zotero_feed` nodes → bibcite feed configuration
- CKEditor 4 footnote markup transformation (in the texts body field migration)
- Tibetan Unicode content — verify character encoding is preserved
- User shared-table configuration — D7's five sites share one user base via a MySQL cross-database table-prefix kludge (`users`/`users_roles`/`role`/`authmap`/`sessions`/name fields all redirect to `mandala_shared`); the per-site dumps used elsewhere in this migration (e.g. `d7_images`) do **not** contain real user data. See [deferred: D7 shared user database](../deferred/d7-shared-user-database.md).

### Migration Sequencing

Migrations have dependencies. The correct order:

1. Users (no dependencies)
2. Taxonomy terms (no dependencies)
3. Files (no dependencies)
4. Group entities / collections (depends on users)
5. Content nodes per type (depends on users, files, groups, taxonomy)
6. KMaps field values (depends on content nodes existing)
7. Group relationships / collection membership (depends on both groups and content nodes)

### Validation Strategy

Run migrations against a copy of the production database in the staging environment. Do not attempt a single-pass cutover migration. Plan for:

- Multiple migration test runs with rollback between runs
- Per-content-type validation queries (count, spot-check field values)
- KMaps field round-trip validation (term IDs match live KMaps API)
- Solr re-indexing and result count comparison against D7 production
- API response comparison: D7 output vs D11 output for same node IDs

---

## 8. Dependency Map

```
Phase 0: Repo + Infrastructure
    │
Phase 1: D11 core + Auth
    │
Phase 2: KMaps field type + Solr validation ◄─── CRITICAL GATE
    │
Phase 3: Group/Collections architecture ◄─────── CRITICAL GATE
    │
    ├── Phase 4a: Texts (CKEditor 5, Tibetan Unicode validation first)
    ├── Phase 4b: Images (IIIF integration)
    ├── Phase 4c: Audio/Video (Kaltura)
    └── Phase 4d: Sources (bibcite + zotero_feed approach first)
         │
    Phase 5: APIs ◄──────────────────────────────  React app URL strategy must be decided
         │
    Phase 6: Search + Browse UI
         │
    Phase 7: Theme
         │
    Phase 8: Content Migration
         │
    Phase 9: Cutover
```

Phases 4a–4d can be parallelized by different developers once Phase 3 is complete. Phase 5 requires at least one content type complete as a reference implementation and requires the API URL strategy decision.

---

## 9. Pre-Work: Audits Required Before Coding

These audits should be completed before Phase 2 begins. They are low-effort investigations that de-risk high-effort implementation work.

| Audit | What to Check | Blocks |
|---|---|---|
| KMaps API current contract | Hit live KMaps API endpoints, verify response format matches D7 widget code | Phase 2 widget port |
| Solr `kmassets` schema field names | Pull schema from `mandala-scripts/solr-schema/`, map to D7 `apachesolr` field naming conventions | Phase 2 Solr integration |
| CKEditor 4 footnote markup format | Extract sample texts content from D7 DB, document exact footnote HTML | Phase 4a migration plugin |
| Tibetan Unicode in texts | Run sample Tibetan-language nodes through migration pipeline prototype | Phase 4a migration |
| bibcite D11 status + feature audit | Verify D11 release exists; check reference types and Zotero import against current Sources usage | Phase 4d |
| `zotero_feed` content type usage | Inventory `zotero_feed` nodes in D7 DB; understand what they configure | Phase 4d |
| Zotero API current credentials | Verify Zotero library access is current and API key is available | Phase 4d |
| Kaltura contract + API | Verify contract (Partner ID: 381832) is active; confirm API credentials and endpoint | Phase 4c |
| Group module beta API stability | Review Group module issue queue for known breaking changes before stable release | Phase 3 |
| React app API URL strategy | Coordinate with Andres Montano; decide Option A/B/C for subdomain handling | Phase 5 |
| D7 API response structure | Hit live D7 API endpoints with sample node IDs; document exact JSON structure per content type | Phase 5 |
