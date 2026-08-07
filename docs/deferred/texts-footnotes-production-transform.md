# Texts footnotes: production transform + Notes-list aggregation (downstream of Spike 4b)

**Area:** migration / Texts / CKEditor 5 / footnotes
**Raised during:** Spike 4b closeout, 2026-08-07 (spike marked Complete; this is its downstream implementation work, preserved so it isn't lost)
**Jira:** (add when available)
**Priority:** **Medium** — not current-phase; belongs to the **Texts-site migration**. No open technical risk (feasibility proven + prototype working in Spike 4b), so this is build-out, not investigation.

## Context

[Spike 4b](../spikes/spike-04b-ckeditor5-footnotes.md) is **Complete**: it chose **Option 1 +
Option 3** and proved the whole approach feasible end-to-end, with a working prototype
(`spike_footnotes_demo`). What remains is the **production implementation**, which happens during
the Texts-site migration — not spike scope. This note is the checklist so that work is remembered.

**The chosen approach (recap):**
- **Option 1** — a migration-time, *book-outline-aware* transform resolves each D7 `nb{N}`/`n{N}`
  citation/definition pair across a book (`bid`) and rewrites **each citing page's own field** to
  inline the resolved text into a self-contained `<footnotes data-value data-text>` tag. D11 keeps
  one node per page (D7's exact granularity — no node-merge, no CKEditor editing-UX risk).
  Per-citation popovers work unconditionally (text is baked into each tag).
- **Option 3** — the end-of-book Notes list is built from the transform's own resolved output (a
  dedicated table/field), **not** the stock `footnotes_footer_disable` + `FootnotesGroupBlock`
  accumulator, which Spike 4b **empirically confirmed broken** under Drupal's default entity
  render cache (silently drops any footnote from a page ever rendered standalone). The
  `spike_footnotes_demo` module proves the dedicated aggregation is correct against that exact
  cache-HIT precondition.

## What remains to build (production, Texts migration)

1. **Book-outline-aware transform.** For each citing page, resolve its `nb{N}` citations to their
   `n{N}` definitions anywhere in the same book (via `bid`) and rewrite *that page's own field*
   to inline the resolved text into a self-contained `<footnotes data-value data-text>` tag.
   **Must match both D7 footnote-div markup variants**, including the `xmlns:i18n`-namespaced form
   (~3.3% of rows — 299 of 9,013). Writes back **per-page**, never merged.
2. **Integrate Option 3 with the real transform.** Wire the `spike_footnotes_demo` mechanism to
   the migration's actual resolved-footnote output (replace the hand-seeded `spike_footnotes_resolved`
   table), add book-outline-aware batch integration, styling/theming, and **automated test coverage**.
3. **CKEditor 5 render verification** of the transformed markup (round-trip through the editor).
4. **"Orphan footnote 1" convention.** Spot-check 1–2 of the 11 books that show the benign
   single extra `[1]` definition with no matching inline ref — confirm it's the expected
   editorial convention (unmarked introductory/translator's note), not a data bug.
5. **Two real content-quality outliers** (manual review — content, not markup):
   - `bid=15582` — 25 refs vs. 56 defs (def-heavy).
   - `bid=15988` — 21 refs vs. only 1 def (ref-heavy / definitions missing).
   Decide fix-in-D7-first vs. migrate-as-is.
6. **Full-corpus edge-case scan** beyond Spike 4b's 22-node sample: nested footnotes, footnotes
   inside list items (seen in the sample, not yet stress-tested against `footnotes` 4.x).

## Reference

- Spike doc: [`spike-04b-ckeditor5-footnotes.md`](../spikes/spike-04b-ckeditor5-footnotes.md) —
  full D7/D11 findings, the CONFIRMED render-cache bug, and the Option 3 prototype writeup.
- Prototype module: `drupal/web/modules/custom/spike_footnotes_demo/` (isolated reference impl,
  safe to keep; not production).
- The once-speculative "is this the same proof as the AV-transcript reshaping?" question is now
  tracked separately as [Spike 11 (AV transcript replication)](../spikes/spike-11-av-transcript-replication.md).
