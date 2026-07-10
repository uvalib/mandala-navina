# Spike 4b: CKEditor 5 Footnotes
**Status:** In progress — D7 markup pattern documented, D11-side work not started
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
verified as 10,120 lines / 280 tables before use). Not yet loaded into DDEV.

Analysis so far was done directly against the compressed dump with `grep`/
`zgrep` (no DB load needed to document the markup pattern):

```bash
gunzip -c mandala-prod-texts-db_20260710.sql.gz \
  | grep "^INSERT INTO \`field_data_field_book_content\` " \
  | grep -oP 'name=\\"nb\d+\\"'   # inline reference anchors
```

## Findings

### Content inventory
- `node.type = 'book'`: 7,633 nodes — this is `shanti_texts`' primary content
  type, analogous to Images' `shanti_image`. Also 65 `collection` + 57
  `subcollection` nodes (same collection pattern as Images/1b.2) and 8
  `asset_link` nodes.
- Book body text lives in **`field_data_field_book_content`** (91.9MB),
  *not* `field_data_body` — `field_data_body` only has rows for
  `collection`/`subcollection` bundles. `field_data_field_split_text` /
  `field_data_field_split_headings` (from `shanti_texts_splitter`) are much
  smaller (117KB/229KB) — appear to be derived/cached split output, not the
  primary source.
- 1,138 book nodes are tagged `field_dc_lang_code = 'bo'` (ISO 639 Tibetan) —
  confirms a large enough Tibetan-language subset exists to satisfy the
  spike's sampling requirement (min 20–30 nodes, Tibetan-language nodes
  specifically).

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

### Corpus-wide markup counts (against the full 90MB dump)
| Pattern | Count |
|---|---|
| Inline reference anchors (`name="nb{N}"`) | 839 |
| Footnote definition divs (`<div class="footnote">`) | 1,015 |
| Footnote dividers (`<hr class="footnote-divider"/>`) | 21 |
| Inline refs missing `class="note"` | 132 (subset of the 839 above) |

### Open edge case: definitions outnumber references by 176

1,015 definitions vs. 839 inline references is not 1:1 — 176 more
definitions than references. Not yet root-caused; candidate explanations to
check against the actual node sample:
- Orphaned footnote definitions (content edited, inline citation removed,
  definition left behind) — plausible content-quality issue, not a markup
  format problem.
- A second inline-reference variant not matched by the `name="nb\d+"` regex
  (would need a broader pattern pass to rule out).
- Duplicate/copy-pasted definition blocks within the same node.

This needs the formal 20–30 node sample (not just corpus-wide regex counts)
to resolve — can't tell from aggregate counts alone whether it's a
markup-pattern gap or a content-quality issue in the D7 source.

### Not yet done
- Formal representative sample extraction (20–30 nodes incl. Tibetan-language)
- `footnotes 4.x` install on D11 test instance + its CKEditor 5 markup format
- Transformation function (D7 pattern above → CKEditor 5 format)
- Rendering verification in CKEditor 5
- Resolving the 839-vs-1,015 discrepancy above

## What this does NOT establish
- Nothing about the CKEditor 5 / `footnotes 4.x` side yet — D11 module not
  installed or inspected.
- Whether the 839-vs-1,015 mismatch is a markup gap or a content-quality
  issue in D7.
- Whether the AV-transcript-format dependency (noted below) changes this
  spike's scope.

## Deferred notes
*To be completed when spike is run.*

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
| `footnotes 4.x` uses a fundamentally different storage model | Evaluate alternative footnote modules for D11 |
| Complex footnote patterns cannot be transformed deterministically | Manual content review required; scope the cleanup effort |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-4b--ckeditor-5-footnotes)*
