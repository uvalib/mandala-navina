# AV Pre-Work Audit: `mediabase`/`kaltura`/`transcripts` D7 Content Model

**Audience:** Developers (Sprint 2 Workstream C1 — audit only, no migration code)
**Date:** 2026-09-01
**Source:** Legacy D7 custom modules `mediabase` and `transcripts`, contrib module
`kaltura`, and the vendored `KalturaClient` PHP SDK
(`mandala-drupal/docroot/sites/all/{modules/custom/mediabase,modules/custom/transcripts,modules/contrib/kaltura,libraries/KalturaClient}`)
**Relates to:** [ADR 009](../adr/009-migration-sequencing-strategy.md) (AV is sequenced
last, "hardest, last"), [Sprint 2 backlog](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-c--content-model-audits-av-sources-texts--audit-only)
(C1), [Images Content-Model Audit](images-content-model-audit.md) (methodology template
and point of comparison throughout this doc)

> **Scope.** This is a data/field/entity-graph inventory only. No migration code, no
> `mandala_migrations` scaffolding, and no D11 content-type is created as part of this
> audit — per the Sprint 2 backlog's explicit constraint on Workstream C.

---

## Purpose

Inventory the real D7 field model behind the AV site so a future D11 `av`/`audio_video`
content-type decision can be scoped from data, not guesswork — the same purpose the
Images audit served for Workstream B, now for the site ADR 009 sequences last. AV has no
single owner module (unlike Images' `shanti_images`): the model is split across a custom
metadata/workflow layer (`mediabase`), an externally-hosted-media integration (contrib
`kaltura` + vendored `KalturaClient`), and a separate transcript-timing module
(`transcripts`) — which is itself the first open modeling question this audit surfaces.

## Key finding: two content types (`audio`, `video`), Kaltura-hosted media, PBCore metadata as embedded field_collections — not referenced nodes

AV nodes are plain Drupal 7 nodes of bundle `audio` or `video`, defined by a Features
export (`mediabase/features/audio_video`) that is a sub-component of the `mediabase`
module family, not `mediabase.module` itself. The two bundles are near-identical — they
differ only in which single field holds the media reference (`field_audio` vs.
`field_video`); every other field is shared.

**The `mediabase` module family** (all under `modules/custom/mediabase/`) is a set of
cooperating submodules, none of which owns a bare DB table or custom entity the way the
prior team note assumed:
- `mb_metadata` — form-alter/view/index glue (auto-sets node title from PBCore, strips
  internal workflow fields from the Solr index)
- `mb_structure` — OG collection/subcollection helper functions, plus historical
  `hook_update_N` data-repair migrations (see Open questions #6)
- `mb_access` — a **custom node-access-grants layer** on top of stock OG (see Access
  section below)
- `mb_kaltura` — the AV-specific Kaltura import/sync glue (see Kaltura section below)
- `mb_services`, `mb_solr` — JSON services endpoints and Solr indexing customization

**Contrast with Images:** Images' satellite metadata (agents, descriptions,
classifications) are separate **referenced nodes** via Inline Entity Form — real,
independently-existing entities that had to be collapsed to Paragraphs (see the Images
audit's central decision). AV's PBCore metadata is instead built entirely from
**`field_collection` items** — a D7 primitive that is *already* embedded/owned by the
parent node (no independent node ID, no independent access, cascades on node delete).
**This makes AV's D11 target-model question structurally easier than Images': the D7
shape is already close to Paragraphs**, rather than requiring a node→Paragraph collapse
decision. This is not a decision made here — see Open questions #3 — but it is a
materially different (and lower-risk) starting point than Images had.

## The entity graph

```
audio | video (node)
├── field_audio | field_video (req, card 1)     → field_kaltura_entryid (scalar Kaltura entry-id string)
├── field_thumbnail_image                        → image
├── field_pbcore_title (req, -1)                 → field_collection (title / title_type / language)
├── field_pbcore_description (-1)                 → field_collection
├── field_pbcore_creator / _contributor / _coverage
│   / _relation / _publisher / _sponsor
│   / _identifier / _extension (-1 each)          → field_collection
├── field_pbcore_instantiation (card 1)            → field_collection, ~25 technical/instantiation sub-fields
│                                                     (duration, frame_rate, bit_depth, encoding_scheme, …)
├── field_workflow (card 1)                        → field_collection (cataloging/media QA state, admin-only,
│     ├── field_transcript_workflow_notes            stripped from Solr index by mb_metadata)
│     └── field_catalog_workflow_notes              → each its own nested field_collection
├── field_kmap_annotation (-1)                      → field_collection (KMaps term + annotation position)
├── field_transcript                                → file (transcript document)
├── field_recording_location_new / field_subject
│   / field_kmap_terms                              → shanti_kmaps_fields_default (same KMaps pattern as Images)
├── field_tags                                      → taxonomy_term_reference
├── field_og_collection_ref                         → OG group_audience (membership in collection/subcollection)
└── group_content_access (req)                      → OG Visibility
```

**Where Kaltura and transcripts attach:** `field_audio`/`field_video` hold only the
Kaltura **entry ID** (a scalar string) — the actual media file lives entirely on
Kaltura's hosted service, mediabase never duplicates it. `mb_kaltura.module` sits between
the node and Kaltura as an **import/sync layer**: it auto-populates PBCore fields from
Kaltura entry metadata on import and pushes PBCore metadata back to Kaltura on save (per
its own docblock, it "creates Mediabase node types (video, audio, image) when importing
entries from Kaltura"). Transcripts attach to the **same audio/video node**, not a
separate entity: `field_transcript` (a file field) holds the transcript document itself,
while the `transcripts` module keeps its own tier/timing table
(`transcripts_apachesolr_transcript`, referenced in `mediabase.module:577`) keyed by node
ID — `mb_kaltura_transcripts_apachesolr_add_transcript()`/`_delete_transcript()` index
that timing data against the node via `apachesolr`.

## Field inventory (shared across `audio`/`video`, ~60 fields including nested `field_collection` sub-fields)

### Media / identity
| Field | Type | Card | Required | Notes |
|---|---|---|---|---|
| `field_audio` (audio only) | `field_kaltura_entryid` | 1 | **yes** | cardinality hard-locked to 1 by the field module itself |
| `field_video` (video only) | `field_kaltura_entryid` | 1 | **yes** | same |
| `field_thumbnail_image` | image | 1 | no | poster/thumb |
| title | node title | — | — | **hidden field**; auto-set from `field_pbcore_title[0]` by `mb_metadata_validate_title()` |

### PBCore descriptive metadata (all `field_collection`, embedded)
| Field | Card | Required |
|---|---|---|
| `field_pbcore_title` | -1 | **yes** |
| `field_pbcore_description` | -1 | no |
| `field_pbcore_creator` | -1 | no |
| `field_pbcore_contributor` | -1 | no |
| `field_pbcore_coverage` | -1 | no |
| `field_pbcore_relation` | -1 | no |
| `field_pbcore_publisher` | -1 | no |
| `field_pbcore_sponsor` | -1 | no |
| `field_pbcore_identifier` | -1 | no |
| `field_pbcore_extension` | -1 | no |
| `field_pbcore_rights_summary` | — | no |

Each has its own sub-fields inside its `field_collection` bundle (e.g. `field_pbcore_title`
→ `field_title`/`field_title_type`/`field_language`; `field_pbcore_creator` →
`field_creator`/`field_creator_role`; `field_pbcore_identifier` →
`field_identifier`/`field_identifier_source`).

### PBCore technical/instantiation metadata (`field_pbcore_instantiation`, card 1)
One embedded field_collection with ~25 sub-fields: `field_alternate_modes`,
`field_aspect_ratio`, `field_bit_depth`, `field_channel_conf`, `field_colors`,
`field_data_rate`, `field_date_created`, `field_date_issued`, `field_digital_format`,
`field_duration`, `field_encoding_scheme`, `field_file_size`, `field_format_standard`,
`field_frame_rate`, `field_frame_size`, `field_generations`, `field_language`,
`field_media_type`, `field_pbcore_annotation`, `field_pbcore_date_available`,
`field_pbcore_format_id`, `field_physical_format`, `field_sampling_rate`,
`field_start_time`, `field_track_data`.

### Cataloging/media workflow (`field_workflow`, card 1, admin-only)
Sub-fields: `field_basic_cataloging`, `field_cataloging_proofed`,
`field_extended_cataloging_new`, `field_media_needs_re_editing`,
`field_media_needs_recompression`, `field_media_problem_1/2/3`,
`field_masters_archived`, `field_edls_archived`, `field_audio_quality_acceptable`,
`field_video_quality_acceptable`, `field_timecoded`, `field_timecode_too_infrequent`,
`field_timecoding_problem_1/2`, `field_transcribed`, `field_place_data_present`,
`field_trans_proofed_lang_1/2/3`, `field_translation_input_lang_1/2`,
`field_translation_lang_3`, `field_translation_language_1/2`,
`field_transcript_workflow_notes` (nested field_collection),
`field_catalog_workflow_notes` (nested field_collection). `mb_metadata` explicitly
strips this whole group plus `field_transcript*` from the Solr index — internal state,
not public content.

### Transcript / caption
| Field | Type | Card | Required |
|---|---|---|---|
| `field_transcript` | file | 1 | no |
| Timing/tier data | separate `transcripts_apachesolr_transcript` table, keyed by node ID (not a field) | — | — |

### Subject / discovery (KMaps — same pattern as Images)
| Field | Type | Card |
|---|---|---|
| `field_kmap_terms` | `shanti_kmaps_fields_default` | -1 |
| `field_recording_location_new` | `shanti_kmaps_fields_default` | -1 |
| `field_subject` | `shanti_kmaps_fields_default` | -1 |
| `field_kmap_annotation` | field_collection (`field_tid`, `kmap_id`, `field_annot_*`) | -1 |
| `field_tags` | taxonomy_term_reference (`tags` vocab) | -1 |

### Access / collections
| Field | Type | Required |
|---|---|---|
| `field_og_collection_ref` | OG group_audience | no |
| `group_content_access` | OG Visibility | **yes** |

### Rights / provenance / misc
`field_license`, `field_copyright_owner`, `field_available_from`, `field_location`,
`field_year_published`, `field_subcollection_new`, `field_rating` (fivestar), plus Flag
features (`editors_pick`, `favorites`, `loanable`).

## Kaltura integration

- **Field type:** `contrib/kaltura/plugins/field_kaltura` provides field type
  `field_kaltura_entryid`, default widget `all_media`, default formatter
  `field_kaltura_player_default` (a Kaltura player embed). Instance settings (player
  dimensions, thumbnail size, `entry_widget`, delivery type) are presentation config
  riding on the field, not separate entities.
- **SDK:** the vendored `libraries/KalturaClient` PHP SDK — a dependency only, not
  inventoried here.
- **Config:** partner ID / admin secret / secret / sub-partner ID are stored as Drupal
  variables at runtime (`kaltura_partner_id`, `kaltura_admin_secret`, `kaltura_secret`,
  `kaltura_subp_id`), not in code — no secret values are in the codebase. `mb_kaltura`
  additionally hardcodes two operational IDs: `METADATA_PROFILE_ID = 2631` and
  `MB_MAIN_PLAYER_ID = 24762821` — Kaltura-side profile/player IDs a D11 rebuild would
  need to re-provision or confirm are still valid on whatever Kaltura account serves D11.
- **Customized ingestion:** `mb_kaltura_menu_alter()` overrides the base contrib
  module's own "Import Kaltura Items" UI because, per its own code comment, the stock
  import mechanism is broken — AV runs a customized Kaltura ingestion path, not stock
  contrib behavior. This is functional/module-layer work (per the 2026-08-25 theme audit's
  finding that Kaltura lives at the module layer, not the theme layer), separately tracked
  as **Spike 7 (○ Pending)** — this audit does not re-scope that spike, only confirms
  where the customization lives.

## Access / collections pattern

**Same base OG pattern as Images** — `field_og_collection_ref` and `group_content_access`
(required) mirror Images' fields exactly, and AV nodes join `collection`/`subcollection`
nodes from the shared `shanti_collections` module.

**AV additionally layers a custom node-access-grants realm** that Images' audit does not
mention: `mb_access.module` implements `hook_node_grants()`/`hook_node_access_records()`
with two realms:
- `group_access_uva_member` — grants view access to any authenticated user recognized as
  a UVA community member
- `mb_collection_admin` — grants view/update/delete to any user with
  `og_user_access('node', $nid, 'administer group')` on the relevant
  `collection`/`subcollection` node

**This is a materially more elaborate access model than Images.** A straight port of
Images' OG→D11-Group access mapping (Phase 1b/ADR-009-Step-1b concern) would miss the
UVA-member and collection-admin grant realms — flagged as an input to that future work,
not resolved here.

## Data profile — NOT AVAILABLE (dump corrupted)

Unlike the Images and Texts audits, this audit has **no production-data validation**.
Both copies of the AV dump found in this environment are unusable:

- `~/Sandbox/Mandala/data/mandala-prod-av-db_2026-06-11.sql.gz` (1.7GB) — fails
  `gzip -t` integrity check (`unexpected end of file`, truncated mid-stream). Attempting
  to load it into a DDEV `d7_av` database confirmed the same: `gzcat` aborted partway and
  fed a broken SQL statement to MySQL (`ERROR 1064 ... at line 28909`, a corrupted line
  from a serialized-PHP blob, consistent with a mid-stream truncation, not a schema
  problem).
- `~/Sandbox/Mandala/mandala11/data/mandala-prod-av-db_2026-06-11.sql.gz` — despite the
  identical filename (initially assumed to be a duplicate), this is a **different, much
  smaller file** (24.8MB vs. 1.7GB) and is also gzip-corrupt.

**No AV dump obtained out-of-band via S3/shared drive was checked here either** — only
what already existed locally. Every finding above (content types, fields, entity graph,
Kaltura integration, access model) is derived entirely from **static analysis of the D7
module code**, not validated against real rows. That means, unlike Images:
- Real `audio`/`video` node counts are unknown.
- Field fill-rates (e.g. how often `field_thumbnail_image`, `field_transcript`,
  `field_pbcore_description` are actually populated) are unknown.
- Whether Open question #6's corrupted legacy columns
  (`field_extended_cataloging`/`field_translation_lang_1/2`, pre-repair) still exist in
  the data is unconfirmed.
- The field_collection→Paragraphs fit (the audit's central structural recommendation)
  is a code-level judgment, not data-validated the way Images' agent-reuse or
  required-field cleanliness was.

**Recommended before any AV modeling decision is finalized:** obtain a fresh, verified
AV production dump (re-export or re-copy, then `gzip -t` it before use) and re-run this
audit's data-profile section — the same way the Images audit validated its Paragraphs
choice against real agent-reuse numbers before that decision was made.

## What this audit establishes

**All findings below are derived from static analysis of the D7 module code only — no
production dump was usable for validation.** See
[Data profile](#data-profile--not-available-dump-corrupted).

- AV is **two node bundles** (`audio`, `video`), not a custom entity or a
  `mediabase`-owned DB table — the prior team note ("mb_metadata/mb_structure" as schema
  owners) was incorrect; neither module defines `hook_schema()` or `hook_node_info()`.
  The bundles are defined by a Features export sub-component of `mediabase`
  (`mediabase/features/audio_video`).
- The full field list, types, cardinalities, and required flags for both bundles
  (identical except the single media field).
- The entity graph: PBCore descriptive/technical metadata and cataloging workflow state
  are all embedded `field_collection` items (not referenced nodes) — structurally closer
  to Paragraphs already than Images' as-found D7 shape was.
- The Kaltura integration boundary: `field_audio`/`field_video` hold only a scalar entry
  ID; `mb_kaltura` is a customized import/sync layer, not a stock contrib integration.
- Transcripts attach to the same node (file field + a separate timing table keyed by
  node ID), not a separate entity.
- AV's access model is OG plus **two additional custom grant realms**
  (`group_access_uva_member`, `mb_collection_admin`) not present in Images' model.

## What this audit does NOT establish (still open)

1. **Whether `audio` and `video` collapse to one D11 content type** with a media-kind
   field, or stay as two types for parity with D7. Not decided here.
2. **The D11 target model for the PBCore/workflow field_collections** — almost certainly
   Paragraphs given the structural fit noted above, but that is a recommendation for a
   future modeling decision, not a decision made by this audit (per ADR 010's caveat that
   each site's remodeling choice is judged on its own merits, with no precedent set by
   Images' choice).
3. **Kaltura hosting/re-provisioning strategy for D11** — whether D11 points at the same
   Kaltura account/partner ID, and whether `METADATA_PROFILE_ID`/`MB_MAIN_PLAYER_ID` are
   still valid, is unconfirmed. Overlaps Spike 7 (○ Pending), not resolved here.
4. **Transcripts module's own D11 fate** — the `transcripts_apachesolr_transcript` timing
   table's schema was not inventoried (owned by the separate `transcripts` module); its
   D11 target model is unaddressed.
5. **OG → D11 Group mapping for the two custom access realms** — `group_access_uva_member`
   and `mb_collection_admin` need an explicit mapping decision; deferred to the
   ADR-009-Step-1b-equivalent access work for AV, same as it was for Images.
6. **Corrupted-field history.** `mb_structure_update_7003`/`7004` document that
   `field_extended_cataloging` and `field_translation_lang_1/2` were corrupted in
   production and ported to renamed replacement fields
   (`field_extended_cataloging_new`, `field_translation_input_lang_1/2`). The old
   corrupted columns/values may still exist in a fresh dump — **do not migrate them**;
   unconfirmed either way since no usable dump was available for this audit (see
   [Data profile](#data-profile--not-available-dump-corrupted)).
7. **No production-data validation at all** (see Data profile section) — every
   structural finding in this audit is static-code-derived, not row-verified.

## Recommended next step

No modeling decision is made here — per the Sprint 2 Workstream C scope, this audit
stops at data/field/entity-graph facts. When AV's turn comes up in the migration
sequence (ADR 009 sequences it last, after Texts/Sources), the recommended starting
point is:
- Confirm the `field_collection`→Paragraphs fit (open question #2) as an explicit
  scope note, the same way [ADR 010](../adr/010-adr-008-scope-clarification.md) was
  used for Images — expect this to be a shorter discussion than Images' node→Paragraph
  case, since no independent-node collapse is involved.
- Resolve the Kaltura re-provisioning question (open question #3) with whoever owns the
  UVA Library's Kaltura account before Spike 7 is picked back up.
- Fold the two custom access realms (open question #5) into whatever proxy-auth/access
  design work Phase 1b/2's OG→Group mapping produces, rather than treating AV's access
  model as a copy of Images'.
