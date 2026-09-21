# Mandala home page: real customizable-content system

**Area:** theme / site home page / editorial content
**Raised during:** Sprint 3 (AV landing-page scoping session), 2026-09-15
**Jira:** (add when available)
**Priority:** Medium — not blocking; the home page ships as a deliberate placeholder first

## Context

The D11 site's front page (`system.site.yml` `page.front`) was `/gallery` (the Images
masonry view) up through this session — there has never been a real, distinct Mandala
home page. This session split that apart: `/images` and `/av` become the two collection
landing pages, and `/` gets its own placeholder (`mandala_home` module) that, for now,
just links to those two. The real curated home page content is out of scope for that
placeholder and tracked here.

## What D7's real home page (`mandala.library.virginia.edu/`) actually was

Traced directly from the real source (`shanti-uva/mandala-drupal`, site directory
`mandala.lib.virginia.edu`), not guessed:

- **The front page itself was zero code.** `site_frontpage` pointed at **node 6**, a plain
  core "Page" content type node, edited live through the admin UI. No Features export, no
  Panels/ctools `page_manager` involvement (unlike `shanti_collections`' collection pages
  or `shanti_texts_features`, which do use that machinery elsewhere in the same codebase).
- **The static content** (the "Explore / Create / Connect" 3-step graphic, the two
  "Scholarly Collections" / "Knowledge Maps" feature-blurb panels) was literal WYSIWYG
  body HTML on that one node. No custom fields, no content type of its own.
- **The hero carousel is the one genuinely reusable piece of engineering**: a custom
  module, `shanti_carousel` (inside `shanti_general`,
  `docroot/sites/all/modules/custom/shanti_general/modules/shanti_carousel`). It's a real
  Drupal 7 Block plugin (`hook_block_info`/`hook_block_view`), block-admin-configurable:
  number of carousels, and per-carousel a plain textarea of
  `node ID or Solr record ID | optional image URL override` lines (one per slide), plus
  link text/URL and rotation speed. No structured content entity backs a slide — it's a
  hand-rolled admin form storing everything in Drupal variables.
- Two sections visible in the live page's markup (a "New & Recently Updated Collections"
  block and an "Experiences" testimonials block) are **dead** — wrapped in an HTML
  comment with literal placeholder text ("...some text about someone's experience...").
  Confirmed live 2026-09-15. Do not port these as though they were real, working
  features — per this project's "migrate, not improve" floor (ADR 008), a disabled
  placeholder is not user-facing behavior to preserve.

## What this means for D11

None of D7's mechanism is directly portable as code — it was live content, not exported
config, so there is nothing to migrate in the usual sense (no rows, no Features). What
*is* worth carrying forward is the **shape**: a real Block plugin for the carousel
(D11's native equivalent: a Block Plugin, or Layout Builder if the team wants
per-instance visual placement), rather than a bespoke content type or a hardcoded
Twig-only port. The two static feature panels are simple enough to be ordinary
Drupal Block content (or Layout Builder blocks) once this is designed.

## DECIDED 2026-09-18 (Yuji) — planned for Monday 2026-09-21, not yet implemented

Picked over Layout Builder and a direct D7-shape port (raw textarea of
node/Solr IDs), per the reasoning already in this doc: the D7 approach was a
tooling-era workaround, not worth preserving, and this project already
models repeatable structured content as Paragraphs everywhere (AV's
PBCore fields, etc.) — this follows the same pattern rather than inventing
a new one.

- **`mandala_home_slide`** (new Paragraph type): `field_slide_image`
  (image, required), `field_slide_link` (link, optional — URL + title in
  one core field), `field_slide_caption` (plain string, optional).
- **`mandala_home_carousel`** (new custom Block type, `block_content`,
  revisionable): `field_carousel_slides` (entity_reference_revisions to
  paragraph, unlimited, target bundle `mandala_home_slide`),
  `field_carousel_rotation_ms` (integer, default 5000). Editors manage
  slides through the normal "Custom block library" UI (add/reorder/edit,
  no deploy) and place the one carousel block instance in a region like
  any other block -- no raw PHP Block Plugin class needed for the
  editable part itself.
- **The two static feature panels** ("Scholarly Collections"/"Knowledge
  Maps") -- D7 had these as literal WYSIWYG body HTML on the front-page
  node. Real D11 equivalent: core's existing **Basic block** type (`block_
  content.type.basic`, already in this codebase), not a new content type --
  same reasoning, editors edit body HTML with no deploy.
- D7's two dead sections (empty "Recently Updated Collections"/
  "Experiences" blocks, confirmed dead in the finding above) are **not**
  being ported, per this project's migrate-not-improve floor (ADR 008) --
  a disabled placeholder isn't real user-facing behavior to preserve.

**BUILT 2026-09-21.** All three previously-open build items landed:

- Entity/field creation via Entity API + `config:export` (established
  pattern): `mandala_home_slide` paragraph type + its 3 fields,
  `mandala_home_carousel` block_content type (revisionable) + its 2
  fields, plus default form/view displays for both. The paragraph's own
  view display is a normal one (image/link/string formatters); the block
  type's view display has both fields hidden -- rendering is custom (see
  below), matching the same hidden-field-plus-custom-render pattern AV's
  own paragraph fields already use.
- Carousel rendering: `mandala_home`'s own `mandala_home_carousel` theme
  hook + Twig template, plain Bootstrap 5 carousel markup
  (`data-bs-ride`/`data-bs-interval`) -- no new JS dependency, confirmed
  `bootstrap5-js-latest` is already globally attached via the base
  theme's own `libraries:` list.
- Wiring: `HomeController::carousel()` loads the single
  `mandala_home_carousel` block_content instance (if one exists with
  slides), builds the image URL via the `wide` image style, and passes a
  render array into `mandala-home.html.twig` above the existing
  Images/AV links. No block-placement UI is used -- this whole page's
  markup already comes from this one controller/template, so the
  carousel is pulled in the same way rather than via Block Layout region
  visibility rules.

Verified end-to-end in DDEV: a temporary 2-slide test block rendered
correct Bootstrap 5 markup (indicators, controls, per-slide
`data-bs-interval`, image-style URL, link-wrapped image, caption), then
deleted -- this was disposable verification data, not curated content.
`config:status` clean before and after; the `config:export` run also
picked up ~17 files of pure re-serialization noise (comment stripping,
quote-style changes) from unrelated pre-existing config, reverted
unchanged.

Still open, unchanged from before: who curates the actual slide
content/copy (Carla? David Germano?) -- editorial, not engineering. The
two static feature panels ("Scholarly Collections"/"Knowledge Maps") are
also still not built (plain Basic block, per the decision above) --
next engineering step once someone wants them.

**A second, deliberately-kept demo block exists live on DDEV as of
2026-09-21** (`block_content` id 2, `info: "Mandala Home Carousel
(demo)"`, 3 slides -- Potala Palace/Tibetan mountain stream/Upper Tsum,
real files already in the DDEV DB). Kept on request so it can be looked
at directly rather than cleaned up like the first (disposable,
2-slide) verification pass. Not curated content -- don't mistake it for
a real editorial decision if found in a later session; safe to delete
once real content exists or whenever it's no longer useful.

## Bookmarked 2026-09-21: who/how manages carousel content long-term

Raised while looking at the demo: carousel content shouldn't be
editable by specific named people (that doesn't survive staff turnover
and isn't how the rest of this project's access model works) -- it
should gate on a **Drupal role**, consistent with ADR 015's global
`content_editor` model, not per-group Group roles (there's no
group/collection context for the home page). Management should
probably also get a **friendlier UI** than core's generic "Custom
block library" screen (`/admin/content/block`) eventually.

**Not being implemented now** -- explicitly deferred until someone
actually needs to manage this content in production. What's worth
recording so that later work doesn't start from zero:

- **The permission hook already exists, for free.** Because
  `mandala_home_carousel` is its own `block_content` bundle, core's
  `BlockContentPermissions::blockTypePermissions()` already generates
  per-bundle permissions --
  `create mandala_home_carousel block content`,
  `edit any mandala_home_carousel block content`,
  `delete any mandala_home_carousel block content`, etc. (see
  `core/modules/block_content/src/BlockContentPermissions.php`). Scoping
  a role to *just* this block type needs zero new code -- just granting
  those specific permissions to a role, the same shape as `content_editor`
  in `user.role.content_editor.yml` today (which currently has none of
  these -- it would need them added, or a new narrower role created
  instead of widening `content_editor` itself).
- **Avoid `administer blocks`/`administer block content`** for this --
  both are broad, unscoped ("restrict access: TRUE" in core), covering
  every block type and block *placement* sitewide, not just this one
  editorial surface.
- **UI**: core's `/admin/content/block` list + the standard entity
  add/edit form (already confirmed working end-to-end this session,
  including the `paragraphs` widget for adding/reordering/editing
  slides) is a legitimate v1 -- functional today, no extra engineering
  needed to hand someone real curation work. A friendlier
  purpose-built UI (e.g. scoped just to this one block instance,
  hiding the generic block-library chrome) is the "should probably"
  the user flagged, not a requirement -- worth another look once a
  real curator is actually using this regularly and finds the generic
  screen awkward.

## Bookmarked 2026-09-21: carousel should be configurable for different situations

Not designed yet -- explicitly deferred, but recording the concrete
constraint in the current implementation so a later session doesn't
have to rediscover it: `HomeController::carousel()` currently assumes
**exactly one** carousel instance exists, site-wide. It loads the first
`mandala_home_carousel` block_content entity it finds (no filter beyond
bundle) and renders it in exactly one place -- this page's controller.
There is deliberately no block-placement UI wiring (see the "who/how
manages" section above) and no per-instance identifier of any kind.

"Configurable for different situations" wasn't narrowed down further,
so this covers what it plausibly means until someone picks a direction:

- **Multiple distinct instances** -- e.g. a different curated carousel
  per collection landing page, per asset-type gallery (Images vs. AV),
  or per campaign/season -- would need the lookup to be scoped somehow
  (a machine name / config reference per placement, or switching to
  real Block Layout placement instead of the current direct-load
  pattern, which was deliberately chosen over placement UI for the
  *single* home-page case and would need revisiting for multiples).
- **Per-instance behavior beyond rotation speed** -- transition style
  (fade vs. slide), autoplay on/off, pause-on-hover, indicators on/off,
  aspect ratio/height. `field_carousel_rotation_ms` is the only such
  knob that exists today; the rest are hardcoded in
  `mandala-home-carousel.html.twig`/`mandala-home.css`.
- **A library of reusable configurations** vs. one-off instances (e.g.
  a "carousel style" the block type could reference) -- only relevant
  if multiple instances end up sharing a look/behavior rather than each
  being configured independently.

No decision made on which of these (if any) is actually wanted --
narrow this down with the user before building any of it.
