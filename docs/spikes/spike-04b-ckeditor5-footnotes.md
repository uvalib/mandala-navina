# Spike 4b: CKEditor 5 Footnotes
**Status:** In progress — D7 side fully documented; D11 side confirms `footnotes` 4.x cannot represent the cross-page pattern as-is (Fail Criteria scenario triggered); awaiting team decision on 3 response options before continuing
**Lead:** Than Grove (built D7 shanti_texts and footnotes)
**Mode:** Individual
**Date:** 2026-07-10
**Branch/commit:** `spike/4b-ckeditor5-footnotes`

**Split from [Spike 4](spike-04-ckeditor5-footnotes.md) on 2026-07-10** — team-ratified.
See that file for the original combined scope and why it was split.

## Theory
Existing Texts site CKEditor 4 footnote markup (`shanti_footnotes`) can be
reliably transformed to CKEditor 5's footnote markup format without data loss.

## Open dependency (unresolved as of the split)
Depending on the **AV transcript format** (plain text vs. structured /
time-coded / rich markup), this spike and the AV transcript work may turn out
to be the same underlying proof ("structured Tibetan rich-text round-trip") —
see [docs/roadmap.md](../roadmap.md#open-question-resolve-before-phase-3-scoping).
That determination is still open and independent of the Unicode/CKEditor split
that created this file — it does not need to block starting this spike, but
the findings here may end up folded into a merged spike with AV transcript
work later.

## Demo

Source data: `mandala-prod-texts-db_20260710.sql.gz` (90MB, database
`mandalatextslibv`, downloaded 2026-07-10 — a prior download at the same
path was silently truncated to a 16-line mysqldump header; re-pulled and
verified as 10,120 lines / 280 tables before use).

Loaded into DDEV as its own database (`d7_texts`, alongside the existing
`d7_images`) so real SQL/Python analysis could replace the earlier
regex-over-raw-dump approach, which turned out to be misleading (see below):

```bash
ddev mysql -e "CREATE DATABASE d7_texts CHARACTER SET utf8mb4;"
gunzip -c mandala-prod-texts-db_20260710.sql.gz | ddev mysql d7_texts
```

Field values were exported with MySQL's default batch-mode escaping
(`ddev mysql d7_texts -e "SELECT entity_id, delta, field_book_content_value
FROM field_data_field_book_content"` — **not** `--raw`, which disables the
escaping needed to keep embedded `\r\n` from breaking row boundaries) and
analyzed per-row with a small Python script using real regexes, not shell
`grep` against the mysqldump text.

## Findings

### Content inventory
- `node.type = 'book'`: 7,633 nodes — this is `shanti_texts`' primary content
  type, analogous to Images' `shanti_image`. Also 65 `collection` + 57
  `subcollection` nodes (same collection pattern as Images/1b.2) and 8
  `asset_link` nodes.
- Book body text lives in **`field_data_field_book_content`** (91.9MB, 9,013
  rows across 7,633 book nodes — some nodes have multiple `delta` values),
  *not* `field_data_body` — `field_data_body` only has rows for
  `collection`/`subcollection` bundles. `field_data_field_split_text` /
  `field_data_field_split_headings` (from `shanti_texts_splitter`) are much
  smaller (117KB/229KB) — appear to be derived/cached split output, not the
  primary source.
- 1,138 book nodes are tagged `field_dc_lang_code = 'bo'` (ISO 639 Tibetan).
  **None of them contain footnote markup** — checked directly (`JOIN`
  against `field_data_field_book_content ... LIKE '%footnote%'` returned
  zero rows). Footnotes appear to be an English-scholarly-apparatus
  convention in this corpus, not used in pure Tibetan-script primary texts.
  Tibetan-language nodes are still in the sample below (for 4a's benefit /
  general corpus familiarity) but don't exercise the footnote transform.
- **All 7,633 book nodes sit in the D7 core Book module's outline table**
  (`book`: `mlid`, `nid`, `bid`) — every book is a tree of page-nodes sharing
  a `bid`. This turned out to be load-bearing for the footnote structure
  (next section).

### D7 `shanti_footnotes` markup pattern (confirmed from live data, not assumed)

Two-anchor pair, inline reference ↔ bottom-of-content definition:

**Inline reference** (in running prose):
```html
came from Sog sde of the Nag chu kha region<a href="#n2" name="nb2" class="note">2</a>
```

**Footnote definition** (grouped at the end of the content, after an
`<hr class="footnote-divider"/>`):
```html
<div class="footnote"><a name="n1"/><a href="#nb1" class="note">
  [1] </a>
  At the beginning, this temple was known as dPon tshang lha khang...
</div>
```

The pairing is symmetric: inline anchor `nb{N}` links forward to `#n{N}`;
definition anchor `n{N}` links back to `#nb{N}`. Visible marker is the bare
number in the inline case, `[N]` in the definition case.

### RESOLVED: the ref/definition count discrepancy

**The earlier "839 vs 1,015" corpus-wide `grep`-against-raw-dump counts were
themselves unreliable and are superseded by these numbers**, measured with
Python regex against clean field values pulled from the loaded DB:
**552 inline references, 579 definitions** (a much smaller, much more
explicable gap — the original numbers were an artifact of counting against
escaped mysqldump text rather than actual field content).

**Root cause of the (remaining, smaller) gap: footnote references and their
definitions are frequently on *different pages of the same book*, not the
same node.** `shanti_texts` books are D7 core Book-module outlines (a tree
of page-nodes under one `bid`); many books put all footnote *definitions* on
a single dedicated "Notes" (or "Glossary") page near the end, while the
*references* are scattered across many preceding content pages. Confirmed
concretely: footnote `nb168`'s inline reference is on node 15274
("Nangchu Doring"), but its definition (`n168`) is on node 15581 — a
sibling page titled **"Notes"** in the same book (`bid=15256`).

Grouping by `bid` instead of by individual node resolves nearly all of it:
of 29 distinct books containing footnote content, only **15 still show any
ref/def mismatch after grouping by book** (down from "every node mismatches"
when checked per-node in isolation). Of those 15:

- **Most (11) show the identical narrow pattern**: exactly one extra
  definition, numbered `1`, with no matching inline reference. This is
  consistent enough across independent, unrelated books that it reads as an
  **editorial convention** — an unmarked introductory/translator's note used
  as "footnote 1" — rather than a data-quality bug. Worth a quick manual
  check on 1–2 examples to confirm, but not expected to block the
  transformation design.
- **2 real outliers** worth flagging as genuine content-quality cases for
  manual review, not markup-pattern problems:
  - `bid=15582`: 25 refs vs. 56 defs — a book with far more footnote
    definitions than citations (`def_only` includes numbers 8, 9, 10, 18,
    19, and more).
  - `bid=15988`: 21 refs vs. only 1 def — the reverse: many inline citations
    (`nb2` through `nb6`+) with almost no corresponding definitions present
    at all.

### A second markup variant found: XML-namespaced footnote divs

**299 of the 9,013 `field_book_content` rows (~3.3%) use a different,
namespaced footnote div**, not the plain `<div class="footnote">` form:

```html
<div xmlns:i18n="http://apache.org/cocoon/i18n/2.1" xmlns:str="http://exslt.org/strings" class="footnote">
```

Same `class="footnote"` and same `n{N}`/`nb{N}` anchor convention inside,
just with extra namespace attributes on the wrapping `div` — almost
certainly residue from a Cocoon/XSLT-based import pipeline (consistent with
the "imported from the Tibetan and Himalayan Library" provenance noted in
some collection descriptions). **The transformation function must not
assume a bare `<div class="footnote">` opening tag** — needs to match on
the `class="footnote"` attribute regardless of what else is on the div.

### Corpus-wide markup counts (final, from DB-backed analysis)
| Pattern | Count |
|---|---|
| Inline reference anchors (`name="nb{N}"`) | 552 |
| Footnote definition divs (incl. namespaced variant) | 579 |
| Distinct books (`bid`) with any footnote content | 29 |
| Books with a ref/def mismatch after grouping by book | 15 (11 = likely benign "orphan footnote 1" convention, 2 = real outliers, 2 more minor) |
| Nodes using the `xmlns:i18n` namespaced div variant | 299 |

### Representative sample pulled (22 nodes)

Selected from the DB-backed analysis above to cover every pattern found —
standard same-page pairs, cross-page (book-outline) pairs, both markup
variants, both real outliers, and Tibetan-script content for 4a's benefit:

| nid | Title | Why included |
|---|---|---|
| 15274 | Nangchu Doring (Nang chu rdo ring) | Inline ref whose definition is on a different page (15581) |
| 15580 | Glossary | Same book (`bid=15256`), standard pattern |
| 15581 | Notes | The dedicated "Notes" page holding cross-page definitions; also has the `xmlns:i18n` variant |
| 16094 | Notes | From `bid=15988`, the ref-heavy/def-missing outlier |
| 15716, 15718, 15728, 15734 | Approaches to the bKa' 'gyur / Notes | From `bid=15582`, the def-heavy outlier |
| 16152 | Notes | From `bid=16110`, clean example of the "orphan footnote 1" pattern |
| 16249, 16271, 16287, 16301, 16311 | Location and Layout (×4), Introduction | More independent "orphan footnote 1" books, to confirm the convention is consistent across unrelated books |
| 39531, 39536, 39541, 39601, 39606, 39611, 39616 | Tibetan-script glossary entries (e.g. དུང་དཀར།, རྒྱ་གླིང་།) | Tibetan-language (`bo`) content, no footnotes — for 4a / general corpus reference |

### D11 side: `footnotes 4.x` architecture — DECISIVE FINDING, spike theory FAILS as originally scoped

Installed `footnotes` (already in `composer.json`/`composer.lock` at `^4.0`,
locked `4.0.0-rc2`, present in `drupal/web/modules/contrib/footnotes` but not
enabled — `ddev drush en footnotes -y`; pulled in `media` as a dependency).

**Source code (`src/Plugin/Filter/FootnotesFilter.php`) shows there is no
entity storage, no cross-node concept anywhere in the module.** The entire
mechanism is a single text filter (`filter_footnotes`) that processes one
field's rendered text in one pass:

- CKEditor 5 inserts an inline placeholder tag carrying **both** the
  citation position **and** the footnote body together:
  `<footnotes data-value="1" data-text="The footnote content itself"></footnotes>`
- At render time, the filter walks the DOM for that **single text value**,
  builds the inline citation link in place, and collects `data-text` content
  into a static, per-request list that gets appended as a footnote list at
  the end of that **same** text (or at a `<footnotes-placeholder>` marker
  within it).
- The only opt-out is `footnotes_footer_disable`, which defers rendering to
  a `FootnotesGroupBlock` instead of inline — but that block is still scoped
  to rendering **one entity's own footnotes** (via Twig Tweak node context),
  not aggregating across sibling nodes.

**Empirically confirmed** (`renderInIsolation()` against the `full_html`
format with `filter_footnotes` enabled — not enabled on any format by
default, had to be added for this test):

- A citation with `data-text` populated (definition co-located): renders
  correctly, full footnote text appears.
- A citation with `data-value="1"` but **no** `data-text` (simulating the D7
  pattern where the definition lives on a different page): still renders a
  numbered citation link and a footnote-list entry, but with the text
  **silently empty** — `<span class="footnotes__item-text ...">></span>`.
  No error, no cross-reference resolution, no way to point it at content
  living in another node's field value.

**Conclusion: `footnotes` 4.x cannot represent the D7 corpus's cross-page
footnote pattern as-is.** Citation and definition must be co-located in the
same field value it processes. This is exactly the "Fail Criteria" scenario
anticipated in this spike's own template ("`footnotes 4.x` uses a
fundamentally different storage model") — confirmed true, not hypothetical.

### What this means for the Texts migration (not yet decided — options only)

1. **Restructure content during migration**: merge each book's
   constituent pages (all nodes sharing a `bid`) into a single consolidated
   field/entity before/during migration, so every citation and its
   definition end up co-located and the module's per-field model works
   unmodified. This is very likely **the same underlying reshaping question**
   as the open AV-transcript-format dependency noted above ("structured
   Tibetan rich-text round-trip") — strengthens the case that these two
   should be evaluated together, not independently.
2. **Convert cross-page citations to plain hyperlinks** to the
   sibling "Notes" page/anchor instead of true `footnotes` module citations
   — loses the popover/footnote-list UX but requires no content restructuring
   and needs no cross-node capability.
3. **Evaluate alternative D11 footnote modules** — low expectation this
   changes the outcome; the single-field-scope limitation is an architectural
   choice already flagged in the fail-criteria table for the same reason
   before any module was installed. Worth a brief check but not a leading
   option.

No decision made yet — this needs discussion, likely with the team, since
option 1 has real content-modeling implications beyond this spike alone.

### Not yet done
- Decide between the three options above (or another) — team input needed
- Transformation function (D7 pattern → chosen D11 approach) — must be
  **book-outline-aware** (operate across all pages sharing a `bid`, not
  per-node) if option 1 is chosen, and must match both D7 footnote-div
  markup variants either way
- Rendering verification in CKEditor 5 for whichever approach is chosen
- Manual confirmation that the "orphan footnote 1" pattern is a benign
  editorial convention (spot-check 1–2 of the 11 books)

## What this does NOT establish
- Which of the three response options (above) is right for Mandala — needs
  team input, not just technical feasibility.
- Whether the two real content-quality outlier books (`bid=15582`,
  `bid=15988`) need cleanup before migration or can be handled as-is.
- Whether the AV-transcript-format dependency and this spike's book-outline
  reshaping question really are the same underlying proof — strongly
  suspected now, not confirmed.
- Full corpus scan for `footnotes`-4.x-incompatible edge cases beyond the
  22-node sample (e.g., nested footnotes, footnotes inside list items —
  seen in the D7 sample but not yet stress-tested against the module).

## Deferred notes
*To be completed when spike is run — likely: a deferred note capturing the
book-outline/AV-transcript merge question if the team confirms it's the
same underlying spike, so it's tracked as one decision rather than two.*

---

## Reference: Pass Criteria
- Footnote markup format for both CKEditor 4 and 5 is fully documented
- A deterministic transformation function handles all patterns found in the sample
- Transformed content renders correctly in CKEditor 5
- Edge cases are documented and accounted for

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| CKEditor 4 markup is inconsistent across the corpus | Plan a content cleanup pass in D7 before migration |
| `footnotes 4.x` uses a fundamentally different storage model | **TRIGGERED, confirmed 2026-07-10.** Module is single-field-scoped only; no cross-node capability. See "D11 side" findings above — 3 response options identified, team decision needed rather than a single "evaluate alternative modules" response. |
| Complex footnote patterns cannot be transformed deterministically | Manual content review required; scope the cleanup effort |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4b--ckeditor-5-footnotes)*
