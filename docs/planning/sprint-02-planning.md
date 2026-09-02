# Sprint 2 Planning: D11 Base Theme, Images Interactive UI, and Other-Asset-Type Groundwork

**Audience:** Yuji, Xiaoming, Than — for team review before Sprint 2 implementation starts
**Date:** 2026-08-28
**Status:** Planning draft — implementation has not started. This document is the deliverable
of a planning session; workstreams below begin once the team has reviewed it.
**Relates to:** [D7 Theme / UI Commonalities Audit](theme-ui-commonalities-audit.md),
[Images UI gaps](../deferred/images-missing-interactive-viewing-surfaces.md),
[Uniform asset-endpoint access](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md),
[Images Content-Model Audit](images-content-model-audit.md) (methodology template for
the other-asset-type audits below), [ADR 009](../adr/009-migration-sequencing-strategy.md),
[ADR 010](../adr/010-adr-008-scope-clarification.md), [Sprint 1](../sprints/sprint-01-images-implementation.md)

---

## Context

Sprint 1 fully migrated Images (content, KMaps, Solr sync, Group collections, IIIF,
auth) — all 8 acceptance criteria closed. Two things Sprint 1 deliberately left out:

1. **No theme exists yet.** D11 still runs stock Olivero. But the 2026-08-25
   [theme/UI commonalities audit](theme-ui-commonalities-audit.md) found that the five
   legacy D7 sites (Images, AV, Sources, Texts, Mandala Home) were never six separate
   designs — they were thin Bootstrap sub-themes of one base theme, `shanti_sarvaka`,
   sharing identical regions, page templates, JS/CSS, and preprocessing. **D11's
   architecture goes further than D7's ever did: one Drupal instance, one theme is the
   default and starting point** — not one shared base designed around per-asset-type
   sub-themes or sub-identities. Where the language below or in the source audit says
   "site," read it as "legacy D7 precedent for this content type," not as a claim that
   D11 has separate site identities to theme individually. (The team has reserved,
   without committing to, the option of a subtheme for AV specifically if its
   complexity genuinely warrants one once real requirements are known — see workstream
   A's note below. That is not a plan to build one now.)
2. **Images' own UI is incomplete.** A 2026-08-19 review
   ([deferred note](../deferred/images-missing-interactive-viewing-surfaces.md)) found
   three interactive surfaces live on D7 that D11 never got: an OpenSeadragon deep-zoom
   viewer, an AJAX collection-carousel, and a masonry/gallery grid Views plugin.
   Deliberately deferred as "the next item after Sprint 1."

Separately,
[the uniform asset-endpoint access decision](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md)
already committed a third item to Sprint 2 (decided 2026-08-26): a uniform node-access
pattern every future site's endpoint must copy, plus a still-unstarted spike for the
authenticated-fetch half.

### Scope decisions made this planning session (2026-08-28)

- Build the base theme now, port Images' UI in full.
- For AV, Sources, and Texts: **content-model audits only** this sprint — the same
  data-driven field/entity-graph inventory Images got, no migration code, no scaffolding.
- AV's audit is **included** even though ADR 009 calls AV "hardest, last" for full
  migration — only the audit is pulled forward; Kaltura integration, the AV player, and
  transcripts stay deferred.
- **Corrected 2026-08-28, later the same session:** the theme carries **no dedicated
  regions or page-level slots per asset type.** An earlier draft of this plan proposed
  three reserved regions (`av_player`, `texts_reader_chrome`, `sources_citation_display`)
  — that modeled AV/Texts/Sources as future mini-sites-within-the-site, each eventually
  getting its own visual identity, which contradicts the one-site/one-theme decision.
  Corrected direction: AV/Texts/Sources' eventual asset-specific UI (a Kaltura player, a
  citation display, reader chrome) will be **components** — field formatters / view-mode
  variations rendered inside the **same shared regions and `page.html.twig`** every
  other content type uses, exactly the way Images' own IIIF viewer (workstream B) is
  built. No placeholder regions are needed for this — Drupal's per-bundle field-display
  configuration already provides the extension point, for free, once the shared theme
  exists. Nothing is built for AV/Texts/Sources now; this just corrects what "leaving
  room for later" concretely means.
- **Model the base theme directly off the real D7 theme files**, not just this audit
  doc's summary of them. Confirmed present at
  `~/Sandbox/Mandala/Site/mandala-drupal/docroot/sites/all/themes/`: `shanti_sarvaka`
  (base) + `sarvaka_images`, `sarvaka_mediabase`, `shanti_sarvaka_texts`,
  `sources_theme`, `sarvaka_shiva` (Visuals — retired, ignore), `sarvaka_projects`
  (likely dead, audit flags a 5-minute check), `sarvaka_kmaps`, `shiny`. The theme audit
  is the map of what's shared vs. site-specific; the actual `.tpl.php`/`template.php`/
  CSS/JS under this path are what gets ported.
- **The theme stays on the latest Bootstrap**, not a lift of D7's version.

## Workstreams and sequencing

```
A. Base theme  ──────► B. Images interactive UI (needs A's regions/templates first)
D. Uniform endpoint access (independent, parallel)
C. AV/Sources/Texts content-model audits (independent research, parallel)
```

A lands first (or at least its skeleton) since B's three surfaces need template
suggestions and library-attachment points to exist rather than being built against
Olivero and re-pointed later. C and D have no code dependency on A/B — split across
owners in parallel from day 1, matching how ADR 009 already modeled Than/Xiaoming
forking on Texts/Sources.

---

## Workstream A — D11 base theme

Build a new custom theme at `drupal/web/themes/custom/shanti_sarvaka/` (reusing the D7
name deliberately — signals lineage). This is a base theme with no subtheme declared
yet; AV/Texts/Sources/Home subtheming is explicitly deferred.

### Bootstrap version — confirmed real delta, not a straight port

D7's `shanti_sarvaka` bundled Bootstrap directly as vendor assets (no contrib base-theme
dependency — its `.info` file has no `base theme` line); confirmed **Bootstrap
3.x-era** (LESS source files present: `styleguide_bs/less/bootstrap.less`). The team
wants the D11 theme on **latest Bootstrap (5.x)**, which is a real upgrade, not a
lift-and-shift:

- **Recommended approach:** rather than hand-embedding raw Bootstrap 5 assets the way D7
  did, depend on the community-maintained Drupal contrib base theme (`drupal/bootstrap5`
  via Composer) and make `shanti_sarvaka` a genuine D11 subtheme of it
  (`base theme: bootstrap5` in the `.info.yml`). This is more idiomatic than D7's
  approach and gets Bootstrap 5's grid/utilities/JS maintained upstream instead of
  vendored by hand — a deliberate, disclosed improvement over the D7 pattern, justified
  the same way ADR 010 already permits internal-architecture improvements.
- **Flag, don't silently port:** Bootstrap 5 dropped jQuery and renamed its data-API
  (`data-bs-*` instead of `data-*`). Two vendor plugins in the shared JS set are tied to
  the old API and need real replacements, not version bumps: `bootstrap-select`
  (jQuery-plugin, targets Bootstrap 3/4 markup) and the vertical-tabs CSS
  (`bootstrap.vertical-tabs.min.css`, keyed to Bootstrap 3 tab markup). Identify
  Bootstrap-5-compatible equivalents (e.g. `bootstrap-select` has no official BS5
  build — evaluate `tom-select` or a similar maintained alternative) as part of this
  workstream, and note the swap explicitly rather than silently dropping or forcing the
  old plugin to work.
- The rest of the shared vendor set (wookmark, jssor slider, mCustomScrollbar, hammer)
  is Bootstrap-independent and ports without this concern.

### Files, in build order

1. `shanti_sarvaka.info.yml` — `base theme: bootstrap5`; the 12 D7 regions verbatim
   (`header`, `banner`, `content`, `search_flyout`, `search_results`, `sidebar_first`,
   `sidebar_second`, `highlighted`, `help`, `page_top`, `page_bottom`, `footer`,
   `admin_footer`), sourced from the real D7 `.info` files at the path above (confirmed
   identical across all sub-themes). **No additional regions are added** — one theme,
   one region set, for every content type and future asset type alike.
2. `templates/html.html.twig`, `page.html.twig` (renders all 12 regions), `node.html.twig`,
   `page--403.html.twig`, `page--404.html.twig`, `breadcrumb.html.twig` — twig ports of the real
   `page.tpl.php`/`html.tpl.php`/`node.tpl.php`/etc. at the D7 theme path, updated to
   Bootstrap 5 grid/utility classes where the base theme's markup uses them (structure
   and skeleton ported faithfully; twig syntax + BS5 classes are the D11-idiomatic
   changes).
3. `shanti_sarvaka.libraries.yml` — wraps the Bootstrap-independent shared vendor JS/CSS
   (wookmark, jssor slider, mCustomScrollbar, hammer) plus the resolved BS5-compatible
   replacement for `bootstrap-select`, as declared libraries (js/css keys, `core/drupal`
   + `core/once` deps, `bootstrap5/*` as a dependency where relevant) — replacing
   hardcoded script tags. Follow `shanti_kmaps_fields.libraries.yml`'s existing structure
   as the in-repo precedent for how a library entry should look.
4. `shanti_sarvaka.theme` — `hook_preprocess_html/page/node/breadcrumb()` porting the
   real `shanti_sarvaka_*` theme functions from `template.php` (breadcrumb, faceted
   search, search-result preprocessing, and the KMaps typeahead template — shared by
   every site with a KMaps field per Spike 1, so it belongs at this base layer).
5. `drupal/web/themes/custom/shanti_sarvaka/README.md` — documents the extension-point
   convention (below) and the Bootstrap-5 rationale/plugin-swap decisions, so both read
   as intentional, not dead code or an accidental scope change.
6. Only once 1–5 are visibly working: flip `drupal/config/sync/system.theme.yml`'s
   `default` from `olivero` to `shanti_sarvaka`. This is the one system-wide change — do
   it deliberately last, not first.

### The real extension mechanism for future AV/Texts/Sources UI — components, not regions

One site, one theme: AV/Texts/Sources get no dedicated regions, no sub-themes, and no
distinct page skin, now or later. Their eventual asset-specific UI is exactly the same
kind of thing Images' own interactive UI (workstream B) is: a **component** — a field
formatter or view-mode variation — that renders inside the shared `content` region like
everything else. The extension point that actually matters is Drupal's ordinary
per-bundle field-display configuration, which needs no theme-level scaffolding to exist
later:
- A future AV video field gets its own field formatter (parallel to how
  `IiifDeepZoomFormatter` will work for Images), rendering in the same `content` region.
- A future Sources citation display is a formatter/view-mode on the Sources bundle, same
  region.
- A future Texts reader chrome (tabs, footnotes) is likewise scoped to the Texts
  bundle's own field display, same region.
- Per-bundle node template suggestions remain available if a future bundle needs
  different *internal* field ordering (`node--shanti_av.html.twig` etc., available for
  free from Drupal's suggestion hierarchy once `node.html.twig` exists) — but this is an
  ordinary Drupal mechanism, not a placeholder anyone needs to build now.
- The README documents this as the intended pattern (pointing at workstream B's Images
  formatters as the concrete precedent) so the next implementer doesn't reach for a new
  region or a sub-theme by default.
- **Do not create any subtheme now** for AV/Texts/Sources/Home — one theme serves the
  whole site, and component-level extension (above) is the default approach.
  **Reserved, not ruled out (2026-08-28):** if AV — the most complex content type,
  per ADR 009's own "hardest, last" framing, with a Kaltura player that has real
  layout/behavior needs beyond a simple field formatter — turns out to need a subtheme
  once its content-model audit (workstream C) and actual migration are underway, that
  option stays open. Nothing in this sprint forecloses it; it would be a deliberate,
  revisited decision made against real requirements at that point, not a default to
  reach for now or to design around preemptively.

---

## Workstream B — Images interactive UI (build all three, in full)

All three reuse `shanti_iiif.url_builder`'s `infoUrl($i3fid)` and `buildUrl(...)`
methods (verified present in `drupal/web/modules/custom/shanti_iiif/src/IiifUrlBuilder.php`)
and the node-access pattern from `mandala_node_api.routing.yml`
(`_entity_access: 'node.view'` — verified, this is the literal string to copy).

### B1 — OpenSeadragon deep-zoom viewer

- New field formatter in `shanti_iiif` (extend, don't replace `IiifImageFormatter` —
  teasers/search still want the flat derivative):
  `shanti_iiif/src/Plugin/Field/FieldFormatter/IiifDeepZoomFormatter.php`,
  `@FieldFormatter(id = "iiif_deep_zoom")`. Renders a trigger + placeholder container.
- New `shanti_iiif.libraries.yml`: an `openseadragon` vendor library + a
  `shanti_iiif/deep-zoom` behavior library (`js/iiif-deep-zoom.js`, using
  `drupalSettings.shanti_iiif.infoUrls` — D11's `drupalSettings`, not D7's
  `Drupal.settings`) porting the real `shanti-main-images.js`
  (`Drupal.behaviors.shantiImagesIIIF`) overlay behavior (`#sddiv`/`.sdwrapper`/
  `#iiiftools`, rotation, navigator, Escape-to-close).
- **Open scope question to confirm before building:** D7's `sdviewer.php` +
  `shanti_images_sdinit.js` multi-image-sequence variant (for sorting/classifying
  workflows) is a related but distinct mechanism from the single-image viewer. Treat as
  **out of scope for Sprint 2** unless the team says otherwise.

### B2 — AJAX sibling carousel

- New module `drupal/web/modules/custom/shanti_images_carousel/` (Images-specific,
  small/single-purpose):
  - `shanti_images_carousel.routing.yml`: `/shanti-images/carousel/{node}`,
    `_entity_access: 'node.view'` (same gate as `mandala_node_api`, not reinvented).
  - `src/Controller/CarouselController.php`: resolve the node's owning collection using
    the **exact pattern already proven twice in-repo** —
    `\Drupal::entityTypeManager()->getStorage('group_relationship')->loadByEntity($node)`,
    filtering `$relationship->getGroup()->bundle()` to `['collection', 'subcollection']`
    (verbatim from `NodeJsonController::buildCollection()`, which itself mirrors
    `_mandala_group_inheritance_node_access()`'s lookup). **Do not write new
    group-query logic** — this is the third call site for the same lookup; extracting a
    shared service (e.g. `mandala_group_inheritance.collection_resolver`) is now a real
    candidate, without committing to it mid-sprint.
  - New logic actually needed (doesn't exist yet): given a resolved group, get all
    `shanti_image` member nids ordered, then window ±15 around the current node — port
    the intent of D7's `_shanti_images_get_coll_node_ids`, cached per-collection
    (D11 idiom: `\Drupal::cache()` keyed by group id, invalidated via a cache tag on the
    group entity).
  - `js/shanti-images-carousel.js` (own library): AJAX-load-into-placeholder behavior,
    attached from `node--shanti_image.html.twig` (workstream A's per-bundle suggestion)
    shipping the `#fscarousel-placeholder` equivalent.

### B3 — Masonry/gallery grid view

- New module `drupal/web/modules/custom/shanti_grid_view/` (not folded into
  `shanti_iiif` — D7's original was explicitly general-purpose, works with plain files/
  node images too, not just IIIF; keep it that way so it doesn't misrepresent
  `shanti_iiif`'s scope):
  - `src/Plugin/views/style/GridView.php`, `@ViewsStyle(id = "shanti_grid_view")`.
  - **Decision to make explicit, not silently pick:** port the D7 PIG masonry library
    faithfully vs. swap to a maintained modern masonry/CSS-grid library.
  - `shanti_grid_view.libraries.yml`: masonry JS + PhotoSwipe (click-to-popdown lightbox).
  - `src/Controller/GridInfoController.php`: the AJAX popdown-detail endpoint
    (`/shanti/grid/info/{type}/{eid}`), same `_entity_access` gate as B2.
  - No bespoke `shanti_grid_image_sizes`-style schema table — use `\Drupal::cache()`
    keyed by entity+style, or lean on image-style derivative caching (flag as a decision
    point, don't port the D7 table verbatim).
  - A Views config (`drupal/config/sync/views.view.all_image_gallery.yml` or similar)
    using the new style plugin for the homepage gallery.
  - Data source: read directly from the Views/entity-query result set (matching D7's
    own "auto-detected from the view's fields" approach) — **Solr/`mandala_kmassets_sync`
    is not needed** as this feature's data source; don't introduce that dependency.

**B sequencing:** B1 and B2 both touch `node--shanti_image.html.twig` — build together
first. B3 is homepage/gallery-only and independent; can run in parallel with a
different implementer.

---

## Workstream C — Content-model audits (AV, Sources, Texts) — audit only, no code

One doc per site, same structure as [`images-content-model-audit.md`](images-content-model-audit.md)
(Purpose → content-type/entity-graph inventory → field inventory → data profile against
a real dump → technical-debt/decision write-up as *open questions*, explicitly not
pre-decided by Images' Paragraphs precedent → explicit "what this audit does/doesn't
establish" boundary section).

### Legacy D7 modules to start from

(at `~/Sandbox/Mandala/Site/mandala-drupal/docroot/sites/all/modules/custom/`)

- **Texts:** `shanti_texts`, `shanti_texts_splitter`, `shanti_texts_features` (likely
  where the actual bundle/field-instance definitions live — a Features export),
  `shanti_texts_search_settings`, `shanti_footnotes` + `shanti_footnotes_ckeditor`.
- **Sources:** `shanti_biblio_modules` and its submodules (`sources_views`,
  `sources_text_format`, `sources_misc`, `biblio_import_mods`, plus Feature exports
  `sources_biblio_search`, `csc_zotero_importer`, `sources_misc_config`,
  `biblio_long_fields`, `sources_collection_views_links`) — bibcite-based, matches
  Spike 5.
- **AV:** no single obvious owner module — spread across contrib `kaltura` +
  `KalturaClient` library, custom `mediabase` (submodules `mb_access`, `mb_kaltura`,
  `mb_metadata`, `mb_services`, `mb_solr`, `mb_structure` — `mb_metadata`/`mb_structure`
  most likely hold the real content-type/field definitions), and `transcripts`
  (`transcripts_apachesolr`, `transcripts_ui`). **AV's audit will take longer to scope**
  than Texts'/Sources' since there's no single module to start from.

### Output files

`docs/planning/av-content-model-audit.md`, `docs/planning/sources-content-model-audit.md`,
`docs/planning/texts-content-model-audit.md`. Each must explicitly state (mirroring the
Images audit's own precedent-scope disclaimer) that no migration code, module
scaffolding, or `mandala_migrations` migration group is created as part of it — audit
only, per this planning session's explicit decision.

**Sequencing:** fully independent of A/B/D. Split across up to three owners in parallel.

---

## Workstream D — Uniform asset-endpoint access (already-decided scope, owned by Than)

### D1 — Document the enforcement pattern

Write-up, not new code — nothing else exists to converge yet: add a section to
[the deferred decision doc](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md)
(or a new `docs/planning/` pattern doc) stating, as committed convention for every
future site endpoint: declare `_entity_access: 'node.view'` (verbatim, per
`mandala_node_api.routing.yml`), no per-site exemptions. Cross-reference from B2/B3
(which already follow this) and from each of C's three audit docs (as a forward
pointer: "this site's future endpoint must follow the D1 pattern").

### D2 — Spike the authenticated-fetch identity-forwarding gap

Design spike, not implementation, per Than's stated intent to do this "with Claude":
new `docs/spikes/spike-0X-authenticated-asset-fetch.md` (next available spike number)
that:
- Restates the blocking gap from
  [`mandala-node-api-no-identity-forwarded-through-json-proxy.md`](../deferred/mandala-node-api-no-identity-forwarded-through-json-proxy.md).
- Evaluates resolving `sid` → uid server-side via a trusted call to solr-proxy: what new
  endpoint, what trust mechanism between `mandala_node_api`, `solr-proxy`, and the
  external `mandala-wp-proxy` plugin, latency cost of a synchronous cross-service call.
- Explicitly out of scope: touching the external `mandala-wp-proxy` repo, implementing
  the chosen option, or resolving the standalone-deployment question (tracked separately
  in [`option-a-proxy-unavailable-on-standalone-deployments.md`](../deferred/option-a-proxy-unavailable-on-standalone-deployments.md)).
- Deliverable is a recommended direction + follow-on task list for a later sprint — no
  module code lands for D2 in Sprint 2.

**D sequencing:** independent, can start day 1. Soft (non-blocking) benefit from B2/B3
existing as cross-reference examples for D1.

---

## Scope boundary

| Item | In / Out |
|---|---|
| D11 base theme (`shanti_sarvaka`, Bootstrap 5) | **In** |
| AV/Texts/Sources future-UI convention documented (component-level, no new regions) | **In** |
| AV/Texts/Sources site-specific theming | **Out** — after each site's own migration |
| Images: OpenSeadragon deep-zoom viewer | **In** |
| Images: AJAX sibling carousel | **In** |
| Images: masonry/gallery grid Views plugin | **In** |
| Images: multi-image sequence viewer (`sdviewer.php` equivalent) | **RESOLVED 2026-09-02: not needed** — see the sprint doc's Workstream B section and `docs/deferred/images-missing-interactive-viewing-surfaces.md` |
| AV/Sources/Texts content-model audits | **In** |
| AV/Sources/Texts migration code, module scaffolding | **Out** |
| Uniform endpoint-access pattern (D1) | **In** — documentation, no new code needed yet |
| Authenticated-fetch spike (D2) | **In** — spike/design doc only, not implementation |

## Next step once this doc is reviewed

Follow-on sprint doc `docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md`
(matching `sprint-01-images-implementation.md`'s format: Status line, per-workstream
backlog checklists, testable acceptance criteria, References) gets written once the team
confirms this scope, and implementation of workstreams A–D begins from there.

## Verification (once implementation starts)

- **Theme (A):** load the site in a browser, confirm `shanti_sarvaka` is active
  (`drush config:get system.theme default`), all 12 D7 regions render without PHP/twig
  errors — no regions beyond the original 12 — no visual regression against a side-by-side of the
  live D7 site's page skeleton. Confirm Bootstrap 5 is genuinely in use (BS5 JS/CSS
  loaded, `data-bs-*` attributes on interactive components, not `data-*`) and that the
  `bootstrap-select` replacement works without jQuery.
- **Images UI (B):** on a real migrated `shanti_image` node — click the deep-zoom
  trigger and confirm OpenSeadragon loads real tiles from the node's `info.json`;
  confirm the carousel AJAX-loads and windows the correct ±15 siblings from the node's
  real Group collection (test against both a public and a private collection to confirm
  the access gate holds); load the homepage gallery view and confirm the masonry grid
  renders with working click-to-popdown.
- **Audits (C):** each doc names concrete D7 field counts/entity-graph structure (not
  placeholders), cites the real dump used for its data profile, same bar as
  `images-content-model-audit.md`.
- **Endpoint access (D):** D1 — confirm the pattern doc explicitly names
  `_entity_access: 'node.view'` and cross-references B2/B3 as live examples. D2 —
  confirm the spike doc exists under `docs/spikes/` and states a recommended direction,
  not just a restatement of the problem.
