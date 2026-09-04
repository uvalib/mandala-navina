# Uniform Asset-Endpoint Access: the `_entity_access: 'node.view'` convention

**Audience:** Developers adding any new route that returns entity-derived data or bytes
(JSON, HTML fragments, images, downloads) outside the normal node/page render path.
**Status:** Committed convention, effective immediately for all new endpoints of this
shape. Not an ADR — this is an implementation pattern, not an architectural decision.
**Relates to:** Sprint 2 Workstream D1; the four real endpoints below, all built during
Sprint 2 (B2, B3).

## The rule

Any custom Drupal route that serves data derived from a single node — JSON, an HTML
fragment, a file/image download — **must** gate on that specific node's own view access,
not a blanket site-wide permission. In practice this means the route's `requirements`
key includes:

```yaml
requirements:
  _entity_access: 'node.view'
  node: \d+
options:
  parameters:
    node:
      type: entity:node
```

This is a core Drupal routing feature, not custom code: `_entity_access` combined with
an `entity:node`-typed route parameter means Drupal's own access-checked entity upcasting
resolves `{node}` and calls the node's real `access('view')` check — including anonymous
access, Group-based visibility (ADR 011), and any other access hook already wired for the
node — before the controller ever runs. No bespoke access-checking code is needed in the
controller itself.

## Why this is a hard requirement here, not just a best practice

**D7's real equivalent endpoints did not do this**, confirmed by reading the actual D7
route definitions, not assumed:

| D7 endpoint | D7 access check | Real gap |
|---|---|---|
| `shanti/grid/info/%/%` (grid popdown panel) | `'access arguments' => array('access content')` | Blanket permission only — would render *any* node's info panel to *any* logged-in user, regardless of that specific node's real visibility. See [`b3-masonry-gallery-production-reference.md`](b3-masonry-gallery-production-reference.md). |
| `/api/carouseldata/{nid}` (sibling carousel) | Access callback `TRUE` | Fully public, no check of any kind — confirmed in `shanti_images.module`. |
| `image/download/%/%` (image download) | `'access arguments' => array('access content')` | Same blanket-permission gap as the grid popdown. See the Sprint 2 doc's B2 Deferred section. |

Every one of these is a real, confirmed instance of the same shape of gap: a per-node
resource served behind a permission that doesn't actually check the node. Porting D11
endpoints without fixing this would silently reopen the same hole three times, which is
exactly why this became a tracked Workstream (D) rather than a one-off fix.

## The four real D11 examples

All four were built independently across Sprint 2, converged on the identical
`requirements` block, and are the working precedent for anything built next:

1. **`mandala_node_api.node_json`** (`/api/json/{node}`) — the original example this
   convention is named after. Path is a fixed external contract (the kmassets Solr
   index's `url_json` template), not a free choice.
2. **`shanti_grid_view.info`** (`/shanti/grid/info/node/{node}`) — `GridInfoController`,
   the masonry-gallery popdown panel (B3). Explicitly fixes the D7 blanket-permission gap
   above.
3. **`shanti_images_carousel.data`** (`/api/carouseldata/{node}`) — `CarouselController`,
   the single-image page's sibling carousel (B2). Explicitly fixes D7's fully-public
   access callback.
4. **`shanti_iiif.image_download`** (`/api/iiif-download/{node}/{size}`) —
   `ImageDownloadController`, the download-size dropdown (B2). Explicitly fixes D7's
   blanket-permission gap; also a same-origin proxy so the browser's `download`
   attribute actually fires (that attribute is silently ignored for cross-origin links).

Each controller's own docblock names this convention and the specific D7 gap it closes —
see the routing YAML and controller source for each module listed above for the exact,
current wording.

## When this does *not* apply

- **Group-entity routes** (e.g. B5's collection/subcollection pages) use Group's own
  permission system (`group.role.*` config, ADR 011), not `_entity_access: 'node.view'`
  — there is no standalone custom route for those; the collection gallery is a Views
  argument plugin embedded in the page, not a controller endpoint. Don't force this
  node-shaped convention onto Group-entity access; use Group's own mechanism instead.
- **Multi-entity or aggregate endpoints** (e.g. a search/listing endpoint returning many
  nodes) need the access check applied per-result inside the controller, not via a single
  route-level `{node}` parameter — this convention covers the single-entity case only.
