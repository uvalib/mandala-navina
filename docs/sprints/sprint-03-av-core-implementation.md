# Sprint 3: AV core implementation (`audio`/`video`, Kaltura, access, collections)

**Status:** ○ Planned — not started. Blocked on [Spike 7](../spikes/spike-07-kaltura-av-integration.md)
(Kaltura, ◐ Partial — started 2026-09-04, module landscape + a live D11 prototype
done, upload/ingest and a real migration source plugin still open).
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
| AV2 | Content-type decision: one bundle with a media-kind field, or `audio`/`video` kept as two — scope note (ADR-010-style) | AV content-model audit (done) | ○ |
| AV3 | PBCore/workflow `field_collection` → Paragraphs modeling decision + build | AV2 | ○ |
| AV4 | Migrate API source plugins for `audio`/`video` nodes; collection membership sourced from `og_membership`; exclude old corrupted fields; `field_transcript` migrated inertly | AV1–AV3 | ○ |
| AV5 | 68 `MISSING_TYPE` node disposition — root cause confirmed (Kaltura entries whose `mediaType` doesn't map to VIDEO/AUDIO, imported anyway with an invalid bundle string); decide exclude vs. repair-to-real-type | — (can run in parallel with AV1–AV4) | ○ |
| AV6 | KMaps field wiring (reuse Images pattern, already proven) | AV4 | ○ |
| AV7 | OG → D11 Group access mapping, including `group_access_uva_member` and `mb_collection_admin` | AV4 | ○ |
| AV8 | Solr/kmassets sync wiring for the AV bundle(s) | AV4, AV6 | ○ |
| AV9 | UI: Kaltura player field formatter; collection-content gallery variant of `shanti-thumbnail` (generalizing Sprint 2 B5) | AV1, AV4, Sprint 2 B5 (done) | ○ |
| AV10 | **Kaltura configuration layer** — config-driven, extensible, covering the full element set inventoried below (players/`uiconf_id`, uploader widget ui_conf, delivery, player + thumbnail dimensions, rotate/stretch), selectable **per view mode** via formatter settings. Must carry the known values and accept new ones as config, no code change | AV1 | ○ |
| AV11 | **Kaltura Session (KS) minting service** — server-side, using the official `kaltura/api-client-library` PHP SDK; short-TTL, upload-scoped KS handed to the browser. Admin secret read from container env (the `SOLRPROXY_CLIENT_SECRET` pattern), **never** in `config/sync` | AV1 | ○ |
| AV12 | **Browser-direct chunked upload widget** — file selected on the node form uploads straight to Kaltura (`uploadToken.add` → chunked `uploadToken.upload` → `media.add`), never through PHP/the ALB; resulting `entryId` written into the `kaltura_media` field on submit. Pause/resume and a progress UI, matching D7's behaviour | AV10, AV11 | ○ |
| AV13 | Migrate D7's **per-view-mode player configuration** (`entry_widget` in each `field_config_instance` display) into AV10's registry + formatter settings — not a single site-wide default | AV10, AV4 | ○ |

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
> [docs/non-public-documentation.md](../non-public-documentation.md). For D11 these
> must come from the container environment (the `SOLRPROXY_CLIENT_SECRET` pattern),
> never `config/sync`, and the admin secret must never reach the browser — AV11's
> KS-minting service exists precisely so it doesn't.

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
- [ ] Old corrupted fields are excluded from migration; the 68 `MISSING_TYPE` nodes are triaged with a documented disposition (not silently dropped or silently included)
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
