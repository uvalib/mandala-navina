# Session Log: B1 — OpenSeadragon deep-zoom viewer

**Date:** 2026-09-01
**Participants:** Than Grove (in a live group session with Yuji Shinozaki and Xiaoming Wang), Claude Code
**Outcome:** Sprint 2 Workstream B1 built and verified live in DDEV: a new `IiifDeepZoomFormatter` field formatter renders a static thumbnail with a click-to-open OpenSeadragon deep-zoom viewer, replacing the plain `iiif_image` formatter on `shanti_image`'s default view.

---

## 1. Starting point

With Workstream C (all three content-model audits) closed, moved to Workstream B —
Than's own track, no dependency on C. B1 was the natural first piece since B2 (sibling
carousel) depends on it for a shared template touch-point.

`shanti_iiif` already existed with a working `IiifUrlBuilder` service (including
`infoUrl()`, exactly what B1 needed) and a static `IiifImageFormatter`. B1 adds a
second formatter alongside it rather than replacing the module's existing pieces.

## 2. Porting the D7 behavior

Read D7's real `shanti-main-images.js` (`sarvaka_images` theme) directly rather than
guessing at the interaction pattern: a click-to-open overlay, OpenSeadragon lazily
loaded on first click (not on page load), rotation read from a per-node field and
normalized to OSD's signed-degrees convention, Escape-to-close, and a custom close
button.

**Deliberately did not port D7's exact vendored OpenSeadragon 2.2.1 (2016)** — fetched
the current stable release (6.1.0) from the upstream project instead. Per ADR 010, the
vendored library version is an implementation detail with no user-visible behavior
change, and the D7 build predates six years of upstream fixes. Icon set matches D7's
convention closely (modern OSD ships the same toolbar icon naming); carried D7's own
`close_*.png` icons forward since modern OSD doesn't ship a dedicated close button by
default and D7's custom close affordance is worth keeping visually.

## 3. What was built

- `IiifDeepZoomFormatter` (new field formatter, `shanti_iiif` module) — renders the
  existing static-thumbnail markup plus a "View full image" trigger button in a
  wrapper carrying the IIIF `info.json` URL and rotation as data attributes. Uses
  proper constructor dependency injection (`IiifUrlBuilder`, `ModuleExtensionList`)
  rather than the sibling `IiifImageFormatter`'s `\Drupal::service()` static calls —
  phpcs flagged that exact pattern as a warning on the older formatter; the new one
  avoids it by construction.
- `shanti_iiif.libraries.yml` (new) — `openseadragon` (vendored JS, same
  checked-in-vendor-file pattern as `shanti_sarvaka`'s wookmark/jssor/hammer libraries)
  and `deep-zoom-viewer` (the behavior JS + CSS, depending on it).
- `js/shanti-iiif-deep-zoom.js` — a `Drupal.behaviors` using `once()`, matching
  `shanti_sarvaka.js`'s established style exactly. Lazy-constructs the OSD `Viewer`
  only on first click; reuses it on subsequent opens.
- `css/shanti-iiif-deep-zoom.css` — trigger button + fullscreen overlay styling.
- `core.entity_view_display.node.shanti_image.default.yml` — `field_image`'s formatter
  switched from `iiif_image` to `iiif_deep_zoom`, with a new `rotation_field: field_image_rotation`
  setting. **This is a real fidelity improvement over the previous static formatter**,
  which had a hardcoded `rotation: 0` setting and never actually read the per-node
  rotation field — the new formatter reads it live per node, matching D7's behavior.

## 4. Verification

Live in DDEV, not just code review:
- `drush config:import` — clean, only the one expected config change.
- Rendered `/node/1` as an authenticated user (uid 1 one-time-login link) — no new
  watchdog errors, correct markup (`data-iiif-info-url` pointing at the real IIIF
  server), library/CSS/JS all confirmed attached (verified literal `<script>`/`<link>`
  tags with preprocessing briefly disabled, then re-enabled — config left clean
  afterward).
- **Clicked through in a real Chrome browser session** (claude-in-chrome): the overlay
  opened with working toolbar/navigator/close button; OpenSeadragon correctly attempted
  the real `https://iiif.lib.virginia.edu/mandala/shanti-image-16/info.json` fetch and
  surfaced its own clean 404 error (expected — node 1 is seed/placeholder data, "Crab
  Nebula" with Lorem Ipsum body text, not a real Cantaloupe-hosted asset). Close button
  and page state after closing both verified working. No console errors.
- `phpcs --standard=Drupal,DrupalPractice` run against the new file — same category of
  pre-existing doc-comment-style findings as the sibling `IiifImageFormatter`/
  `IiifUrlBuilder` already have (not a CI gate in this repo currently), so the new file
  is consistent with, not worse than, existing convention.

## 5. Open item carried into the sprint doc

The sprint backlog's own flagged scope question (D7 multi-image sequence viewer,
`sdviewer.php`) is still explicitly left for team confirmation, not silently resolved
— but noted that D7's own overlay code hardcoded `is_series = false` and never wired a
real sequence source, so the underlying D7 behavior this would be "faithfully porting"
may never have actually shipped.

## Next-session starting point

B2 (AJAX sibling carousel) is next — depends on this B1 skeleton (shared template
touch-point) and the already-proven `group_relationship`/`loadByEntity()` collection
lookup pattern from `NodeJsonController::buildCollection()`. B3 (masonry/gallery grid)
has no dependency on B1/B2 and could be picked up in parallel if the group wants two
threads going. Workstream D (endpoint-access docs) remains open and small.
