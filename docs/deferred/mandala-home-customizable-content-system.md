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

## Not yet decided

- Block Plugin vs. Layout Builder vs. something else for the carousel.
- Whether slide data should be a real content entity (a "Slide" paragraph/media
  reference) instead of D7's raw textarea-of-ids approach — the D7 approach was clearly a
  workaround for the era's tooling, not a design worth preserving on its own merits.
- Who owns curating the actual slide content/copy (Carla? David Germano?) — this is an
  editorial decision, not an engineering one, and shouldn't block the engineering design
  from proceeding.
