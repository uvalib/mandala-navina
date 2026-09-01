# Session Log: B3 Masonry Popdown Fixes

**Date:** 2026-09-01  
**Participants:** ThanGrove, Claude Code  
**Outcome:** Popdown-panel bug fixes for [PR #171](https://github.com/uvalib/mandala-navina/pull/171) (Sprint 2 B3 masonry gallery grid) — tile size, row-push overlap, animation smoothness, and missing arrow/border, all traced against production's live D7 `pig-shanti-ext.js`/CSS. Not yet committed as of end of session.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-09-01 19:07

our last session got interrupted. Where are we?

---

## Claude — 2026-09-01 19:07

You're on `feat/sprint2-b3-masonry-gallery-grid`, working tree clean, branch pushed and up to date with origin. Here's the state:

**PR #171 (B3 masonry gallery grid)** is open and mergeable, checks green (GitGuardian passed), but has **no reviews yet**. It's ready for someone to review/merge.

What it delivers: a real `GridView` Views style plugin + PIG.js masonry grid at `/gallery`, click-to-open info panel (`GridInfoController`), plus two real bug fixes found along the way (IIIF scale-mode prefix bug, missing rotation field in `IiifImageFormatter`). It's been visually verified against production layout on dev-0.

**Left open per the session log's "next-session starting point":**
- Decide with the team whether `/gallery` becomes the actual front page or stays separate (currently `system.site.yml` still points `/node`)
- PhotoSwipe lightbox — deferred, not yet assessed for D11
- KMaps popover widget (place/subject tags currently render as plain links, not the interactive popover production uses)
- Performance check against full ~108k-image dataset (untested at that scale)
- Workstream D (uniform endpoint access docs) — still open, small

**Sprint 2 status:** Workstream C done, B1 merged (#170), B3 ready pending review. B2 (sibling carousel) is the last unstarted Workstream B item.

Nothing looks broken or mid-edit — the interruption just landed between "PR opened" and "PR reviewed/merged." Want me to check CI/checks again, ping about review, or start on B2?

---

## User — 2026-09-01 19:09

So we were working on an issue in the image gallery. when you click on an image, a drop down row appears and is supposed to push all the images below it down. It moves them down somewhat but not all the way and then it overlaps them, also it is not smooth. Finally the image tiles in the carousel are bigger than they are on production.

---

## Claude — 2026-09-01 19:10

Local ddev site is running. Let me open the gallery in the browser and reproduce the bug directly.

---

## Claude — 2026-09-01 19:10

Good, tiles here indeed look large (row height ~250-330px). Let me click an image in row 2 (not the first row) to reproduce the overlap bug when panel opens.

---

## Claude — 2026-09-01 19:10

Let me check the real production site to compare tile size, panel behavior, and exact styling (border, arrow).

---

## Claude — 2026-09-01 19:14

Found the actual construction. Let me get the rest of this block.

---

## Claude — 2026-09-01 19:15

Now I have a clear picture of all four issues. Let me look at the current CSS again and the `.module` file to check GridView PHP defaults before implementing fixes.

---

## Claude — 2026-09-01 19:15

Now implementing the fixes. First, the JS behavior file — adding production's row-packing table, fixing the image-load race that causes the overlap, and adding an explicit smooth transition on shift:

---

## Claude — 2026-09-01 19:16

Now update the click handler to select the figure (for the arrow) and wait for images before shifting:

---

## Claude — 2026-09-01 19:16

Now let's clear caches and test the fix in DDEV.

---

## Claude — 2026-09-01 19:17

Tile size now matches production's dense packing (~130px rows, ~11 per row). Now let's test the click-to-open panel behavior — click an image deep in the grid to check smoothness and no overlap.

---

## Claude — 2026-09-01 19:17

Panel is fully sized to accommodate the image with the white bottom border visible, and rows below shift down cleanly without overlap — the image-load-wait fix worked. Let's verify the arrow and check the row above didn't overlap.

---

## Claude — 2026-09-01 19:17

Found it — pig.js injects `overflow: hidden` on figures, which clips the arrow. Production overrides that. Let me fix it.

---

## Claude — 2026-09-01 19:18

Panel opened directly below row 3 correctly. Let's zoom into the seam at the clicked tile to check for the arrow now.

---

## Claude — 2026-09-01 19:19

Now overflow is visible. Let's click a tile again and verify the arrow renders.

---

## Claude — 2026-09-01 19:19

Now let's zoom into the seam between the second tile in row 2 (the clicked one) and the panel top.

---

## Claude — 2026-09-01 19:20

Now let's zoom into the seam of the clicked tile to check for the arrow.

---

## Claude — 2026-09-01 19:21

Good, now loaded. Let's click the tile and check.

---

## Claude — 2026-09-01 19:21

The panel opened directly below row 2 (the clicked tile is now at row 2, second position). Let's zoom into that seam.

---

## Claude — 2026-09-01 19:21

The upward-pointing arrow is now visible at the seam. All four reported issues are fixed. Let's do one final full check — click a different row and confirm smooth transition + no overlap by scrolling.

---

## Claude — 2026-09-01 19:21

Panel closed cleanly, grid restored with no gaps. All fixes verified working. Let me close the browser tabs and run linting on the changed JS/CSS before wrapping up.

---

## Claude — 2026-09-01 19:22

The diff looks clean and correct. Let's close the extra browser tabs.

---

## Claude — 2026-09-01 19:22

Fixed all four issues by tracing them to production's D7 `pig-shanti-ext.js` (fetched and diffed the live aggregated JS/CSS from `images.mandala.library.virginia.edu`):

**1. Tiles bigger than production** — Pig.js's default row-packing (`getMinAspectRatio`) only reaches 6 at wide viewports; production overrides it with a much denser table (up to 14 at ~2000px). Ported that table into our `new Pig(...)` call.

**2. Row doesn't push all the way down / overlaps** — root cause: the panel's height was measured via `getBoundingClientRect()` immediately after setting `innerHTML`, but the panel contains an `<img>` (the node's own image) that has no intrinsic height until it loads. The shift was applied using that too-small measurement, then the image finished loading afterward with no second shift — leaving later rows overlapping the panel. Fixed by waiting for all images inside the panel to load (with a 3s safety timeout) before measuring and shifting.

**3. Not smooth** — Pig.js's own row-transition is 10ms (an instant snap), and that's what our shifted rows were reusing. Production explicitly sets a real `transition: transform 200ms ease` on each shifted figure at shift time; added the same (220ms cubic-bezier) here.

**4. Missing white border + up-arrow** — two things were missing: the CSS (`border-bottom: 0.8rem solid #f0f0f0` + inset box-shadow on the panel, plus a CSS-triangle arrow on the clicked tile), and pig.js injects `overflow: hidden` on every figure which clipped the arrow — production overrides that too, which I replicated (`overflow: visible !important`), plus a `z-index` bump so the arrow paints above the panel instead of getting hidden under its own top edge.

All four verified live against the DDEV site (row packing, panel open/close, shift, arrow) with no regressions. Changes are in `shanti-grid-view.js` and `shanti-grid-view.css` only, not yet committed — want me to commit and push to the `feat/sprint2-b3-masonry-gallery-grid` branch (PR #171 is already open)?

---

## User — 2026-09-01 19:26

The drop down does not open smoothly. It seems to wait for the image to load. Best to open it full height smoothly and then let the image load when it will. Also there is no whitespace above the dropdown row and so you can't see the arrow.

---

## Claude — 2026-09-01 19:28

Good catches — let's fix both. The real problem is the panel waits on the image's real pixels to know its own height; I'll instead reserve the image's box size from data we already have server-side (width/height fields), so the panel can size itself immediately without waiting for the image to actually load. And I'll add the gap above the panel so the arrow is visible against the page background instead of merging into the panel's own top edge.

---

## Claude — 2026-09-01 19:28

Now the CSS — give the image wrapper a resolvable width so the aspect-ratio has something to compute against, and make the img fill that reserved box:

---

## Claude — 2026-09-01 19:28

Now the JS — remove the wait-for-image-load (no longer needed) and add the gap above the panel so the arrow shows:

---

## Claude — 2026-09-01 19:29

Now update the panel creation top offset and the fetch handlers to use `measureAndShift`:

---

## Claude — 2026-09-01 19:30

The image loaded into its reserved space without any additional shift/jump — panel height stayed the same throughout. The arrow and gap are also now visible above the panel. Let's zoom to confirm the arrow clearly.

---

## Claude — 2026-09-01 19:30

The arrow is now clearly visible in the gap, pointing up at the clicked tile. Let's also verify closing works cleanly and there's no double-shift artifact from the "Loading…"→content swap.

---

## Claude — 2026-09-01 19:31

Closes cleanly. Let's do one more full check with a row deep in the grid to confirm no overlap post-close and grid restoration.

---

## Claude — 2026-09-01 19:31

Grid restored cleanly. Both fixes verified. Let's clean up the browser tab.

---

## Claude — 2026-09-01 19:31

Both fixes are in and verified:

1. **Opens smoothly, doesn't wait on the image** — the panel now reserves the image's box size via CSS `aspect-ratio` (computed server-side from `field_iiif_width`/`field_iiif_height`, already available in the twig template) instead of waiting for the actual image bytes to decode. The row-shift fires synchronously as soon as the HTML fetch resolves — no longer gated on image load — and the image fills its already-reserved space whenever it finishes downloading, with zero extra layout shift.

2. **Arrow now visible** — added a 13px gap (`PANEL_GAP`, matching production's `0.8rem`) between the clicked row and the panel, so the arrow renders against the page background instead of merging into the panel's own top edge.

Diff is now in `shanti-grid-view.js`, `shanti-grid-view.css`, and `node--shanti-image--grid-details.html.twig`, still uncommitted. Want me to commit and push these to the branch?

---

## User — 2026-09-01 19:32

Much better but there is a tiny gap between the arrow and the dropdown row, some whitespace. We want them to be flush so it looks like the arrow comes out of the drop down row.

---

## Claude — 2026-09-01 19:32

The arrow (`::after`) is a 10px-tall triangle starting right at the image's bottom edge, but the gap is 13px — leaving 3px of dead space between the arrow's tip and the panel. Making the gap match the arrow's height exactly closes that.

---

## Claude — 2026-09-01 19:33

Let's zoom into the seam at the tile bottom to confirm the arrow is now flush.

---

## Claude — 2026-09-01 19:33

The arrow is now flush with the panel's top edge, looking like it's growing directly out of the dropdown row with no gap. Let's close the tab.

---

## Claude — 2026-09-01 19:33

Fixed — the gap was 13px but the arrow triangle is only 10px tall, leaving a 3px sliver of visible background between the arrow's tip and the panel. Set the gap to exactly match the arrow's height (10px) so it's flush.

---

## User — 2026-09-01 19:34

perfect thank you. documenta and log the progress to date.

---
