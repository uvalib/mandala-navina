# Images Pre-Work Audit: `shanti_images` D7 Content Model

**Audience:** Developers (Images Phase 1 pilot)
**Date:** 2026-06-16
**Source:** Legacy D7 `shanti_images` module (`mandala-legacy/.../modules/custom/shanti_images`)
**Relates to:** [ADR 009](../adr/009-migration-sequencing-strategy.md) (Images is the Phase 1 pilot), [Critical Path §9](critical-path.md) (audits required before coding)

---

## Purpose

Inventory the real D7 field model behind the Images site so the D11 `shanti_image`
content type can be scoped from data, not guesswork. This is the one genuinely
unproven unknown for the ADR 009 Step 1a public-plumbing slice. KMaps fields
(Spike 1) and Solr read (Spike 2) are already proven; this audit closes the gap.

## Key finding: it's five content types + a sidecar table, not one content type

The Images site is **not** a single content type. It is a primary type plus three
inline-referenced satellite types and one nested lookup type, all defined by the
`shanti_image_type` Feature:

| Bundle | Role | # fields | Referenced from |
|---|---|---|---|
| `shanti_image` | Primary image metadata record | 50 | — (the node users see) |
| `image_agent` | Person in image provenance (photographer, owner…) | 5 | `field_image_agents` (inline entity form, **required**) |
| `image_descriptions` | Caption/summary/description w/ author + language | 4 | `field_image_descriptions` (inline entity form) |
| `external_classification` | One tag in an external scheme (LoC, Getty…) | 3 | `field_external_classification` (inline entity form) |
| `external_classification_scheme` | Defines an external scheme | (lookup) | `field_external_class_scheme` on `external_classification` (nested) |

**The entity graph.** Three satellites hang directly off a `shanti_image` node, and a
fourth type is nested one level deeper under `external_classification`:

```
shanti_image
├── field_image_agents          → image_agent              (REQUIRED, unlimited)
├── field_image_descriptions    → image_descriptions       (optional, unlimited)
└── field_external_classification → external_classification (optional, unlimited)
                                      └── field_external_class_scheme → external_classification_scheme
```

**Migration implication:** an Image is an *entity graph*, not a flat node. Migrating
one image means migrating the `shanti_image` node **plus** its referenced agent /
description / classification nodes (and the nested scheme) and re-establishing the
references.

**Decision (2026-06-16): collapse the satellite graph to Paragraphs.** Two models were
weighed: keep the satellites as separate referenced nodes (D7 parity, lowest migration
risk) vs. collapse them into Paragraphs / composite sub-entities (cleaner model, owned
lifecycle, inherited access). [ADR 010](../adr/010-adr-008-scope-clarification.md)
(Accepted 2026-06-16) settles that "migrate, not improve"
([ADR 008](../adr/008-mvp-migrate-not-improve.md)) governs *user-facing* behavior, **not**
internal data modeling — so remodeling the graph is permitted and is not an MVP scope
violation. On the merits, the team chose **Paragraphs**: the deciding factor was debt
item #2 (access propagation) — a silent privacy risk far cheaper to get right via
inherited access than to retrofit onto independent satellite nodes — reinforced by #1
(no node-space suppression) and #6 (no future node→paragraph re-migration). The choice
remains bound by ADR 008's faithful-migration floor (data round-trips identically, stays
retrievable).

> **Scope of this decision:** it applies to the **Images satellite graph only**. It sets
> **no precedent** for other content types or sites — per ADR 010, each such case is
> decided on its own merits. Do not cite "Images collapsed to paragraphs" as a general rule.

The debt below is what the keep-as-nodes path *would have* incurred; it is recorded as the
rationale for collapsing, not as accepted debt.

### Technical debt of preserving the entity graph (keep-as-nodes path)

The D7 model is already *paragraph-shaped, built from the wrong primitive*: the satellite
references use Inline Entity Form with `allow_existing => 0` (each child created fresh
inside the parent, never shared) and, for agents, `delete_references => 1` (deleting the
image deletes its agents). That is a composite/owned relationship emulated with full
nodes. Carrying it forward as nodes in D11 incurs:

1. **Node-space pollution requiring active suppression.** Agent/description/classification
   nodes are "not viewed directly" (per the module README) yet remain real nodes with nids,
   URLs, revisions, and access records. They must be *actively* excluded from Solr indexing,
   Views listings, sitemaps, JSON:API collections, and pathauto — every surface that lists
   "nodes" is a potential leak. Paragraphs never enter node-space, so this burden vanishes.
2. **Access-propagation coupling (security-adjacent).** `shanti_image` carries
   `group_content_access` (Visibility, required); each satellite node has *independent*
   access. Nothing automatically makes a private image's description node private — the
   parent's visibility must be propagated to every satellite and kept in sync on edit.
   Paragraphs inherit parent access for free. This intersects directly with the Step 1b
   proxy-auth work and solr-proxy visibility filtering ([ADR 009](../adr/009-migration-sequencing-strategy.md));
   it is silent and a privacy leak if missed — much cheaper to design into Step 1b than retrofit.
3. **Inconsistent orphan lifecycle.** Cascade-delete is per-field and *not* uniform: agents
   cascade (`delete_references => 1`), external_classification does not (`=> 0`). Some
   satellites are orphaned by design; cleanup logic becomes ours to own forever.
4. **IEF dependency for the edit UX.** The inline editing experience leans on the Inline
   Entity Form contrib module (its D11 maturity and quirks) rather than first-class
   Paragraphs tooling.
5. **Loose referential integrity.** entityreference-by-nid has no DB-level FK; the
   five-type, dependency-ordered migration must preserve/remap nids exactly or leave a
   broken graph.
6. **Likely second migration later.** If a post-MVP pass moves these to paragraphs (the
   natural model), that is a second content migration (node → paragraph) on live data —
   the most expensive kind. Deferring with interest.

Items #1 and #2 fold into Solr and auth work already on the Phase 1 plan; #2 is the sharp
one. The counterweight is real: collapsing now adds target-model design and node→paragraph
transform-plugin risk *during* the pilot meant to establish the shared pattern.

## Scope decision: all five content types are in scope for Step 1a

Step 1a (public plumbing) builds and migrates the **full entity graph**, not just the
primary node:

1. `shanti_image` (primary)
2. `image_agent` (required satellite)
3. `image_descriptions` (satellite)
4. `external_classification` (satellite)
5. `external_classification_scheme` (nested lookup)

Rationale: this proves the complete inline-entity-graph migration pattern once, in the
pilot, rather than deferring the satellite complexity into a later parallel track where
it would be rediscovered. The required `field_image_agents` reference also makes this
non-optional — a valid `shanti_image` node cannot be created without at least one
`image_agent`, so that type must exist and migrate first regardless.

Note: "five content types" describes the **D7** model. Per the decisions above, the D11
target is:

- `shanti_image` — content type
- agents / descriptions / classifications — **Paragraph** types embedded on `shanti_image`
  (owned, per-image, cascade-deleted with the image)
- `external_classification_scheme` — a **taxonomy vocabulary**, referenced by the
  classification paragraphs (shared lookup, one canonical record per scheme; see decision below)

The full entity graph's *data* is in scope for Step 1a either way; the target shape is now
settled.

**Decision (2026-06-16): `external_classification_scheme` is a taxonomy vocabulary.**
The scheme is a small, editorially-managed, shared controlled list (Getty TGN, LoC, …) —
"contact us to add a scheme," per the D7 UI — referenced by many classification
paragraphs. Modeled as a **taxonomy vocabulary** (terms carrying the scheme's URL / API
fields), referenced via an entity-reference field on the classification paragraph. Chosen
over a custom content entity because there is no real per-scheme behavior in scope:
link-to-external construction (external ID → URL) rides on a **field formatter or small
service**, not entity-class logic. Caveat noted: if substantial scheme behavior emerges
later, promoting taxonomy → custom entity is itself a (small, low-volume) migration.

**Migration ordering** (with the satellites collapsed to paragraphs):

1. `external_classification_scheme` → migrate D7 scheme nodes into **taxonomy terms**
   first (no deps); they remain referenced entities, not paragraphs.
2. `shanti_image` → sourced from the D7 image node, pulling its D7 `image_agent` /
   `image_descriptions` / `external_classification` nodes in and transforming each into an
   **embedded paragraph** on the image (classification paragraphs reference the scheme
   *term* from step 1). No separate pre-migration of the satellite nodes.
3. the `shanti_images` sidecar / IIIF identity linkage (below).

## The `shanti_images` sidecar table (not a content type)

`shanti_images_schema()` defines a custom table that maps a node to its file and its
IIIF-server identity. **It is a registry, not Drupal field data:**

| Column | Meaning |
|---|---|
| `siid` | Shanti Image ID (PK, serial) |
| `fid` | Drupal file id of the uploaded original |
| `nid` | The `shanti_image` node it belongs to |
| `uid` | Uploader |
| `i3fid` | IIIF file id on the image server, form `shanti-image-123` |
| `filename` | Original filename |
| `width` / `height` | Pixel dimensions (backfilled from IIIF) |
| `created` | Timestamp |
| `mmsid` | Legacy Media Management System id (0 if not an MMS import) |

**Migration implication:** this table is the join between a node and the external
IIIF server. Because **the IIIF server stays as-is** (see decision below), the
`i3fid` values remain valid and the D11 model must *preserve* this linkage verbatim
(a field/sub-entity carrying `i3fid`, dimensions, and `mmsid`) rather than
reconstruct or re-derive IIIF identity. The `mmsid` and `field_other_ids` carry
**legacy MMS IDs that external links and several APIs depend on** (per the module
README) — they must survive migration.

### Decision: the IIIF server stays as-is

The existing external IIIF server is **out of scope for the rebuild and stays in
place for the foreseeable future.** D11 does not stand up a new IIIF stack or change
the `shanti-image-NNN` identifier scheme. This turns IIIF from an open design
question into a **wiring task**: point D11 at the existing IIIF upload/view/delete
URLs (the D7 `shanti_images_*_url` settings) and keep the `i3fid` linkage intact
through migration. Consistent with [ADR 004](../adr/004-solr-source-of-truth.md)
(treat existing external infrastructure as source of truth, don't refactor it for MVP).

## `shanti_image` field inventory (50 fields)

Storage `type` is the D7 field-base type; cardinality `-1` = unlimited.

### KMaps fields (ride on Spike 1 — proven)
| field_name | type | card | label | notes |
|---|---|---|---|---|
| `field_subjects` | shanti_kmaps_fields_default | -1 | Subjects | kmassets `subjects` domain |
| `field_places` | shanti_kmaps_fields_default | -1 | Places | kmassets `places` domain |
| `field_kmap_terms` | shanti_kmaps_fields_default | -1 | Terms | kmassets `terms` domain (no search root) |
| `field_kmap_collections` | shanti_kmaps_fields_default | -1 | Kmap Collections | search root kmapid 2823 |

### Image + identity
| field_name | type | card | label |
|---|---|---|---|
| `field_image` | image | -1 | Image (extensions: jpg jpeg png jp2 tif tiff cr2) |
| `field_original_filename` | text | 1 | Original Filename |
| `field_other_ids` | text | -1 | Other IDs (**holds legacy MMS IDs**) |

### Referenced satellite entities
| field_name | type | card | req | label | target |
|---|---|---|---|---|---|
| `field_image_agents` | entityreference | -1 | **1** | Image Agents | `image_agent` |
| `field_image_descriptions` | entityreference | -1 | 0 | Image Descriptions | `image_descriptions` |
| `field_external_classification` | entityreference | -1 | 0 | Other Classifications | `external_classification` |

### Access / collections (OG → Group module migration)
| field_name | type | req | label | notes |
|---|---|---|---|---|
| `group_content_access` | (OG) | **1** | Visibility | OG access — Phase 1b auth concern |
| `field_og_collection_ref` | (OG) | 0 | Collection | OG membership → Group relationship |

### Descriptive metadata
`field_image_type` (list_text, req), `field_image_color` (list_text, req),
`field_image_quality` (list_text, req), `field_image_rotation` (list_integer, req),
`field_keywords` (text), `field_spot_feature` (text), `field_physical_size` (text),
`field_image_materials` (text_long), `field_image_enhancement` (text_long),
`field_image_notes` (text_long), `field_general_note` (text),
`field_technical_notes` (text_long), `field_classification_notes` (text_long),
`field_admin_notes` (text_long), `field_private_note` (text),
`field_image_digital` (list_boolean), `field_noise_reduction` (list_boolean).

### Rights / provenance
`field_copyright_holder` (text), `field_copyright_date` (datetime),
`field_copyright_statement` (text_long), `field_license_url` (url, **req**),
`field_rights_notes` (text_long), `field_organization_name` (text),
`field_project_name` (text), `field_sponsor_name` (text).

### Geo
`field_latitude` (text), `field_longitude` (text), `field_altitude` (number_float),
`field_spot_feature` (text).

### Camera / EXIF (mostly admin-hidden)
`field_aperture`, `field_exposure_bias`, `field_focal_length` (number_float),
`field_iso_speed_rating` (number_integer), `field_lens`, `field_light_source`,
`field_metering_mode`, `field_sensing_method`, `field_flash_settings`,
`field_image_capture_device` — all single-value text/number.

### Satellite type fields
- **image_agent:** `field_agent_role` (list_text), `field_agent_place` (KMaps, -1),
  `field_agent_dates` (datetime), `field_agent_dates_approx` (text), `field_agent_notes` (text_long).
- **image_descriptions:** `field_description` (text_long), `field_summary` (text),
  `field_author` (text, -1), `field_language` (KMaps, search root 301).
- **external_classification:** `field_external_class_scheme` (entityref →
  `external_classification_scheme`), `field_external_class_id` (text), `field_external_class_note` (text_long).

## What this audit establishes (ready to scope Step 1a)
- The D11 `shanti_image` content type field list, types, cardinalities, and required flags.
- The full entity graph: three satellites referenced directly from `shanti_image`
  (`image_agent` **required**, `image_descriptions`, `external_classification`) plus the
  nested `external_classification_scheme` lookup — all five in scope for Step 1a.
- 4 KMaps fields confirmed → directly reuse the Spike 1 field type; map to kmassets
  `subjects`/`places`/`terms` (+ a collections root).
- The IIIF/MMS identity linkage (`shanti_images` table, `i3fid`, `mmsid`, `field_other_ids`)
  that must be preserved.

## What this audit does NOT establish (still open)
1. **IIIF wiring details (not a strategy decision).** The IIIF *server* decision is made
   (stays as-is, above), so what remains is implementation: confirm the existing IIIF
   upload/view/delete endpoints and credentials are reachable from D11, and port the D7
   upload/display path (`shanti_images_*_url` settings, the `shanti_image_formatter`) to
   D11. No new IIIF stack and no `shanti-image-NNN` identifier change.
2. **OG → Group migration mapping.** `group_content_access` (Visibility) and
   `field_og_collection_ref` depend on the collections architecture (Spike 3, still partial)
   and the proxy-auth contract (ADR 009 Step 1b). Out of scope for Step 1a public plumbing.
3. **JSON/AJAX API parity.** `shanti_images_node_json()` (`api/json/{nid}`) and the
   `api/*` family are Phase 5; documented in the module README, not re-derived here.
4. **`list_*` allowed-values inventory.** The exact option sets for `field_image_type`,
   `field_image_color`, `field_image_quality`, `field_image_rotation` were not extracted —
   pull from the field_base before building those fields.

## Recommended next step
Both modeling decisions are settled: ADR 008 scope by
[ADR 010](../adr/010-adr-008-scope-clarification.md) (remodeling permitted), and the
satellite graph collapses to **Paragraphs** (Images-only; above). Ready to scope the
ADR 009 **Step 1a** slice:

- Build the D11 `shanti_image` content type with the three satellites as **paragraph
  types** (agents / descriptions / classifications), `external_classification_scheme` as a
  **taxonomy vocabulary** referenced from the classification paragraph, and the 4 KMaps
  fields wired to kmassets.
- Wire image display to the **existing** IIIF server (a known quantity, not a decision);
  the remaining IIIF risk is wiring (open item #1) — verify D11 can reach the endpoints
  and port the upload/display path.
- Write the D7 → D11 migration as a node→paragraph transform for the satellites, in the
  dependency order above; defer OG/auth to Step 1b.

A near-term follow-up: enumerate the `list_*` allowed-values (open item #4) before
building those fields.
