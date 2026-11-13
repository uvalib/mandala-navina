# ADR 021: Texts books use contrib Book with a flat-outline whole-book render; page URLs, API and alternative formats keep D7 behaviour under ADR 016 paths

**Status:** Proposed — 2026-11-13, by Than Grove. **Needs team sign-off** (Sprint 5 TX3) before
TX5 onward starts.
**Date:** 2026-11-13
**Deciders:** Than Grove (Spike 13 lead); for sign-off: Yuji Shinozaki, Xiaoming Wang
**Relates to:** [Spike 13](../spikes/spike-13-texts-whole-book.md) (the evidence for every point
here), [Sprint 5](../sprints/sprint-05-texts-core-implementation.md) (TX3 and the tasks it gates),
[Spike 4b](../spikes/spike-04b-ckeditor5-footnotes.md), [Spike 6](../spikes/spike-06-api-compatibility.md),
[ADR 008](008-mvp-migrate-not-improve.md), [ADR 011](011-group-collections-inheritance.md),
[ADR 016](016-public-url-structure-single-host.md), [ADR 017](017-legacy-identity-composite-key.md),
[entity-access endpoint convention](../planning/entity-access-endpoint-convention.md)

## Context

A D7 Texts book is an outline of nodes (1,051 books, up to 374 pages and depth 7). Any page URL
renders the **whole book** as one scroll (`single_text_body` view), each page in a
`shanti-texts-section` with anchor `#shanti-texts-<nid>`, beside Contents, Description and Views
tabs. The Views tab offers PubReader, raw text and Voyant. Book is no longer in Drupal core, and
nothing built so far renders a document assembled from many access-checked entities, so Spike 13
proved the model before Sprint 5 builds it. All six steps passed (S6 part (c), Voyant, moved to a
post-migration check). **Go.**

## Decision

### 1. Outline model: contrib `drupal/book` 3.x

The outline is stored by contrib Book (verified on D11.4 with 3.0.8, S1). No custom outline table.

- The outline migration is a **Mandala copy of Book's `d7_book`** that maps `nid` and `book/bid`
  through `migration_lookup` (D7 Texts nids collide with existing D11 nids; ADR 017), still using
  Book's own source and destination plugins.
- Book's destination cannot update an existing outline, so a reset rolls back the outline
  migration, then the node migration, then re-imports both (S1 finding 2).
- `book.settings.allowed_types` is exported from a live Drupal (structured list in 3.x, S1 finding 4).
- **Tied sibling weights are normalized** (decided 2026-11-06): each group of siblings with equal
  weight is renumbered to strictly increasing values in D7's order (weight, title in MySQL
  collation, menu-link id); the original D7 weight is kept for audit. Rendered order then equals
  D7's in every book, independent of collation. Acceptance: checked on a real full migration,
  explicitly for 13283, 13871, 14158 and 15582.

### 2. Whole-book render: built on request from the flat outline, with per-page access

- Render on request from the **flat `{book}` rows**, ordered by Book's own sort key
  (`50000 + weight`, title, nid), checking **each page's own access**. Do **not** use Book's tree
  API, which drops an inaccessible page together with its subtree; in D7's data 1,410 published
  pages in 36 books sit under an unpublished ancestor and D7 shows them (S3 finding 1).
- No pre-rendering, queue worker or lazy sections. Measured on the largest books with the per-page
  access check (Spike 13, "Load times", 2026-11-13): server cold 0.6–0.9 s, warm 0.05–0.15 s, for
  14158 (294 pages, 4.8 MB), 15256 Zhang Zhung vol. II (326), 15582 Studies in Genre (284) and
  14679 (374). In Chrome, first paint 0.4–1.6 s and DOM ready 0.6–2.5 s, the same range as live D7
  on the same books (first paint 1.2–1.5 s, ready 1.5–2.1 s).
- **Cache declaration** (the S3 `full` configuration): merge the cache tags and max-age of every
  outline page and its access result, **hidden pages included**; drop per-user contexts; declare
  `user.permissions`, `user.roles:authenticated` and `user.mandala_collection_memberships`. Never
  rely on `user.group_permissions` for membership.
- Markup is D7's: section/title/content classes, `text-level-<depth>`, and newlines between section
  elements (the raw text depends on them, S6). Contents is built in the same access loop as the
  sections, in D7's `views_tree` shape (a page whose parent is hidden moves to the top level, S4).
- **Anchor ids use the D7 nid** (`field_legacy_nid`), so existing deep links like
  `#shanti-texts-14209` keep working.
- Page text is `field_book_content` (D7's `body` is empty for books, S2); the migration strips the
  whole-document XHTML wrapper from the 25 pages that have one (S5 finding 4).

### 3. Footnotes: Spike 4b's transform, resolved per chapter, Notes pages kept (variant A)

Decided 2026-11-09. A migration-time transform rewrites each citation to a self-contained
`<footnotes data-value data-text>` tag. A citation resolves to **the first page at or after it, in
outline order, that defines its key**; keys are strings; citations and definitions get new unique
ids. D7's Notes pages stay where they are as content (no aggregated Notes list). Production outputs
one footnote dialog per page (S5 findings 1–6).

### 4. Page URLs: a page redirects to its book root, with the page's anchor

As D7 does (checked in a browser on live D7, 2026-11-13: `/node/15581` →
`/thl/zhangzhung/antiquities/vol2#shanti-texts-15581`, one redirect). A request for a non-root
page's URL (its preserved D7 alias, or `/texts/{nid}`, ADR 016) redirects to the book root's URL
with `#shanti-texts-<D7 nid>`. Only the root renders the whole book, so there is one cached render
per book (per access context), not one per page. The redirect is access-checked like the page
itself, so it never reveals a page the viewer cannot see.

An earlier draft of this ADR chose "render the whole book at the page's own URL" as D7 parity; that
description of D7 was wrong (Spike 13, "Load times", finding), and Than chose the redirect on
2026-11-13 once it was checked.

### 5. `/api/json/{nid}`: a page collapses to its book root

As D7's `shanti_texts/node_json` does (Spike 6): any page nid returns the book root's document,
with its Contents. The flat ADR 016 path is unchanged.

### 6. Alternative formats: new routes under `/texts/{nid}/…`, legacy paths redirected

| Format | D7 path | D11 route |
|---|---|---|
| Raw text | `shanti_texts/node_ajax_text/{nid}` | `/texts/{nid}/text` |
| PubReader | `book_pubreader/{nid}` | `/texts/{nid}/pubreader` |
| Voyant | `shanti_texts/voyant/{nid}` | `/texts/{nid}/voyant` (redirect to voyant-tools.org with the raw-text URL as `input`) |
| Embed | `shanti_texts/node_embed/{nid}` | `/texts/{nid}/embed` (the **requested page**, not the root, as D7; `?nostyle`, JSONP, CORS) |

- Legacy paths redirect through `mandala_legacy_redirect` using the composite key (ADR 017), since
  their `{nid}` is a D7 nid.
- Every route enforces access per the entity-access endpoint convention.
- **One access rule for every format:** PubReader and raw text use the same flat-outline per-page
  check as the whole-book render. This deviates from D7's PubReader, which dropped a hidden page's
  subtree; it affects only the 36 books above, and keeps all formats showing the same pages.
- PubReader is D7's bundled NCBI PubReader3, served outside the theme (S6 b).
- Raw text is the whole-book render without Contents, whitespace collapsed, tags stripped, and
  **without KMaps popover text** (D7 leaked it inline; S6 a).
- Voyant was not proven in the spike. It is checked after migration on dev-0, including whether the
  WAF lets Voyant's server fetch the raw-text URL
  ([#408](https://github.com/uvalib/mandala-navina/issues/408)).

## Deviations from D7 (ADR 008)

All invisible or corrective; none changes what a reader sees except where noted:

1. Stored weights of 36 tied sibling groups are renumbered (rendered order unchanged from D7).
2. PubReader keeps the visible subtree of a hidden page (36 books), matching the book page.
3. Raw text omits KMaps popover text and does not run words together where D7 did.
4. Footnote ids are new and unique per chapter; D7's duplicate `#n1` ids in anthologies sent
   clicks to the wrong chapter.
5. Alternative formats move to `/texts/{nid}/…`; the D7 paths redirect.

## Consequences

- Sprint 5 TX5 onward is unblocked once this is signed off. Work handed on by the spike:
  TX5 group role grants for `group_node:book` (S3 finding 2); TX7 the `NodeQueryAlter::baseTable()`
  fix for Book's `node_access` queries, with a non-admin test (S1 finding 3); TX11 the lookup-based
  outline migration with weight normalization; TX15 the per-chapter footnote transform and XHTML
  wrapper strip; TX17 rollback order; TX20 the render and cache declaration above, timed on the
  largest books with per-page access; TX23 the routes above.
- One cache entry per book per distinct (bypass permission, authenticated, membership set). The
  per-page access check adds about 0.2–0.3 s to a cold render on the largest books (measured
  locally); TX20 re-measures on staging hardware.
- An upstream Book issue for the destination's update branch is worth filing.

## Not decided here

- Whether the D7 raw-text route enforced node access (an unconfirmed question; ask Than). D11's
  route enforces access regardless.
- Visual styling and the D7 editor-only "Insert" control (TX20).
