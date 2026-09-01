# Sprint 2: D11 base theme, Images interactive UI, and other-asset-type groundwork

**Status:** ◐ In progress — Workstream A (base theme) closed and merged 2026-09-01
([PR #169](https://github.com/uvalib/mandala-navina/pull/169)); B, C, D not yet started.
**Phase:** [Roadmap](../roadmap.md) Phase 2 setup — the shared theme foundation and
Images' remaining UI, plus the research groundwork Phase 2's per-site tracks (Texts,
Sources) and Phase 3 (AV) will need before they fork off.
**Lead:** Workstream A (base theme) is a **group/mob-build for now**, matching Sprint 1's
own "mob-build first, then individuals replicate the pattern" mode. Than leads B and D
(D already his per the prior decision). Workstream C splits across owners, matching
ADR 009's Texts/Sources fork: Than (Texts audit), Xiaoming (Sources audit), and the
**AV audit is also a group effort for now** (2026-08-28) — reflecting that AV's audit
was pulled forward outside ADR 009's original per-owner sequencing and has no single
source module to assign to one person (see workstream C1).
**Mode:** Workstream A built first, as a group, since B depends on it. B (Than) and D
(Than) proceed once A's skeleton exists. C2/C3 (Xiaoming/Than) can start immediately in
parallel; C1 (AV, group) likewise.
**Relates to:** [Sprint 2 planning doc](../planning/sprint-02-planning.md) (full design
rationale — read this first for the "why," this doc is the "what/when"),
[D7 Theme/UI Commonalities Audit](../planning/theme-ui-commonalities-audit.md),
[Images UI gaps](../deferred/images-missing-interactive-viewing-surfaces.md),
[Uniform asset-endpoint access](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md),
[Images Content-Model Audit](../planning/images-content-model-audit.md) (methodology
template for workstream C), [ADR 009](../adr/009-migration-sequencing-strategy.md),
[ADR 010](../adr/010-adr-008-scope-clarification.md), [Sprint 1](sprint-01-images-implementation.md)

---

## Goal

Close the two gaps Sprint 1 deliberately deferred — D11 has no theme yet, and Images'
own UI is incomplete — while laying only the *research* groundwork (content-model
audits, not code) for AV, Sources, and Texts so Phase 2's per-site tracks can fork off
with real data instead of guesswork. Also land the already-decided uniform
asset-endpoint access pattern and its blocked authenticated-fetch spike.

**One site, one theme is the architecture, now and as the default going forward.** The
base theme built here serves every current and future asset type through ordinary
per-bundle field-display components (exactly like Images' own IIIF viewer, workstream
B) — not through per-asset-type regions or sub-themes. The one exception the team has
explicitly reserved, without committing to, is a possible future AV subtheme if AV's
Kaltura-player complexity genuinely warrants it once its content-model audit and
migration are underway (workstream C1 / a later sprint) — that is not decided or built
here.

## Scope boundary

| In scope (Sprint 2) | Out of scope (later) |
|---|---|
| D11 base theme `shanti_sarvaka`, ported from the real D7 theme files, rebuilt on Bootstrap 5 | Per-asset-type regions, sub-themes, or distinct page skins (see Goal) |
| All three Images interactive UI surfaces: OpenSeadragon deep-zoom viewer, AJAX sibling carousel, masonry/gallery grid | D7's multi-image sequence viewer variant (`sdviewer.php` equivalent) — **open question, not decided either way** |
| Content-model audits for AV, Sources, Texts (data/field/entity-graph inventory only) | AV/Sources/Texts migration code, `mandala_migrations` scaffolding, any content-type/module creation |
| Uniform node-access pattern write-up (D1) | New endpoints for sites that don't have one yet (per Than, D7's AJAX endpoints are low-importance/low-consumer; default answer is no) |
| Authenticated-fetch identity-forwarding spike (D2) — design doc only | Implementing the spike's chosen direction; touching the external `mandala-wp-proxy` repo |

## Backlog

### Workstream A — D11 base theme (group/mob-build)

| | Task | Depends on | Status |
|---|---|---|---|
| A1 | `shanti_sarvaka.info.yml`: subtheme of contrib `bootstrap5`, the 12 D7 regions verbatim, no additional regions | — | ☑ |
| A2 | Twig templates (`html`, `page`, `node`, `page--403`, `page--404`, `breadcrumb`) ported from the real D7 `.tpl.php` files at `~/Sandbox/Mandala/Site/mandala-drupal/docroot/sites/all/themes/shanti_sarvaka/`, updated to Bootstrap 5 grid/utility classes | A1 | ☑ |
| A3 | `shanti_sarvaka.libraries.yml`: Bootstrap-independent shared vendor JS/CSS (wookmark, jssor slider, mCustomScrollbar, hammer) + a resolved Bootstrap-5-compatible replacement for `bootstrap-select` (no official BS5 build — evaluate `tom-select` or similar) | A2 | ☑ |
| A4 | `shanti_sarvaka.theme`: `hook_preprocess_*` porting breadcrumb, faceted search, search-result preprocessing, and the KMaps typeahead template from the real `template.php` | A2 | ☑ |
| A5 | `README.md` documenting the Bootstrap-5 rationale/plugin swaps and the component-level (not region-level) extension pattern for future asset types, pointing at workstream B's formatters as the concrete precedent | A1–A4 | ☑ |
| A6 | Flip `drupal/config/sync/system.theme.yml` default from `olivero` to `shanti_sarvaka` — the one system-wide change, done last once A1–A5 are visibly working | A1–A5 | ☑ |

### Workstream B — Images interactive UI

| | Task | Depends on | Status |
|---|---|---|---|
| B1 | OpenSeadragon deep-zoom viewer: `IiifDeepZoomFormatter` field formatter in `shanti_iiif`, reusing `IiifUrlBuilder::infoUrl()`; `shanti_iiif.libraries.yml` (OpenSeadragon vendor lib + behavior JS using `drupalSettings`, porting `shanti-main-images.js`'s overlay behavior) | Workstream A skeleton (A1–A2) | ☐ |
| B2 | AJAX sibling carousel: new `shanti_images_carousel` module, `_entity_access: 'node.view'` route, controller reusing the proven `group_relationship`/`loadByEntity()` collection-lookup pattern (`NodeJsonController::buildCollection()`), new ±15-windowing query cached per-collection, JS behavior attached from `node--shanti_image.html.twig` | Workstream A skeleton, B1 (shared template touch-point) | ☐ |
| B3 | Masonry/gallery grid view: new `shanti_grid_view` module, `GridView` Views style plugin, masonry + PhotoSwipe libraries, `GridInfoController` AJAX popdown endpoint (same access gate), Views config for the homepage gallery | Workstream A skeleton | ☐ |

**Open scope question (flag, don't silently resolve):** whether D7's multi-image
sequence viewer (`sdviewer.php` + `shanti_images_sdinit.js`) is in scope for B1 — treated
as out of scope for now per the planning doc, confirm with the team before B1 closes.

### Workstream C — Content-model audits (AV, Sources, Texts) — audit only

| | Task | Owner | Status |
|---|---|---|---|
| C1 | `docs/planning/av-content-model-audit.md` — start from contrib `kaltura`/`KalturaClient`, custom `mediabase` (`mb_metadata`/`mb_structure` most likely), `transcripts`. No single owner module — expect this to take longer to scope than C2/C3. | **Group** | ☑ |
| C2 | `docs/planning/sources-content-model-audit.md` — start from `shanti_biblio_modules` and its submodules (bibcite-based, matches Spike 5) | Xiaoming | ☑ |
| C3 | `docs/planning/texts-content-model-audit.md` — start from `shanti_texts`/`shanti_texts_features`/`shanti_footnotes` | Than | ☑ |

Each audit follows `images-content-model-audit.md`'s structure (purpose →
content-type/entity-graph inventory → field inventory → data profile against a real
dump → technical-debt/decision write-up as open questions, explicitly not pre-decided by
Images' own Paragraphs precedent) and must explicitly state that no migration code or
`mandala_migrations` scaffolding is created as part of it.

### Workstream D — Uniform asset-endpoint access

| | Task | Depends on | Status |
|---|---|---|---|
| D1 | Document the `_entity_access: 'node.view'` enforcement pattern (verbatim from `mandala_node_api.routing.yml`) as committed convention for every future endpoint; cross-reference from B2/B3 and each of C's audit docs | B2/B3 as live examples (soft dependency) | ☐ |
| D2 | `docs/spikes/spike-0X-authenticated-asset-fetch.md` — design spike (not implementation) for the identity-forwarding gap blocking authenticated JSON/AJAX fetch; evaluates a trusted `sid`→uid resolution call to solr-proxy; explicitly excludes touching `mandala-wp-proxy` or implementing the chosen direction | — | ☐ |

## Acceptance criteria

- [ ] `shanti_sarvaka` is the site's default theme (`drush config:get system.theme
      default`), a genuine Bootstrap 5 subtheme (BS5 JS/CSS loaded, `data-bs-*`
      attributes, no jQuery-only BS3/4 plugins left unreplaced), renders all 12 D7
      regions with no visual regression against the live D7 site's page skeleton, and
      adds no additional regions.
- [ ] A real migrated `shanti_image` node page shows a working OpenSeadragon deep-zoom
      viewer sourced from the node's real `info.json`.
- [ ] The sibling carousel AJAX-loads and windows the correct ±15 collection members
      around the current node, and respects private-collection access (verified against
      both a public and a private collection).
- [ ] The homepage gallery renders via the new `shanti_grid_view` Views style plugin
      with working click-to-popdown.
- [x] `av-content-model-audit.md`, `sources-content-model-audit.md`, and
      `texts-content-model-audit.md` all exist, each naming its site's real D7
      field/entity-graph structure (not placeholders) against a real dump, each
      explicitly scoped as audit-only.
- [ ] The node-access enforcement pattern is written up as the copyable convention for
      future endpoints, cross-referenced from B2/B3.
- [ ] The authenticated-fetch spike doc exists under `docs/spikes/` and names a
      recommended direction, not just a restatement of the blocking gap.

## References

- [Sprint 2 planning doc](../planning/sprint-02-planning.md) — full design rationale,
  including the one-site/one-theme correction and the reserved-AV-subtheme note
- [ADR 004](../adr/004-solr-source-of-truth.md) — IIIF/Solr stay as-is (relevant to B1)
- [ADR 009](../adr/009-migration-sequencing-strategy.md) — Texts/Sources fork, AV last
- [ADR 010](../adr/010-adr-008-scope-clarification.md) — internal remodeling permitted
  (justifies the Bootstrap-5 upgrade over a faithful Bootstrap-3 port)
- [ADR 011](../adr/011-group-collections-inheritance.md) — Group inheritance (relevant
  to B2 and D)
- [ADR 013](../adr/013-drupal-source-of-truth-solr-client-compatibility.md),
  [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — relevant to D2
- [D7 Theme/UI Commonalities Audit](../planning/theme-ui-commonalities-audit.md),
  [Images UI gaps](../deferred/images-missing-interactive-viewing-surfaces.md) — B's
  source docs
- [Images Content-Model Audit](../planning/images-content-model-audit.md) — C's
  methodology template
- [Uniform asset-endpoint access](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md),
  [identity-forwarding gap](../deferred/mandala-node-api-no-identity-forwarded-through-json-proxy.md) —
  D's source docs
- [Sprint 1](sprint-01-images-implementation.md) — format template for this doc
