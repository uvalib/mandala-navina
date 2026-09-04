# D11 Images has no interactive viewing surfaces — no deep-zoom viewer (RESOLVED 2026-09-01), no collection carousel, no mosaic/gallery view

**Area:** Images / UI-UX / shanti_iiif / views
**Raised during:** Session 2026-08-19 — reviewing the live D7 site
(`images.mandala.library.virginia.edu`) against the Sprint 1 acceptance criterion "Images
render through the existing IIIF server with `i3fid` linkage intact"
**Jira:** (add when available)
**Priority:** **To be discussed by the team as the next item after Sprint 1 closes.** Not
a Sprint 1 blocker — Sprint 1's AC as written is satisfied by 1a.5's URL-contract proof
(see below); this note captures a materially bigger scope the AC didn't actually cover.
**Related:** the broader [D7 theme / UI commonalities
audit](../planning/theme-ui-commonalities-audit.md) (2026-08-25) confirms this viewer is
Images-specific — it doesn't factor into the shared-base-theme question, since D7 delivered
it as a per-site theme addition on top of a common `shanti_sarvaka` base, same as every
other site's content-specific viewer (Kaltura for AV, etc.).

## What we found

Live D7 (`images.mandala.library.virginia.edu`) has three interactive viewing surfaces
built directly into Drupal — not just backend IIIF URL correctness, and not something the
React client (`mandala-om`) supplies. None of the three exist in D11 yet.

### 1. OpenSeadragon deep-zoom viewer on the image detail page — RESOLVED 2026-09-01

Built as Sprint 2 Workstream B1: `IiifDeepZoomFormatter` (`shanti_iiif` module),
[PR #170](https://github.com/uvalib/mandala-navina/pull/170). Ports the same
click-to-open/lazy-load/Escape-to-close UX described below, ~~against OpenSeadragon
2.2.1~~ against OpenSeadragon 6.1.0 (vendored current stable, not the exact D7 version
— implementation detail per ADR 010). Verified live against a real migrated image (not
just seed data) — found and fixed two real WebGL bugs along the way (missing CORS mode,
a compositing failure; switched to the explicit `canvas` drawer). See
`docs/session-logs/2026-09-01-b1-openseadragon-deep-zoom-viewer.md` for the full record.

**The multi-image sequence variant (`sdviewer.php` + `shanti_images_sdinit.js`,
`|$|`-delimited series) described below was NOT ported** — deliberately out of scope
per the Sprint 2 planning doc, and now **decided: not needed for D11** (2026-09-02,
Than). Checked the actual D7 source at
`docroot/sites/all/modules/custom/shanti_images/` in the legacy `mandala-drupal` repo:

- `sdviewer.php` is a standalone, unfinished page — title literally "Test of SeaDragon,"
  hardcodes a single OpenSeadragon instance reading one tile source off a `?json=` query
  param. It is the beginning of a new function, never finished or wired into any live
  page.
- `js/shanti_images_sdinit.js` does compute `is_series` dynamically from a
  `data-iiifurls` attribute split on `|$|` (correcting the earlier note above, which said
  D7 hardcoded `is_series = false` — it doesn't; the flag is real, only the page that
  would set the attribute was never built).
- No caller anywhere in the module sets `data-iiifurls` on any real page, so the series
  path exists in code but was never reachable in production.

No D11 work needed. Left here only as a pointer to where the abandoned prototype lives
in the legacy repo, in case a real multi-image sequence need ever comes up later.

**Original finding, kept for context:**

`sarvaka_images` theme + `shanti_images` module wire a real deep-zoom viewer into the node
page itself:

- `shanti-main-images.js` (`Drupal.behaviors.shantiImagesIIIF`) lazy-loads
  `openseadragon.min.js` and shows a full-screen overlay (`#sddiv`/`.sdwrapper`/`#iiiftools`
  — rotation control, navigator, close button, Escape-to-close) triggered by clicking the
  "View in IIIF Viewer" icon on the node page.
- Tile sources come from `Drupal.settings.shanti_images.infourls`, set server-side in
  `shanti_images.module` (~line 838) — real IIIF `info.json` URLs, not the flat derivative
  URLs `shanti_iiif`'s `IiifUrlBuilder` builds.
- A second, related mechanism (`sdviewer.php` + `shanti_images_sdinit.js`, `data-iiifurls`
  with `|$|`-delimited multi-image sequences) supports viewing an ordered *series* of
  images — used for sorting/classifying workflows, not just single-image viewing.

**D11's `shanti_iiif` module (built in 1a.5) only ported `IiifUrlBuilder` +
`IiifImageFormatter`** — a flat `<img>` derivative matching D7's non-interactive fallback
rendering. The interactive viewer itself was never carried forward.

### 2. AJAX sibling carousel on the image detail page

Below the main image, D7 shows a carousel of other images in the same collection,
windowed around the current image:

- `shanti_images_get_node_carousel($nid)` (module) finds the node's collection
  (`shanti_collections_get_collection`), gets the collection's full ordered nid list
  (`_shanti_images_get_coll_node_ids`, cached), and windows ±15 around the current node (30
  total).
- Loaded via AJAX after page load — the node template only ships a placeholder
  (`<div id="fscarousel-placeholder">`), replaced client-side
  (`shanti-main-images.js`) once the carousel markup arrives.
- Falls back to a hidden/no-data state if the image has no collection.

**Nothing in D11 does this today.**

### 3. Mosaic/gallery grid view (e.g. the images.mandala.library.virginia.edu homepage)

**Production reference done 2026-09-01** — see
[`b3-masonry-gallery-production-reference.md`](../planning/b3-masonry-gallery-production-reference.md)
for a read-only review of the live site plus the real D7 module source: confirms the
click-to-info-panel is just a `grid_details` node view mode (not a bespoke
serializer), a real access-control gap in the D7 endpoint (`access content` only, no
per-node check), and the PIG.js masonry engine's server-side aspect-ratio
precomputation. Not yet implemented in D11.

A general-purpose custom Views style plugin, not IIIF-specific:

- `shanti_grid_view` (submodule of `shanti_general`,
  `docroot/sites/all/modules/custom/shanti_general/modules/shanti_grid_view` in the D7
  repo) — a Google-Photos-style masonry grid (PIG library) usable on *any* View. Can source
  images from IIIF, plain Drupal files, node images, or an arbitrary data source
  (auto-detected from the view's fields, per the module's own README).
- Click a tile → AJAX "popdown" panel (`shanti/grid/info/{type}/{eid}` or
  `shanti/grid/dinfo/...` for data-source views) showing a larger image + metadata, plus a
  "Details" link through to the full node page. Uses PhotoSwipe for the lightbox. **Note
  2026-09-04**: separately confirmed (on the single-image detail page, not this popdown)
  that PhotoSwipe only ever serves as a frame around the IIIF deep-zoom viewer and isn't
  worth porting — D11 already has its own deep-zoom viewer (`IiifDeepZoomFormatter`,
  section 1 above) that doesn't need pswp's wrapper. See Sprint 2 doc's B2 Deferred
  section for the decision.
- Has its own admin settings page and an image-size cache table
  (`shanti_grid_image_sizes`).
- The module's README cites both an IIIF example (`all_image_gallery` — the live homepage
  gallery) and a non-IIIF example (`related_images`), implying it's used in more than one
  place across the site(s) — not audited yet which views actually use it.

**Nothing in D11 does this today** — confirmed no trace in `drupal/web/modules/custom`,
`docs/sprints/`, or `docs/deferred/` prior to this note.

## Why this wasn't caught by the existing acceptance criterion

Sprint 1's AC (`docs/sprints/sprint-01-images-implementation.md` line 124) reads: *"Images
render through the existing IIIF server with `i3fid` linkage intact."* 1a.5 satisfied this
narrowly and correctly — byte-identical derivative URLs to D7, live reachability against
the real Cantaloupe server, `i3fid` preserved end-to-end through the 1a.7 migration. The
criterion is about backend identifier/URL-contract fidelity, and says nothing about
interactive viewing, carousels, or gallery views. As written, it's already satisfiable
without any of the above — this note exists because the *user-facing experience* implied
by "renders through the IIIF server" turned out to be much bigger than the criterion
captured.

## Scope note: other asset types

Images is the only site actually migrated so far (ADR 009 — Phase 2 for
Texts/Sources/AV/Home hasn't been forked off yet). D7's AV site almost certainly has an
equivalent interactive surface (Kaltura player, not IIIF — `sarvaka_mediabase` theme,
`KalturaClient` library referenced in the D7 repo) and Sources/Texts likely have their own
patterns; **none of that has been traced yet** and is explicitly out of scope for this
note. **Visuals is explicitly excluded from this concern per team direction (not a site
D11 needs to carry forward).** When Phase 2 scoping starts, each site's equivalent
viewing/sorting/classifying surface needs the same kind of audit this note gives Images.

## Decision

**2026-08-19 (Than, team pending): defer.** Not a Sprint 1 blocker — Sprint 1 is otherwise
functionally complete, gated only on the two open OAuth2 defects (signing keys not
persisted across deploy; solr-proxy's missing Bearer header on UserInfo). Flagged here as
**the next item for the team to discuss and scope once Sprint 1 closes** — likely a
sizeable follow-on task (or several), not a quick fix: rebuilding an OpenSeadragon
integration, an AJAX collection-scoped carousel, and a general-purpose masonry/gallery
Views plugin, each from scratch against D11's stack.

**Update 2026-09-01:** Scoped and picked up as Sprint 2 Workstream B (see
`docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md`). B1 (item #1, the
deep-zoom viewer) is done — see above. B2 (item #2, the sibling carousel) and B3 (item
#3, the masonry/gallery grid) remain open, tracked in that sprint doc rather than here
going forward.

## Cross-references

- [`docs/sprints/sprint-01-images-implementation.md`](../sprints/sprint-01-images-implementation.md)
  — 1a.5 (IIIF wiring, URL-contract-only) and the acceptance criteria section
- `drupal/web/modules/custom/shanti_iiif/` — the D11 module that ported the URL builder
  only, not the interactive viewer
