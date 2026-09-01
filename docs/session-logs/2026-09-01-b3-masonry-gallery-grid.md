# Session Log: B3 — masonry/gallery grid view

**Date:** 2026-09-01
**Participants:** Than Grove, Claude Code
**Outcome:** Sprint 2 Workstream B3 built and verified live in DDEV: a new `shanti_grid_view` module providing a real Views style plugin (`GridView`, PIG.js masonry) plus the click-to-open info-panel endpoint (`GridInfoController`), wired into a working `/gallery` page against real migrated Images data. Two real bugs found and fixed along the way.

---

## 1. Starting point and architecture choice

Picked up after the earlier read-only production review
(`docs/planning/b3-masonry-gallery-production-reference.md`). Before starting, checked
with Than on one real fork: build the masonry grid as a full, reusable Views style
plugin (matching D7 and the sprint backlog's own scope) vs. a faster one-off
controller/block. **Chose the full Views style plugin**, per Than's explicit call —
matches the sprint doc, reusable via Views UI for any future entity listing, not just
this one page.

## 2. The info-panel piece (smaller, built and verified first)

- Extended `IiifUrlBuilder` with an `upscale` parameter (IIIF's `^` prefix) and fixed
  `IiifImageFormatter` to support a `rotation_field` setting (mirroring what B1's
  `IiifDeepZoomFormatter` already had) — the static formatter previously always used a
  hardcoded rotation, a real gap symmetrical to the one B1 fixed on the deep-zoom side.
- New `core.entity_view_mode.node.grid_details` + a `shanti_image` entity view display
  for it — the same "just a node view mode" pattern the production-reference doc found
  in D7's real code (`node_view($node, 'grid_details')`), not a bespoke serializer.
- `GridInfoController::info()` renders that view mode and returns it as a plain HTML
  fragment at `/shanti/grid/info/node/{node}`.
- **Fixed the real D7 access-control gap the research doc flagged**: the route requires
  `_entity_access: 'node.view'` (same convention as `mandala_node_api.node_json`), not
  D7's blanket `access content`. Verified live: 403 for anonymous on a node the current
  session can't see, 200 once authenticated — the real per-entity check is doing its job,
  not just present in the routing YAML.
- Verified against a real node (111339) in DDEV: correct title, IIIF image at the right
  size/rotation, agents/description paragraphs all rendering, no watchdog errors.

## 3. The masonry grid itself

- Vendored `pig.js` from the D7 module's own proven copy (small, stable,
  `schlosser/pig.js`, MIT) rather than re-fetching upstream — same reasoning as B1's
  OpenSeadragon vendoring, but here reusing the exact file already known to work
  against this production data.
- New `GridView` Views style plugin (`#[ViewsStyle]` attribute, D11's current
  convention): reads `$row->_entity` per result row (standard Views entity-base
  population, not D7's raw-SQL-row approach), computes aspect ratio server-side
  (rotation-aware — 90/270° inverts it, same as D7), and builds a per-row thumbnail URL
  directly via `IiifUrlBuilder` rather than D7's client-side `__FNAME__`/`__SIZE__`
  string-templating.
- New behavior JS (`shanti-grid-view.js`): initializes `Pig` from the style plugin's
  `drupalSettings` payload, then does its own delegated click handling (fetch +
  inject) — pig.js itself has no click support at all (confirmed by reading its
  source; D7's click-to-popdown lives entirely in the site-specific
  `pig-shanti-ext.js`, not in vendor `pig.js`). Repurposed pig.js's `filename` field as
  "the ready-to-use thumbnail URL" (`urlForSize` set to an identity function) since the
  URL is already fully built server-side — simpler than reconstructing D7's
  placeholder-templating approach.
- New `views.view.image_gallery.yml`: `shanti_image` nodes, 80/page (matching D7),
  exposed title search, sort by created DESC, page display at `/gallery`.

## 4. Bug #1: the IIIF "height-only" thumbnail size syntax doesn't exist on this server

Initial masonry layout worked immediately (correct row-packing, aspect-ratio-driven
tile widths), but every thumbnail stayed a gray placeholder. Network inspection showed
`full/^!,250/0/default.jpg` returning **400**, not the expected image. Confirmed via
direct `curl` against the real Cantaloupe server: `^!,250` and `!,250` both 400;
`,250` (no scale-mode prefix at all) returns 200. **This server rejects the `!`/`^!`
scale-mode prefix unless *both* width and height are given** — IIIF's spec-defined
unprefixed `,h` syntax already means "fit within bounds, preserve aspect" for a single
given dimension, so the prefix has nothing left to mean there. Fixed in
`IiifUrlBuilder::buildSize()`: the prefix now only applies when both dimensions are
present. This also means grid thumbnails don't get the `^` upscale behavior (only
`,h` semantics) — noted, not chased further; visually indistinguishable in testing.

## 5. Bug hunt #2 that turned out not to be a bug: click-to-popdown "not working"

After fixing thumbnails, clicking a tile appeared to do nothing — no panel, no console
errors, extensive isolated testing (manually rebuilding the exact click-handler logic
inline, monkey-patching `addEventListener`, checking `data-once` markers, verifying the
served JS matched source byte-for-byte) all showed the code was correct. **Root cause:
not a bug at all** — the info panel is inserted via `insertAdjacentElement('afterend',
container)`, and the masonry grid container's own height is ~3290px (pig.js sets this
explicitly for its absolute-positioning layout). The panel was rendering correctly the
whole time; automated screenshots just weren't scrolled far enough to see it.
Confirmed definitively by reading `innerHTML` directly via JS rather than relying on
screenshots, then got a clean visual confirmation once scrolled to the right position.

**Lesson recorded for future sessions**: when a click handler appears to silently do
nothing in browser automation, check the DOM directly (`querySelector` +
`innerHTML`/`getBoundingClientRect`) before assuming the JS is broken — a
correctly-working feature whose result renders off-screen looks identical to a
non-firing handler from a screenshot alone.

## 6. Verification, live in DDEV

- `/gallery` renders the real masonry grid against actual migrated Images data (not
  seed nodes) — confirmed visually matching production's row-packing/tile-shape
  behavior.
- Clicked a real tile (node 111339, "An Endless Knot!"): info panel opened with the
  correct 800×500 IIIF image, agent/photographer metadata, notes — genuine
  end-to-end confirmation of fetch → `GridInfoController` → `grid_details` view mode →
  DOM injection.
- No new watchdog errors at any point.
- `phpcs --standard=Drupal,DrupalPractice` — same category of pre-existing
  doc-comment-style findings as prior session files; one genuine line-length issue
  fixed.

## 7. What was deliberately not built (scope, not oversight)

Per the production-reference doc's own recommendation, scope stayed to the entity/node
case:
- **PhotoSwipe lightbox** — D7 has one, not ported. Not yet assessed.
- **D7's data-source (non-entity) view mode** (`shanti/grid/dinfo`) — arbitrary
  field-mapped views, not needed for Images' actual use case.
- **KMaps place/subject popovers** inside the info panel — still a separate
  `kmaps_explorer` widget not yet ported (flagged in the production-reference doc,
  unchanged by this session).
- **The view isn't wired as the site's actual front page** — lives at `/gallery`,
  `system.site.yml` still points `/node`. A small remaining step, left for a
  deliberate follow-up rather than silently flipped without confirming with the team.
- **Per-row entity loads at 108k-image scale** — this session's `GridView::render()`
  loads a real entity per result row (`$row->_entity`), unlike D7's raw-SQL-row
  approach that avoided entity loads entirely for the full gallery. Untested at real
  scale; if it's a real performance problem once tested against the full dataset,
  that's the first place to optimize.

## Next-session starting point

- Confirm with the team whether `/gallery` should become the actual front page, or
  stay a separate page.
- PhotoSwipe lightbox assessment, if wanted.
- KMaps popover widget porting (shared need with the info panel's place/subject tags).
- Performance check against the full ~108k-image dataset once available in a
  more production-like environment than DDEV's local dataset.
- Workstream D (uniform endpoint access docs) remains open and small — this session
  added a second real example (`GridInfoController`) of the `_entity_access` pattern to
  cite alongside `mandala_node_api`.
