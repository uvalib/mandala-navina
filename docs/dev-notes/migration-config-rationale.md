# Migration and settings config: rationale and data findings

**Why this exists.** These notes used to be `#` comments inside `drupal/config/sync/`. An
export rewrites those files and silently strips comments (see
[config-export-not-scoped-strips-comments.md](../deferred/config-export-not-scoped-strips-comments.md)),
and most of what the comments said was about the *data*, not the config. They live here now,
keyed by config name. Counts are as measured when the migration was written (2026-09) and are
point-in-time audit figures, not live assertions.

If you change one of these configs and the reason is not obvious from its keys, add it here, not
as a comment in the YAML. Bugs and open data problems belong in `docs/deferred/`; links below
point at the ones that exist.

## Contents

- [`mandala_kaltura.settings`](#mandala_kalturasettings)
- [`mandala_kmassets_sync.settings`](#mandala_kmassets_syncsettings)
- [`d7_av_audio` and `d7_av_video`](#d7_av_audio-and-d7_av_video)
- [`d7_av_files`](#d7_av_files)
- [`d7_av_collections`, `d7_av_subcollections`](#d7_av_collections-and-d7_av_subcollections)
- [`d7_av_node_collection_membership`](#d7_av_node_collection_membership)
- [`d7_av_user_memberships`](#d7_av_user_memberships)
- [`d7_images_collections` and `d7_images_subcollections`](#d7_images_collections-and-d7_images_subcollections)
- [Paragraph-reference migrations (`d7_av_workflow`, `d7_av_pbcore_instantiation`)](#paragraph-reference-migrations)
- [`d7_av_tags`](#d7_av_tags)
- [`d7_av_url_alias` and `d7_av_collection_url_alias`](#d7_av_url_alias-and-d7_av_collection_url_alias)

---

## `mandala_kaltura.settings`

- **Site-level constants are the real production values** (Sprint 3 design note, from D7's
  `variable` table dump). They are not secret, so they are committed directly like every other
  non-secret value in `config/sync`. See the AV10 scope note's "Secrets" section for why
  `partner_id` / `subp_id` / `server_url` do not need the `settings.php` env-var plumbing that
  the credential pair does ([av10-kaltura-configuration-layer.md](../planning/av10-kaltura-configuration-layer.md)).
- **Primary-playback `uiconf_id` is `31832371`** (AV13, 2026-09-17), traced two independent ways:
  1. The live React app (`mandala-om/kmaps-app`'s `AudioVideo.DrawPlayer()`, the only reachable
     code path; its sibling `Draw()` is dead and starts with a bare `return;`) hardcodes
     `uiConfId='31832371'` for both `field_video` and `field_audio`, with no per-bundle branch.
  2. D7 Drupal's own `field_video` instance display (`field_config_instance`) configures the same
     `31832371` for its full/default view.

  So no per-bundle split is needed. `24762821` is **not** primary playback: it is
  `MB_MAIN_PLAYER_ID`, used only by `mb_services_node_player()` (a share/embed-redirect
  endpoint). AV4's migration constant borrowed it by mistake and AV9/AV10 shipped it to dev-0
  before AV13 corrected it.
- **Delivery is HTTP, never RTMP.** RTMP is dead even in D7's own embed builder
  (`field_kaltura_build_embed()` only ever passes `targetId`/`wid`/`uiconf_id`/`entry_id` to
  `kWidget.embed()`) and is off the table by team decision regardless.
- **Which file governs.** `config/sync/mandala_kaltura.settings.yml` is the live, versioned value
  for the running site: to change a preset, change it in a live Drupal and export. The copy under
  `config/install/` is only the starting default a fresh site install falls back to. Add more
  entries under `presets` for new named players; nothing about the schema or resolver limits it
  to one.

## `mandala_kmassets_sync.settings`

(This file has already lost its comment block to an export once, 2026-09-14.)

- **AV8 (Sprint 3), 2026-09-14: `service` / `asset_type` are not invented.** They are read off
  the ~8,579 real production kmassets documents the legacy mediabase pipeline already indexes for
  AV (queried live against `mandala-index-dev`). Both are `audio-video`, a single shared facet
  value for both D11 bundles. That matches
  [ADR 016](../adr/016-public-url-structure-single-host.md) decision 3 ("the Solr `asset_type`
  facet is a single `audio-video` value... do not align" it with the two-bundle URL grammar) and
  ADR 017's kmassets service vocabulary. Getting it wrong would fragment the `asset_type` facet
  against 8,579 already-live documents, so it is matched, not guessed.
- **`url_html` / `url_ajax` / `url_json` are placeholders**, in exactly the same sense
  `shanti_image`'s are. ADR 016 (Proposed, 3 open items) found the Images entry's URLs "known
  wrong" (legacy host, D11 nid substituted into a route that takes a slug) and left them as an
  accepted placeholder rather than block indexing ("not blocking... known-wrong URLs, no worse
  than what's already indexed"). The AV values mirror that treatment, using values queried live
  off the legacy mediabase documents (`av.mandala.library.virginia.edu`, singular
  `/audio|video/{slug}`, `services/node/ajax/{nid}`). Fixing this properly is ADR 016 open item 2
  (which path is canonical) and was not that sprint item's call to make.

## `d7_av_audio` and `d7_av_video`

The two files are written out in full rather than factored: migration config is read far more
often than it is edited, and Drupal's config exporter would flatten any YAML anchors anyway.
They are identical apart from the media field and `field_thumbnail_image`, which is exactly the
difference AV2 measured between the two bundles, and nothing else.

- **Counts:** 4,187 audio nodes, 7,396 video nodes.
- **Nodes with no Kaltura entry ID: 1 audio, 17 video.** They migrate published, as-is, per AV14.
  D7 makes the media field required, so they are uneditable in the D11 node form until someone
  supplies a reference. The nid list goes to AV staff as pre-cutover cleanup; see
  [av14-media-less-nodes-staff-handoff.md](../deferred/av14-media-less-nodes-staff-handoff.md).
- **`field_thumbnail_image` is audio-only**, populated on 2,844 of 4,187 audio nodes (68%).
  Kaltura generates a poster frame for video and not for audio, so editors upload cover art.
  This is the AV2 audit correction.
- **`field_tags` is labelled "Tags Old" and hidden on the D7 video bundle** while live as "Tags"
  on audio. The divergence migrates as-is (AV2); harmonising it is an improvement, not a
  migration. See [av2-field-tags-old-label-inconsistency.md](../deferred/av2-field-tags-old-label-inconsistency.md).
- **The `constants` block is configuration, not content.** The site-level Kaltura identity
  (`kaltura_partner_id = 381832`, `kaltura_server_url = //www.kaltura.com`, read from D7's
  `variable` table) and `MB_MAIN_PLAYER_ID = 24762821` (the `uiconf_id` the live D7 node-view
  embed uses, confirmed in Spike 6) are written per item only because `kaltura_media` stores them
  per item and its formatter reads them there. AV10 builds the real configuration layer and AV13
  migrates D7's per-view-mode player settings into it; at that point these three columns stop
  being the source of truth. Do not treat them as content. (Which player is actually primary is
  settled under [`mandala_kaltura.settings`](#mandala_kalturasettings).)
- **Author `uid` is the real D7 uid.** Verified 2026-09-09: all 614 distinct audio/video author
  uids exist in D11, so no row falls back to anonymous. `d7_images_shanti_image` does **not** map
  `uid`, which is why all 111,340 migrated Images nodes are owned by Anonymous; that is a
  Sprint 1 fidelity gap, not a convention to copy. See
  [images-node-authorship-not-migrated.md](../deferred/images-node-authorship-not-migrated.md).
- **`field_pbcore_rights_summary` text format.** D7 stores it with a NULL format on all 2,340
  rows (plain text); D11's field is `text_long` and needs one.
- **`field_rating` migrates raw.** D7 fivestar is a 0-100 scale; the whole corpus has two rows,
  both `20`. Rescaling would be a judgement about what the number means, and no D11 UI reads it
  yet. See [av15-avinfo-abandoned-fields-review-with-than.md](../deferred/av15-avinfo-abandoned-fields-review-with-than.md).
- **`field_available_from` loses its end date on 9 of 595 rows.** D7's field is a datetime
  *range* (`value`/`value2`, max delta 0 between the migrated value and source); D11's is a single
  datetime. See [av-available-from-end-date-not-migrated.md](../deferred/av-available-from-end-date-not-migrated.md).
- **Files fields** (`# --- Files ---`) are migrated by `d7_av_files` as inert references, never
  parsed.
- **Paragraph fields (13 `field_collection` fields).** Each `*_items` property is an ordered list
  of `field_collection` `item_id`s built by `D7AvNode` using the same en-preferred,
  archived-excluded rule `D7AvFieldCollection` uses to decide which items become paragraphs. The
  paragraph migrations are keyed on `item_id` alone, unlike Images, whose satellites were keyed on
  `(nid, delta)`. See [av-paragraph-model.md](../planning/av-paragraph-model.md) and
  [av-node-migration-notes.md](../planning/av-node-migration-notes.md).
- **KMaps (6 fields)** use the `sub_process` shape that Images proved in Sprint 1.

## `d7_av_files`

- **8,292 files / ~2.9 GB**, fetched one at a time over HTTP from the live D7 AV site by
  `file_copy`. Scoped to the three fields that actually reference them:

  | Field | Files | Size |
  |---|---|---|
  | `field_transcript` | 5,379 | 82 MB |
  | `field_thumbnail_image` | 2,843 | 2,598 MB (the bulk) |
  | `field_general_featured_image` | 70 | 94 MB |

  The thumbnail size is not a mistake: audio has no Kaltura poster frame, so editors upload cover
  art at full camera resolution (largest single file 10.7 MB).
- **Slowest non-node segment: budget 30-90 minutes**, latency-bound rather than bandwidth-bound.
  (Timings measured on DDEV do not transfer to dev-0; see
  [migration-cycle-runbook.md](../planning/migration-cycle-runbook.md).)
- **`field_transcript` migrates as an inert file field**: present and downloadable, never parsed.
  Any processing of its contents is Sprint 4 (Spike 11); see
  [sprint-04-av-transcripts.md](../sprints/sprint-04-av-transcripts.md).
- **File `uid` is 1**, matching the convention `d7_images_collection_featured_image` set. D7 file
  ownership is not carried: file uid is not user-visible anywhere in Mandala, and the Images
  migration set the precedent. This is not a coverage problem: the user migration imported all
  1,542 shared-DB users with zero failures (the one genuinely dangling uid is under
  [`d7_av_user_memberships`](#d7_av_user_memberships)).
- **Public-path prefix constant.** Core's `d7_file` source strips this prefix off the computed
  `filepath`. AV's `file_public_path` is already Drupal-root-relative, so there is nothing to
  strip, but the key must exist or the parent errors.

## `d7_av_collections` and `d7_av_subcollections`

- **152 collections and 85 subcollections.** The `collection` Group bundle is shared across sites
  and told apart by `field_legacy_site` ([ADR 017](../adr/017-legacy-identity-composite-key.md)),
  so AV and Images collections coexist in one bundle with colliding D7 nids and no ambiguity.
- **Every subcollection has exactly one parent collection** (verified: zero subcollections carry
  more than one `field_og_parent_collection_ref` row), so the parent lookup can never be
  ambiguous.
- **Deliberately excluded D7 fields:**
  - `group_group`, `og_roles_permissions`, `og_user_inheritance`,
    `og_user_permission_inheritance`: OG mechanics superseded by the Group module (Images
    excluded them on the same grounds).
  - `field_rss_feed`: zero collections have it set to 1. Vestigial.
  - `field_subcollection_root`: a collection-only field in D7, migrated by `d7_av_collections`
    and not repeated for subcollections.
- **AV-only field:** the KMaps subject root(s) a collection browses its subcollections under. 60
  rows across the 152 collections, all in the `subjects` domain. Images collections have no
  equivalent and leave it empty.
- **Author `uid`:** real D7 creator uid, identity-mapped 1:1 by the user migration. Verified
  2026-09-09: all 87 distinct collection/subcollection author uids exist in D11, so no row falls
  back to anonymous.
- **Text format:** D7's `filtered_html` has no D11 equivalent; same default as the Images
  collection migration and every formatted-text field in the AV migrations.

## `d7_av_node_collection_membership`

- **11,518 memberships** (7,333 video + 4,185 audio). Every AV node belongs to exactly one
  collection or subcollection (verified: zero nodes carry more than one `field_og_collection_ref`
  row), so no node fans out here.
- **65 of the 11,583 AV nodes have no membership at all** and migrate without one. That is a
  property of the source, not a gap. They are the collection-less orphans handled by the
  [orphaned-content review group](../deferred/orphaned-content-temp-group-on-migration.md).
- **`member_types` implements the AV5 disposition.** The 68 `MISSING_TYPE` nodes hold 68
  `og_membership` rows; scoping the *source* to audio/video excludes them here instead of
  letting them fail a `migration_lookup` downstream. The triage collection they sit in ("Admin:
  On Kaltura Not in Mediabase") survives regardless: it holds 285 real AV nodes. See
  [av-anomalous-node-dispositions.md](../planning/av-anomalous-node-dispositions.md).
- **The single `source` node** (1 row) is excluded on the same basis: `source` is not an AV
  bundle and has no D11 destination in this sprint.
- **The relation plugin must name the member's bundle**, and audio and video are two bundles
  (AV2), so the bundle is derived per row rather than defaulted.

## `d7_av_user_memberships`

- **1,235 (user, group) pairs**, collapsed from 1,237 `og_membership` rows: AV records user
  memberships under both `og_group_ref` (1,207) and OG's user-side `og_user_node` (30), and two
  pairs appear in both. D11 Group treats a membership as unique per `(group, user)`, so the
  source plugin groups on `(gid, etid)` and keys the map on the lowest `og_membership` id.
- **One dangling uid** (7471) costs two memberships; the `entity_exists` guard skips them rather
  than letting the destination plugin throw. Details and the reconciliation are in
  [av-collection-memberships-dropped-for-deleted-d7-user.md](../deferred/av-collection-memberships-dropped-for-deleted-d7-user.md).

## `d7_images_collections` and `d7_images_subcollections`

- **Author `uid` is the real D7 creator uid**, identity-mapped 1:1 to D11 by the user migration
  (verified 2026-09-02: 0 of 171 source collection/subcollection nodes have `uid=0`, so Group's
  uid=0-on-insert bug never applies to this data; the earlier blanket `default_value: 1`
  workaround discarded real ownership for no reason). See
  [d7-shared-user-database.md](../deferred/d7-shared-user-database.md) and
  [migrate-entity-group-update-mode-nulls-uid.md](../deferred/migrate-entity-group-update-mode-nulls-uid.md).
- **The source field is `node_uid`, not `uid`.** The `d7_node` source exposes the author as
  `node_uid` (and `revision_uid`); there is no `uid` source field, and core's `Node::query()`
  adds `n.uid` only under that alias. `uid: uid` resolves to nothing. It was corrected in
  Sprint 3 AV4 (2026-09-09); dev-0's 171 groups carry correct uids, but the wrong mapping would
  have bitten silently the next time these two migrations were re-run. Check with
  `drush migrate:fields-source <migration>`.
- **Text format:** D7's `filtered_html` has no D11 equivalent (D11 has `basic_html`,
  `full_html`, `plain_text`, `restricted_html`); `default_value` matches the convention used for
  every other formatted-text field in `d7_images_shanti_image`, not a per-row format lookup. The
  subcollection file follows the same convention.

## Paragraph-reference migrations

Applies to `d7_av_workflow` and `d7_av_pbcore_instantiation` (and any other process that nests
one paragraph migration inside another).

- **Nested collections** (e.g. workflow note, PBCore format id) are resolved by the child
  migration's map.
- **`entity_reference_revisions` needs both ids.** `migration_lookup` on a paragraph destination
  returns `[id, revision_id]`, so mapping it straight onto `target_id` assigns the whole pair to
  one column. Map `target_id` and `target_revision_id` separately.

## `d7_av_tags`

- **1,489 terms, entirely flat**: zero rows in `taxonomy_term_hierarchy` carry a non-zero parent,
  so there is no `parent` process and no second pass.
- **1,460 are referenced by `field_tags` on an AV node; the other 29 migrate too.** An unused
  term is still a term an editor created, and dropping them would be a content decision, not a
  migration one.
- `field_tags` is "Tags Old" on video and "Tags" on audio; see
  [`d7_av_audio` and `d7_av_video`](#d7_av_audio-and-d7_av_video) and the
  [AV2 content-type decision](../planning/av-content-type-decision.md).

## `d7_av_url_alias` and `d7_av_collection_url_alias`

- **11,900 alias rows across all 11,583 AV nodes.** Every AV node has at least one, and 317 have
  more than one because D7 pathauto left the older alias in place when a title changed. Each is
  a URL somebody may have saved, so the source is keyed on `pid` (one row per alias, not per
  node) and none are deduplicated. See [ADR 016](../adr/016-public-url-structure-single-host.md)
  decision 7.
- **D7 AV aliases are already namespaced** `audio/...` and `video/...`, the shape ADR 016
  specifies for D11, so they carry over unchanged.
- **247 alias rows across all 237 AV collections and subcollections** (`d7_av_collection_url_alias`).
  The destination path is `/group/{id}`, not `/node/{id}`, because AV collections became Group
  entities, exactly as Images collections did. Same source plugin as `d7_av_url_alias`, different
  path construction, which is why the node-type filter lives in the source and the path shape
  lives in the migration.
