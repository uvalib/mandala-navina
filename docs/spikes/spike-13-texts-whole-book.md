# Spike 13: Texts book outline and whole-book render

**Status:** ◐ In progress — S1 done 2026-11-02 (passed, four findings); S2 done 2026-11-04 (passed, three findings); S3–S6 not started
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

### S1 outline — PASS (2026-11-02, Than with Claude Code)

Evidence and scripts: [`assets/spike-13-texts/`](assets/spike-13-texts/README.md) (throwaway; nothing
merged). Environment: local DDEV, Drupal 11.4.11, `drupal/book` 3.0.8, the `d7_texts` database
(loaded from `mandala-prod-texts-db_20260710.sql.gz`).

**Books tested (444 outline rows):** `bid` 53536 (5 pages, depth 4), 15641 (52, depth 5), 53126
(13, **depth 7**, the deepest in the corpus), 14679 (**374**, the largest). The plan named three
books; the depth-7 book was added because outline depth is a risk.

**Result.** Contrib Book installs and enables with no other dependency change. Its own source and
destination plugins migrated all 444 rows (0 failed). A comparison of every D11 row against D7
(`menu_links` and `book`, with D7 ids translated through the migration map) found **zero
differences** in book id, parent, weight, depth and the materialized path p1–p9, for all four books.
Book's own `bookTreeAllData()` then read each tree back with the right page counts (5, 52, 13 with
depth 7, 374). The 374-page tree took 275 ms uncached on DDEV, as an administrator. This is not the
S2 render test.

**The comparison can fail.** I corrupted three D11 rows (a weight, a parent, a depth plus a path
entry), and it flagged exactly those, in the right books, before I repaired them.

**Findings that change Sprint 5:**

1. **The shipped `d7_book` cannot run unmodified.** It copies `nid` and `book/bid` straight
   through, assuming D7 node ids are preserved, as in an in-place upgrade. In Mandala **all 7,763
   D7 Texts node ids collide with existing D11 nodes** (on this DB the sampled ones are Images
   nodes), the situation ADR 017 describes. The test used a copy of `d7_book` whose `nid` and
   `book/bid` use `migration_lookup` on the node migration. It still runs Book's own plugins, so the
   module is viable; Sprint 5 TX11 should be a Mandala outline migration of this shape.
2. **Book's migration destination cannot update an existing outline.** Its update branch builds the
   new values but never calls `setBook()`. Observed: `migrate:import --update` reports "444
   updated" and changes nothing, and rolling back the outline migration then importing again also
   leaves wrong values in place (rollback only clears the migration map). A reset has to roll back
   the **outline migration first** (clears the map), then the **node migration** (deleting the nodes
   removes their outline rows), then re-import nodes and outline. Verified end to end, zero
   differences afterward. This fits our rollback-cycle runbooks but must be written into
   `run-texts-migration.sh`, and an upstream issue is worth filing.
3. **`mandala_group_inheritance` breaks Book's tree queries for non-administrators.** Book tags its
   queries `node_access` with `base_table` metadata `book`. `NodeQueryAlter::baseTable()` falls back
   to any table whose name matches the declared base table, so it treats the `book` table (alias `b`)
   as the node table and adds `b.type`, a column that does not exist, producing an SQL error.
   Administrators bypass it. Sprint 5 TX7 must make the alter join `node_field_data` (or skip) when
   the declared base table has no `type`. Not fixed in the spike.
4. **Book 3.x's configuration differs from core's.** `book.settings.allowed_types` is a list of
   `{content_type, child_type}` entries, not a flat list of type names, and the module registers its
   node bundle class from that setting. A flat list silently leaves nodes without `getBook()`, which
   showed as a fatal error in the destination plugin. Sprint 5 TX5 should export this config from a
   live Drupal.

**Smaller notes.** `migrate:import` insists that a dependency migration has processed every row, so
partial runs need `--force` (an artefact of testing four books, not a production issue). The DDEV
`db` user needs an explicit grant on `d7_texts` (what `scripts/load-d7-source.sh` does).

**Verified, not assumed:** `drupal/book` 3.0.8 installs and works on Drupal 11.4.11; it ships
`migrations/d7_book.yml` using its own `book` source and destination plugins; D7 `menu_links`
`plid`/`weight`/`depth`/`p1..p9` map cleanly (with id translation) onto the D11 `book` table,
including depth 7 and a 374-page tree.

**Not tested in S1:** the full corpus (7,633 rows) and its migration time; Solr/kmassets
interaction; anything about rendering, cache or access (S2–S4); contrib Book's behaviour under the
Group access model beyond finding 3.

**Effect on TX3:** on this evidence the outline model is contrib Book with a Mandala lookup-based
outline migration; the custom-table fallback is not needed. S2 onward is unchanged.

### S2 whole-book render — PASS (2026-11-04, Than with Claude Code)

Evidence and scripts: [`assets/spike-13-texts/`](assets/spike-13-texts/README.md) (throwaway; nothing
merged). Same environment as S1, plus the throwaway module `spike13_render`.

**What was built.** A route `/spike13/book/{nid}` that renders the whole book in outline order using
D7's section markup (taken from the `single_text_body` view's field rewrite in `shanti_texts_features`):
`<div class="shanti-texts-section">`, `<a name="shanti-texts-<nid>">`, a title div
`id="shanti-texts-<nid>" class="shanti-texts-section-title text-level-<depth>"`, and a
`shanti-texts-section-content` div holding the page text through its text format. The render array
carries each node's cache tags plus a per-book tag. The S1 stand-ins were title-only, so S2 first
copied D7's page text onto them (see finding 1).

**Books and results** (DDEV, administrator, render cache on; cold = render and dynamic-page caches
emptied first; peak = `memory_get_peak_usage` for the request; one operator, so indicative):

| D7 book | Pages | Output | Cold | Warm | Peak memory |
|---|---|---|---|---|---|
| 14679 (the 374-page book) | 374 | 2.1 MB | 0.3–0.5 s | 0.08 s | 26–34 MB |
| 14158 (largest by text) | 294 | 4.8 MB | 0.5–0.6 s | 0.18 s | 29–34 MB |
| 58791 (the 4 MB single page) | 1 | 4.1 MB | 0.6 s | 0.15 s | 33 MB |
| 15641 | 46 of 52 | 0.56 MB | 0.17 s | 0.04 s | 18–22 MB |

Targets were cold under about 10 s, warm about 1 s, within the production PHP memory limit.
Cold and warm are an order of magnitude inside the targets. The first request after a full `drush cr`
was slower (up to 2.4 s, mostly Twig and opcache warm-up), so quote steady-state cold numbers. **Not
met strictly:** the production PHP memory limit was not found; local FPM allows 1 GB and the peak was
at most 34 MB.

**Order and ids match D7.** Section ids come out in the same order, with the same count, as D7's
own view for 14679, 15641, 53126 and 53536; 14158 differs by one page (finding 3). D7's view drops
unpublished pages, so 15641 renders 46 of its 52 outline pages, as D7 does.

**Findings that change Sprint 5:**

1. **D7's page text is `field_book_content`, not `body`.** The `body` field is empty for all Texts
   book nodes. The D11 `book` bundle needs a `field_book_content` (long text, format preserved)
   before any rendering work; the node migration must copy it with its format. 419 of the 445 pages
   in the test books have text and the other 26 are empty in D7 too; the copied byte total matched D7
   exactly (6,600,202 for the four S1 books plus 58791).
2. **The "4 MB page" is D7 node 58791, which is unpublished in D7.** It is not in any S1 book. D7's
   view filters on published status, so D7 renders nothing for it today. The test published the local
   stand-in to get a timing. The largest published text is book 14158 (294 pages, 4.8 MB).
3. **Sibling order: use Book's own order; ties need a decision.** D7's whole-book view (and its
   Contents sidebar view, `single_text_toc`) set Views' *sort within level*. That orders each level by
   menu-link weight, then title (MySQL collation), then the materialized path as a last tiebreak,
   which is the same rule as D11 Book's tree (`50000 + weight`, title, nid, via `ksort`). So the
   migration needs no weight rewriting for the order to match D7, and the `p1..p9` columns only have to
   describe ancestry (Book builds them itself when each row is saved). A raw `p1..p9` sort is **not**
   D7's order (it differs in 14158 and 15641). The only divergence is ties: when siblings share a
   weight, D7 breaks the tie with MySQL's title collation and D11 with PHP's byte order. In 14158 one
   page (D7 node 14209) sits two positions from where D7 puts it.

**Tie simulation across the whole corpus** (published pages, 980 sibling groups; D7 order computed
in SQL, D11 order by reimplementing Book's sort key in Python): 36 groups in 24 books have tied
weights; **5 groups in 4 books** (13283, 13871 twice, 14158, 15582) order differently under D7 and
D11, with 20 sibling positions differing in total. The simulation flagged the 14158 group found by
hand. Caveats: the D11 side is a reimplementation (the last tiebreak uses the D7 menu-link id because
the real D11 nids do not exist for the full corpus); the D7 order depends on this database's
collation; D7 sorts on the menu link's title and D11 on the node title. Re-check against a real full
migration.

**Decision (TX3, Than, 2026-11-06): normalize tied weights.** The outline migration rewrites each
group of tied sibling weights to strictly increasing values in D7's order (menu-link weight, then
title in MySQL collation, then menu-link id). The rendered order then matches D7 and no longer depends
on either side's collation. The cost is a documented deviation in the stored weight of 36 sibling
groups, which is invisible in rendered output and so consistent with [ADR 008](../adr/008-migrate-dont-improve.md).
The migration keeps the original D7 weight (e.g. in a migrate map or log) so the change is auditable.
**Acceptance test:** after a real full migration, the rendered sibling order must equal D7's in every
book, checked explicitly for 13283, 13871, 14158 and 15582. This also replaces the Python
reimplementation caveat above with a check against real D11 data. The decision is part of the TX3
decision record, which Sprint 5 lists for team sign-off.

**A wrong turn, recorded.** The first S2 write-up said D7 sorts by the raw path and Book's order
diverged in 70 books. That was wrong: it compared Book to a path-only ordering that D7 never used, and
did not read the view's *sort within level* option. It was caught when the Book UI's weight-based
ordering was questioned. A cache-context miss (the render ignored `?order=`) briefly made a
comparison look wrong too; the controller now varies on that query argument. Both are fixed; the
numbers above are the corrected ones.

**D7 sidebar TOC (input to S4).** The `single_text_toc` view is a tree of
`<a href="#shanti-texts-[nid]" id="shanti-texts-toc-[nid]">title</a>`, published pages only, depth
greater than 1 (the root is not listed), ordered as above. S4 should reproduce that anchor scheme.

**Not tested in S2:** anonymous and group-member rendering and the non-administrator Book query
(finding 3 of S1 blocks it; S3 and TX7), cache invalidation on edit (S3), the Contents sidebar and
anchor resolution by click-through (S4), footnotes (S5), alternative formats (S6), the D7 "Insert"
control (editor-only, omitted), production hardware and PHP limits, and the visual styling (not a
spike concern; the markup, classes and ids are what must be preserved). Anchors in the test use the
D7 nid looked up from the migration map; production must use `field_legacy_nid`.

**Effect on TX3:** the whole-book render fits the D7 shape without pre-rendering or lazy sections, so
the fallback in the plan (queue-worker pre-render, cache tag per `bid`) is not needed on this
evidence. Record the sibling-order rule and the tie decision. S3 onward is unchanged.

### S3–S6

Not started.
