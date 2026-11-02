# Spike 13: Texts book outline and whole-book render

**Status:** ○ Pending — planned 2026-11-02, not started
**Lead:** Than Grove
**Mode:** Individual
**Time-box:** 3.5–4.5 days
**Gates:** [Sprint 5](../sprints/sprint-05-texts-core-implementation.md) task TX3 (the decision record), and through it every Sprint 5 task from TX5 on.
**Relates to:** [Texts content-model audit](../planning/texts-content-model-audit.md),
[Spike 4a](spike-04a-tibetan-unicode-roundtrip.md) (Tibetan DB fidelity, proven),
[Spike 4b](spike-04b-ckeditor5-footnotes.md) (footnotes, closed; its prototype is reused in S5),
[Spike 6](spike-06-api-compatibility.md) (Texts `node_json` collapses to the book root),
[ADR 008](../adr/008-migrate-dont-improve.md), [ADR 016](../adr/016-public-url-structure-single-host.md),
[entity-access endpoint convention](../planning/entity-access-endpoint-convention.md)

---

## Why this spike exists

Images and AV render one entity per request. Texts does not. In D7, **any** book page (the book
root or a chapter) renders `node--book.tpl.php`, which prints
`views_embed_view('single_text_body', 'panel_pane_default', $bid)`: **every page of the book, in
outline order, as one scroll**, each wrapped in `div.shanti-texts-section.text-level-N` with an
anchor `#shanti-texts-<nid>`. A sidebar offers three tabs (Contents, Description, Views). The
largest book has 374 pages; one page body is about 4 MB.

Two facts make that a real risk, not routine implementation:

1. **Book is no longer in Drupal core.** Drupal 11.4 has no `book` module and this repo does not
   install one. The Texts audit (2026-09-01) said "D11's Book module uses the same
   `bid`/`plid`/`weight` shape"; that was written as if core Book still existed. Contrib
   `drupal/book` 3.x does exist (3.0.8; requires core `^11.2 || ^12`), and its git tree ships
   `migrations/d7_book.yml` (source plugin `book`, destination `book`, depends on `d7_node`).
   **Not yet verified:** that it installs cleanly on this site, that its schema holds up for
   1,051 books up to depth 7, or its performance on the 374-page book.
2. **Nothing built so far renders a document assembled from many entities** under D11's render
   cache and the Group access model. A failure here reshapes the display tasks (Sprint 5 TX20)
   and the endpoint tasks (TX23), so it is proven first.

Already settled and **not** re-proven here: Tibetan fidelity through Migrate and MySQL (4a), the
footnote strategy (4b: migration-time book-outline-aware transform plus a Notes list built from a
table), and the API collapse to the book root (Spike 6).

## Theory

A D7 Texts book can be reproduced on D11 as: an outline stored by contrib Book (or a small custom
table if Book is unsuitable), a node per page, and a whole-book render that (a) stays within
acceptable time and memory for the largest real books, (b) caches and invalidates correctly, (c)
never leaks a page the viewer may not see, and (d) can drive the D7 "Views" tab formats (PubReader,
raw text, Voyant) from the same render.

## Work

Use real data from the `d7_texts` database (loaded from `mandala-prod-texts-db_20260710.sql.gz`
with `scripts/load-d7-source.sh <dump> d7_texts`). Pick three books: about 5 pages, about 50
pages, and the 374-page `bid=14679`; also the book containing the ~4 MB page. Throwaway branch,
nothing merged (the Spike 5 pattern).

| Step | Work | Pass | Fail response |
|---|---|---|---|
| **S1 outline** | `composer require drupal/book:^3.0` on the throwaway branch. Create a `book` bundle, enable Book for it. Run `d7_book` (a hand-written `d7_node` stand-in satisfies its dependency) on the three books. | `bid`/`pid`/`weight`/depth identical to the D7 `{book}` plus `menu_links.depth`, zero diffs. Book installs and works on D11.4. | Custom outline table plus a `D7TextsBook` source plugin (adds about one task to Sprint 5). |
| **S2 whole-book render** | A controller or view that renders the full tree in outline order with D7's section classes and anchors. Test the 374-page book and the 4 MB page, cold and warm, render cache on. | Cold under about 10 s, warm about 1 s, within the production PHP memory limit. Section order and ids match D7. | Pre-render on save (queue worker, cache tag per `bid`) or lazy sections. Either departs from D7 and needs an ADR. |
| **S3 cache and access** | Edit one page and watch invalidation. Build a mixed-access tree (`group_content_access` 0 default / 1 public / 2 private; D7 children inherit the root's access) and render it as anonymous, collection member and editor. | Editing a page invalidates only that book's output. No page the viewer cannot see appears in the render. | Per-viewer cache contexts for these books; record the cost. |
| **S4 TOC and anchors** | Build the Contents sidebar from the same tree. | TOC link count equals page count; every `#shanti-texts-<nid>` anchor resolves. | Fix in the display build; note any depth that breaks the layout. |
| **S5 footnotes** | Reuse the 4b prototype (`spike_footnotes_demo`) inside the whole-book render, including the outlier books `bid=15582` and `bid=15988`. | Citation and Notes counts match D7 for the sampled books. | Revisit the 4b transform's assumptions (the cross-page model) and record which. |
| **S6 alternative formats** | From the S2 render, produce (a) the raw-text output (whitespace-collapsed, tags stripped), (b) a PubReader view (bundled NCBI PubReader3 library fed from the D11 tree) for three books, (c) a Voyant hand-off whose `input=` points at a publicly reachable dev or staging URL. | Raw text matches D7's output for sampled books. PubReader shows contents and sections for the 374-page book. Voyant loads the text. | Record the blocker (for example a WAF challenge stopping Voyant's server-side fetch, or the PubReader library not fitting D11 asset handling) and decide in TX3. |

### What the D7 "Views" tab offers (source: view `single_text_views` in `shanti_texts_features`; not overridden in the D7 database)

- **View in PubReader**: `book_pubreader/[nid]`, opens in a new tab. The `book_pubreader` module
  bundles NCBI's PubReader3 and builds the page from the book outline.
- **View as raw text**: `shanti_texts/node_ajax_text/[nid]`, the whole-book render with tags
  stripped.
- **View in Voyant**: `shanti_texts/voyant/[nid]`, a redirect to `voyant-tools.org` with the raw-text
  URL as input; Voyant's servers fetch that URL.
- A "View as stand-alone text" link (`shanti_texts/node_embed/[nid]`) exists in the view but is
  hidden; the route is still used by `legacy/texts.js` through `url_ajax`.

All D11 routes must enforce access per the [entity-access endpoint convention](../planning/entity-access-endpoint-convention.md).

## Out of scope

- Tibetan MySQL to Solr and CKEditor 5 normalization (Spike 4a's open limits). Tracked as
  [deferred notes](../deferred/README.md), not spike work.
- Any production migration code, content-type config, or Group config. Those are Sprint 5.
- The splitter, `asset_link`, listings and landing pages.

## Outputs

- This document, completed: results per step, a go/no-go, and which fail responses fired.
- Decisions handed to Sprint 5 TX3: outline model, whole-book render strategy, page URL semantics,
  D11 routes for the alternative formats.
- Every claim about contrib Book recorded as **verified** or **assumed**; S1 replaces the
  assumptions with evidence.

## Results

Not started.
