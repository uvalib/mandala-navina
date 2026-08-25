# D7 Theme / UI Commonalities Audit

**Audience:** Developers and Yuji (Sprint 2 planning)
**Date:** 2026-08-25
**Source:** Legacy D7 `mandala-drupal` codebase (`sites/all/themes/`), checked out locally at
`~/Sandbox/Mandala/Site/mandala-drupal`
**Relates to:** [ADR 010](../adr/010-adr-008-scope-clarification.md) (internal remodeling
permitted; user-facing behavior is the floor), [Images interactive viewing surfaces
gap](../deferred/images-missing-interactive-viewing-surfaces.md), [ADR
009](../adr/009-migration-sequencing-strategy.md) (Phase 2 forks to per-site tracks)

---

## Purpose

Yuji's Images data migration is closing out Sprint 1. Before Phase 2 forks into
independent per-site tracks (Than on Texts, Xiaoming on Sources, AV last), establish
whether the D7 sites share enough theme structure to justify a baseline D11 theme now —
so each site owner extends a shared foundation instead of independently reinventing chrome
that was never actually site-specific in D7.

This audit inventories the real D7 theme layer (not memory, not assumption) and sorts
findings into **shared baseline** vs. **genuinely site-specific**, per the ADR 010
vocabulary ("faithful port" vs. "internal remodeling permitted").

## Key finding: it's one theme, not six

Every one of D7's site-facing themes is a Bootstrap-based **sub-theme of a single parent,
`shanti_sarvaka`** ("A responsive Drupal 7 theme for all Shanti@UVa sites, built with
Bootstrap"). This was not an accident of naming — it is declared in each `.info` file's
`base theme = shanti_sarvaka` line, and confirmed by file size: the base theme's
`template.php` is 1,677 lines; every sub-theme's is a fraction of that (193–860 lines).

| Site (per current 6-way scope) | D7 theme | `template.php` size | Sub-theme's only real additions |
|---|---|---|---|
| Images | `sarvaka_images` | 860 lines | Gallery CSS, broken-image fallback setting, `shanti-main-images.js` (drives the OpenSeadragon deep-zoom viewer — see the [interactive surfaces note](../deferred/images-missing-interactive-viewing-surfaces.md)) |
| AV | `sarvaka_mediabase` | 653 lines | AV-transcript CSS, media-edit-form CSS, `body_tag`/`icon_class` = `audio-video`. Kaltura itself is **not** theme code — it's the contrib `kaltura` module + `KalturaClient` library (`sites/all/modules/contrib/kaltura`, `sites/all/libraries/KalturaClient`) |
| Texts | `shanti_sarvaka_texts` | 193 lines (smallest) | Texts CSS/JS, `jquery.ui.tabs`; depends on the `shanti_texts` and `shanti_collections_admin` *modules*, not theme code |
| Sources | `sources_theme` | 450 lines | Sources CSS/JS; several **commented-out** stylesheets (`search-bibliography`, `fancytree`, `biblio-full-page`) — bibcite/citation UI was present but partially disabled even in D7 |
| Visuals | `sarvaka_shiva` | 488 lines | Depends on the `shivanode` module; `body_tag`/`icon_class` = `visuals` |
| Home / KMaps browse | `sarvaka_kmaps` | (not separately measured) | KMaps subject/places explorer CSS/JS (`kmaps-explorer.css`, `shanti-mandala-homepage-kmaps.css`) — this is the taxonomy-browse chrome, not a distinct site skin |

**Note on scope:** the current top-level docs describe "five sites (AV, Images, Sources,
Texts, Mandala Home)," but the D7 codebase's actual `sites.php` multisite map and theme
set show **six**: the five plus a distinct **Visuals** site (`sarvaka_shiva`, host
`visuals.mandala.library.virginia.edu`). This matches what session logs from 2026-06-12,
2026-06-15, and 2026-07-30 already independently found (Visuals appears in the reindeer_x
site list and the Spike 6 host map) — it is not a new discovery, but it's easy to
under-count from the top-level docs alone, so it's restated here for anyone scoping Phase
2 site-by-site. A ninth theme, `sarvaka_projects`, exists in the codebase but doesn't map
to any of the six live hosts in `sites.php` — likely legacy/unused; worth a quick
confirmation before Phase 2, not a blocker to this audit's conclusion.

## What's actually shared (the baseline)

All six site sub-themes declare **the exact same twelve regions**, verbatim, in every
`.info` file: `header`, `banner`, `content`, `search_flyout`, `search_results`,
`sidebar_first`, `sidebar_second`, `highlighted`, `help`, `page_top`, `page_bottom`,
`footer`, `admin_footer`. No site introduced or dropped a region. That's a strong signal
this was one designed layout system, not six independent ones that happened to converge.

The base theme owns the actual page skeleton and the vast majority of shared behavior —
sub-themes add CSS/JS on top, they don't override structure:

- **Page templates** (`shanti_sarvaka/templates/`): `page.tpl.php`, `html.tpl.php`,
  `node.tpl.php`, `page--403.tpl.php`, `page--404.tpl.php`, `page-user.tpl.php`,
  `block.tpl.php`, `region--header.tpl.php`, plus search-result templates
  (`search-result.tpl.php`, `search-results.tpl.php`) and a KMaps typeahead template
  (`shanti-kmaps-typeahead.tpl.php`) — the last one shared by every site with a KMaps
  field, i.e. all of them per Spike 1.
- **Shared JS**: `shanti-main.js`, `shanti-search.js`, `shanti-iframe.js`, plus a large
  shared vendor set (Bootstrap, `multilevelpushmenu`, `wookmark`, `jssor` slider,
  `mCustomScrollbar`, `progressive-image`, `hammer`, `icheck`, `bootstrap-select`) — none
  of this is per-site.
- **Shared theme functions** (`shanti_sarvaka_*` in `template.php`): breadcrumb,
  carousel, faceted search (`facetapi_count`), search-result preprocessing
  (`preprocess_apachesolr_search_snippets`), block/field/node/page preprocessing, a
  shared "get mandala home" helper, and a shared explore-menu renderer
  (`menu_tree__shanti_explore_menu`). This is the actual navigation, search UX, and
  cross-site "return to Mandala home" chrome — genuinely common, not duplicated per site.
- **Shared visual language**: Bootstrap grid + vertical tabs, `shanticon` icon font,
  a common color-strip banner region, faceted search styling, collections-view CSS, all
  declared once in the base theme, inherited everywhere.

## What's genuinely site-specific (do not converge these)

Each site's real divergence is narrow and maps directly to **what the content type needs
to be viewable**, not to look-and-feel:

- **Images** — the interactive deep-zoom viewer (already scoped in the [interactive
  surfaces deferred note](../deferred/images-missing-interactive-viewing-surfaces.md)).
- **AV** — Kaltura player integration, but this lives at the **module** layer
  (contrib `kaltura` + `KalturaClient`), not the theme — so it doesn't factor into a
  theme-baseline decision at all. It's tracked separately as
  [Spike 7](../spikes/spike-07-kaltura-av-integration.md), currently ○ Pending.
- **Texts** — reader-specific chrome (tabs) and dependency on footnote-adjacent modules;
  overlaps with the already-proven [Spike 4b](../spikes/spike-04b-ckeditor5-footnotes.md)
  work, which is a content/markup concern, not a theme one.
- **Sources** — citation/bibliography display (`search-bibliography`,
  `biblio-full-page`, `fancytree` styles) — relates directly to [Spike 5's bibcite
  work](../spikes/spike-05-bibcite-sources.md). Notably, D7 itself had **already disabled
  most of this** (stylesheets commented out in the live `.info` file), so "faithful port"
  here may mean porting less than expected.
- **Visuals** — depends on the `shivanode` module; not yet audited in any D11 planning
  doc. Flagging as a gap: Visuals has no ADR/spike/deferred-note presence at all today.

## Recommendation

**A shared D11 base theme is justified by evidence, not just intuition** — D7 already
built and ran one for eleven years across all six sites. The commonalities aren't
incidental overlap to be discovered case-by-case as each site migrates; they're a single
designed system (identical regions, identical page skeleton, identical JS/CSS foundation)
that Phase 2's per-site owners would otherwise each partially rebuild in isolation.

Concretely:

1. **Port `shanti_sarvaka`'s region layout and shared JS/CSS foundation as a D11 base
   theme before Phase 2 forks.** This is squarely "faithful migration" per ADR 008/010 —
   it's porting what's already there, not introducing a new design.
2. **Each site's real add-on work is a module/content concern, not a theme concern** —
   IIIF viewer (Images), Kaltura (AV, already its own spike), footnote rendering (Texts,
   already proven), bibcite display (Sources, already its own spike). None of these compete
   with or complicate a shared base theme; they layer on top of it exactly as they did in
   D7.
3. **Resolve the Visuals gap before Phase 2 site assignments are finalized** — it has no
   owner, no spike, no deferred note, and (per `sites.php`) is a real live production site
   distinct from Images.
4. **Confirm whether `sarvaka_projects` is truly dead** — a five-minute check (grep
   `sites.php` history / ask if any redirect still points at it) before anyone spends time
   auditing it further.

This audit does not itself decide *when* the base theme gets built (Sprint 2 vs. folded
into Images' close-out) — that's the open scheduling question already raised with Yuji.
What it settles is that the shared-baseline approach is sound: the D7 sites were never six
independently-designed UIs, they were one theme with six thin, content-driven skins.
