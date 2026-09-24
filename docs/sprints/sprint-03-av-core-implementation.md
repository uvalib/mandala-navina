# Sprint 3: AV core implementation (`audio`/`video`, Kaltura, access, collections)

**Status:** ◐ **In progress — started 2026-09-08.** AV2, AV3, AV4, AV5, AV6, AV8, AV9,
AV13, AV14, AV15 and AV16 done (see the [AV2 scope note](../planning/av-content-type-decision.md), the
[AV3 paragraph model](../planning/av-paragraph-model.md), the
[AV4 migration notes](../planning/av-node-migration-notes.md) and the
[AV5/AV14 disposition note](../planning/av-anomalous-node-dispositions.md)); the full AV
corpus migrates and is verified locally (PR #193, ready for review). **Two tracks remain
deferred as of 2026-09-15 until Than is back (now Wednesday 2026-09-24, pushed from
Monday)**: the upload track (AV11 → AV12) and AV7's remaining two access realms — the
latter because Than understands the D7 OG/Group access requirements best, not a
technical blocker either. **Everything else solo-workable in Sprint 3 is now done** —
AV4's live dev-0 run finished 2026-09-10 (5h04m49s, 24/24 checks verified); AV8's live
`kmassets:index-all`/`kmassets:audit` run finished 2026-09-14, index fully in sync (0
missing/orphaned/stale across all 122,921 published nodes). **AV7's core gap closed
2026-09-14** (see its row) — AV content became visible in Drupal at all, which is what
surfaced that AV6/AV9's remaining work was display-layer, not data-layer: the data was
already fully migrated and indexed, it just had nothing telling Drupal how to render it.
**AV6 closed the same day, and AV9 (player formatter + gallery variant) closed the same
day too** — KMaps fields render with the same popover UX Images has, a migrated node
plays back correctly, and both the per-collection and site-wide (`/av`, AV16) AV
galleries render real cards with Kaltura-derived thumbnails. **AV15 fully closed
2026-09-17/18** (see its row) — the technical-metadata accordion now matches D7's real
production shape end to end, including Availability & Access, Details' KMaps fields,
the collection link, multi-language descriptions, and duration; two real migration bugs
found and fixed corpus-wide along the way.
**No longer blocked on [Spike 7](../spikes/spike-07-kaltura-av-integration.md)** — the
spike's migration-source-plugin item is satisfied by AV4; its remaining open item
(upload/ingest) lives in AV10–AV12, and its packaging work
(`drupal/kaltura_media` 1.0.4 + two patches) already landed inert on `main`.
**Phase:** [Roadmap](../roadmap.md) Phase 3 (AV) — reordered ahead of strict "last"
sequencing by [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md).
**Lead:** Yuji Shinozaki, per ADR 018.
**Mode:** Individual, following the pattern Sprint 1 (mob) established and Sprint 2's
Workstream B/D (individual-led) already replicated.
**Relates to:** [ADR 009](../adr/009-migration-sequencing-strategy.md) (AV's risk
analysis — unrevised), [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md)
(why AV starts now, and why it splits into two sprints), [AV Content-Model Audit](../planning/av-content-model-audit.md),
[AV/Sources/Texts Migration Complexity Comparison](../planning/av-sources-texts-migration-complexity-comparison.md)
(AV scored hardest, 3.8/5), [Spike 7](../spikes/spike-07-kaltura-av-integration.md),
[Sprint 1](sprint-01-images-implementation.md) (methodology precedent), [Sprint 2](sprint-02-theme-images-ui-and-endpoint-access.md)
(`shanti-thumbnail` component this sprint's UI reuses), **[Sprint 4](sprint-04-av-transcripts.md)
— depends on this sprint's content type existing; this sprint does NOT depend on Sprint 4.**

---

## Goal

Migrate AV (`audio`/`video`) content end-to-end on D11 — content type(s), Kaltura
playback, PBCore/workflow metadata, collections, access, and KMaps tagging — mirroring
the pattern Sprint 1 (Images) proved, **deliberately excluding the transcript
pipeline**, which is Sprint 4's job. `field_transcript` migrates as an inert file field
in this sprint (download-only, no TCU/XSLT processing) so nothing is lost, just
deferred to the sprint that depends on this one.

## Scope boundary

Inherited from [ADR 008](../adr/008-mvp-migrate-not-improve.md) /
[ADR 010](../adr/010-adr-008-scope-clarification.md): faithful migration of
*user-facing* behavior is the floor; internal data remodeling (e.g. `field_collection`
→ Paragraphs) is permitted where it reduces risk, per the same latitude Images used.

| In scope (Sprint 3) | Out of scope (Sprint 4 or later) |
|---|---|
| `audio`/`video` content-type decision (collapse to one bundle vs. keep two) | The TCU/XSLT transcript authoring pipeline and D11 transcript data model |
| PBCore/workflow `field_collection`s → Paragraphs (structurally easier than Images' node→Paragraph case, per the audit) | Transcript viewer, scroll-sync, and search-within-transcript |
| Kaltura playback embed (Spike 7) | — |
| **User uploads to Kaltura from D11 (DECIDED 2026-09-04, Yuji): in scope.** D7 has an in-node-form chunked upload and `kaltura_media` has none, so this is a real build, not a config step. See AV10–AV12 and the design note below | Replicating D7's *admin batch import* page (attach already-existing Kaltura entries) — separate capability, not required for authoring parity; revisit if AV staff say they rely on it |
| Collection membership via `og_membership` (NOT `field_data_field_og_collection_ref`, confirmed empty — same bug as Sources) | Sub-subcollection nesting beyond what [ADR 011](../adr/011-group-collections-inheritance.md) already covers |
| OG → D11 Group access mapping, **including AV's two extra realms** (`group_access_uva_member`, `mb_collection_admin`) — materially more elaborate than Images' model | The React app's independent transcript viewer / `mandala-av` Solr core (deliberately out of scope, see Spike 11's scope note) |
| KMaps field wiring (same proven pattern as Images) + Solr/kmassets sync for the AV bundle(s) | Search **quality** improvements (same MVP boundary as every other site) |
| Collection/gallery UI reusing the `shanti-thumbnail` component (Sprint 2 B5) | Spike 6 (API/URL reconciliation) — the cutover gate, handled once per the roadmap, not per site |
| Excluding the old corrupted `field_extended_cataloging`/`field_translation_lang_1/2` fields (use the `_new`/`_input_lang` replacements) | — |
| Deciding disposition for the 68 `MISSING_TYPE` nodes before any migration source query filters by bundle name — root cause confirmed 2026-09-04 (Spike 7): `create_node_mediabase()`'s bundle-mapping fallback for non-video/audio Kaltura entries, not corruption | — |
| `field_transcript` migrated as a plain, inert file field (download-only) | Any processing of `field_transcript`'s content — Sprint 4 |

## Backlog

| | Task | Depends on | Status |
|---|---|---|---|
| AV1 | Spike 7 — Kaltura module landscape survey, playback prototype, upload/ingest assessment, partner/credential re-provisioning confirmation | — | ◐ (module survey + live playback prototype done 2026-09-04; upload/ingest + migration source plugin open) |
| AV2 | Content-type decision: one bundle with a media-kind field, or `audio`/`video` kept as two — scope note (ADR-010-style) | AV content-model audit (done) | ✅ **Done 2026-09-08** — **two content types, built from one shared field definition**; see the [AV2 scope note](../planning/av-content-type-decision.md) |
| AV3 | PBCore/workflow `field_collection` → Paragraphs modeling decision + build | AV2 | ✅ **Done 2026-09-08 — 15 paragraph types built and exported.** 1:1 with D7's field_collections except the three structurally-identical note collections, consolidated into one `av_workflow_note` referenced by three fields. 84 new field storages, 87 instances, 186 config files. Nesting verified live through both levels. See the [AV3 paragraph model](../planning/av-paragraph-model.md) |
| AV4 | Migrate API source plugins for `audio`/`video` nodes; collection membership sourced from `og_membership`; exclude old corrupted fields; `field_transcript` migrated inertly | AV1–AV3 | ✅ **Done 2026-09-09, verified on dev-0 2026-09-10 (24/24 checks, 5h04m49s live run, 0 failed stages).** PR #193 merged. 10 new migrations (group now 27): audio/video nodes, collections/subcollections as Groups, node + user memberships, URL aliases. `scripts/verify-av-migration.sh` checks 24 counts against the D7 source directly; **all 24 match**. Four bugs found and fixed, two silently losing data (a fetch-mode bug and a `sub_process` shape bug that created 6,814 paragraphs referenced by nothing). The **cardinality-1 language-layer conflict** on `field_pbcore_instantiation` (667 hosts) resolved exactly — measured that one item always strictly contains the other, so no data is lost either way; the wider `und`/`en` split confirmed a **data artifact** (stale content-type language flag), not a schema problem. **`uid: uid` maps nothing** in core's `d7_node` source (it is `node_uid`) — fixed here and in the pre-existing `d7_images_collections`/`subcollections`, root-causing the open [`entity:group --update` deferred note](../deferred/migrate-entity-group-update-mode-nulls-uid.md); all 111,340 migrated **Images nodes are owned by Anonymous** is a separate, larger, still-open [deferred issue](../deferred/images-node-authorship-not-migrated.md). The live dev-0 run landed right at the top of the 3.5–5h band the 09-08 estimate projected — real node rates (~102/min audio, ~92/min video) came in far below DDEV's local figures, confirming DDEV timings never transfer. Full detail, including the complete dev-0 stage-by-stage timing table, in the [AV4 migration notes](../planning/av-node-migration-notes.md) |
| AV5 | 68 `MISSING_TYPE` node disposition | — (can run in parallel with AV1–AV4) | ✅ **Done 2026-09-08 — EXCLUDE all 68.** Repair-to-real-type proved unavailable: the bundle's only field instance is `og_group_ref`, so the Kaltura entry ID was dropped at save time and there is nothing to repair from; `node_type` has no `MISSING_TYPE` row. All 68 are 2014/uid 1, titled with `.jpg` filenames, hold zero field data, and sit in the single admin triage collection "Admin: On Kaltura Not in Mediabase" (`2503`) — which survives regardless, holding 285 real AV nodes. nid list + rationale in the [AV5/AV14 disposition note](../planning/av-anomalous-node-dispositions.md) |
| AV6 | KMaps field *display* wiring (reuse Images pattern, already proven) | AV4 | ✅ **Done 2026-09-14.** Built `core.entity_form_display`/`core.entity_view_display` config for both bundles by taking Drupal's own computed defaults (via `entity_display.repository`, so every non-KMaps field keeps exactly the behavior it already had — none regress) and overriding only the 6 KMaps fields to `kmap_tree_picker` (form)/`kmap_popover_formatter` (view), matching `shanti_image`'s existing shape exactly. All other custom AV fields (`field_video`, `field_workflow`, the PBCore set, `field_transcript`, ...) land in `hidden` — that's not a regression, it's the honest current state: those fields were never given a display default (created via migration/config import, not Field UI), so this is the *first* deliberate rendering decision made about them, and building their real presentation is explicitly AV9's job, not AV6's. Verified live in DDEV against real migrated content (not just config review): rendered node 119716's `field_subject` through the popover formatter and confirmed real KMaps taxonomy data (e.g. "Tibetan Nangma Music", full ancestor path intact) renders correctly, no errors |
| AV7 | OG → D11 Group access mapping, including `group_access_uva_member` and `mb_collection_admin` | AV4 | ◐ **Core visibility gap closed 2026-09-14 (PR #201).** `shanti_image` had working group role permissions for its `group_node:shanti_image` content plugin; `audio`/`video` never got the equivalent grants when AV4 installed those plugins on `collection`/`subcollection` — Group's own permission model generates a plugin's permission *strings* automatically but grants them to nobody, and doesn't defer to `bypass node access`/`bypass group access`/`administer group` the way core's node access does. Verified live: this made every grouped AV node (7,333 of 7,395 `video` nodes) invisible to **everyone**, including a site administrator. Fixed by mirroring `shanti_image`'s exact permission shape onto `audio`/`video` across all 10 `collection`/`subcollection` role configs; `drush mandala:group-permission-audit [--fix]` (new, `mandala_group_inheritance`) makes the invariant self-healing for future bundles (Sources, Texts) without hardcoding a bundle name. **Remaining scope deferred 2026-09-15 (Yuji) — paused until Than is back (now Wednesday 2026-09-24, pushed from Monday).** The two AV-specific realms this row was originally scoped for, `group_access_uva_member` and `mb_collection_admin`, are unrelated to the gap just closed and still need their own mapping design — Than understands the D7 OG/Group access requirements best, so this waits for him rather than getting scoped solo. Not yet estimated with any confidence (see the effort-estimate discussion, 2026-09-15) — unlike AV6/AV9/AV10/AV13/AV15, nobody has read the D7 mechanism for these two realms yet. **Decided 2026-09-24 (Than, with Yuji):** (1) `mb_collection_admin` is dead -- zero `node_access` rows in the 2026-09-01 prod dump, its record-writing code removed 2015 (OG 2.x) -- **dropped from scope**; (2) `group_access_uva_member` = **any authenticated user**, copying D7 (`DRUPAL_AUTHENTICATED_RID`), not NetBadge-only; (3) unauthenticated users **never** see UVA-only items (listings and search included); (4) a node's own `group_content_access` (1 public / 2 private / 3 UVA only) **wins** over its collection's, and 0 "use group defaults" resolves from the collection's `group_access` (0 public / 1 private / 2 UVA) at access time -- a D7 node belongs to exactly one collection (D11 matches: every grouped AV node is in one group), cross-collection presence was asset links; (5) D7's quirk where a group-default node's public grant took the **collection's** published status is a bug to fix in D11, not copy; (6) D7's subcollection-specific access values were enforced, so the 22 restored by PR #248 are correct. Node-level labels corrected in PR #247. Remaining build: the UVA tier in Drupal access + the Solr `fq` token, and the group-default resolution |
| AV8 | Solr/kmassets sync wiring for the AV bundle(s) | AV4, AV6 | ✅ **Done 2026-09-14** — `audio`/`video` bundles wired into `mandala_kmassets_sync.settings` (both `service`/`asset_type: audio-video`, matched live against the ~8,579 real legacy-mediabase kmassets docs already indexed, not invented — see ADR 016 decision 3). `kmassets:index-all` run live on dev-0, scoped per-bundle (not the bare command, which would have needlessly re-indexed all 111,339 `shanti_image` docs): **4,187/4,187 audio, 7,391/7,395 video** on the first pass, ~830–890 nodes/min combined (~13–14 min total). Full unscoped `kmassets:audit --check-stale` across all 122,921 published nodes: **0 missing, 0 orphaned, 0 stale.** Surfaced (and fixed, PR #199) two pre-existing bugs never exercised by Images' all-English, single-service data: (1) `KmassetDocBuilder`'s `title_sort_s` used a byte-oriented `trim()` charlist that corrupted any title ending in a colliding UTF-8 continuation byte — crashed 4 real video nodes outright (Cyrillic/Chinese titles), likely silently truncated others; fixed with a Unicode-aware `preg_replace`. (2) `KmassetAuditor`'s orphan pass was unsafe to scope to a single bundle when it shares a `service` with another (`audio`/`video` both use `audio-video`) — `kmassets:audit audio --fix` would have deleted all of `video`'s docs as false orphans, and vice versa; fixed by widening the orphan-safety set to sibling bundles. Also fixed, in the same PR, a stale `groupKmassetUid()` bug hardcoding `images-` for every collection regardless of site — security-adjacent since it feeds ADR 014's proxy `fq`. Full writeup: [session log](../session-logs/2026-09-14-av8-kmassets-sync-and-two-bugs-found.md) |
| AV9 | UI: Kaltura player field formatter; collection-content gallery variant of `shanti-thumbnail` (generalizing Sprint 2 B5) | AV1, AV4, Sprint 2 B5 (done) | ✅ **Done 2026-09-15.** Player formatter built and verified same day (`mandala_kaltura`'s `KalturaConfiguredFormatter` + `mandala_kaltura_player` theme hook, PR #209/#210) — deliberately faithful to D7's *actual* embed (`field_kaltura_build_embed()`: `kWidget.embed()` only ever gets `targetId`/`wid`/`uiconf_id`/`entry_id`), verified live against a real video node (nid 119715, entry_id `1_jmvntv5j`). **Gallery variant done same day, PR #217:** new `collection_gallery_av` view (row `entity:node`/teaser) embeds `node--audio--teaser`/`node--video--teaser` shanti-thumbnail cards alongside the Images-only masonry gallery on a collection's page. Thumbnail: `audio`'s real `field_thumbnail_image` when present, else a Kaltura-derived URL (`KalturaConfigResolver::thumbnailUrl()`, ports `_kaltura_thumbnail_base_url()`) — no new field, no image style, no Media entity, matching the estimate. **Found and fixed two pre-existing bugs live-testing against a real AV-only collection** (172, zero prior exercise since every tested collection had real `shanti_image` members): `SiblingCarouselService::getCollectionMemberNids()` was hardcoded to `group_node:shanti_image` only (generalized with a `$pluginIds` param, sibling-carousel default behavior unchanged); `CollectionMembership::query()`'s empty-result fallback used the wrong Views API (`addWhere()` vs `addWhereExpression()`), 500'ing any collection with zero members of any migrated bundle. Verified live: group 172 (8,408 AV members, 0 images) renders correctly with pagination; group 31 (26,130 images) confirms the Images gallery and sibling carousel are unaffected |
| AV10 | **Kaltura configuration layer** — config-driven, extensible, covering the full element set inventoried below (players/`uiconf_id`, uploader widget ui_conf, delivery, player + thumbnail dimensions, rotate/stretch), selectable **per view mode** via formatter settings. Must carry the known values and accept new ones as config, no code change | AV1 | ◐ **Scaffolded and verified 2026-09-15** — see the [AV10 scope note](../planning/av10-kaltura-configuration-layer.md) for the full design and its revision history. New module `mandala_kaltura`: a `mandala_kaltura.settings` config object (site constants + a keyed `presets` map, schema-validated) and a read-only `mandala_kaltura.resolver` service — the entire surface AV9/AV12 need. Deliberately **not** a config entity (this codebase's first would have cost real CRUD scaffolding for a write path nobody wants — confirmed with Yuji: engineering-owned, config-only, no admin UI, matching ADR 008's floor since D7 never had self-service UI here either). Verified live in DDEV: module installs cleanly (schema passes), the seeded `default` preset resolves correctly merged with site constants, an unknown preset id correctly returns `NULL`. **Still open:** only one preset is seeded (the value AV4's migration already wrote onto every node, known-working, not a guess) — reconciling D7's other known `uiconf_id`s (`31832371`, `48501`) into named presets is AV13's job; nothing yet consumes the resolver (AV9's formatter is the first real consumer) |
| AV11 | **Kaltura Session (KS) minting service** — server-side, using the official `kaltura/api-client-library` PHP SDK; short-TTL, upload-scoped KS handed to the browser. Secrets delivered at deploy time via the established ccrypt/`container_0.env.secret` pattern (see below), **never** in `config/sync` | AV1 | ⏸ **Deferred 2026-09-15 (Yuji) — paused until Than is back (now Wednesday 2026-09-24, pushed from Monday).** Not blocked technically (AV10's config layer + the secret-delivery pattern are both already scoped/proven); a capacity/sequencing call, matching the same pattern already used for Texts/Sources while Than was away. Picks up as its own track — doesn't block AV7's realms or AV15, which can proceed independently |
| AV12 | **Browser-direct chunked upload widget** — file selected on the node form uploads straight to Kaltura (`uploadToken.add` → chunked `uploadToken.upload` → `media.add`), never through PHP/the ALB; resulting `entryId` written into the `kaltura_media` field on submit. Pause/resume and a progress UI, matching D7's behaviour | AV10, AV11 | ⏸ **Deferred alongside AV11** (same session, same reason) — depends on it directly |
| AV14 | **18 media-less AV node disposition** — 17 `video` + 1 `audio` with no Kaltura entry ID | — (can run in parallel with AV1–AV4) | ✅ **Done 2026-09-08 — MIGRATE AS-IS, hand staff the list.** Not empty shells: all 18 have a PBCore title, 17 have workflow data, and they span 15 published collections of which only 7 are scratch. Migrating published reproduces D7's current behaviour (ADR 008 floor); the nid list goes to AV staff as pre-cutover cleanup. ⚠ **AV4 note:** the media field is required in D7, so these nodes will be uneditable in the D11 node form until fixed. List in the [AV5/AV14 disposition note](../planning/av-anomalous-node-dispositions.md) |
| AV15 | **PBCore/workflow technical-metadata display** — `field_workflow` + the full PBCore paragraph set (`field_pbcore_{contributor,coverage,creator,description,extension,identifier,instantiation,publisher,relation,rights_summary,sponsor,title}`) + `field_transcript`, all currently `hidden` in AV6's view display since none of them ever had a display default (created via AV3's migration, not Field UI) and neither AV6 (KMaps only) nor AV9 (player + gallery) claims them | AV3 (paragraph model), AV6, AV9 | ✅ **Done 2026-09-17, revised same day after checking the real live shape.** Built first as a single Bootstrap modal mirroring Sprint 2 B2's `shanti_images_carousel` precedent (this row's original premise) — then, asked to replicate a real production AV page (`av.mandala.library.virginia.edu`), found that precedent doesn't match reality: **that URL serves D7's own live theme directly (`shanti_sarvaka`, jQuery, no React), not the `mandala-om` SPA** — the real presentation is a Field Group accordion with 5 named panels (Details, People, Rights & Licensing, Availability & Access, Technical Metadata) plus a "Video Overview" block (creator + description) above the fold, pulled from the **live DOM directly** since the local `d7_av` dump's own `field_group` table only has edit-form groups (`mode=default`), confirming that dump predates this accordion entirely — a real drift between the migration reference data and current production, not assumed. Rebuilt to match: same extra-field/pseudo-field mechanism, but a Bootstrap 5 accordion instead of a modal, fields correctly split across panels per the live mapping (Details: coverage+relation; People: creator/contributor/publisher/sponsor with the **role value used as the row label** — "Cinematographer", "Executive Producer" — matching production exactly, not a generic "Creator:"/"Creator role:" pair; Rights & Licensing: rights_summary + copyright_owner + year_published; Technical Metadata: identifier + extension + instantiation's sub-fields flattened in, not nested). Demo-priority (shape + a glimpse of real data, per direct instruction), not full fidelity: **"Availability & Access" is an empty placeholder** — its real field mapping was empty on the live example checked and may belong to AV7 (Group access) rather than AV15; no "Related Audio-Video" tab; transcript panel stubbed (Sprint 4/Spike 11 territory, not this row's job); still generic/introspection-based under the hood so it can't drift from AV3's config. `field_workflow` gating unchanged from the first pass (D7 `field_permissions` type 2/CUSTOM, confirmed against the real `role_permission` table — no anonymous/general-authenticated view grant — gated on `$node->access('update')` as the D11 stand-in). Verified live in DDEV against real nodes (115529, 119715, 126524, 126468, 116968): correct panel population including the role-as-label pairing, nested instantiation/workflow-note data, and the workflow gate confirmed both ways on the same node; no new watchdog errors. **Fully closed out 2026-09-17/18, PRs #222/#223/#224/#225.** "Availability & Access" wired to real data (`field_group_content_access`/`field_available_from`); Details panel gained the node-level KMaps fields (Subject/Recording Location/Language/Terms) D7 actually shows there; owning-collection link added to the Overview block; two real migration bugs found and fixed corpus-wide (`field_relation_identifier` never migrated on 6,114 paragraphs; `field_pbcore_instantiation`'s single-valued-winner scoring picked the wrong D7 item on 307 hosts); Overview's description now shows every language (collapsed "Show All Languages" list, matching D7's own toggle) instead of silently dropping all but the first; a real Overview-suppression bug fixed (16 nodes showed nothing at all, not even the date); and duration (`field_kaltura_duration`, backfilled from D7's `node_kaltura.kaltura_duration`) now renders, matching D7's `.avduration` row exactly — `field_duration` (PBCore's own) was assumed to be the source and found empty, which was itself the bug: D7 never uses it, and a corpus check found it disagrees with the real Kaltura value 41% of the time where both exist (filed for Than: `docs/deferred/av15-pbcore-duration-vs-kaltura-duration.md`). Remaining known gaps, all deliberately out of scope for this row: no "Related Audio-Video" tab, transcript panel stubbed (Sprint 4/Spike 11 territory) |
| AV13 | Migrate D7's **per-view-mode player configuration** (`entry_widget` in each `field_config_instance` display) into AV10's registry + formatter settings — not a single site-wide default | AV10, AV4 | ✅ **Done 2026-09-17 — resolved to a single corrected preset, not a per-bundle split.** Traced the real `entry_widget` two independent ways: the live React app's `AudioVideo.DrawPlayer()` (`mandala-om/kmaps-app/src/legacy/audiovideo.js` — the only reachable code path; a sibling `Draw()` method is dead, starts with a bare `return;` from an August 2020 refactor) hardcodes `uiConfId='31832371'` for **both** `field_video` and `field_audio`, no per-bundle branch; D7 Drupal's own `field_video` instance display (`field_config_instance`, read from the real `d7_av` DB) independently configures the same `31832371` for its full/default view. Two independent confirmations converge on one value — no per-bundle split needed, contrary to the row's original premise. AV9/AV10's shipped `default` preset had instead used `24762821` (`MB_MAIN_PLAYER_ID`), which turned out to belong to a *different* mechanism (`mb_services_node_player()`, a share/embed-redirect endpoint, not primary playback) — an AV4 migration-constant mistake, live on dev-0 until this fix. Corrected in place (`mandala_kaltura.settings.presets.default.uiconf_id`); `delivery` stays `HTTP` (never `RTMP` — confirmed dead even in D7's own `field_kaltura_build_embed()`, which only ever passes `targetId`/`wid`/`uiconf_id`/`entry_id` to `kWidget.embed()`, and off the table per team decision regardless). Teaser stays `hidden` for both bundles, unchanged — matches D7 exactly. Verified live in DDEV: a real video node (119715) and a real audio node (115528) both render `uiconf_id/31832371` post-fix. The registry itself is untouched structurally — adding further named presets later (e.g. if AV12's upload-preview player or a future distinct view mode needs one) is unaffected |
| AV16 | **AV landing page (`/av`), Images moved off `/gallery` to `/images`, placeholder Mandala home page (`/`)** — added to scope 2026-09-15 (asked directly, not pre-planned); site-wide navigation, not AV-only, but landed in this sprint because it depends on AV9's just-built shanti-thumbnail cards | AV9 | ✅ **Done 2026-09-15, PR #217.** `/av`: new site-wide `views.view.av_gallery` (row `entity:node`/teaser), reuses AV9's cards rather than a new masonry style (Kaltura thumbnails carry no IIIF dimensions for that); an Any/Audio/Video type picker via a Views **grouped exposed filter** (a plain exposed bundle filter would leak every node bundle — `article`, `page` — into the picker). `/images`: `image_gallery`'s `page_1` path changed `gallery` → `images`; `/gallery` dropped outright, confirmed dev-only/never-public so no redirect needed. `/`: new `mandala_home` module, a deliberate placeholder (links to `/images`/`/av` only) — the real curated home page is D7's `mandala.library.virginia.edu/`, traced directly from the real source (`shanti-uva/mandala-drupal`, not guessed): 100% live editorial content (a plain "Page" node + a hand-rolled `shanti_carousel` Block module, both admin-UI-configured, nothing in Features/code), so nothing was portable as code. Follow-up tracked in [`mandala-home-customizable-content-system.md`](../deferred/mandala-home-customizable-content-system.md). Verified live: `/` serves the placeholder, `/images` shows all 111,339 images (regression-clean), `/av` shows all 11,582 audio+video nodes with real Kaltura thumbnails and working type/search/sort filters, `/gallery` 404s as intended |

## Design note: uploads and multiple players (decided 2026-09-04, Yuji)

**Both are in scope**, and they interact — recording the shape so AV10–AV13 aren't
re-derived.

**Uploads.** D7 uploads browser-direct to Kaltura (chunked), and D11 should do the
same rather than routing media through Drupal. Rationale: AV masters are large, and a
through-Drupal upload would hit PHP `upload_max_filesize`/`post_max_size`, the ALB
idle timeout, and EC2 disk — and would store every file twice. The Kaltura flow is
`uploadToken.add` → `uploadToken.upload` (chunked, with `resume`/`finalChunk`
semantics and parallel chunks) → `media.add`. Kaltura publishes a reference
browser widget, [`kaltura/chunked-file-upload-jquery`](https://github.com/kaltura/chunked-file-upload-jquery)
(blueimp jQuery-File-Upload based) — almost certainly the same lineage as D7's
vendored `jquery.fileupload-kaltura.js`, so D7's behaviour is reproducible rather
than novel. **The security-shaped part is the Kaltura Session**: the browser needs a
KS to upload, and it must be a short-lived, narrowly-privileged one minted
server-side (AV11) — the partner admin secret must never reach the client. That
secret follows this project's existing convention: container environment variable,
not `config/sync`.

**Multiple players — and the wider configuration surface.** There is no single
`uiconf_id` to carry over, and player id is only one of several settings D7 carries.
Read directly out of the 2026-09-01 production dump (`field_config_instance` for
`field_video`), the real element set is:

| Element | `default` view mode (formatter `field_kaltura_player`) | Instance/widget settings | Notes for D11 |
|---|---|---|---|
| `entry_widget` (player `uiconf_id`) | `31832371` | `48501` | Also `24762821` on the live node-view embed (Spike 6) and `31832371` hardcoded in the React app — **at least 3 in play, more expected** |
| `custom_cw` (uploader Contribution-Wizard ui_conf) | — | `4396241` | A **separate** Kaltura ui_conf for the *upload* widget, distinct from the player — feeds AV12 |
| `delivery` | `RTMP` | `HTTP` | ⚠ **Do not port `RTMP` blindly** — Flash-era and long dead; the live embed uses `flashvars[streamerType]=auto`. Treat as vestigial and choose HTTP/HLS deliberately |
| `player_height` / `player_width` | `425` / `880` | `364` / `410` | Genuinely per-view-mode; not one size |
| `thumbsize_height` / `thumbsize_width` | `45` / `80` | `90` / `120` | Same |
| `rotate` | `0` | `0` | Mirrors Images' `field_image_rotation` concept |
| `stretch` | `NULL` | `0` | |
| `custom_player` | `""` | `""` | An override slot, unused in production |
| `teaser` view mode | `hidden` | — | AV content is hidden in teaser display today |

Site-level Kaltura settings live in D7's `variable` table: `kaltura_partner_id`
(`381832`), `kaltura_subp_id` (`38183200` — matches the `/sp/38183200/` segment in
the embed URL), `kaltura_server_url` (`//www.kaltura.com`),
`kaltura_notification_type`, `kaltura_local_registration`, plus a stale
`kaltura_partner_url2` pointing at a long-gone `dev1.shanti.virginia.edu` host, plus
**credentials — see the security note below.** `mb_kaltura` additionally hardcodes
`METADATA_PROFILE_ID = 2631` and `MB_MAIN_PLAYER_ID = 24762821` **in module code**;
in D11 both belong in this configuration layer, not as constants.

**The design consequence:** this is a configuration *layer*, not a couple of formatter
settings bolted on. `kaltura_media` gives us per-item `entry_id`/`partner_id`/
`uiconf_id`/`domain` storage — a fine foundation — but nothing to select a player or
carry delivery/dimension settings per view mode, so AV10 adds that and AV13 migrates
D7's per-view-mode values into it. Build it open: new elements should be addable as
config, since "potentially more players" is an explicit requirement.

> ⚠ **Security note — credentials.** D7's `variable` table stores
> `kaltura_admin_secret` and `kaltura_secret` in **cleartext**, and they are present
> in the production dumps we hold locally. Values are deliberately **not** recorded in
> this public repo; ask Yuji, and see the private docs convention in
> [docs/non-public-documentation.md](../non-public-documentation.md). The admin secret
> must never reach the browser — AV11's KS-minting service exists precisely so it
> doesn't.

### Secret delivery: use the established ccrypt pattern (confirmed 2026-09-04)

Decided by Yuji: secrets are passed in **at deploy time**, encrypted at rest and
decrypted as needed. This is an existing house pattern in `terraform-infrastructure`
(`mandala/drupal/<env>/ansible/`) — follow it rather than inventing anything:

- **Three layered env files** are merged into the container's environment by
  `deploy_backend.yml`:
  `container_0.env.generated` → `container_0.env.managed` (**committed, non-secret**)
  → `container_0.env.secret` (**gitignored plaintext**, `mandala/.gitignore: *.secret`),
  combined via `container_env | combine(managed_env, secret_env)`.
- **Only the encrypted form is committed**: `container_0.env.secret.cpt`
  (**ccrypt**). Encrypt/decrypt with the repo's own helpers,
  `scripts/crypt-key.ksh` / `scripts/decrypt-key.ksh` (`CRYPT_TOOL=ccrypt`) — the
  same mechanism already used for the SAML `.pem.cpt` keys.
- **`required_env_vars` is a fail-loud gate**: the playbook asserts every listed var
  is present, non-empty, and not still `CHANGE_ME`, refusing to deploy otherwise.

**So for Kaltura, the split follows the same rule the playbooks already document
(non-secret → `.managed`, secret → `.secret`/`.cpt`):**

| Goes in `container_0.env.managed` (committed) | Goes in `container_0.env.secret` (committed only as `.cpt`) |
|---|---|
| `KALTURA_PARTNER_ID` (381832), `KALTURA_SUBP_ID` (38183200), `KALTURA_SERVER_URL` | `KALTURA_ADMIN_SECRET`, `KALTURA_SECRET` |

Add the secret ones to `required_env_vars` too — by the playbook's own "split by
**failure mode**" reasoning, a missing Kaltura secret should refuse the deploy rather
than ship a silently broken uploader.

> **One datum worth not over-reading:** the `kaltura_last_imported` variable sits at
> 2023-02-08. It is written by the **base contrib module's own** entry-sync
> (`kaltura.module` / `kaltura.admin.inc`), which `mb_kaltura` explicitly bypassed as
> "broken" — so it says nothing about how recently AV staff used Mandala's *custom*
> import page, and must not be cited as evidence that path is dormant.

## Acceptance criteria

- [ ] `audio`/`video` nodes migrate with an exact count match against the D7 source (11,583 total: 7,396 video, 4,187 audio)
- [ ] Kaltura playback works live for a real migrated node, using a confirmed-valid partner/profile/player configuration
- [ ] Collection membership matches D7 exactly (sourced from `og_membership`, verified against D7 group/membership counts — 11,587 node memberships + 85 subcollection→collection memberships)
- [ ] AV's two custom OG access realms are mapped and enforced in D11 (verified with a real UVA-member-only test and a real collection-admin test, not just the baseline OG pattern Images used)
- [ ] KMaps tagging fields are wired and indexed in kmassets/Solr for AV content
- [ ] Old corrupted fields are excluded from migration; the 68 `MISSING_TYPE` nodes are triaged with a documented disposition (not silently dropped or silently included) — **disposition decided 2026-09-08 (exclude), [documented here](../planning/av-anomalous-node-dispositions.md); the criterion closes when AV4's source query actually implements it**
- [ ] The 18 AV nodes with no Kaltura entry migrate as-is and the cleanup list has been delivered to AV staff (AV14)
- [ ] Collection/gallery UI renders AV content using the shared `shanti-thumbnail` component
- [ ] `field_transcript` is present and downloadable on migrated nodes but not processed — confirmed no broken links, no attempted TCU/XSLT handling
- [ ] **A user can upload a media file from the D11 node form and it lands in Kaltura** — verified end to end with a real file against the real partner account, including a large enough file to exercise chunking, with the resulting `entryId` stored on the node and the media playable afterwards
- [ ] **The upload never routes through PHP/the ALB** (browser-direct), and the browser never receives the Kaltura admin secret — only a short-TTL, upload-scoped Kaltura Session
- [ ] **All known players render**, selected per view mode from configuration — the three confirmed `uiconf_id`s (31832371, 48501, 24762821) each verified against a real entry, plus a fourth added *as config only* to prove new players need no code change
- [ ] The other configuration elements (uploader `custom_cw`, delivery, player + thumbnail dimensions, rotate/stretch) are carried as configuration, with D7's per-view-mode values migrated — and a deliberate, recorded decision on `delivery` rather than a blind `RTMP` port
- [ ] No Kaltura credential appears in `config/sync` or any committed file; secrets come from the container environment

## References

See the **Relates to** line above. This sprint's acceptance closes Phase 3's AV-core
half of ADR 018; [Sprint 4](sprint-04-av-transcripts.md) is the transcript half.
