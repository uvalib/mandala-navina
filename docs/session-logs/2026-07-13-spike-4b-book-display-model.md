# Session Log: Spike 4b Book-Display Model Correction

**Date:** 2026-07-13  
**Participants:** Than Grove, Claude Sonnet 5  
**Outcome:** Corrected the D7 book-display model in spike 4b (pages concatenate at render time, not display independently), refined the migration transform to avoid merging book pages into one node, and empirically confirmed a real bug: the `footnotes` module's stock end-of-book Notes-list aggregation breaks under Drupal's default entity render cache. Reopens the Option 1 vs. Option 2 decision for the team. See [spike-04b-ckeditor5-footnotes.md](../spikes/spike-04b-ckeditor5-footnotes.md) and [PR #34](https://github.com/uvalib/mandala-navina/pull/34).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded. Trimmed from a longer session that opened with*
*unrelated spike-status review, a PR #30 code review, and general infrastructure*
*Q&A (Terraform/Ansible/Shibboleth/ALB) — none of that is part of this branch's work.*

---

## User — 2026-07-13 14:34

Ok I would like to do a little more on spike 4b. Can you open a separate branch for that?

---

## Claude — 2026-07-13 14:35

What did you have in mind?

---

## User — 2026-07-13 14:38

I think you have a fundamental misunderstanding of how texts works in the Mandala Texts D7 site. While each section of the text is stored as a different node/page, at display time they are all concatenated together and the whole book is displayed with a TOC to the rights. Anchors are placed at the beginning of each section and these are referred to by the TOC. Thus, https://texts.mandala.library.virginia.edu/thl/sera/space#shanti-texts-16099 goes to the section on Sera and the Metaphysics of Tibetan Sacred Space within the whole essay. Notes are included in the concatenated text, so note references can link to the note section at the end with anchor links.

---

## Claude — 2026-07-13 14:39

I have the D7 source checked out — let me look at how book display concatenation actually works before updating the spike doc.

---

## Claude — 2026-07-13 14:41

This is a real correction, and I verified it directly against the D7 source (not just taking it on faith) — it changes the picture significantly. Let me open the branch and check what this actually means for the spike's conclusion.

---

## Claude — 2026-07-13 14:42

Now inserting the correction section right after the decisive-finding section, with the code evidence.

---

## Claude — 2026-07-13 14:43

Now updating the "Recap for the team" section at the top to match, and the "Not yet done" list.

---

## Claude — 2026-07-13 14:43

Now updating the "Not yet done" list to reflect the new open question and confirmed status.

---

## Claude — 2026-07-13 14:44

Now let me commit this on the new branch.

---

## Claude — 2026-07-13 14:44

Committed on `spike/4b-book-display-model` (`a9de8df`). Updating memory now to reflect this.

---

## Claude — 2026-07-13 14:44

Also updating the top-level project state memory's spike-4b line to match.

---

## Claude — 2026-07-13 14:44

Memory and docs are updated. Summary of what I found and did:

**The correction, verified against the actual D7 source** (`/Users/ndg8f/Sandbox/Mandala/Site/mandala-drupal`):
- `node--book.tpl.php:15` calls `views_embed_view('single_text_body', 'panel_pane_default', $bid)` — book display is a Views query keyed on the whole book's `bid`, not a per-node render.
- The `single_text_body` view wraps every page-node sharing that `bid` in `<a name="shanti-texts-[nid]">` anchors; `single_text_toc` links to them with `<a href="#shanti-texts-[nid]">` — exactly matching your `#shanti-texts-16099` example.
- So D7 has never had a "cross-page footnote" problem for readers — it solved this over a decade ago by treating the whole book as the display unit.

**What this changes:** Option 1 (merge book pages into one field at migration) isn't novel, risky content-modeling — it's replicating an already-proven D7 pattern. That decouples it from the AV-transcript-format question and makes it the clear leading option, pending your team's sign-off.

**What doesn't change:** `footnotes` 4.x still needs the footnote text *inside the citation tag itself* (`data-text` attribute), so a transform is still required — it just now operates on content that's provably meant to be concatenated, not speculatively restructured.

I flagged one new open question the correction surfaces: CKEditor 5 editing UX for very large concatenated books (some have 11+ citing pages). Wrote all of this into `docs/spikes/spike-04b-ckeditor5-footnotes.md` on branch `spike/4b-book-display-model` (commit `a9de8df`), not yet pushed/PR'd. Want me to keep going — maybe test whether the anchor-pair transform + concatenation actually round-trips cleanly through `footnotes` 4.x?

---

## User — 2026-07-13 14:47

The only problem with concatenation on migration is that some books are rather large. If you are suggesting we make a single node per book, it might reach the size limit for the body field and/or result is slow rendering since the node is so large.

---

## Claude — 2026-07-13 14:48

Good catch — that's exactly the question I flagged as unresolved. Let me get real numbers instead of speculating.

---

## Claude — 2026-07-13 14:48

Good, the `d7_texts` DB from the earlier spike session is still loaded. Now querying actual content sizes per book.

---

## Claude — 2026-07-13 14:50

Good catch, and it made me realize I'd bundled two separate things together that don't need to be. Let me show you the actual numbers first.

---

## Claude — 2026-07-13 14:50

Two separate things here, and I'd conflated them:

**Field size limit — not a real constraint.** Both D7's `field_book_content` and D11's equivalent long-text field storage use MySQL `longtext` (4GB limit). Even the single largest book — 294 pages concatenated, `bid=14158` — is 4.46MB, a thousand times under that ceiling. That part of your concern doesn't actually bite.

**Editing/rendering performance — real, but narrower than I implied.** The corpus is heavily skewed: median book is 5.7KB, only 20 of 1,046 books (1.9%) exceed 1MB, and 4.46MB is the extreme outlier. So "concatenate every book into one node" would only meaningfully hurt CKEditor editing performance for that long tail of ~20 books, not the corpus generally — but it's still a real risk for those, and I shouldn't wave it away.

Here's the actual fix, though: **I don't think we need to concatenate into one node at all.** I conflated two different requirements when I wrote up Option 1:

1. The migration transform needs *read* access across a whole book's pages (to match each `nb{N}` citation to its `n{N}` definition, wherever it lives) — this is unavoidable.
2. But it does **not** need to *write* everything into one merged node. `footnotes` 4.x's requirement is just that the citation tag itself carries the resolved text (`data-text="..."`) — that's a per-citation rewrite, not a per-book merge.

So the transform can be: for each citing page, look up its citations' definitions anywhere in the book (via `bid`), and rewrite *that page's own field* to inline the resolved footnote text into a self-contained `<footnotes>` tag — while keeping D11's node structure exactly as granular as D7's, one node per page. No node grows beyond its own original content plus some short inlined footnote text. The size/editing-UX problem disappears because nothing is actually being concatenated into a single node — only read at migration time, not merged at storage time.

Does that hold up from your side — any reason the Notes-page separation needs to survive as its own thing (e.g., something else links directly to it, or it's meant to double as a standalone bibliography view), or is inlining the resolved text into each citing page and dropping/redirecting the Notes page clean from a content standpoint?

---

## User — 2026-07-13 14:57

But how would the notes be displayed, if the book is display in a single scrollable window? Would they be displayed at the bottom of each section? Or would they only be displayed as a popup when the refence number was clicked?

---

## Claude — 2026-07-13 14:57

The module's already present locally. Let me check the templates and JS to see exactly how it renders — bottom list, popup, or both.

---

## Claude — 2026-07-13 14:58

Both happen by default, confirmed from the module's actual Twig templates:

**Popup on click** — `footnote-link.html.twig` renders the citation as `<a class="footnote__citation js-footnote-citation" title="{{ fn.text_clean }}">`, and there's a `<dialog id="js-footnotes-dialog">` that JS opens on click, populated from that same embedded text. This works per-citation regardless of layout, since the resolved text is baked into that citation's own tag — no dependency on where anything else is.

**A list at the bottom** — `footnote-list.html.twig` *also* renders a `<ul class="footnotes">` of every footnote in that text value, with backlinks, appended after the content. This isn't optional/either-or — both render together unless explicitly disabled.

Here's the part that matters for your question: **"the bottom" is scoped to whatever text value got filtered, not the whole book.** Given the per-page-node structure I'm proposing (each page keeps its own field, independently run through the filter, then D11 glues the rendered fragments together the same way D7's `single_text_body` view does), that means each page's own footnotes list would render right after *that page's own section* within the scrollable window — not all collected at the true end of the book the way D7's dedicated "Notes" page currently reads.

If you want the D7-style "everything collected at the very end of the book" layout instead, that needs extra custom work — I checked the module's alternative (`footnotes_footer_disable` + a `FootnotesGroupBlock`), and it's also scoped to one entity at a time, not built to aggregate footnotes across multiple sibling nodes assembled into one view. So it's not a config flip, it'd be a small custom render step.

So the real question for you: is **per-section note lists** (notes appear right after the section that cited them, popup always works either way) an acceptable — maybe even better — reading experience than D7's current single end-of-book Notes page, or does the "all notes gathered at the end" layout need to be preserved as-is?

---

## User — 2026-07-13 15:03

So if we are concatenating section nodes at display time, could we not also concatenate footnotes in an end section at display time and thus achieve the exact display the the D7 site has?

---

## Claude — 2026-07-13 15:04

This is exactly the mechanism. Let me read the `process()` method fully to see how the static accumulator behaves across multiple field renders in one request.

---

## Claude — 2026-07-13 15:05

Confirmed — that's exactly what the module is built for. Walked through `FootnotesFilter.php` line by line:

**The accumulator is a static PHP class property** (`self::$storedFootnotes`, `self::$counter`) that persists across every call to `process()` within one PHP request — and each call *appends* (`self::$storedFootnotes[$key][...] = $footnote`, not an overwrite), with the numbering counter incrementing continuously across calls too.

**The behavior switches on one setting, `footnotes_footer_disable`:**
- **Off (default):** each field's footnote list renders inline right after that field's own text, then the accumulator resets to empty — this is the "per-section" behavior I described earlier.
- **On:** no inline list is rendered at all. Instead, the filter just keeps *feeding* the running static accumulator into `FootnotesGroup` (a separate service holding the current total) on every call, with **no reset** — so if D11 renders every page-node in the book within one request (exactly what concatenating them into one view requires), by the time the last page's field has been filtered, that accumulator holds the union of every footnote from every page in the book. A `FootnotesGroupBlock` (or an equivalent single render call to `FootnotesGroup::buildFooter()`) placed once at the true end of the book then dumps that complete, correctly-numbered list — which is precisely D7's "Notes" page, reproduced.

So yes — turn on `footnotes_footer_disable` for the text format used on book pages, render each page independently (citations still work per-page via inlined `data-text`, popups included), and place one group block at the very bottom of the concatenated view. That gets you the exact D7 layout with none of the node-size concerns.

**One real risk before this is locked in:** this only works if every page's filter actually *executes* within the request that builds the final book view. Drupal caches filtered text output by default (the `cache.filter` bin, plus render-array `#cache` on fields/entities) — if D11 ends up caching each page's body field independently and the book view just stitches together already-cached fragments, `process()` never re-runs for those pages in that request, and the accumulator misses them. This needs the book-body view to be cached as one atomic unit (matching your existing `single_text_body` view's own render caching, if any) rather than per-page-fragment caching — worth verifying against however D11 ends up rendering the concatenated view before treating this as settled.

Want me to write this into the spike doc now, or dig into the caching question first?

---

## User — 2026-07-13 15:05

Write it into the spike doc

---

## Claude — 2026-07-13 15:07

Now let's also fix the top-of-file status line and recap section to reflect this refined understanding rather than the superseded "merge into one field" framing.

---

## Claude — 2026-07-13 15:07

Now committing this.

---

## Claude — 2026-07-13 15:07

Committed as `887968b` on `spike/4b-book-display-model`. The doc now reflects the full refined picture: no node merge needed (verified against real size data — only 1.9% of books exceed 1MB, and the fix sidesteps that anyway by keeping D11's per-page granularity identical to D7's), and the exact D7 end-of-book Notes layout is reproducible via `footnotes_footer_disable` + `FootnotesGroupBlock`, confirmed by tracing the actual filter source rather than assuming. One open item flagged for later: verifying the book view won't be render-cached per-page-fragment, which would silently break the aggregation.

---

## User — 2026-07-13 15:09

Do we not want to verify that the caching won't be an issue?

---

## Claude — 2026-07-13 15:10

Yes — let's verify rather than leave it as an assumption. The module ships its own test for exactly this scenario. Let me read it first before building anything myself.

---

## Claude — 2026-07-13 15:18

That confirms the render cache is populated with entity-scoped keys (`node:111344`, bin `render`), independent of any page context — exactly the mechanism that could bypass the filter. Now the critical test: a **fresh PHP process** (fresh static accumulator) rendering both nodes together, where node A is already cached from the prior run.

---

## Claude — 2026-07-13 15:18

That's a methodology bug on my end (I read the accumulator before the content that populates it actually rendered) — let me check how the real block handles this ordering, since it's exactly the same problem.

---

## Claude — 2026-07-13 15:19

Fully confirmed, cleanly isolated. Node A (previously rendered, cache HIT) and Node C (brand new, cache MISS) both display correctly on the page — but the aggregated footer list is **silently missing Node A's footnote entirely**, with no error. That's the exact failure mode I'd flagged as a risk. Let me clean up the test artifacts now.

---

## User — 2026-07-13 16:34

[Request interrupted by user for tool use]

---

## User — 2026-07-13 16:35

Didn't mean to interrupt. Continue....

---

## Claude — 2026-07-13 16:35

The cleanup script's file write got rejected along with the rest of that command, so the test nodes and filter config change are still in place. Let me redo the cleanup properly.

---

## Claude — 2026-07-13 16:35

Good, cleaned up. Now removing the temp test scripts directory.

---

## Claude — 2026-07-13 16:36

Now replacing the "residual risk, not yet verified" paragraph with the confirmed empirical finding, and updating the sections that depend on it.

---

## Claude — 2026-07-13 16:36

Now updating the "What this means" Option 1 description and "Not yet done" list to reflect this confirmed finding rather than a hedge.

---

## Claude — 2026-07-13 16:36

Also updating the top status line and recap section to reflect the confirmed (not just flagged) caching problem.

---

## Claude — 2026-07-13 16:37

Now let's commit this.

---

## Claude — 2026-07-13 16:37

Committed as `75ffa84`. Updating memory to reflect this.

---

## Claude — 2026-07-13 16:37

Yes — glad you pushed on that. Here's what the empirical test found:

**Confirmed, not just theoretical:** I rendered a footnote-bearing node standalone first (populating Drupal's entity render cache), then in a fresh process rendered it alongside a brand-new node in one composite array — simulating a book view. Both citations displayed fine, but the aggregated Notes list **silently dropped the cached node's footnote entirely**, with no error.

**And it's worse than "an edge case to watch for"** — Drupal's entity render cache persists indefinitely and isn't scoped to book-view context, so any page ever viewed on its own (direct link, search result, crawler, a prior book view) poisons the aggregation on every later render. This is close to guaranteed in production, not incidental. The module's own test suite only validates the single-node/multiple-fields case — it was never built or tested for aggregating across sibling nodes.

This changes the decision materially: Option 1 (the refined per-page transform) is still sound for citation popups, but the end-of-book Notes list now needs a real mitigation (disable caching on the book view, cache the whole book atomically, or build a cache-independent aggregation step) rather than being "solved by a config flag." Option 2 (plain hyperlinks, no popover) now has zero open technical risk, making it a genuine competitor rather than a fallback.

Wrote all of this into the spike doc (commit `75ffa84`) and updated memory. Test artifacts (3 temp nodes, temp filter config, temp scripts) are fully cleaned up — verified via direct DB check. This is a real open decision for the team now, not a clear lean toward Option 1.

---

## User — 2026-07-13 16:41

ok I pushed the branch. Can you make a pull request?

---

## Claude — 2026-07-13 16:42

PR opened: **https://github.com/uvalib/mandala-navina/pull/34**

Summary: the book-display-model correction, the refined per-page transform, and the empirically-confirmed render-cache bug in the Notes-list aggregation — reopening the Option 1 vs. Option 2 decision for the team. Docs-only, nothing merged yet.

---

## User — 2026-07-13 16:42

Update memory with the PR #34 link

---

## Claude — 2026-07-13 16:42

Memory updated with PR #34's link in both `project-spike-4b-ckeditor-footnotes.md` and `project-mandala-state.md`.

---

## User — 2026-07-13 16:42

Let's flush a session log for this branch too

---

## Claude — 2026-07-13 16:43

Let me find where the "open a separate branch" request starts, to trim the log to just this branch's work — consistent with how I handled the earlier session log.

---

## Claude — 2026-07-13 16:43

Found the boundary — `14:34:36Z`. Now extracting from there to the end of the session.

---
