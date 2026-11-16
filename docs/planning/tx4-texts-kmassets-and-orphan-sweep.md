# TX4: Texts kmassets naming and orphan review-group sweep

**Audience:** Developers
**Date:** 2026-11-16
**Author:** Than Grove, with Claude Code
**Sprint item:** [Sprint 5](../sprints/sprint-05-texts-core-implementation.md) TX4. Feeds TX8 (kmassets settings) and the Texts part of [#289](https://github.com/uvalib/mandala-navina/issues/289).

TX4 had three parts. The roadmap "paused" status was already corrected on 2026-11-02 (it now reads
"resumed 2026-11-02"), so only the other two are covered here.

## 1. kmassets naming for Texts

### What the legacy index holds

Measured read-only on the **staging** kmassets core, 2026-11-16 (production not queried):

| Field | Value(s) | Docs |
|---|---|---|
| `service` | `texts` | 6,173 (all) |
| `asset_type` | `texts` | 6,173 (all) |
| `asset_subtype` | `book` / `page` | 992 / 5,181 |
| `visibility_i` | 1 / 2 | 5,346 / 827 |
| `uid` | `texts-{d7 nid}` | |

The 5,381 count in the [index inventory](solr-index-inventory.md) is an earlier/other-cluster
figure; staging now holds 6,173. The 992 `book` docs equal the D7 count of published book
roots exactly. Staging also contains docs whose `url_html` points at the old dev host
(`mandala-texts-dev.internal...`), so staging URLs are not a reliable reference; the D7 code is.

### How D7 builds them

`shanti_texts_kmaps_fields_solr_doc_alter()` (D7 `shanti_texts.module`):

- **One doc per book node, root and page alike.** There is no separate page type: both are
  node type `book`.
- **`asset_subtype` is derived from the outline, not stored in a field:** `book` when
  `nid == bid`, otherwise `page`.
- Pages of unpublished books are dropped from indexing
  (`shanti_texts_kmap_fields_index_nids_alter`).
- Extra fields on **every** book node: `creator` + `creator_role_ss` (author/editor/translator),
  `mlid_i`, `mlweight_i`, `plid_i` (outline position, "for recreating TOC"), `citations_ss`,
  `pagination_s`, `node_lang`.
- **Pages only:** `book_title_s`, `book_nid_i` (the root).
- **Roots only:** `part_id_ss` / `part_titles_ss` (child nids/titles), `part_id_kmap_ss`
  (`texts-page-{nid}|{kmapid}`), child kmapids rolled up into the root's `kmapid`, `url_thumb*`
  from the featured image, `caption`, `description`, `summary` (≤750 chars), `date_start` (first
  of original year / publication year / book date).
- URLs: `url_html` is the path alias (`/content/<alias>`), `url_ajax` is
  `/shanti_texts/node_ajax/{nid}`, `url_json` is `/shanti_texts/node_json/{nid}`.

The React app (`mandala-om`, `TextsViewer.js`) relies on `asset_subtype === 'page'` to
redirect a page link to its book root with a `#` anchor, which is the same behaviour ADR 021
keeps for D11 page URLs.

`asset_link` nodes (8 in D7) are **not** indexed: there are no `texts` docs for them.

### Decision

- **`service: texts`, `asset_type: texts`**, unchanged. D11 uids become `texts-11-{d11 nid}`
  with `uid_legacy_s = texts-{d7 nid}`, per the
  [uid identity note](../deferred/kmassets-uid-identity-across-migration.md).
- **Index both roots and pages**, with `asset_subtype` = `book` / `page` derived from the D11
  outline (`bid == nid`), and skip pages of unpublished books, as D7 does.
- **Do not add `asset_link` to the kmassets settings.** This corrects TX8, which currently says to
  add `book` and `asset_link`. D7 never indexed `asset_link`, and ADR 008 says migrate, don't
  improve.
- **URL templates** follow whatever the AV/Images bundles use at TX8 time (the canonical-path
  decision, ADR 016). This doc doesn't settle them.

### Consequence for TX8: a code change, not only config

`KmassetDocBuilder` only supports a field-backed subtype (`asset_subtype_field`), and the
bundle settings can't express "root vs page". TX8 therefore needs a **Texts contributor**
(`src/Contributor/`, alongside `ImageFieldContributor` and `TranscriptIdContributor`) that sets
`asset_subtype` and the book-specific fields listed above from the Book outline. It also needs
an index-time filter for pages of unpublished books. Which of the extra fields are actually
read downstream (React app, Solr proxy) should be checked during TX8 before porting all of them;
`asset_subtype`, `book_nid_i` and `book_title_s` certainly are.

## 2. Orphan review-group sweep (#289)

### D7 data (local `d7_texts` DB, 2026-11-16)

Node types: `book` 7,633, `asset_link` 8, `collection` 65, `subcollection` 57.
"OG" = has a row in `og_membership`.

| Book node | Status | Total | With own OG | No OG, root has OG | No OG, root has none |
|---|---|---|---|---|---|
| root | published | 992 | 944 | (n/a) | **48** |
| root | unpublished | 59 | 52 | (n/a) | **7** |
| page | published | 6,576 | 6,409 | **34** | **133** |
| page | unpublished | 6 | 6 | 0 | 0 |

- All 8 `asset_link` nodes have a membership: no orphans.
- One page sits in a different group than its root.
- No `book` node is missing from the `{book}` outline.

Unlike Images and AV, **pages normally carry their own membership** (6,415 of 6,582). So
`group:sweep-orphans --site=texts`, which treats every group-less node as an orphan, is broadly
right for Texts. It needs no code change to run.

### Plan

1. After the Texts node and membership migrations (TX10 and TX7) land on DDEV, run
   `drush group:sweep-orphans --site=texts --gid=<Texts review group> --dry-run`. Before step 2
   that would be 222 nodes (55 roots + 167 pages); less any excluded under TX10's
   unpublished-book decision.
2. **Decided (Than, 2026-11-16): the 34 pages with no membership whose root has one take
   their root's group** in the Texts membership migration (TX7), and are not swept. A book renders
   as one scroll (ADR 021), so mixed access within a book has no sensible display. This is a
   deliberate, small ADR 008 deviation. The rejected alternative was sweeping them as-is, which
   would split those books across two groups. The dry-run should then report about **188** nodes
   (55 roots + 133 pages).
3. The 133 pages under a group-less root follow their root into the review group either way.
4. Run on dev-0 (Than, by hand) after the Texts migration reaches it; the Texts review group
   (DDEV gid 417) must exist there first, as it did for Images and AV.
5. Hand the swept list to Texts content staff, as with AV14.

The one page in a different group from its root is a related oddity, recorded here and left
for TX7.
