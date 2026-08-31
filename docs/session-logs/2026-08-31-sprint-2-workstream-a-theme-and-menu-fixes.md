# Session Log: Sprint 2 Workstream A — shanti_sarvaka base theme, group-driven header/menu fixes

**Date:** 2026-08-31
**Participants:** Than Grove (in a live group session with the rest of the team), Claude Code
**Outcome:** Closed all six of Workstream A's original backlog items (A1–A6) plus substantial follow-up work the group surfaced live in session. Opened [PR #169](https://github.com/uvalib/mandala-navina/pull/169), 16 commits, not yet merged — group visual sign-off on the live DDEV instance is the remaining step.

---

## 1. Session start: security-advisory block, cleared as a team decision

Before any theme work could begin, `composer require drupal/bootstrap5` hit a project-wide block: Composer's audit feature rejected nearly the entire dependency tree (`drupal/core` itself at 11.3.11, plus `group`, `paragraphs`, `search_api`, `simplesamlphp_auth`, and others) as affected by security advisories reaching past the currently-locked versions — not a bootstrap5-specific conflict. Flagged to the group rather than forced through unilaterally; the team's live call was to run a full `composer update --with-all-dependencies`, bumping `drupal/core` to 11.4.5 and contrib alongside it. Verified clean in DDEV (`composer install`, `drush updb`, `drush config:import`, `cache:rebuild` all succeeded, no new watchdog errors) before proceeding. Commit `9796e30`.

## 2. Workstream A1–A4: theme skeleton (mob-build, as scoped)

- **A1**: `shanti_sarvaka` scaffolded as a Bootstrap 5 subtheme, the 12 D7 regions declared verbatim, no additions — verified via `system_region_list()`.
- **A2**: Twig templates (`html`, `page`, `node`, `page--403`, `page--404`, `breadcrumb`) ported from the real D7 `.tpl.php` files, translated to BS5 grid/utility classes.
- **A3**: `shanti_sarvaka.libraries.yml` — wookmark/jssor/hammer/mCustomScrollbar vendored as-is (no Bootstrap-version dependency), `bootstrap-select` replaced with Tom Select 2.x (no official BS5 build exists for the original).
- **A4**: preprocess hooks for breadcrumb and search-result, plus the KMaps typeahead theme-hook registration — scoped to exactly what the backlog line named, not the full page-level site-identity variable set (`shanti_site`, `mandala_home`, banner styling), which stayed explicitly deferred.

Commits `cdc1f7b`, `9814b76`, `3b1db7c`, `1b281a7`.

## 3. A7 (added mid-session): block placement + a real D11-vs-D7 bug

Not one of the original six backlog items — added when the group looked at the live DDEV site and found it had no visible banner, footer, or title. Two causes, both real:

1. **Block placement**: `drush theme:enable` had auto-cloned bootstrap5's default block layout into regions that don't exist in `shanti_sarvaka`'s 12-region set, dumping almost everything into `header` unsorted. Placed the real blocks into the correct regions.
2. **A2 template bug**: `page.html.twig` had assumed D7's `template_preprocess_page()` variable set (`title`, `site_name`, `breadcrumb`, `tabs` as bare variables) carries over to D11. It doesn't — `ThemePreprocess::preprocessPage()` never sets those; they only exist in `preprocessMaintenancePage()` for maintenance/install pages with no blocks available. Fixed to render `page.header`/`page.banner` instead.

Commit `cfcec00`.

## 4. facets module installed (live team decision)

Raised as an open question (no ADR or deferred note had actually decided whether D11 needs the `facets` module), then decided live by the group mid-session: install it now. Scope: module install + enable only, `82da31b` — wiring an actual facet source to the `kmassets` index is separate follow-up work, not done here.

## 5. A8 (added mid-session): the theme didn't look like the site

Than compared the DDEV instance directly against `images.mandala.library.virginia.edu` and found they looked nothing alike — correct diagnosis: A1–A4/A7 only ported structural markup and block placement, never D7's actual visual identity. Ported `shanti-main.css` and five sibling stylesheets, the shanticon icon font, and the Museosans webfont verbatim, then rebuilt the Mandala icon+wordmark header lockup. Commit `211c085`.

Also pulled the current production Drupal tag (`7.x-1.43.10`) into the local D7 reference checkout mid-session and diffed it against the branch previously used for research — confirmed zero difference in the theme directory, so no rework was needed from the earlier reads.

## 6. Six rounds of group-driven header/menu fixes

What followed was iterative, screenshot-driven correction against the real production site, each round verified live in Chrome (not just visual inspection — computed `getBoundingClientRect()`/`elementFromPoint()` checks caught more than one case where a screenshot looked wrong but the actual DOM state was correct, and one case where it was a real bug):

- **Left-justification, working Explore/hamburger, gold banner, "Explore {title}"** (`d47a4cc`): traced the gold accent (`#ad8a28`) and the "Explore" title prefix to real, confirmed production CSS/HTML (including checking a 404 page) rather than guessing; found D11 uses `.path-frontpage` where D7's CSS keyed off `.front`.
- **Hamburger rebuilt as native Bootstrap 5 Offcanvas** (`d75bc5e`): D7's `multilevelpushmenu` jQuery plugin was never ported; confirmed with the group it isn't part of Bootstrap, then rebuilt the same right-docked/compact panel natively per their direction.
- **Explore trigger corrected** (`49667be`): Than's direct correction — Explore is not hidden on desktop and is not part of the hamburger's drilldown; it's its own persistent block sitting next to the hamburger. Moved out of the offcanvas.
- **Alignment + full-width Explore panel** (`486a470`): Explore/hamburger height mismatch and a broken (non-full-width, badly-positioned close button) Explore panel, both fixed against computed geometry.
- **Real drilldown menu structure** (`d0b149c`): added real parent+child test content (Collections → All Collections, matching production's actual menu, sourced from `explore_menu`'s own `shanti-collections.json`) and found bootstrap5 ships a more specific `menu--main.html.twig` that had been silently winning over the theme's own override.
- **Compact rows, no scroll, no bleed-through** (`2c667d8`), **whitespace/Back-size** (`4503070`), **hover contrast** (`fcefa97`): iterative sizing/spacing/color fixes against a reference screenshot Than provided, plus a real stacking-context bug (submenu not fully covering its container) and a real hover-state bug (white text on white background, traced to a specificity conflict between an override and D7's own ported `:hover` rule).

## 7. A5–A6 closed

- **A5**: `README.md` in the theme — BS5 rationale, the three plugin swaps and why, the component-level (not per-asset-type-region) extension pattern, and an explicit list of the open per-asset-type presentation questions this sprint surfaced but didn't resolve (accent color, banner icon, brand lockup second segment) so the next person adding AV/Sources/Texts UI doesn't have to rediscover them.
- **A6**: `system.theme.yml`'s default flipped from `olivero` to `shanti_sarvaka` for real — the several `drush config:set` calls earlier in the branch were local-only previews for Than to look at work in progress, explicitly not exported until A1–A5 were done, per the sprint doc's own sequencing.

Commit `5c1e2ba`.

## 8. PR opened

[PR #169](https://github.com/uvalib/mandala-navina/pull/169), targeting `main`, 16 commits. Test plan lists what's been verified live (block regions, Explore/hamburger interaction, hover states, the theme flip itself) vs. what still needs the group's own visual sign-off on the running DDEV instance.

## What's still open (see the theme README for full detail)

- **Per-asset-type accent color / banner icon / brand lockup second segment.** D7 had a real mechanism for this (per-site Color module config); D11's single shared theme has no direct equivalent. Currently hardcoded to Images' values since it's the only live asset type — needs an actual decision once a second asset type lands.
- **True multi-level menu drill-down** beyond one level — the Offcanvas rebuild handles one level; current menu content is flat, so this is unverified against real nested menus.
- **Faceted search UI** — module installed, not configured or wired to the `kmassets` index.
- **Content overflow inside the 266px hamburger panel** for any block wider than that (flagged early, not fully re-verified after the later sizing passes).

## Artifacts

| | |
|---|---|
| Opened, not yet merged | [PR #169](https://github.com/uvalib/mandala-navina/pull/169) — Sprint 2 Workstream A: shanti_sarvaka base theme + facets install |
| New | `drupal/web/themes/custom/shanti_sarvaka/` — the theme itself (templates, CSS, JS, README) |
| Config change | `drupal/config/sync/system.theme.yml` — default theme is now `shanti_sarvaka` |
| Config change | `drupal/composer.json`/`composer.lock` — core 11.3.11 → 11.4.5, `drupal/bootstrap5`, `drupal/facets` added |

## Next-session starting point

Get the group's visual sign-off on PR #169 against the live DDEV instance, then merge. After that, the open items above (per-asset-type accent color, faceted search wiring, multi-level menu verification) are natural next tickets — none of them block the merge itself.
