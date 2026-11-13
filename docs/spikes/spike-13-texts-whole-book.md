# Spike 13: Texts book outline and whole-book render

**Status:** ◐ In progress — S1 done 2026-11-02 (passed, four findings); S2 done 2026-11-04 (passed, three findings); S3 done 2026-11-06 (passed with the per-viewer-context fail response, five findings); S4 done 2026-11-06 (passed; one tie-order difference, which the TX3 normalization fixes); S5 done 2026-11-09 (passed; six findings, resolution must be per chapter; variant A chosen); S6 done 2026-11-13 ((a) raw text and (b) PubReader passed; (c) Voyant moved to a post-migration check on dev-0)
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

### S3 cache and access — PASS with the fail response (2026-11-06, Than with Claude Code)

**Setup (local DDEV, throwaway branch).** Book 15641 (D11 123746, 52 pages) placed in a public
collection, with per-page overrides: 3 private (`field_group_content_access` = 2), 2 UVA-only (3), 2
explicit public (1), the rest default. Four viewers over real HTTP (curl, a fresh cookie jar each):
anonymous, an authenticated non-member, a collection member, and a `content_editor`. Ground truth
came from SQL, not from the code under test: 46 published pages, so the expected counts are
anonymous 42, non-member 44, member 46, editor 46.

**Result.** The render gives those counts once the outline is read as in finding 1. Under the
`full` cache configuration below, every one of 25 checks passed (baseline per viewer, page edit,
hidden page made visible and back, whole collection made private and back, member removed and
re-added) and responses were actually cached (6 dynamic-page-cache HITs; the only uncacheable
responses were the 403s). Editing a page in book A refreshed A's viewers and left book B's cached
page untouched (checked in all three configurations).

**Findings**

1. **Book's tree API is the wrong source for a whole-book render.** `bookTreeAllData()` drops an
   inaccessible parent *together with its whole subtree* (`doBookTreeCheckAccess`). D7's whole-book
   view filters each row on its own status, so published pages under an unpublished parent still
   render there (read from the view config, not run against D7). The D7 data has this at scale:
   **1,410 of 7,568 published book pages, in 36 books, sit under an unpublished ancestor** (largest:
   bid 54836 with 330, 39676 and 40956 with 194 each, 41931 with 187). In the S3 test book all six
   chapters are unpublished and 45 published pages sit beneath them: through the tree API every
   non-bypass viewer got 1 section instead of 44 to 46. The S2 admin-only runs could not see this,
   because an administrator can view unpublished pages. The render now orders the flat `{book}` rows
   with Book's own sort key (50000 + weight, title, nid) and checks each page on its own. TX20 should
   do the same, and its tests must include a non-admin viewer.
2. **Enabling the `book` bundle is not zero-config for access.** `CollectionVisibility` says Texts
   gets enforcement "with no further code change" once its bundle has a group_node plugin, and that
   is true of the *visibility rule*. But installing `group_node:book` on the collection type also
   brings Group's own access into play, and with no `group_node:book` permissions on the collection
   roles every viewer, including `content_editor`, got 403 on the book root. The roles needed the
   same view/create/update/delete grants the audio plugin has (5 roles; copied from audio here).
   TX5 must include them.
3. **The `NodeQueryAlter` `b.type` error is confirmed over HTTP** (S1 finding 3). With the book
   bundle in a collection, a non-bypass viewer requesting a page that went through Book's own
   access-tagged query got a 500 (`SQLSTATE[42S22] Unknown column 'b.type'`, in the Drupal log).
   Book declares `base_table = book`; `baseTable()` falls back to that table's alias, which has no
   `type` column. Not fixed here (TX7). One thing I did not explain: the same call from drush returned
   a (pruned) tree without the error.
4. **Cache design for an aggregate render.** Three configurations were run against the same four
   viewers (`?cache=naive|bubbled|full`, run one after the other from a cleared cache):
   - `naive` (cache tags from visible pages only; context `user.permissions`) **fails**: when a hidden
     page becomes visible, anonymous and non-member viewers keep the stale count (42 and 44 instead
     of 43 and 45), and in one of two runs a member removed from the collection kept seeing all 46
     pages, private ones included (the other run passed that step, so it depends on cache state).
     It was not worse only because the route's `node.view` check on the book root adds
     `user.group_permissions` to the response, which happens to separate members from outsiders. That
     is incidental: the `CollectionMembershipsCacheContext` docblock already explains why
     `user.group_permissions` is not a safe substitute for membership.
   - `bubbled` (merge every page's access result as-is) is correct, 25 of 25, but **never cached**:
     the forbidden-private results declare a per-user context, and dynamic page cache refuses a
     response with that ("poor cacheability").
   - `full` is correct, 25 of 25, **and cached**. It merges the cache tags and max-age of every
     page's access result and node (hidden pages included, so a hidden page that becomes visible
     refreshes the render), drops the per-user context, and declares `user.permissions`,
     `user.roles:authenticated` and `user.mandala_collection_memberships` instead. The membership
     context already determines the private decision, which is what it exists for.
5. **The S3 fail response fired, and its cost.** Per-viewer cache contexts are needed for these
   books. The cost is one cache entry per distinct combination of bypass permission, authenticated
   status and membership set, and an access check on every outline page on each cold render (not
   measured for the 374-page or 294-page books; S2's timings were admin and had no per-page
   access work). Also: the `spike13_book:<bid>` tag the spike controller adds is never invalidated
   by anything; the real invalidation comes from the page and group tags, so TX20 should either drop
   it or invalidate it from a page-save hook.

**Anchor scrolling in a real browser (Chrome, as an administrator, book 123746).** Loading the page
with `#shanti-texts-67480` lands the section 79 px from the top, which is the height of the Drupal
admin toolbar, so it starts just below it. Clicking Contents links across the book (first, middle,
last, 6 of 52 sampled) did the same for 5; the sixth is the last section, which cannot reach the top
because the page ends there. The URL hash updated each time. The page sets `scroll-behavior: smooth`,
so a test has to wait about 1.5 s for each scroll to settle (my first run measured too early and
looked like a failure). The full 52-link run exceeded the tool's time limit on this 126,000 px page,
so 6 links, not all, were clicked. Not covered: a link to a page the viewer cannot see (the anchor is
simply absent from that render, as in D7) and any fixed header in the real theme (TX20).

**Not tested.** Subcollection and inherited membership (direct member only); D7's rule that children
inherit the root's access versus D11's per-page overrides (the mixed tree here uses overrides, which
is what the D11 field supports); the timing of `full` on the large books; two members of different
collections; unpublished pages for editors with "view own unpublished". One operator, one machine,
one 52-page book.

**Handed to Sprint 5.** TX5: group role grants for `group_node:book`. TX7: the `baseTable()` fix,
plus a test with a non-admin viewer on a book. TX20: flat-outline render with per-page access and
the `full` cache declaration, and a measurement of it on the largest books.

### S4 Contents and anchors — PASS (2026-11-06, Than with Claude Code)

**What was built.** The whole-book render now emits a Contents list in D7's shape: a root link, then
a nested list of `<a href="#shanti-texts-<nid>" id="shanti-texts-toc-<nid>">` entries, built in the
same per-page access loop as the sections so the two cannot disagree about which pages a viewer may
see. Section ids and TOC hrefs use the D7 nid, as in S2.

**The D7 rule, read from code.** D7's `single_text_toc` is a *tree* list of published pages at depth
greater than 1. It renders through the contrib `views_tree` style, which sends any row whose parent
is not in the result set to the top level (`views_tree.module`, "normalize the top level"). So a
published page under an unpublished chapter is a top-level Contents entry, not a child of its nearest
visible ancestor. The D11 list does the same.

**Verification.** A verifier parses the HTML (not string counts) and compares it with expectations
computed from the D7 database: published pages at depth > 1, each page's parent by the rule above,
and sibling order by weight, then title, then link id, through MySQL. D7's view itself was not run;
its rules were taken from the view config and the `views_tree` code. As the `content_editor` (who
sees every published page):

| D7 book (D11 stand-in) | Pages in Contents | Links resolve, ids unique | Set, nesting | Order |
|---|---|---|---|---|
| 15641 (123746) | 45 | pass | pass (27 promoted to top level) | pass |
| 14679 (123372) | 373 | pass | pass | pass |
| 14158 (123817) | 293 | pass | pass | **3 positions differ** |

Every Contents href resolves to a section anchor in the same page, every section has both the
`<a name>` and the `div id`, ids are unique, the root link resolves, and the link count equals the
number of visible non-root sections.

**The one order difference is the known tie group.** In 14158 all three differing positions are the
same siblings (D7 nodes 14209, 14214, 14215, under 14176), with identical weight 16: the S2 tie case,
with 0 differences outside a tie. To check that the TX3 decision fixes it, the 49 siblings under 14176
were renumbered 0..48 in D7 order in the local DB. The Contents order then matched D7 exactly and the
whole check passed. Restoring the original weights brought the same 3 differences back (weights
verified identical to the original). This is evidence for the normalize decision, not a new finding.

**Weight range: not a problem (checked).** That one parent has 49 children and D7 weights already
spanning -15 to 16, so I first suspected that renumbering would not fit Book's outline form. It does:
Book 3.x builds the weight element with `#delta = max(50, abs(weight))` (`BookManager.php:316`), so
the form offers at least -50 to 50 and widens to show the page's current weight. D7's weight of 16 and a
renumber to 0..48 both display. TX11 can renumber a whole tied sibling set without a range concern;
only a sibling set larger than 100 would need the widget to widen, and it does so by itself. (An
earlier draft of this note assumed a -15 to 15 range without checking; that was wrong.)

**Per viewer, on the mixed-access book (123746).** Contents links follow what the viewer may see:
anonymous 41, non-member 43, member 45, editor 45 (each one less than the section count, the root
being linked separately). Checked by anchor id, not by title (the titles occur inside other pages'
text): the private pages' ids appear only for the member, the UVA-only pages' ids for any logged-in
viewer, and the unpublished chapter for nobody.

**Not tested.** title markup (D7's
`shanti_sarvaka_process_title_markup` turns `__x__` into italics; only 1 Texts title, a book root, contains
it, so it is a TX20 footnote, not a risk); depth limits of the real theme; the
Description and Alternative Formats tabs (S6); Contents for books whose root is hidden.

### S5 footnotes — PASS, with corrections to Spike 4b's transform (2026-11-09, Than with Claude Code)

**Setup (local DDEV, throwaway branch).** Four more books were migrated through the S1/S2
`spike13_*` migrations: 15256 (the 4b Antiquities sample), 15582 and 15988 (4b's two outliers),
16110 (a 4b "orphan footnote 1" book), alongside 14679 and 15641 from earlier steps. The
transform is a migration-time script (`s5-transform.php`) that writes to side tables instead of
rewriting nodes, so two variants render from the same content through a `?fn=a|b` switch on the
S2 controller. Text format: `full_html` plus `filter_footnotes` with its own list off (4b: the stock
list drops render-cache hits).

- **Variant A**: citations become `<footnotes data-value data-text>`; D7's Notes pages stay where
  they are, as content.
- **Variant B**: as A, and the definitions are removed from the Notes pages; an Option 3 list (read
  from the transform's own table) is appended, one group per chapter.

**Acceptance: counts match D7, both variants.** D7 counts are from the published pages' stored
bodies (the D7 whole-book view prints them as-is, S2), parsed as HTML.

| D7 book | Citations (D7 = A = B) | Notes (D7 = A in place = B listed) | Note |
|---|---|---|---|
| 15256 | 134 | 135 | |
| 15582 | 410 | 419 | anthology; 23 wrapped XHTML pages (finding 4) |
| 15988 | 21 | 21 | not an outlier (finding 5) |
| 16110 | 52 | 53 | |
| 14679 | 67 | 68 | |
| 15641 | 10 | 0 | citations with no definition anywhere; left in D7 form |

Beyond counts, per citation, in both variants: the popover text equals the definition D7's reader
reaches (0 mismatches of 684); every citation links to an element that exists and whose note text is
that definition; every definition links back to its first citation. The text check was shown to
fail: built against 4b's book-wide rule it reports 50 wrong popovers in 15582. In the browser, as
admin, clicking every citation opened the module's dialog with the right note: 410 of 410 in 15582
and 20 of 21 in 15988, in both variants (finding 6 covers the one that did not). On the live D7 site
the 15582 whole-book page shows 410 citations and 419 notes, matching the table.

**Findings (corrections to the 4b transform's assumptions):**

1. **Resolve per chapter, not per book.** 15582 is an anthology: each chapter has its own Notes page
   and numbering restarts, so 30 keys are defined more than once in the book. 4b's rule (match the
   key anywhere in the `bid`) gives 50 wrong popovers. The rule that works on every sampled book:
   a citation resolves to **the first page at or after it, in outline order, that defines its key**.
   Nothing resolves twice under it. D7's own whole-book page has the same collision (`#n1` occurs
   several times), so D7's click-through goes to the first chapter's note; the transform therefore
   gives citations and definitions new unique ids (`s5c-<D7 nid>-<key>`, `s5n-<D7 nid>-<key>`).
2. **Keys are strings.** A second anchor scheme, `nb-b7-1` / `n-b7-1` (chapter-prefixed), carries
   314 of 15582's definitions. 4b counted only `nb{N}` / `n{N}`.
3. **`<a name="nN"/>` is not self-closing to an HTML parser.** Many rows write it that way; libxml
   leaves the anchor open, so the definition text lands *inside* it. A parser that skips the anchor
   finds empty definitions (it emptied every popover in 16110 and 14679 until fixed). The transform
   keeps the anchor's children and drops only the `[N]` back-link.
4. **25 D7 bodies are whole XHTML documents** (`<html><head><title/></head><body>…`, opened and never
   closed); 23 are in 15582, 2 are in no book. **D11 renders them as escaped raw markup**: `full_html`'s
   HTML corrector parses `<title/>` as an open title that swallows the page. D7 shows them correctly
   (checked in the browser). This is not a footnote issue: the Texts migration must strip the
   wrapper for every page, or these pages break whatever the footnote decision is.
5. **4b's outliers were miscounted.** 15988 is fully matched (21/21). 15582's surplus definitions are
   its anthology structure (findings 1, 2). The real quirks in the sample are small: 3 definitions
   cited nowhere, 6 citations in a chapter page that D7 files under a different book (15851) from its
   Notes page, and 15641's 10 citations with no definitions at all. The "orphan footnote 1"
   convention is real but not always number 1: the uncited notes are introductions keyed 253, 32
   and 1.
6. **The module's dialog needs help.** With the module's list off, its dialog script finds nothing:
   it looks the `href` target up inside its own list markup (`.js-footnote-reference`). 4b's "popovers
   work regardless" held only for the native `title` tooltip. The spike writes definitions in that
   markup (A in place, B in the list) and points each citation at its definition, which makes the
   dialog work. Two production points remain: the filter adds one dialog element per page body
   (73 copies with duplicate ids on one 15256 page), and the script looks only 5 ancestors up for one,
   so a citation nested in an image-caption table (15988, D7 16090) gets no dialog, and its click is
   cancelled, so it does nothing. Production should output one dialog per page with its own small
   handler, or override the template.

**A vs B (for TX3).** Both meet the acceptance. A matches D7's layout exactly (notes stay where the
author put them, per chapter), keeps the stored Notes pages' text, and needs no aggregation. B removes
content from stored pages and has to rebuild per-chapter grouping that A gets for free; its one gain,
a single list the module controls, is not needed now that A's definitions carry the dialog markup.
**Decision: A** (Than, 2026-11-09, after clicking through variant A of 15582 locally: "works
perfectly"). This reduces 4b's Option 3 to the citation rewrite plus id assignment; TX3 records it.

**Not tested.** CKEditor 5 editing of the transformed markup (4b item 3); the full corpus (7 books
here); a Notes page hidden from the viewer while its citations are visible (A then shows popovers
whose click target is missing; B drops the group, as D7 would); the theme's styling.

### S6

**Status 2026-11-13: (a) and (b) passed; (c) moved out of the spike.** Throwaway code is on the branch
`spike/13-texts-whole-book-s1` (pushed for this step; never merge): routes `/spike13/text/{nid}` and
`/spike13/pubreader/{nid}` in `spike13_render`.

**(a) Raw text: passed.** Uses the same transform as D7 `shanti_texts_node_ajax_text`: the
`single_text_body` sections without the Contents list, runs of whitespace collapsed, then tags
stripped. Compared word by word with live D7 `shanti_texts/node_ajax_text/<nid>` (fetched with curl,
no WAF challenge) for 15256, 14158 and 14679, all as anonymous. Every word matches except:

1. 14158: one section is in a different position. This is the S4 sibling tie (weight 16), which the
   TX3 normalization fixes.
2. 14679: D7's text includes KMaps popover text ("… places 431 For more information about this
   term, see Full Entry below …"), because D7's KMaps filter writes the popover inline. D11 has no
   such filter yet, so its text is cleaner. TX3 should keep popover markup out of the raw text.
3. A few places where D7 runs two words together (for example a city name and an email address, or
   "WyliePhoneticsType" and a letter) and D11 has a space. D11 is the more correct.
4. One inline `<script>` in 15256 strips to different text. One `&nbsp;` per book stays an entity in
   D11; D7 outputs the character.

Finding: D7's views templates put newlines between the section elements, and the raw text depends
on them. Without them, D11 joined the last word of each page to the next page's title. The spike
render now emits them; TX3's render template must do the same.

Book 15641 was left out of the comparison because S3 changed its access (10 of 52 pages private).

**(b) PubReader: passed.** D7's `book_pubreader` module (the bundled NCBI PubReader3, 2.3 MB, plus its
page template) was copied into the spike module unchanged and is served outside the theme, as D7's
delivery callback did. One nested `<section id="node-<d7 nid>">` per page with an `h<depth>` title.
Checked in Chrome on the 374-page book (14679) against D7's `book_pubreader/14679`: 374 sections in
both, the same 11 Contents entries, the same section ids, no console errors. The other two books
return 200 with every section (checked over HTTP only). The page count differs (D11 289, D7 457 at
the same window size); likely the extra popover and image markup in D7, not checked, and not part of
the acceptance.

Open for TX3: like D7, PubReader drops a page the viewer cannot see together with its subtree; the
whole-book render (S3) keeps the subtree. One rule should apply to both.

Open, not checked: an access question about the D7 raw-text route. Ask Than before writing about it here.

**(c) Voyant: not yet tested.** Local DDEV is not public, and dev-0 does not have the spike module.
First attempt (2026-11-10): `ddev share` with a free ngrok tunnel. The raw-text route answered
through the tunnel, but Voyant built a corpus of 0 words. Cause: on free domains ngrok serves an
"about to visit" warning page (ERR_NGROK_6024) instead of the site to requests that look like a
browser, and only a client-sent header skips it. Reproduced with a Chrome User-Agent (2.8 KB warning
page) against curl or Java (391 KB text). ngrok's request log shows no request from Voyant reached
DDEV. This is a tunnel limit, not a route problem. The tunnel was stopped right after.

**Decision 2026-11-13 (Than): Voyant is not required to close S6.** Rather than retry with another
tunnel, check it once Texts content and the raw-text route are on dev-0, which is publicly reachable.
That check, including the open WAF question, is tracked in
[texts-voyant-handoff-check-post-migration.md](../deferred/texts-voyant-handoff-check-post-migration.md).
