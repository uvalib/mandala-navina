# Sprint 2: D11 base theme, Images interactive UI, and other-asset-type groundwork

**Status:** ◐ In progress — Workstreams A, C closed; B1/B3/B4 closed (B2 open); D not
yet started. Workstream A (base theme) closed and merged 2026-09-01
([PR #169](https://github.com/uvalib/mandala-navina/pull/169)).
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
| All three Images interactive UI surfaces: OpenSeadragon deep-zoom viewer, AJAX sibling carousel, masonry/gallery grid | D7's multi-image sequence viewer variant (`sdviewer.php` equivalent) — **RESOLVED 2026-09-02: not needed, was an unfinished D7 prototype never reachable in production** |
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
| B1 | OpenSeadragon deep-zoom viewer: `IiifDeepZoomFormatter` field formatter in `shanti_iiif`, reusing `IiifUrlBuilder::infoUrl()`; `shanti_iiif.libraries.yml` (OpenSeadragon vendor lib + behavior JS using `drupalSettings`, porting `shanti-main-images.js`'s overlay behavior) | Workstream A skeleton (A1–A2) | ☑ |
| B2 | AJAX sibling carousel: new `shanti_images_carousel` module, `_entity_access: 'node.view'` route, controller reusing the proven `group_relationship`/`loadByEntity()` collection-lookup pattern (`NodeJsonController::buildCollection()`), new ±15-windowing query cached per-collection, JS behavior attached from `node--shanti_image.html.twig` | Workstream A skeleton, B1 (shared template touch-point) | ☐ |
| B3 | Masonry/gallery grid view: new `shanti_grid_view` module, `GridView` Views style plugin, masonry + PhotoSwipe libraries, `GridInfoController` AJAX popdown endpoint (same access gate), Views config for the homepage gallery. **Built and verified live 2026-09-01**; wired as the actual front page (`system.site.yml` `page.front: /gallery`) same day. **6 real popdown/gallery bugs reported from live use, all fixed and verified 2026-09-01→09-03** (scroll-to-panel, loading spinners, details text styling, same-row re-click, prev/next nav arrows, duplicate title + search/sort row CSS — see [PR #180](https://github.com/uvalib/mandala-navina/pull/180)). PhotoSwipe lightbox and the D7 data-source (non-entity) view mode were deliberately not ported; scope stayed to the entity/node case per the production-reference doc | Workstream A skeleton | ☑ |
| B4 | KMaps popover ("mandala popover"): hover popover on every KMaps place/subject/term tag (icon trigger, term info + ancestor breadcrumb, "Full Entry" link, "Related X (N)" links). New `KmapsPopoverInfoService` in `shanti_kmaps_fields` (in-process, not D7's self-referential HTTP round-trip), a new `kmap_popover_formatter` (server-rendered, no AJAX), BS5 popover JS behavior. **Built and verified live 2026-09-02** — see below | Workstream A skeleton (Bootstrap 5 popover), `shanti_kmaps_fields` (field type, already proven) | ☑ |

**Scope question RESOLVED 2026-09-02 (Than): not needed for D11.** `IiifDeepZoomFormatter`
renders a single-image viewer only, as built. Checked the actual D7 source at
`docroot/sites/all/modules/custom/shanti_images/` in the legacy `mandala-drupal` repo:
`sdviewer.php` is an unfinished standalone test page (titled "Test of SeaDragon",
hardcodes one tile source off a `?json=` query param) — the beginning of a new function,
never wired into any live page. `js/shanti_images_sdinit.js` does compute `is_series`
correctly from a `data-iiifurls` (`|$|`-delimited) attribute, but nothing in the module
ever sets that attribute anywhere, so the sequence path was never reachable in D7
production either. No D11 work needed; see
[`images-missing-interactive-viewing-surfaces.md`](../deferred/images-missing-interactive-viewing-surfaces.md)
for the full pointer, kept for reference in case a real multi-image sequence need comes
up later.

#### B4 — KMaps popover ("mandala popover"), planning

**Raised 2026-09-02 (Than): a feature never previously flagged to the team, found to be
central to the production UI.** On production, every KMaps place/subject/term tag (e.g.
in the gallery info panel's tag row) carries a white speech-bubble icon; hovering shows a
tooltip with term info, an ancestor breadcrumb, a "Full Entry" link, and "Related X (N)"
links to other asset types. D11's current tags (`KmapsDefaultFormatter`) render as plain
static links — this is the gap this item closes.

**Investigated the real D7 mechanism before planning** (not the tempting but wrong lead):
the visible widget is **not** `kmaps_explorer`'s old client-side `jquery.kmaps-popup.js`
(that path does raw JSONP straight to Solr and its own module comments mark the
count-fetching functions it duplicates as deprecated 2017 — do not port it). The real,
current mechanism is `shanti_kmaps_fields`'s **`kmap_popover_formatter`** field formatter
— server-side PHP on the exact field type D11 already has (Spike 1, `KmapsItem`). Two
Solr calls per tag: (1) a single term-info lookup on `kmterms` (`q=uid:{domain}-{id}`) for
header/ancestors/feature-types; (2) grouped nested-query counts against `kmterms` +
`kmassets` for the "Related X (N)" links, only shown when count > 0.

**Already in place in D11, no extra work needed:**
- The anchor point already exists: `node--shanti-image--grid-details.html.twig` (B3's
  info panel) already renders `field_places`/`field_subjects` through
  `KmapsDefaultFormatter`, which **already emits `data-kmaps-key="places-41"`** on every
  tag — just as a plain link today.
- The Solr endpoints are already configured correctly:
  `shanti_kmaps_admin.settings`'s `server_solr_terms`/`server_solr` both already route
  through `mandala-solr-proxy`, not raw client-side JSONP like D7's old widget.
- Bootstrap 5's popover component (Popper-based, `bootstrap.Popover` JS API) is already
  loaded site-wide — see the correction below for exactly how D7 drives it (static
  pre-rendered content, not the declarative `data-bs-toggle` attribute).
- **A real simplification D11 gets for free**: D7's count-fetching is a *self-referential
  HTTP round-trip* — the site calls its own `/mandala/popover/populate/{domain}/{id}`
  endpoint over the network (flagged in the D7 source itself as a worker-pool-exhaustion
  risk under load). D11 is single-site (ADR 005) — this becomes a plain in-process
  service call, no HTTP hop, no separate self-call endpoint needed for that leg.

**Correction after reading the actual D7 rendering + JS (not just the formatter PHP):**
the earlier "lazy AJAX on hover" idea below was wrong — production does NOT lazy-fetch.
`shanti_sarvaka_info_popover()` (the theme's override of `theme_info_popover`,
`themes/shanti_sarvaka/template.php:1002`) renders the **entire popover body inline**,
server-side, as a hidden sibling `<div class="popover" style="display:none">` right next
to the tag — term description, feature types, ancestor breadcrumb, and every non-zero
"Related X (N)" link, all pre-built into static HTML at node-render time. The trigger
icon is `<span class="popover-link"><span class="popover-link-tip"></span><span
class="icon shanticon-menu3"></span></span>`, wrapped together with the tag label in
`<span class="kmap-tag-group" data-kmdomain="{domain}" data-kmid="{id}">`.
`shanti-main.js`'s `Drupal.behaviors` (`js/shanti-main.js:364`) just finds each
`.popover-link`, reads its sibling `.popover` div's `innerHTML` + `data-title`, and
initializes Bootstrap's popover plugin with that as **static content** — zero AJAX calls
ever, for any tag, at any time. All the Solr cost is paid once per node render, offset by
the ~12h cache on both Solr calls.

**This simplifies the D11 build — confirmed already in place, nothing new to add:**
- CSS is **already ported verbatim** in `shanti-main.css` (`.kmap-tag-group`,
  `.popover-link`, `.popover`, `.popover-footer`, `.popover-footer-button` — all present,
  lines ~2035–2245) as part of Workstream A's wholesale theme port. No new CSS needed.
- `bootstrap.bundle.js` (Popper + `bootstrap.Popover`) is **already loaded site-wide**
  via `bootstrap5/bootstrap5-js-latest`, a declared dependency of `shanti_sarvaka`. No new
  vendor JS needed.
- **No new route or controller** — since content is server-rendered inline, not fetched.

**Concrete build shape:**
1. **`KmapsPopoverInfoService`** (new, `shanti_kmaps_fields/src/KmapsPopoverInfoService.php`,
   registered in `shanti_kmaps_fields.services.yml`):
   - `getTermInfo(string $domain, int $id): array` — one Solr GET to
     `{server_solr_terms}/select?q=uid:{domain}-{id}&wt=json` via Drupal's `http_client`
     (Guzzle), decode `response.docs[0]`. Cached (D11 cache API, bin `cache_default` or a
     dedicated `cache_kmaps_popover` bin, key `kmaps_popover:info:{domain}-{id}`, TTL from
     a new `shanti_kmaps_admin.settings:popover_cache_ttl` config, default 43200s/12h
     matching D7).
   - `getRelatedCounts(string $domain, int $id): array` — ports
     `kmaps_explorer_get_popover_data()`'s three domain-specific branches
     (`places`/`subjects`/`terms`, each with different nested-child-doc Solr query shapes
     against `kmterms`) plus the shared `kmassets` asset-type-grouped count query, merged
     and mapped to the 7 category keys (`subjects`, `places`, `images`, `audio-video`,
     `sources`, `texts`, `visuals`) exactly as
     `shanti_kmaps_fields_get_all_counts_by_kmapid()` does — but as one in-process method,
     not a self-HTTP-call to a separate endpoint. Cached the same way.
   - Both methods read Solr URLs from `shanti_kmaps_admin.settings` (`server_solr_terms`,
     `server_solr`) — already configured, already routed through `mandala-solr-proxy`.
2. **New `KmapPopoverFormatter`** (`src/Plugin/Field/FieldFormatter/KmapPopoverFormatter.php`,
   `@FieldFormatter(id = "kmap_popover_formatter", label = "KMaps Tags (with popover)")`
   on `shanti_kmaps_fields_default`) — for each item, calls the service, builds a render
   array matching D7's `info_popover` theme variables (`label`, `domain`, `kid`, `ftypes`,
   `desc`, `tree` (ancestor breadcrumb), `links` (Full Entry + non-zero Related-X links,
   using the existing `explorer_{domain}` URL templates already in
   `shanti_kmaps_admin.settings`)).
3. **New Twig template** `templates/kmaps-popover.html.twig` replicating
   `shanti_sarvaka_info_popover()`'s exact markup structure (`.kmap-tag-group` wrapper,
   `.popover-link` trigger, hidden sibling `.popover` div with `.popover-body`/
   `.popover-footer`) so the already-ported CSS applies with zero changes.
4. **New JS behavior** (`shanti_kmaps_fields.libraries.yml` → new `kmaps_popover` library,
   `js/kmaps-popover.js`) — `Drupal.behaviors.kmapsPopover`, using `once()`, porting
   `shanti-main.js`'s logic: find each `.popover-link`, read the sibling `.popover` div's
   content (already in the DOM, no fetch), initialize `new bootstrap.Popover(el, {title,
   content, html: true, trigger: 'hover focus', placement: 'bottom', container: 'body'})`.
   Module-level (not gallery-specific) since this is field-type behavior — the gallery
   panel is just today's one consumer.
5. **View mode config**: `core.entity_view_display.node.shanti_image.grid_details.yml` —
   switch `field_places`/`field_subjects`/`field_kmap_terms` to `type:
   kmap_popover_formatter`, un-hide `field_kmap_terms`.

**Scope decisions:**
- ~~Fold in enabling `field_kmap_terms` display in `grid_details`?~~ **DECIDED
  2026-09-02 (Than): yes.** `field_kmap_terms` is currently `hidden: true` in
  `core.entity_view_display.node.shanti_image.grid_details.yml` — a deliberate
  per-view-mode visibility setting, not a bug, but production shows all three
  categories (places/subjects/terms) in the same tag row and D11's panel currently
  shows only two. Flip it to displayed as part of this work.

- ~~One formatter with a setting, or two formatters?~~ **DECIDED 2026-09-02 (Than): two
  formatters**, matching D7's `kmap_default_formatter`/`kmap_popover_formatter` split.
  Reasoning: it's the least-surprising match to the legacy site; formatter selection is
  already Drupal's native mechanism for "same field, different display" so a setting
  toggle would reinvent that; and this is explicitly a special-case use — the plain
  default formatter may still have other, unconfirmed uses elsewhere, so keeping them
  as separate plugins avoids coupling the popover behavior to every consumer of the
  default one.

- ~~Full "Related X" category parity vs. trim to what's non-zero today?~~ **DECIDED
  2026-09-02 (Than): full parity.** Build all six category count queries (Sources,
  Audio-Video, Photos, Texts, Visuals, Places/Subjects) now, even though most will show
  0 (hidden) until Sources/Texts/AV migrate — the query shape is identical per category
  (one extra `groupValue` branch each), so the marginal build cost is small, and this
  avoids a follow-up ticket to add each category back in as every future site migrates.

**All scope questions resolved — plan is ready to implement.**

**Built and verified live 2026-09-02.** Implemented exactly to spec:
`KmapsPopoverInfoService` (Solr term-doc lookup + domain-specific related-count
queries, cached), `KmapPopoverFormatter` (`kmap_popover_formatter`, server-rendered
`#theme: kmaps_popover` render array), `kmaps-popover.html.twig` (matching
`shanti_sarvaka_info_popover()`'s markup exactly), `kmaps-popover.js` (Bootstrap
popover wiring, reading the pre-rendered sibling content, no AJAX), and the
`grid_details` view mode switched to the new formatter with `field_kmap_terms`
un-hidden.

Two real bugs found and fixed during live verification (Chrome, DDEV, node 30289 —
real data: 1 place, 26 subjects, 4 terms):
1. **Render array used bare keys instead of `#`-prefixed ones** on the `'#theme' =>
   'kmaps_popover'` array (`'label' => ...` instead of `'#label' => ...`) — Drupal's
   `Element::children()` treats any non-`#` key as a child render element to
   recurse into, so a plain string value threw `InvalidArgumentException`. Fixed by
   explicitly prefixing every key.
2. **The popover JS library never reached the browser.** `GridInfoController`
   returns the info panel as a raw HTML string via `renderInIsolation()`, which
   drops all `#attached` assets by design — and the panel's own insertion JS
   (`shanti-grid-view.js`) sets `innerHTML` directly via `fetch()`, not through
   Drupal's AJAX framework, so `Drupal.attachBehaviors()` never ran on the inserted
   content either. Fixed both halves: added `shanti_kmaps_fields/kmaps_popover` as a
   dependency of `shanti_grid_view`'s `masonry-grid` library (so it's already loaded
   on the parent `/gallery` page before any panel opens) and added an explicit
   `Drupal.attachBehaviors(panelBody)` call right after the fragment is inserted.
   **Same trap applies to any future formatter used inside `grid_details` that
   needs its own JS** — `#attached` alone will never be enough for content rendered
   through this controller.

Verified live end-to-end via the real `/gallery` → search → click-tile flow:
correct popover content (label, description, feature type, ancestor breadcrumb,
"Full Entry" link) and correct non-zero "Related X (N)" counts and icons for every
category. Zero console errors.

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

- [x] `shanti_sarvaka` is the site's default theme (`drush config:get system.theme
      default`), a genuine Bootstrap 5 subtheme (BS5 JS/CSS loaded, `data-bs-*`
      attributes, no jQuery-only BS3/4 plugins left unreplaced), renders all 12 D7
      regions with no visual regression against the live D7 site's page skeleton, and
      adds no additional regions.
- [x] A real migrated `shanti_image` node page shows a working OpenSeadragon deep-zoom
      viewer sourced from the node's real `info.json`. (Verified 2026-09-01 against
      node 111339 / `shanti-image-680701` in DDEV; see PR #170 and
      `docs/session-logs/2026-09-01-b1-openseadragon-deep-zoom-viewer.md`.)
- [ ] The sibling carousel AJAX-loads and windows the correct ±15 collection members
      around the current node, and respects private-collection access (verified against
      both a public and a private collection).
- [x] The homepage gallery renders via the new `shanti_grid_view` Views style plugin
      with working click-to-popdown, wired as the site's actual front page
      (`system.site.yml` `page.front: /gallery`, done 2026-09-01). All 6 real
      popdown/gallery bugs reported from live use fixed and verified 2026-09-03
      ([PR #180](https://github.com/uvalib/mandala-navina/pull/180)).
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
