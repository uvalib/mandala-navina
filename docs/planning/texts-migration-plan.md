# Texts migration plan (approved 2026-11-02)

**Audience:** Developers
**Date:** 2026-11-02
**Author:** Than Grove, with Claude Code (plan mode)
**Status:** Approved plan, kept verbatim below. The working documents it produced are
[Spike 13](../spikes/spike-13-texts-whole-book.md) and
[Sprint 5](../sprints/sprint-05-texts-core-implementation.md); where they differ from this
text later, they win. Not edited after approval, with **one deliberate exception**: one bullet in
the "What the D7 Views tab actually is" section is replaced by a pointer, because this repo is
public (see [non-public-documentation](../non-public-documentation.md)). Ask Than for the detail.

---

# Plan: Texts migration (D7 → D11) and Spike 13

## Context

Texts is the next site to migrate, following Images (Sprint 1–2) and AV (Sprint 3–4). Goal: a D11 Texts site that matches D7 in **structure and look-and-feel** as closely as possible (ADR 008: migrate, don't improve). Nothing for Texts exists in D11 yet — only the audit, Spikes 3/4a/4b/6, a throwaway footnotes prototype (`drupal/web/modules/custom/spike_footnotes_demo`), and shared plumbing that already knows `texts` (`field_legacy_site` token, `mandala_legacy_redirect` host).

The existing audit (`docs/planning/texts-content-model-audit.md`, complexity 2.0/5 "easiest") predates two findings that change the plan:

1. **D7 renders a whole book as one scroll.** Any book page (root or chapter) renders `node--book.tpl.php` → `views_embed_view('single_text_body', ..., $bid)`: every page in outline order as `div.shanti-texts-section.text-level-N` with anchor `#shanti-texts-<nid>`, plus a 3-tab sidebar (Contents / Description / Alternative Formats). Up to 374 pages / ~4.5 MB per book.
2. **Book is not in Drupal 11 core** (11.4 has no `book`; not installed). The audit's "D11's Book module uses the same shape" is wrong as written. Contrib `drupal/book` 3.x exists (3.0.8, requires core ^11.2‖^12) and **ships `migrations/d7_book.yml`** (source plugin `book`, destination `book`, depends on `d7_node`) — verified against the module's git tree this session.

## Recommendation: yes, run a spike — Spike 13 "Texts book outline and whole-book render"

Footnotes (4b), Tibetan DB fidelity (4a) and the API collapse (Spike 6) are already settled. What nothing proves is (a) contrib Book as the outline model and (b) rendering a whole book from many nodes under cache and access rules — Images and AV never render a multi-entity document — plus (c) that the D7 alternative formats (PubReader / raw text / Voyant) can be driven from that render. A failure there reshapes the display tasks, so prove it first. ~3.5–4.5 days, individual mode, lightest-possible demos, following `docs/planning/spikes-plan.md` (theory / work / pass-fail / response / outputs).

| Step | Work | Pass |
|---|---|---|
| S1 outline | Throwaway branch: `composer require drupal/book:^3.0`; run `d7_book` (own node migration satisfies the `d7_node` dependency) on 3 real books (≈5, ≈50, 374 pages) from `d7_texts` DB | bid/pid/weight/depth identical to D7 `{book}`; Book enables on a custom `book` bundle. Fail → custom outline table + `D7TextsBook` source plugin |
| S2 whole-book render | Controller/view rendering the full tree in outline order with D7's section classes and anchors; test the 374-page book and the ~4 MB page, cold and warm, render cache on | Cold < ~10 s, warm ~1 s, within PHP memory; section order/ids match D7. Fail → pre-render on save (queue + cache tag per bid) or lazy sections (needs an ADR; departs from D7) |
| S3 cache + access | Edit one page; mixed-access tree (`group_content_access` 0/1/2; D7 children inherit root access) for anonymous / member / editor | Edit invalidates only its book; no inaccessible page leaks. Fail → per-viewer cache context |
| S4 TOC/anchors | Contents sidebar from the same tree | TOC link count = page count; every anchor resolves |
| S5 footnotes | Reuse 4b prototype (`spike_footnotes_demo`) inside the whole-book render | Citation/Notes counts match D7 for sampled books incl. outliers bid 15582, 15988 |
| S6 alternative formats (added after the PubReader/Voyant/raw-text scope change; +~0.5 day) | Raw-text route from the S2 render; PubReader3 library loading a real book's outline from the D11 tree (3 books); Voyant `input=` fetched from a publicly reachable dev/staging URL | Raw text matches D7's output for sampled books; PubReader renders TOC + sections for the 374-page book; Voyant loads the corpus. Fail → note the blocker (e.g. WAF challenge on the host blocks Voyant's server-side fetch — D7 hosts return 202 to non-browser clients; PubReader library incompatible with D11 asset pipeline) and decide in TX3 |

Output: `docs/spikes/spike-13-texts-whole-book.md` (go/no-go + decisions feeding TX3), a row in `docs/spikes/README.md`/`.pages`. Not in scope: Tibetan MySQL→Solr and CKEditor 5 normalization (4a's open limits) — recorded as deferred items, not spike work.

## Sprint 5 (Texts) task list — `docs/sprints/sprint-05-texts-core-implementation.md`

Style follows Sprint 3 (task / dependency / status table). Scope decisions from the user: **splitter, `asset_link`, a `node_embed` equivalent, and the three "Views" tab formats — PubReader, Voyant, raw (plain) text — are all in scope** (they appear on the live D7 site, e.g. texts.mandala.library.virginia.edu/book/tibetan-literary-genres → "Views" tab). Dropped, to be recorded in the endpoint decision: `node_thumb` (always redirects to one generic icon) and the TEI modules (installed in D7, no TEI data seen).

**What the D7 "Views" tab actually is** (read from `shanti_texts_features.views_default.inc`, view `single_text_views`, not overridden in the D7 DB): a list of links keyed on the book nid —
- "View in PubReader" → `book_pubreader/[nid]` (new tab). `book_pubreader` module bundles NCBI's PubReader3 JS (`pubreader/`), builds the page from `book_menu_subtree_data` (the outline) through two templates.
- "View as raw text" → `shanti_texts/node_ajax_text/[nid]`: whole-book render, whitespace-collapsed, `strip_tags`, served `text/html; charset=utf-8`.
- "View in Voyant" → `shanti_texts/voyant/[nid]`: redirect to `voyant-tools.org/?input=<site>/shanti_texts/node_ajax_text/<nid>` — Voyant's servers fetch the raw-text URL, so it must be publicly reachable.
- "View as stand-alone text" → `shanti_texts/node_embed/[nid]` exists but is excluded in the view, so it is not shown in the tab (still reachable by `legacy/texts.js` via `url_ajax`).
- The same list is also included in the embed fragment and the `views_links` string of `node_json`.
- Note: a D7 access-check gap exists on one of these routes; detail is tracked privately (ask Than). D11 versions of all three routes must be access-checked.

| ID | Task | Depends |
|---|---|---|
| **Phase 0 — audit/decision** | | |
| TX1 | Refresh `texts-content-model-audit.md`: journal fields (doi 125, journal_title/issue/volume, pages 86), whole-book display, Book out of core, real counts (7,633 book / 65 collection / 57 subcollection / 8 asset_link; 1,051 roots; depth ≤7), rescore complexity | — |
| TX2 | **Spike 13** (above) | TX1 |
| TX3 | Decision record/ADR from spike: outline model; whole-book render strategy; page URL semantics (D7: any page URL shows the whole book scrolled to anchor); `/api/json` per page vs book root (Spike 6 open item); `node_embed` equivalent; D11 routes for PubReader / raw text / Voyant (keep D7 paths via redirects? ADR 016 single-host); dropped `node_thumb` + TEI. Team sign-off | TX2 |
| TX4 | Fix roadmap "paused" status; settle `kmassets` `texts` bundle naming against the 5,381 legacy docs; orphan review-group question (#289) | TX1 |
| **Phase 1 — content model (export config from live Drupal; never hand-edit)** | | |
| TX5 | Install outline module (or custom table); `book` bundle with all fields incl. `field_legacy_site=texts` + `field_legacy_nid`; `asset_link` bundle; reuse `collection`/`subcollection` group types | TX3 |
| TX6 | Text formats / CKEditor 5 config for `full_html` with Tibetan fonts; **port the splitter** (page tree from one paste, heading-based) as a D11 form action, audited against CKEditor 5 output | TX3 |
| TX7 | Group relationship types `collection-group_node-book` and `-asset_link`; `mandala_group_inheritance` wiring; ADR 015 `content_editor` CRUD on Texts bundles; map `group_content_access` 0/1/2 (4,668 / 2,693 / 272) | TX5 |
| TX8 | Add `book`/`asset_link` to `mandala_kmassets_sync.settings.yml` (values must match existing legacy docs) | TX5, TX4 |
| **Phase 2 — migrations (`mandala_texts` group, DB key `migrate_texts`, ids `d7_texts_*`)** | | |
| TX9 | Add `migrate_texts` key in `settings.php`; scaffold group (pattern: `mandala_av`). Source loaded already via `scripts/load-d7-source.sh <dump> d7_texts` | TX5 |
| TX10 | `D7TextsNode` source plugin (model: `D7AvNode`): exclude ~1,380 orphan body rows; decide 65 unpublished; map multi-value author/editor/translator, language triple, dc dates (typo `orginial` is real), rights, journal, `field_external_pdf` | TX5 |
| TX11 | Outline migration (`d7_book` or custom): parents before children (migration_lookup, no_stub); cross-check vs `menu_links` `book-toc-<bid>` | TX10, TX3 |
| TX12 | Files: `d7_texts_file` + `file_copy` (537 files, 228 PDFs ≈199 MB); `field_pdf_version` 211, `field_external_pdf` 20, featured image 67; NFC filenames (`mandala_file_hygiene`) | TX10 |
| TX13 | KMaps fields (`field_kmap_term` Subjects, `_places`, `_terms`) using the AV KMaps `sub_process` | TX10 |
| TX14 | Collections/subcollections (65+57) as Groups (pattern `d7_av_collections`); memberships from `og_membership` (NOT `field_og_collection_ref`) via `d7_image_group_membership` with `member_types: [book, asset_link]`; user memberships | TX10, TX7 |
| TX15 | `texts_body` process/post-pass: footnote transform per 4b (book-outline-aware; `docs/deferred/texts-footnotes-production-transform.md` 6-item checklist), inline media (957 `<img>`, 292 media tags), NFC. **Cross-node → runs after TX11; idempotent and resumable** | TX11, TX3 |
| TX16 | URL aliases via `d7_image_url_alias` (`node_types`: book, collection, subcollection, asset_link): 850 unaliased books, ~2,900 Tibetan aliases, 1,183 redirect rows; confirm `mandala_legacy_redirect` texts host | TX10 |
| TX17 | `scripts/run-texts-migration.sh` (ordered, resumable) + `scripts/verify-texts-migration.sh` (D7 SQL vs D11 SQL: counts by type/status, depth histogram, roots, files, memberships, KMaps refs, footnote cite/def counts; documented expected anomalies) | TX9–TX16 |
| **Phase 3 — access & search** | | |
| TX18 | Access verification (public / private / default books in restricted collections; anon / member / editor) | TX14, TX7 |
| TX19 | `kmassets:index-all` + `kmassets:audit`; confirm `MigrateSyncSubscriber` suppression | TX17, TX8 |
| **Phase 4 — look and feel (theme `shanti_sarvaka`)** | | |
| TX20 | Book display: whole-book render + 3-tab sidebar (Contents / Description / Views), ported from `node--book.tpl.php` (D7 source: `~/Sandbox/Mandala/Site/docroot/sites/all/themes/shanti_sarvaka_texts/templates`); PDF icon; Notes list; the Views tab shows the three links from TX23 with D7's labels ("View in PubReader", "View as raw text", "View in Voyant") opening in a new tab | TX3, TX15, TX23 |
| TX21 | Collection/subcollection pages (featured image, body, members 5/page, create links) — reuse `collection_gallery_*` pattern + `collection_gallery_texts` view | TX14 |
| TX22 | Listings: `/texts` (D7 `/alltexts` + facets subjects/places/collections), `/alltexts_open`, `/mytexts`, `/collections`, `/my_collections`, Solr text search; Texts on the landing page (AV16 follow-up) | TX19 |
| TX23 | Endpoints (all access-checked, in a `mandala_texts` module): `/api/json/{node}` per TX3 (extend `mandala_node_api`); `node_embed` equivalent (HTML fragment of the requested page, `?nostyle`, JSONP/CORS as D7); **raw-text** route (whole-book render, whitespace-collapsed, `strip_tags`, `text/html; charset=utf-8`); **Voyant** route (redirect to voyant-tools.org with `input=` the absolute raw-text URL); **PubReader** route (book outline via the outline service's subtree → templates → bundled NCBI PubReader3 library, added as a theme/module library); keep legacy D7 paths working via `mandala_legacy_redirect` where ADR 016 requires | TX3 |
| **Phase 5 — acceptance** | | |
| TX24 | Acceptance checklist + side-by-side D7/D11 of ~10 books (small, 374-page, Tibetan, footnote-heavy, restricted) + production-scale timing (AV4 live run was ~5 h; DDEV timing doesn't transfer) | all |

## Risks / open decisions (carried into TX3)

- Mixed-access trees under a concatenated render (restricted child under public root); cache contexts.
- Whole-book size (4.5 MB, 374 pages): proxy buffering, memory, `max_input`.
- Footnote transform is cross-node → ordering and idempotence; outlier books 15582 / 15988; "orphan footnote 1" convention (11 books).
- Splitter vs CKEditor 5 (`critical-path.md` Risk 2) — unresolved anywhere today.
- 65 unpublished books, ~1,380 orphan body rows, 63 books with no body row: migrate or exclude.
- Tibetan MySQL→Solr and CKEditor normalization remain unproven (record as deferred items).
- Voyant fetches the raw-text URL from its own servers: the D11 host must be reachable by an unauthenticated server-side GET (WAF behaviour on dev/staging/production is unverified); private books cannot be analysed in Voyant (same as D7).
- PubReader is bundled third-party JS (NCBI PubReader3) with its own CSS/images: licensing/size and how it fits D11 asset handling are unchecked; confirm it still works against the D11 outline output.
- Visual check of the live Views tab not done from here (the D7 hosts challenge non-browser clients); the list above is from the D7 source. Confirm labels/order in a browser during TX1.
- Schedule: roadmap marks Texts "paused"; competes with Sources (gated separately on ADR 020 review, #386 — does not gate Texts) and Sprint 4 transcripts.

## Critical files / reuse

- Pattern: `drupal/web/modules/custom/mandala_migrations/` (`D7AvNode`, `D7AvFile`, `D7ImageGroupMembership`, `D7ImageUrlAlias`, `AvLanguageLayerTrait`); `scripts/run-av-migration.sh`, `scripts/verify-av-migration.sh`, `scripts/load-d7-source.sh`.
- Access/Solr: `mandala_group_inheritance`, `mandala_kmassets_sync` (`MigrateSyncSubscriber`, settings bundles), `mandala_node_api`, `mandala_legacy_redirect`.
- Theme: `drupal/web/themes/custom/shanti_sarvaka/`.
- Docs to write/update: `docs/planning/texts-content-model-audit.md` (refresh), `docs/spikes/spike-13-texts-whole-book.md` (new) + `docs/spikes/README.md` + `docs/spikes/.pages`, `docs/sprints/sprint-05-texts-core-implementation.md` (new) + `docs/sprints/README.md`, `docs/roadmap.md` (status), deferred notes + issues for items above (`./scripts/promote-deferral.sh`).

## This session's deliverable (after approval)

Docs only, on one branch → PR (no code, no composer changes, no D11 DB changes): spike doc, Sprint 5 doc, refreshed audit, README/.pages/roadmap updates, deferred notes (+ issues) for the unproven Tibetan Solr/CKEditor items. Then `./scripts/session-close-check.sh`, session log, memory refresh. Starting the spike itself is a separate step.

## Verification of the planning docs

- `./scripts/session-close-check.sh` (index/.pages/Issue-header checks pass).
- Counts quoted in the docs re-derived with `ddev mysql d7_texts` queries (nodes by type, depth histogram, access values, aliases) before committing.
- Every claim about contrib Book (`d7_book`, schema, performance) marked verified-vs-assumed in the spike doc; S1 replaces the assumptions with evidence.
