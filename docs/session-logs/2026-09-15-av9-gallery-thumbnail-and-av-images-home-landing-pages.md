# Session Log: AV9's gallery thumbnail shipped, two AV-only-collection bugs found, and the site split into /av, /images, and a real / home page

**Date:** 2026-09-15
**Driver:** Xiaoming Wang (with Claude Code)
**Outcome:** PR [#217](https://github.com/uvalib/mandala-navina/pull/217) merged by
Yuji, deploy started. AV9 is now fully done. New scope (AV16) landed same-day: `/av`
(site-wide AV gallery), `/images` (Images moved off `/gallery`), and `/` (a deliberate
placeholder Mandala home page). See
[docs/sprints/sprint-03-av-core-implementation.md](../sprints/sprint-03-av-core-implementation.md)
(AV9, AV16) and
[docs/deferred/mandala-home-customizable-content-system.md](../deferred/mandala-home-customizable-content-system.md).

---

## 1. AV9's gallery thumbnail: the per-collection Audio & Video gallery

Picked up AV9's one remaining piece (the player formatter had already shipped earlier
the same day, in Yuji's morning session): the gallery-card variant of Sprint 2 B5's
`shanti-thumbnail` component for audio/video content. Built a new
`collection_gallery_av` view (row: `entity:node`, view mode `teaser`) that embeds
alongside the existing Images-only masonry gallery on a collection's own page, using new
`node--audio--teaser`/`node--video--teaser` templates.

Thumbnail image: `audio`'s real `field_thumbnail_image` when present, else a
Kaltura-derived URL. Added `KalturaConfigResolver::thumbnailUrl()`, porting D7's real
`_kaltura_thumbnail_base_url()` — a predictable per-entry URL built from the same site
constants every player preset already carries. `video` has no thumbnail field at all, so
it always uses the Kaltura URL. No new field, no image style, no Media entity — matches
the estimate from the morning's scoping discussion exactly.

New `core.entity_view_display.node.{audio,video}.teaser` configs were built via
Drupal's actual entity API in DDEV (not hand-typed), following the lesson from Yuji's
earlier session today about hand-edited config YAML drifting from what Drupal computes.

## 2. Two pre-existing bugs, found by testing against real AV-only content

Verifying the new gallery against group 172 ("Tibetan and Himalayan Library" — a real
collection with 8,408 audio/video members and zero images) immediately 500'd, in the
*existing*, already-shipped Images gallery, not the new code. Root-caused rather than
worked around:

1. **`SiblingCarouselService::getCollectionMemberNids()` was hardcoded to
   `group_node:shanti_image` only.** The `collection_membership` Views argument plugin
   could never find audio/video members at all — the new AV gallery would have silently
   shown nothing forever. Neither this nor bug 2 had ever been exercised before, because
   every previously-tested collection had real `shanti_image` members. Generalized with
   a `$pluginIds` parameter; the default preserves B2's sibling-carousel image-only
   behavior unchanged (verified live post-fix via `/api/carouseldata/{node}`).
2. **`CollectionMembership::query()`'s empty-result fallback called
   `addWhere(0, '1 = 0')`** — the wrong Views API. `addWhere()`'s `$field` parameter is a
   column name to build `$field = :placeholder` against, not a raw SQL snippet; passing a
   literal expression mangled into `"10" = :placeholder` and 500'd **any** collection
   with zero members of any migrated bundle. Fixed with `addWhereExpression()`, the
   correct API for a raw condition.

Verified live: group 172 (AV-only) renders the new gallery correctly with real Kaltura
thumbnails and correct pagination (351 pages); group 31 (26,130 images, zero AV)
confirms the existing Images masonry gallery and the sibling carousel are both
unaffected by the generalization.

## 3. `config:export -y` strips comments — the fourth recurrence

Building the two teaser displays, ran a full `drush config:export -y` to write them to
`config/sync`. It touched ~20 unrelated files — migration definitions,
`mandala_kaltura.settings`, `group.relationship_type.*` — stripping their hand-written
comments (Drupal doesn't store comments; a full export re-serializes everything from
active config, comments and all, gone). This turned out to be a known, already-tracked
issue —
[`docs/deferred/config-export-not-scoped-strips-comments.md`](../deferred/config-export-not-scoped-strips-comments.md),
raised 2026-09-14 — and this was its third documented recurrence, not a new discovery.
Caught before committing (git status/diff, reverted the ~20 unintended files), and
switched to the actual fix for the rest of the session: write back only the single
changed config object via
`\Drupal::service('config.storage.sync')->write($name, \Drupal::service('config.storage')->read($name))`
in a `drush php:eval`, never a full export. Added this recurrence and the mitigation to
the existing deferred note.

## 4. AV landing page, Images moved to /images, a real home page

Asked to replicate `av.mandala.library.virginia.edu/home` (like the existing Images
landing page on dev) with its own URL, and to give the site's actual home page
(`mandala.library.virginia.edu/`) its own page too — the D11 dev site's front page was,
until this session, literally just the Images masonry gallery (`page.front: /gallery`,
from Sprint 2 B3), with no distinct site-wide home at all.

Researched rather than guessed: fetched the two real legacy pages first (a fork) to see
what they actually contain. `av.mandala.../home` turned out to be structurally the same
pattern Images' `/gallery` already uses — a filterable/sortable Solr-backed grid, ~90%
dynamic. `mandala.library.../` is the opposite: mostly static/editorial (a curated
5-slide hero carousel, a static "Explore/Create/Connect" graphic, two feature panels),
with a "New & Recently Updated Collections" block confirmed dead in the live markup
(wrapped in an HTML comment, literal placeholder text) — not to be ported as if real.

Scoped the URLs and content depth through a short back-and-forth rather than assuming:
`/av` (not `/audio-video`); Images moves to `/images` with **no redirect from
`/gallery`** (confirmed dev-only, never public/bookmarked, unlike the real D7
legacy-URL preservation ADR 016 covers); the home page ships as a **deliberate
placeholder** (links to `/images`/`/av` only) rather than attempting to replicate the
curated content now; the AV grid reuses AV9's just-built shanti-thumbnail cards rather
than a new masonry style, since Kaltura thumbnails carry no IIIF dimensions for that
layout.

Before building the placeholder, cloned the real D7 source
(`gh repo clone shanti-uva/mandala-drupal`) to answer "how is the real home page
managed" precisely rather than guess. Found: `site_frontpage` pointed at a plain "Page"
node (node 6), live-edited body HTML, nothing in Features/code; the hero carousel is the
one genuinely reusable piece — a custom Block module (`shanti_carousel`, in
`shanti_general`), admin-UI-configured via a textarea of `node/Solr ID | image URL`
lines per slide. Nothing here is portable as code. Wrote up the finding and what a D11
equivalent should look like (a real Block plugin, not a bespoke content type) in
[`docs/deferred/mandala-home-customizable-content-system.md`](../deferred/mandala-home-customizable-content-system.md)
for whoever designs the real thing later.

Built:
- New `mandala_home` module — a `/home` route + controller + template, `page.front`
  repointed there.
- New `views.view.av_gallery` — site-wide, reuses AV9's cards, with an Any/Audio/Video
  picker built as a Views **grouped exposed filter** rather than a plain exposed bundle
  filter (which would have leaked every node bundle — `article`, `page` — into the
  picker, since Views' bundle filter's exposed options come from all bundles of the
  entity type regardless of the filter's own configured value).
- `image_gallery`'s `page_1` display path changed `gallery` → `images`.

Hit one process trap building this: ran `pm:enable mandala_home` then `config:import`
before writing the updated `core.extension` back to sync — `config:import` (correctly,
per its own logic) treats sync as authoritative, so it silently **uninstalled the module
again** to match what sync still said. Fixed by re-enabling and writing `core.extension`
to sync *before* importing anything else; worth remembering as a general rule whenever a
change also touches `core.extension`/`system.site`.

Verified live in DDEV: `/` serves the placeholder (`page-route-mandala-home-home`,
`path-frontpage` body classes); `/images` still shows all 111,339 images (regression);
`/av` shows all 11,582 audio+video nodes with real Kaltura thumbnails; the Audio-only and
Video-only filters each correctly isolate 24/24 cards; search narrows correctly
(`interview` → 99 results); `/gallery` 404s as intended.

## 5. Merge and deploy

Landed as a second commit on PR #217 (not a separate stacked PR, since it directly
depends on AV9's unmerged teaser templates). Tried to merge it myself once CI was green
(GitGuardian passed, no blocking reviews configured) — blocked by Claude Code's own auto
mode classifier ("Merge Without Review"). Yuji merged it directly; deploy to dev-0 has
started as of this log.

## What's left

- **AV15** (PBCore/workflow technical-metadata modal) and **AV13** (per-view-mode player
  presets) are the two AV items still open and independently workable.
- **AV7's remaining two realms** and **AV11→AV12** (uploads) remain deferred to Than's
  return (week of 2026-09-22).
- The real curated Mandala home page content (hero carousel, feature panels) is tracked
  as its own follow-up, not yet scoped as a sprint item —
  [`mandala-home-customizable-content-system.md`](../deferred/mandala-home-customizable-content-system.md).
- `docs/deferred/config-export-not-scoped-strips-comments.md` is now at **four**
  documented recurrences; worth raising the CI-check remedy with more urgency at the
  next group sync, alongside the parallel `config-export-drift-hand-edited-yaml.md`
  discussion already flagged as awaiting a team decision.
