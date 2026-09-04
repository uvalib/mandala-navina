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
| Kaltura playback embed (Spike 7) | Kaltura *upload/ingest* workflow — **but note (Spike 7, 2026-09-04): D7 genuinely has an in-node-form chunked upload to Kaltura, and `kaltura_media` has no upload capability at all.** Not a migration blocker, but a real post-cutover authoring gap needing a scope decision: accept a workflow change (upload via Kaltura KMC, paste entry ID) or build an upload integration |
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

## Acceptance criteria

- [ ] `audio`/`video` nodes migrate with an exact count match against the D7 source (11,583 total: 7,396 video, 4,187 audio)
- [ ] Kaltura playback works live for a real migrated node, using a confirmed-valid partner/profile/player configuration
- [ ] Collection membership matches D7 exactly (sourced from `og_membership`, verified against D7 group/membership counts — 11,587 node memberships + 85 subcollection→collection memberships)
- [ ] AV's two custom OG access realms are mapped and enforced in D11 (verified with a real UVA-member-only test and a real collection-admin test, not just the baseline OG pattern Images used)
- [ ] KMaps tagging fields are wired and indexed in kmassets/Solr for AV content
- [ ] Old corrupted fields are excluded from migration; the 68 `MISSING_TYPE` nodes are triaged with a documented disposition (not silently dropped or silently included)
- [ ] Collection/gallery UI renders AV content using the shared `shanti-thumbnail` component
- [ ] `field_transcript` is present and downloadable on migrated nodes but not processed — confirmed no broken links, no attempted TCU/XSLT handling

## References

See the **Relates to** line above. This sprint's acceptance closes Phase 3's AV-core
half of ADR 018; [Sprint 4](sprint-04-av-transcripts.md) is the transcript half.
