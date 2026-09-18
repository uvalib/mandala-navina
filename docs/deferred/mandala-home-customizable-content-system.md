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

Not yet built: the entity/field creation (via Entity API + `config:
export`, the established pattern for new fields this session), the
carousel's JS rotation behavior (reuse shanti_sarvaka's existing Bootstrap
5 stack, already loaded site-wide, rather than a new JS dependency), and
wiring `mandala_home`'s controller/template to render the block instance
above the placeholder's existing Images/AV links.

Still open, unchanged from before: who curates the actual slide
content/copy (Carla? David Germano?) -- editorial, not engineering, and
doesn't block the build above from proceeding.
