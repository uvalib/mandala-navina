# Session Log: D7 theme/UI commonalities audit; Visuals retirement recorded

**Date:** 2026-08-25
**Participants:** Than Grove, Yuji Shinozaki, Claude Code
**Outcome:** Confirmed JSON API path standardization with Yuji ([PR #146](https://github.com/uvalib/mandala-navina/pull/146),
merged), then ran a new planning audit of D7's theme layer ahead of Sprint 2 scoping
([PR #151](https://github.com/uvalib/mandala-navina/pull/151)). Headline finding: D7's six
sites shared one theme system, not six independent ones — which argues for a shared D11
base theme before Phase 2 forks to per-site owners. Also recorded a team decision:
**Visuals is being retired, not migrated to D11.**

## 1. JSON API path standardization confirmed (PR #146, merged)

Yuji agreed that the JSON API should stay flat and un-namespaced across every content
type — `/api/json/{nid}`, no per-type path segment. Verified directly against the live
routing code (`mandala_node_api.routing.yml` / `NodeJsonController.php`): the route
already implements this, and the controller's `shanti_image`-only restriction reflects
migration scope (only Images exists in D11 yet), not a type-specific path design. This
matches decision 4 of the ADR 016 draft, which merged to `main` in the same window
([#143](https://github.com/uvalib/mandala-navina/pull/143)). No code change was needed —
recorded as a session log for the record.

## 2. Four PRs landed on main mid-session (#142–#145)

While this was in flight, Yuji's Images migration work merged four PRs directly to
`main`: #142 (sprint doc correction — Step 1b is now marked complete, all four OAuth2
defects fixed and verified live 2026-08-20), #143 (ADR 016 — public URL structure,
Proposed), #144 (DB checkpoints + memory-safe drush path), #145 (D7 pathauto path
preservation as D11 `path_alias` entities). The `confirm/jsonapi-path-standardization`
branch was rebased onto the updated `main` before its PR was opened, no conflicts.

## 3. D7 theme/UI commonalities audit (PR #151)

Prompted by a broader question: now that Images' *data* migration is closing out Sprint
1, does UI migration make sense as the next work? Two distinctions sharpened the framing
before any audit work started:

- **Drupal-native theme UI, not the React app.** Than clarified this was about each D7
  site's own Drupal look-and-feel (deep-zoom viewer, player, etc.), not `mandala-om`
  (embedded in WordPress) — the roadmap's Phase 3 "React app reconciliation" is a
  separate, later concern.
- **Viewers are functional requirements, not stylistic choices.** IIIF for Images,
  Kaltura for AV — these are "can you view the item at all," which belongs with each
  site's own migration track (ADR 009 Phase 2 fork), not a separate "UI phase." That left
  the open question narrower: is there a genuine *shared* baseline worth building once,
  ahead of the fork?

Checked out the real D7 codebase (`~/Sandbox/Mandala/Site/mandala-drupal`) rather than
reasoning from memory. Findings, written up in
[`docs/planning/theme-ui-commonalities-audit.md`](../planning/theme-ui-commonalities-audit.md):

- Every one of D7's site themes — Images (`sarvaka_images`), AV (`sarvaka_mediabase`),
  Sources (`sources_theme`), Texts (`shanti_sarvaka_texts`), Visuals (`sarvaka_shiva`),
  and Home/KMaps (`sarvaka_kmaps`) — is a thin Bootstrap sub-theme of one shared parent,
  `shanti_sarvaka`. All six declare the identical twelve regions verbatim. The base
  theme's `template.php` (1,677 lines) dwarfs every sub-theme's (193–860 lines); the base
  owns the page skeleton, shared JS/CSS, breadcrumbs, faceted search, and navigation.
- Site-specific work is narrow and content-driven, and mostly already tracked elsewhere:
  Images' deep-zoom viewer (deferred note), AV's Kaltura integration (lives at the
  **module** layer, not theme — already Spike 7, ○ Pending), Texts' footnote-adjacent
  chrome (overlaps proven Spike 4b), Sources' citation display (overlaps Spike 5 —
  notably, D7 itself had already disabled most of its bibliography CSS).
- Confirmed a ninth theme in the codebase, `sarvaka_projects`, maps to no live host in
  `sites.php` — flagged as likely dead, not confirmed further.
- **Discovered Visuals had no owner, spike, or deferred note anywhere** despite being a
  real 6th production site (`visuals.mandala.library.virginia.edu`, `sarvaka_shiva`
  theme, `shivanode` module dependency) already visible in three earlier session logs
  (2026-06-12, 2026-06-15, 2026-07-30).

**Recommendation:** port `shanti_sarvaka`'s shared region layout and JS/CSS foundation as
a D11 base theme before Phase 2 forks — this is faithful migration per ADR 008/010, not a
new design, since D7 already ran one shared system across all its sites for over a
decade. Each site's real add-on work (viewer, player, footnotes, citations) layers on top
exactly as it did in D7 and doesn't compete with a shared base.

## 4. Visuals retirement recorded

Raising the Visuals gap prompted Than to state the team's actual decision: **Visuals is
being retired, not migrated to D11** — no content migration, no theme work. This was
folded back into the audit (removing it from the "open gap" list, correcting the
site-count note so the top-level docs' "five sites" framing is confirmed correct for D11
scope) and saved to this driver's local Claude memory (`project-visuals-retired.md`) so
future sessions don't re-flag it as an open gap. Memory is per-machine/per-driver per the
session-end ritual — this doc is the durable team-visible record of the decision.

## Artifacts

| | |
|---|---|
| PR (merged) | [#146](https://github.com/uvalib/mandala-navina/pull/146) — JSON API path standardization confirmed |
| PR (open) | [#151](https://github.com/uvalib/mandala-navina/pull/151) — D7 theme/UI commonalities audit + Visuals retirement |
| Landed on main mid-session | [#142](https://github.com/uvalib/mandala-navina/pull/142), [#143](https://github.com/uvalib/mandala-navina/pull/143), [#144](https://github.com/uvalib/mandala-navina/pull/144), [#145](https://github.com/uvalib/mandala-navina/pull/145) (Yuji's Images migration work) |

## Open items

- **Sprint 2 scoping (Yuji + Than):** whether the D11 base theme is its own Sprint 2 item
  or folds into closing out Images now — raised, not decided this session.
- **Images viewer gap** (`images-missing-interactive-viewing-surfaces.md`) still needs a
  team discussion slot, per its existing priority note.
- **`sarvaka_projects`** — confirm dead before anyone spends more time on it.
- **1a.9 staging acceptance run** is now the only thing left to close Sprint 1 (per #142's
  correction) — unchanged from before this session.
