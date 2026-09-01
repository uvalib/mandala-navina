# shanti_sarvaka

D11 base theme for the Mandala Digital Library, serving every current and
future asset type through one shared theme (Images today; AV, Sources,
Texts as they migrate) -- not per-asset-type sub-themes or regions. This
is a deliberate architecture decision (Sprint 2 planning), not an
oversight: see the extension pattern below before adding anything
per-asset-type-specific.

## Why Bootstrap 5, not a faithful Bootstrap 3 port

The real D7 `shanti_sarvaka` theme (all five D7 sites shared it as a base
theme) was built on Bootstrap 3.3.4, loaded from a CDN. This D11 port
is a genuine upgrade to Bootstrap 5, not a version-matched copy --
justified under [ADR 010](../../../../docs/adr/010-adr-008-scope-clarification.md)
("migrate, not improve" covers user-facing features; internal
architecture/tooling upgrades are fair game). Two things forced the
question rather than leaving it optional:

- Bootstrap 3 is long past end-of-life; there's no reason to ship a new
  D11 site on it.
- BS5 dropped several structural things D7's markup relied on outright
  (`.navbar-header{float:left}` doesn't exist anymore, the `.navbar >
  .container-fluid` flex model is different) -- porting the D7 templates
  required accounting for this either way, so upgrading to BS5 cleanly
  cost little extra over patching around BS3-specific behavior that no
  longer exists.

## Plugin swaps

| D7 dependency | D11 replacement | Why |
|---|---|---|
| `bootstrap-select` (jQuery plugin) | [Tom Select](https://tom-select.js.org/) 2.x | No official Bootstrap 5 build of bootstrap-select exists. Tom Select is vanilla JS (matches BS5's own move away from a hard jQuery dependency) and ships its own `tom-select.bootstrap5.min.css` theme. Registered as the `shanti-select` library so consuming code doesn't need to know the underlying library changed. |
| `jquery.multilevelpushmenu` (jQuery plugin) | Bootstrap 5 native [Offcanvas](https://getbootstrap.com/docs/5.3/components/offcanvas/) | Same right-docked/compact slide-in intent as D7's drilldown menu, no jQuery. Not a byte-for-byte port -- see `templates/menu.html.twig`'s own doc comment for what is and isn't reproduced (single-level slide via custom JS, not the plugin's own multi-level push animation). |
| wookmark, jssor slider, hammer.js, mCustomScrollbar | same libraries, vendored as-is | No Bootstrap-version dependency, so these carried over unchanged. Vendored directly under `js/vendor/`/`css/vendor/` rather than pulled from a build pipeline, since this project has no npm/webpack step -- see `shanti_sarvaka.libraries.yml`. |

## Component-level extension pattern (read this before adding per-asset-type UI)

**The theme has no per-asset-type regions, and none should be added.**
`shanti_sarvaka.info.yml` declares the same 12 regions D7's shared base
theme used, verbatim, with nothing appended. When AV, Sources, or Texts
need their own UI (a Kaltura player, a citation display, a footnote
apparatus), the pattern is the same one Workstream B used for Images'
own IIIF viewer: a **field formatter** (or Views style plugin) scoped to
that content type, rendering into the ordinary `content` region like any
other field -- not a new region, not a conditional per-site branch in a
shared template.

The one exception the team has explicitly *reserved, not decided*: a
possible future AV-specific sub-theme, only if Kaltura's UI complexity
turns out to genuinely warrant it once AV's migration is underway. That
is a call for whoever picks up AV, informed by what that migration
actually needs -- not something to pre-build here.

### The same tension shows up in styling, unresolved

A few real per-asset-type presentation questions surfaced while building
this theme and are deliberately left open rather than guessed at:

- **Accent color.** D7 gave each site (Images, AV, Sources, ...) its own
  color scheme via Drupal's Color module, configured on that site's own
  theme instance. D11 has one shared theme, so there's no natural home
  for "Images is gold, AV is [X]" anymore. Right now `shanti-sarvaka.css`
  hardcodes the gold accent (`#ad8a28`, confirmed from live production
  CSS) globally, simply because Images is the only real asset type live.
  Whoever adds the second asset type needs to actually decide this --
  a CSS custom property keyed off route/content-type is one option, not
  the only one.
- **The "Explore {title}" banner prefix and its icon** (`page-title.html.twig`)
  are likewise hardcoded to the Images-appropriate default
  (`shanticon-photos`) for the same reason.
- **The brand lockup's second segment.** D7 showed "MANDALA > Images"
  (or AV, or Sources...), selected per D7 *site*. D11's single-site
  architecture has no per-site signal to key that off anymore -- it
  would need to come from the route/asset-type instead, tying into
  [ADR 016](../../../../docs/adr/016-public-url-structure-single-host.md)'s
  asset-type-namespaced URLs. Not built here; `page.html.twig` just shows
  the "MANDALA" wordmark alone.

## What's ported vs. not

Faithful, verbatim ports (confirmed against the real D7 source and, where
questions came up, against live production -- not assumed from the D7
source alone):

- `shanti-main.css` and five sibling stylesheets, the shanticon icon
  font, Museosans webfont (`css/`, `fonts/`).
- The Explore collections panel content, sourced from
  `shanti_general`'s `explore_menu` submodule and its
  `shanti-collections.json`, not scraped HTML.
- The drilldown hamburger panel's typography/spacing (`.menu-main`
  classes), matched against a real screenshot of production, not the D7
  CSS alone -- the ported CSS's own sizing for that class turned out to
  be wrong for this context (see `shanti-sarvaka.css`'s comments) since
  it was written for the old plugin's different DOM.

Explicitly not ported, flagged rather than silently skipped:

- `facetapi_count`'s badge styling -- nothing to theme until faceted
  search (the `facets` module, installed but not configured) actually
  lands.
- True multi-level menu drill-down (nested submenus beyond one level) --
  the current Offcanvas rebuild handles one level; no real menu content
  has needed more yet.
- Per-asset-type accent color / brand segment -- see above.

## Local development

```bash
ddev drush theme:enable shanti_sarvaka   # already the default; only needed on a fresh install
ddev drush cache:rebuild                 # after any template/library/CSS change
```

No build step -- CSS and JS are hand-authored or vendored directly, no
npm/webpack/gulp involved.
