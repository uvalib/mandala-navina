# B3 (Masonry/Gallery Grid) — Production Reference

**Audience:** Developers (Sprint 2 Workstream B3 prep)
**Date:** 2026-09-01
**Source:** Live production site (`https://images.mandala.library.virginia.edu/`, **read-only
review, no changes made**) + the real D7 module source
(`mandala-drupal/docroot/sites/all/modules/custom/shanti_general/modules/shanti_grid_view`)
**Relates to:** [Sprint 2 backlog](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-b--images-interactive-ui)
(B3), [`images-missing-interactive-viewing-surfaces.md`](../deferred/images-missing-interactive-viewing-surfaces.md)
(the deferred note that scoped Workstream B), [Workstream D1](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-d--uniform-asset-endpoint-access)
(uniform node-access pattern — this doc found a real counter-example)

> **Scope.** This is a research/reconnaissance pass only, done against production
> read-only, so implementation can start informed instead of guessing. No code written,
> no D11 changes made.

---

## What the production homepage actually is

`images.mandala.library.virginia.edu/` **is** the "Explore Images" masonry grid — not
a separate landing page with a gallery widget bolted on. 108,062 images, paginated
(80/page, 1,351 pages), with a free-text search box and a sort dropdown ("POST DATE
DESC" by default). Confirmed this is the same `shanti_grid_view` module the existing
deferred note already named — verified against the real D7 source, not just inferred
from the live site's behavior.

## The click-to-info-panel interaction, confirmed exactly

Clicking a tile opens a panel **inline in the grid flow** (pushes down, doesn't
overlay/lightbox) directly below the clicked tile's row — a larger preview image on
the left, metadata on the right, prev/next arrows, a close button. Captured the real
network request:

```
GET https://images.mandala.library.virginia.edu/shanti/grid/info/node/1631665
```

This is a **server-rendered HTML fragment** (not JSON) injected directly into the DOM
— matches `hook_menu`'s real route definition exactly:
`shanti/grid/info/%/%` → `shanti_grid_view_item_info($type, $eid)`
(`shanti_grid_view.module:34`).

### What `shanti_grid_view_item_info()` actually does (read the real code, not guessed)

```php
$cache_name = "shanti_grid_view_{$type}_{$eid}_info";
if ($cache = cache_get($cache_name)) {
  $html = $cache->data;
} elseif ($type == 'node') {
  $ent = node_load($eid);
  $details = node_view($ent, 'grid_details');   // <-- a dedicated VIEW MODE
  $html = drupal_render($details);
  cache_set($cache_name, $html, 'cache');
}
print $html;
```

**The popdown panel is just the node rendered in a custom `grid_details` view mode**,
themed by `node--shanti-image--grid-details.tpl.php` (confirmed present in the
`sarvaka_images` theme, not just the module's generic fallback template). This is a
clean, very portable pattern for D11 — no bespoke serialization layer to reinvent, just
a second entity view display on `shanti_image` plus a thin controller.

**Real captured fragment (node 1631665, "Village with landscape") shows the exact
field composition:**
- Title (h2)
- Specs line: photographer/author, pixel dimensions, date, **Image Node ID**, **IIIF
  ID** (both IDs shown to the user, not just internal)
- Collection: icon + link to `/collection/{slug}`
- KMaps place tag (e.g. "Huaré") — a `kmap-tag-group` with a **popover** (feature type,
  parent-places breadcrumb, links to "Full Entry" / "Related Places (N)" / "Related
  Images (N)")
- KMaps subject tag (e.g. "area") — same popover pattern, parent-subjects breadcrumb
- Description text (from the `image_descriptions` satellite node — confirmed by a
  `data-nid` attribute on the description div that differs from the image's own node
  ID, matching the Images audit's satellite-node model)
- "Details →" button (links through to the full node page)

**The KMaps place/subject popovers are NOT shanti_grid_view's own code** — they're
`kmaps_explorer`'s `jquery.kmaps-popup.js` widget, loaded separately and reused here.
D11 already has `shanti_kmaps_fields` for the KMaps field type itself; the popover
*widget* behavior is a separate, not-yet-ported piece worth flagging rather than
assuming it comes free with the field type.

## A real access-control gap, worth flagging for Workstream D1

`shanti/grid/info/%/%`'s route definition:
```php
'access arguments' => array('access content'),
```

**Only the blanket "access content" permission — no per-node access check.** Unlike
`mandala_node_api`'s D11 pattern (`_entity_access: 'node.view'`, already the committed
convention target for D1), this D7 endpoint would happily render the info panel for a
node the requesting user shouldn't be able to see, as long as they can view *some*
content. Given Images' content is overwhelmingly public (per the Images audit), this
may be low real-world risk in practice — but it's a concrete counter-example worth
citing when D1 documents the uniform access pattern: **the info-panel endpoint is
exactly the kind of new endpoint that needs the real per-entity check from day one in
D11, not a copy of D7's blanket permission.**

## The masonry layout mechanism (PIG.js) and IIIF thumbnail sizing

`shanti_grid_view_views_pre_render()` (`shanti_grid_view.module:324`) is where tile
data gets built, for the IIIF/node case specifically:

- **Server-side aspect-ratio precomputation.** For each row, `aspectRatio = width /
  height` is computed from the stored `shanti_images_width`/`height` fields (not
  measured client-side) and passed to PIG.js in the initial page JSON — this is how
  the masonry grid lays out correctly with zero layout shift, before any thumbnail
  image has actually loaded.
- **Rotation-aware.** If `field_image_rotation` is 90/270, `aspectRatio` is inverted
  (`1 / aspectRatio`) before being handed to PIG — a real detail a faithful D11 port
  needs to replicate, or rotated images will lay out with the wrong tile shape.
- **Three separate configured IIIF sizes**, not one: `iiif_thumb_size` (grid tile),
  `iiif_pd_size` (the popdown/info-panel image — confirmed live as `800,500`, matching
  the `full/^!800,500/0/default.jpg` URL captured from the real click), and
  `iiif_full_size` (used for the lightbox, see below).
- **Generic URL-template mechanism**, not hardcoded per-size logic: a configurable
  string like `full/!__SIZE__,__SIZE__/0/default.jpg` with `__FNAME__`/`__SIZE__`
  substituted — the `^` (upscale-allowed) prefix seen in production is part of *this
  specific view's configured template string*, not baked into the module. **Note: our
  existing D11 `IiifUrlBuilder::buildSize()` doesn't support the `^` prefix at all** —
  worth a small enhancement if B3 wants the same "fill exactly within bounds, upscale
  if needed" thumbnail behavior for a uniform grid, rather than "scale down only."

Client-side stack, confirmed via the real aggregated-JS manifest on the live page:
`pig.js` (the masonry engine itself — a real, still-reachable upstream project,
`github.com/schlosser/pig.js`) + `pig-shanti-ext.js` (650 lines, Shanti's own
extension — this is where the click-to-popdown wiring and `ppdSettings`/`infoURL`
logic actually live, not in `pig.js` itself) + `jquery.actual.min.js` (a small
dimension-measurement utility) + Hammer.js (touch gestures, already vendored in D11's
theme from Workstream A). PhotoSwipe is also loaded (`photoswipe.js`,
`photoswipe-ui-default.js`) — confirms the deferred note's mention of a lightbox, not
yet exercised in this pass (didn't find the trigger for it — worth checking whether
it's reachable from the info panel or a separate interaction, e.g. clicking the large
image within the popdown).

## Recommended shape for a D11 port (not decided, not built — for team discussion)

- **Don't port the full module's generality.** `shanti_grid_view` also supports an
  arbitrary "data source" mode (`shanti/grid/dinfo/%/%/%`, field-mapping strings,
  function-or-cached-view rendering) for non-entity views like the D7 `related_images`
  example in the module's own README. Images' actual use case only needs the
  entity/node path (`shanti/grid/info/node/{nid}`) — the data-source generality looks
  like unnecessary complexity to carry forward unless another concrete D11 use case
  needs it.
- **`grid_details` as a real D11 view mode** on `shanti_image`, with its own entity
  view display config — reuses existing fields/formatters (KMaps fields are already
  ported; the `IiifUrlBuilder` service already exists from B1) rather than inventing a
  new templating layer.
- **A proper `_entity_access: 'node.view'` route** for the info-panel controller,
  fixing the access gap above from the start.
- **Vendor `pig.js` + a D11-adapted `pig-shanti-ext.js`** the same way B1 vendored
  OpenSeadragon (checked-in library file, `shanti_sarvaka.libraries.yml`-style
  declaration) — `pig.js` itself is small and stable; the Shanti extension needs a real
  rewrite pass for D11 (Drupal.behaviors/once() idioms, the URL-building logic should
  probably call `IiifUrlBuilder` server-side rather than reconstructing URL templates
  client-side).
- **KMaps popover widget** (`kmaps_explorer`'s `jquery.kmaps-popup.js`) is a separate
  open question — not part of `shanti_grid_view` itself, and not yet assessed for D11
  porting effort. Worth its own quick look before B3 implementation starts, since the
  info panel's place/subject tags are visibly incomplete without it (D7 renders them as
  interactive popovers, not just static text).
- **The `^` upscale-prefix gap in `IiifUrlBuilder`** is a small, contained fix if the
  team wants exact D7 thumbnail-fill parity.

## What this doc does NOT establish

- ~~No decision on porting PhotoSwipe/the lightbox~~ — **resolved 2026-09-04 (Than)**:
  it's triggered from the single-image detail page (clicking the main image), and only
  ever served as a frame around the IIIF deep-zoom viewer, not an independent feature.
  Not being ported — D11's own `IiifDeepZoomFormatter` can get its own lighter frame
  instead, and pswp itself didn't work well in production. See Sprint 2 doc's B2
  Deferred section.
- No decision on the KMaps popover widget's D11 porting scope.
- No performance assessment of rendering 108k+ node rows' worth of grid data
  server-side for D11 (D7's approach batches width/height/rotation directly off the
  Views SQL result, avoiding per-row entity loads — worth confirming a D11 Views-based
  approach can do the same, or whether it needs its own query optimization pass).
- No implementation — this is reconnaissance only, ahead of B3 actually starting.
