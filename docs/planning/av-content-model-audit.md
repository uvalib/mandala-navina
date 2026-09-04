# AV Pre-Work Audit: `mediabase`/`kaltura`/`transcripts` D7 Content Model

**Audience:** Developers (Sprint 2 Workstream C1 — audit only, no migration code)
**Date:** 2026-09-01
**Source:** Legacy D7 custom modules `mediabase` and `transcripts`, contrib module
`kaltura`, and the vendored `KalturaClient` PHP SDK
(`mandala-drupal/docroot/sites/all/{modules/custom/mediabase,modules/custom/transcripts,modules/contrib/kaltura,libraries/KalturaClient}`)
**Relates to:** [Migration Complexity Comparison](av-sources-texts-migration-complexity-comparison.md)
(scores this site against Sources/Texts), [ADR 009](../adr/009-migration-sequencing-strategy.md) (AV is sequenced
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
  - **Correction 2026-09-04 (Spike 7, verified against the 2026-09-01 production
    dump):** `all_media` is the field type's *declared default* widget, **not what the
    production instances use** — `field_video` is configured with
    `field_kaltura_video` and `field_audio` with `field_kaltura_audio` (the
    type-specific "Video only" / "Audio only" widgets). All widget types route through
    the same `kaltura_widget_hendler()`, so behaviour is unchanged, but a migration
    should be built against the actual instance values.
  - **Also 2026-09-04: `entry_widget` (the Kaltura player `uiconf_id`) is not a single
    value.** The video field's display settings carry **31832371** (the same id the
    React `audiovideo.js` hardcodes) and a second formatter on both fields carries
    **48501** (contrib's own default), while the live D7 node-view embed observed in
    Spike 6 used **24762821**. Partner id is consistently `381832`. Whichever player
    D11 adopts has to be chosen per view mode, not assumed to be one id.
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
  as **Spike 7** — this audit does not re-scope that spike, only confirms
  where the customization lives.
  - **Expanded 2026-09-04 (Spike 7): there are TWO ingest paths, not one.** The
    admin import page above is only half of it. The **video/audio node form itself
    uploads new media files to Kaltura** via the field widget's chunked-upload modal
    (`kaltura/nojs/chunked-upload/…`), with `kaltura_chunked_uploader` confirmed
    enabled in production and `add_existing = 0` on both field instances — so on the
    node form that button is *exclusively* an uploader, with no browse-existing
    option. `mb_kaltura` actively supports this with a dedicated
    `mb_kaltura/upload-keepalive` route and `keepalive.js` on both node forms to keep
    sessions alive through long uploads. Relevant to migration only indirectly, but
    central to post-cutover authoring: D11's `kaltura_media` has no upload capability
    at all. See [Spike 7](../spikes/spike-07-kaltura-av-integration.md).

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

## Data profile (production dump, 2026-09-01)

**Update 2026-09-01 (same day, later in the session):** the original 2026-06-11 dump
was corrupted (see below for the incident record); Than re-exported a fresh dump
(`mandala-prod-av-db_2026-09-01.sql.gz`, 186MB — much smaller than the corrupted
1.7GB file, consistent with the earlier file being a bad/inflated export rather than a
merely-truncated copy of the same size). **`gzip -t` passed** — loaded into DDEV's
`d7_av` database and profiled successfully.

**Node counts:** 7,396 `video` · 4,187 `audio` (11,583 total) · 152 `collection` · 85
`subcollection` · 4 `page` · 1 `source`. Also **68 nodes with a literal, corrupted
`MISSING_TYPE` bundle value** in the raw `node.type` column (not a display artifact —
confirmed via direct query) — a real, small data anomaly not previously known, flagged
as a new open question (#8 below), not investigated further here.

**The same `og_membership`-vs-field-storage bug found on Sources also affects AV.**
`field_data_field_og_collection_ref` is **empty (0 rows)** for AV despite being a real
`field_sql_storage` field, exactly like Sources. The actual collection-membership data
lives in `og_membership`: 11,587 node memberships tagged `field_og_collection_ref`
(covering essentially all 11,583 audio/video nodes) plus 85 subcollection→collection
memberships tagged `field_og_parent_collection_ref` (matching the 85 subcollection
count exactly). **Confirms the AV audit's original prediction** ("worth double-checking
against the actual dump once loaded," open question #2 in the prior draft) and means
the same migration-source-plugin fix Sources needs — read `og_membership`, not the
Field API value table — applies here too.

**Required/near-required fields: clean.**
- `field_video_entryid` (Kaltura entry ID): 7,379 of 7,396 video nodes (99.8%) have a
  value; 17 missing despite the field being required at the form level — a small
  cleanup population for any pre-migration remediation.
- `field_audio_entryid`: 4,186 of 4,187 audio nodes (99.98%) have a value; 1 missing.
- `field_pbcore_title` (required, -1 cardinality): 11,583 of 11,583 nodes (100%) have
  at least one title — 0 missing, matching Images' clean-required-field pattern.
- `group_content_access` (required OG Visibility): 11,583 of 11,583 (100%) filled — no
  gap here, unlike Sources' 91.2%.

**Optional-field coverage:**
- `field_workflow` (cataloging/QA state): 11,465 of 11,583 nodes (99.0%) have it — 118
  missing.
- `field_transcript`: 5,380 of 11,583 nodes (46.4%) have an attached transcript file —
  just under half, a real gap worth knowing before scoping any transcript-dependent UI.
- `field_kmap_terms`: 2,192 field-value rows; `field_subject`: 16,999 rows — KMaps
  tagging is present but, as with Sources, not universal (exact per-node coverage not
  separately computed here since both fields are unlimited-cardinality).

**Open question #6 (corrupted-field history) — resolved with real numbers, not just a
code-level warning.** The old corrupted columns genuinely still hold data in
production, confirming they must be excluded from any migration:

| Field | Row count |
|---|---|
| `field_extended_cataloging` (old, corrupted) | 2,482 |
| `field_extended_cataloging_new` (replacement) | 8,508 |
| `field_translation_lang_1` (old, corrupted) | 2,482 |
| `field_translation_lang_2` (old, corrupted) | 2,482 |
| `field_translation_input_lang_1` (replacement) | 8,715 |
| `field_translation_input_lang_2` (replacement) | 8,715 |

The old/corrupted fields hold exactly 2,482 rows each (a smaller, frozen subset —
consistent with them having been abandoned in place rather than actively cleaned up
after the repair). **Do not migrate the old-named fields**; use only the `_new`/
`_input_lang` replacements.

### Corrupted-dump incident record (2026-06-11 file)

The original audit pass this session (before the re-export) found both local copies of
the AV dump unusable:
- `~/Sandbox/Mandala/data/mandala-prod-av-db_2026-06-11.sql.gz` (1.7GB) — failed
  `gzip -t` (`unexpected end of file`); a load attempt confirmed `gzcat` aborting
  mid-stream and feeding a truncated line into MySQL.
- `~/Sandbox/Mandala/mandala11/data/mandala-prod-av-db_2026-06-11.sql.gz` — same
  filename but a different, much smaller (24.8MB) file, also gzip-corrupt.

Filed as [`av-dump-corrupted-no-data-profile.md`](../deferred/av-dump-corrupted-no-data-profile.md)
at the time; **now resolved** by the 2026-09-01 re-export above — see that note for
closure.

**A THIRD corrupt AV dump found 2026-09-04** (Spike 7 session, on Yuji's machine):
`~/mandala-prod-av-db_2023-12-05.sql.gz`, 219MB, fails `gzip -t` with "unexpected end
of file"; streaming it reaches the `cache*` tables and dies before `system` /
`field_config_instance`. Different file, different date, same failure mode as the two
2026-06-11 copies. Three for three on pre-2026-09-01 AV dumps — worth treating as a
pattern in how these were produced/transferred rather than three coincidences, and a
reason to always `gzip -t` an AV dump before trusting a negative finding drawn from
it. The 2026-09-01 re-export remains the only known-good copy.

## What this audit establishes

**Structural findings are code-derived; the items below marked with counts are now
validated against the real 2026-09-01 production dump** (see
[Data profile](#data-profile-production-dump-2026-09-01)).

- AV is **two node bundles** (`audio`, `video` — 7,396 and 4,187 respectively), not a
  custom entity or a
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
- **Data-verified:** collection membership is not readable from
  `field_data_field_og_collection_ref` (0 rows) — it lives in `og_membership`, the same
  bug independently found on Sources the same session. Required fields
  (`field_pbcore_title`, `group_content_access`) are 100% clean; the two Kaltura
  entry-id fields are >99.7% filled; the old corrupted cataloging/translation fields
  still hold 2,482 rows each in production and must be excluded from migration.

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
6. ~~**Corrupted-field history.**~~ **RESOLVED (2026-09-01).** `mb_structure_update_7003`/
   `7004` document that `field_extended_cataloging` and `field_translation_lang_1/2`
   were corrupted in production and ported to renamed replacement fields. Confirmed
   against the real dump: the old corrupted fields still hold 2,482 rows each — **do
   not migrate them**; use only `field_extended_cataloging_new`/
   `field_translation_input_lang_1/2`. See [Data profile](#data-profile-production-dump-2026-09-01).
7. ~~**No production-data validation at all.**~~ **RESOLVED (2026-09-01)** — a fresh,
   verified dump was loaded and profiled; see Data profile.
8. ~~**68 nodes carry a literal corrupted `MISSING_TYPE` bundle value.**~~
   **ROOT-CAUSED (2026-09-04, Spike 7's upload/ingest investigation).** Not
   corruption from an unknown source — `mb_kaltura.inc`'s `create_node_mediabase()`
   (the Kaltura-entry-import function) maps `$entry->mediaType` to a bundle
   (`KalturaMediaType::VIDEO` → `video`, `::AUDIO` → `audio`) and falls back to the
   **literal string `'MISSING_TYPE'`**, saved onto the node with no validation and no
   abort, whenever an imported Kaltura entry's media type doesn't cleanly map to
   VIDEO/AUDIO — most likely Kaltura `IMAGE` entries or entries with an unset/
   unexpected type, imported through the same admin page as real audio/video
   content. These are real nodes an editor genuinely created via the normal import
   workflow, not database corruption — still needs a disposition decision before
   migration (exclude, or repair to a real bundle by checking the underlying
   Kaltura entry's actual type), but the "is this corruption or a real data shape"
   question is now answered. See [Spike 7](../spikes/spike-07-kaltura-av-integration.md)
   for the full code-level finding.

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
- **Build the collection-membership migration source against `og_membership`, not
  `field_data_field_og_collection_ref`** — confirmed empty here exactly as it was on
  Sources; the same fix applies to both sites.
- Investigate the 68 `MISSING_TYPE` nodes (open question #8) before they can silently
  fall out of any migration source query filtered by known bundle names.
