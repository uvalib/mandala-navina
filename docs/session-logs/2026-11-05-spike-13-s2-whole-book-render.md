# Session Log: Spike 13 S2 Whole Book Render

**Date:** 2026-11-04 / 2026-11-05  
**Participants:** Than Grove, Claude Code  
**Outcome:** Spike 13 S2 (whole-book render) passed; results, a recorded wrong turn on sibling order, and the tie decision for TX3 are in [Spike 13](../spikes/spike-13-texts-whole-book.md#s2-whole-book-render-pass-2026-11-04-than-with-claude-code).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-11-04 18:05

doing a solo session. Where did we leave off last?

---

## Claude — 2026-11-04 18:06

We left off on **Spike 13 (Texts outline and whole-book render)**, on 2026-11-02.

**Where it stands**
- **S1 passed.** The contrib `drupal/book` 3.0.8 module reproduces the D7 book outlines exactly: 4 books, 444 rows, zero diffs, depth 7. Results are in PR #390, which is merged.
- **Planning is merged.** PR #389 added Spike 13, Sprint 5 (TX1–TX24) and the Texts plan.
- **S1 findings:**
  1. The shipped `d7_book` migration assumes preserved nids, and all 7,763 collide, so we need a lookup-based copy.
  2. The Book destination never updates an outline. A reset means rolling back the outline migration, then the node migration, then re-importing.
  3. `mandala_group_inheritance` `NodeQueryAlter::baseTable()` mistakes Book's `book` table for the node table. That causes a `b.type` SQL error for non-admins and is the TX7 fix.
  4. Book 3.x `allowed_types` is a list of `{content_type, child_type}`.
- **Next is S2.** That's the whole-book render test: the 374-page book and the 4 MB page, cold and warm. S3–S6 follow per the spike doc.

**Your request from last time:** before anything else, review the Texts example books on local DDEV. They are D11 nodes 123372, 123746, 123748 and 123761, viewable at `/admin/structure/book`.

I checked, and they should still be there:
- You're on the throwaway branch `spike/13-texts-whole-book-s1`, with a clean working tree.
- The `book` module is enabled locally.
- I couldn't confirm the snapshot. `ddev snapshot list` failed because of an unrelated DDEV project-catalog conflict with `/Users/ndg8f/Sandbox/CSC`. The snapshot `pre-spike13-s1-2026-11-02` should still exist, but I haven't verified it.

The books are title-only stand-ins, with no body text and no whole-book scroll yet.

**Cautions**
- I haven't run `session-start-check.sh`. Its resync step would wipe the example books, so I'll ask first.
- This branch is never merged. Before any non-spike work, switch to main and restore the snapshot, or config drift will show `book`.
- The Sources migration is still gated on Yuji and Xiaoming signing off on ADR 020 (issue #386).
- There are no open PRs.

Do you want to look at the example books first, then start S2? I can run `ddev drush uli` for a login link.

---

## User — 2026-11-04 18:06

Launch ddev and give me a url to look at

---

## Claude — 2026-11-04 18:07

DDEV is running. Open this one-time admin login link, which lands on the book list:

https://mandala.ddev.site/user/reset/1/1793815627/yN2UySSe9c1nVAV-vEVHBd29DvmLIUDGiG7SZtjfNu4/login?destination=/admin/structure/book

From the book list you can open each book. The nodes are 123372, 123746, 123748 and 123761, at `https://mandala.ddev.site/node/<nid>`.

The link is single-use. If it expires, I can generate another.

---

## User — 2026-11-04 18:14

Ok for the books in the list can you give me the original node ID in the Drupal 7 site. Also, I can see the pages in Child Order but I don't see any text content anywhere either in the book view or single page view.

---

## Claude — 2026-11-04 18:15

**D7 node IDs for the four book roots.** I got these from the spike's migrate map table, `migrate_map_spike13_node`. The stand-in nodes don't carry `field_legacy_site` or `field_legacy_nid`, so those fields are empty.

| D11 nid | D7 nid | Title | Pages in outline |
|---|---|---|---|
| 123372 | 14679 | A Survey of Bonpo Monasteries and Temples in Tibet and the Himalaya | 374 |
| 123746 | 15641 | Overview | 52 |
| 123748 | 53126 | test | 13 |
| 123761 | 53536 | Yoga | 5 |

**Missing text content.** This is expected. The S1 stand-ins were created with the title only, and no pages have a body (0 of 444 rows in `node__body`). S1 only had to prove the outline structure migrates, so the body text was never copied over. The same applies to the book view and the single-page view.

**For S2, the whole-book render test.** It needs real bodies, so the next step is a body-copy step. It would pull `body` (value and format) from the D7 source for those 444 nodes, using the D7 nid to D11 nid map above. The 374-page book and the 4 MB page are the real test cases. Do you want me to extend the throwaway `spike13_node` migration to include the body, or write a separate body-only migration? I'd lean toward extending `spike13_node`, because it's one source and one map. But Book finding 2 says a re-run needs a rollback first, so a separate body-only update migration avoids rebuilding the outline. I haven't checked whether D7 source DB access works from this machine, so I'd verify that first.

---

## User — 2026-11-04 18:20

Ok. The structures of each book match the originals. This part is verified. Let's move on to S2.

---

## Claude — 2026-11-04 18:21

Status: I found the D7 whole-book render. It's the `single_text_body` view in `shanti_texts_features`. I'm reading its markup now, so the D11 render can match its section classes and anchors.

---

## Claude — 2026-11-04 18:25

Status: my loop mangled the nid pairs because zsh doesn't word-split unquoted variables, so every request returned 404. I'm re-running with the pairs split properly.

---

## Claude — 2026-11-04 18:29

**S2 passes on every measure I could test as an admin.** I haven't tested anonymous or group-access rendering, and that's S3.

I used a throwaway module at `/spike13/book/{nid}` that renders the whole book in D7's section markup (`shanti-texts-section`, `id="shanti-texts-<nid>"`, `text-level-<depth>`). I used five books, including the two largest D7 texts.

**Content copy.** D7's text is in `field_book_content`, not `body`, which is why you saw nothing. I added that field to the D11 `book` bundle and copied the text over. Byte totals match D7 exactly. 419 of 445 pages have text, and the other 26 are empty in D7 too.

**Timing** (local DDEV, as admin, render cache on; peak memory measured separately and was low):

| D7 book | Pages | Size | Cold | Warm | Peak memory |
|---|---|---|---|---|---|
| 14679 (D11 123372) | 374 | 2.1 MB | 0.32 s | 0.08 s | 26 MB |
| 14158 (D11 123817) | 294 | 4.8 MB | 0.46 s | 0.18 s | 29 MB |
| 58791 (D11 123816) | 1 | 4.1 MB | 0.63 s | 0.15 s | 33 MB |
| 15641 (D11 123746) | 46 | 0.56 MB | 0.17 s | 0.04 s | 18 MB |

The targets were cold under about 10 s, warm about 1 s, within the PHP memory limit. All of them clear that comfortably. Local FPM allows 1 GB. I haven't found the production limit.

**Section order and ids match D7.** For 14679, 14158 and 15641, the section ids come out in the same order as D7's view (identical), with the same count.

**Order finding for TX3.** D7's whole-book view sorts by the creation-order path (`p1..p9`), not by sibling weight. Book's own tree order, which is weight then title, puts 14158's sections in a different order in one region. D11's `p`-order matches D7 exactly, so the renderer uses it by default, and `?order=outline` gives Book's order. TX3 should record that "match D7" means path order.

**Other findings**
- **15641 count:** D7's view drops unpublished pages, so 15641 renders 46 of its 52 outline pages. That matches D7.
- **The "4 MB page" is D7 58791.** It isn't in any of the four S1 books, and it's unpublished in D7, so D7 renders nothing for it. I published the local stand-in to get the timing. I also added 14158 because it's the largest book.
- **Anchors:** they use the D7 nid, looked up from the migrate map. Production should use `field_legacy_nid`, because D11 nids differ.

**Not covered**
- Cache invalidation, and anonymous or group access (S3). The non-admin `b.type` bug (finding 3, the TX7 fix) will hit there.
- Footnotes (S5).
- Production-hardware timing.

Everything is uncommitted on the throwaway branch. A copy of the module and migration is in `docs/spikes/assets/spike-13-texts/`, and I updated my memory notes.

Do you want S3 next (cache invalidation and a mixed-access tree), or the S2 results written into the spike doc first?

---

## User — 2026-11-04 19:10

What was S2 supposed to prove/validate?

---

## Claude — 2026-11-04 19:10

From the S2 row in the spike doc:

**What it tests:** whether D11 can render a whole book as one page, in outline order, with D7's section classes and anchors. That one page is the D7 "read the whole text" experience. The test cases are the 374-page book and the 4 MB page, cold and warm, with render cache on.

**Pass criteria**
- Cold render under about 10 s.
- Warm render about 1 s.
- Within the production PHP memory limit.
- Section order and ids match D7.

**If it failed:** pre-render on save (a queue worker with a cache tag per `bid`) or lazy sections. Either departs from D7 and would need an ADR.

**Where my run falls short of that**
- **Memory limit:** the criterion says the production PHP limit, and I never found it. I measured peak memory (18–33 MB) against the local 1 GB. It's very probably fine, but that criterion isn't strictly met.
- **Section ids:** I matched the order and the ids, but I left out D7's "Insert" controls link. That link is editor-only.
- **Markup:** I took the D7 template from the view's field-rewrite text. I didn't compare my output with a live D7 render.
- **Test conditions:** I ran as admin on a laptop. Production-like conditions, meaning anonymous users and real group access, belong to S3. The performance headroom is large enough that I doubt this changes the answer, but it wasn't tested.

I read only the S2 table row and the D7 view while doing this. I haven't re-read the spike doc's "Why this spike exists" section, so there may be context there about what the Texts plan needs from S2 that I'm not reflecting.

---

## User — 2026-11-04 19:10

Where can I see the results of S2 on the ddeve site?

---

## Claude — 2026-11-04 19:11

The whole-book renders are at `/spike13/book/<D11 nid>`. If you're still logged in from earlier, use the plain URLs below. If not, this one-time login link opens the 374-page book:

https://mandala.ddev.site/user/reset/1/1793819453/dIwQpujxzGW-CeMfELHrgpK104QwB6f8C3pRx_6jVsY/login?destination=/spike13/book/123372

| Book | URL |
|---|---|
| 374-page book (D7 14679) | https://mandala.ddev.site/spike13/book/123372 |
| 294-page, 4.8 MB book (D7 14158) | https://mandala.ddev.site/spike13/book/123817 |
| 4 MB single page (D7 58791) | https://mandala.ddev.site/spike13/book/123816 |
| "Overview", 46 published pages (D7 15641) | https://mandala.ddev.site/spike13/book/123746 |
| "test", 13 pages (D7 53126) | https://mandala.ddev.site/spike13/book/123748 |
| "Yoga", 5 pages (D7 53536) | https://mandala.ddev.site/spike13/book/123761 |

The Tibetan text is raw D7 HTML, with no footnote transform (S5) and no kmap popovers.

- **Order:** to see Book's weight-and-title order instead of the D7 path order, add `?order=outline` to a URL. For 14158 (123817), the sections in the diverging region visibly reorder.
- **Anchors:** the section ids use D7 nids, so `#shanti-texts-14679` jumps to that section. Each page has no Contents sidebar yet, which is S4.
- **Timing:** it renders inside the Olivero/Sarvaka page chrome, so you'll see the admin toolbar around the text. Use the browser's network tab to see the cold-versus-warm difference. Reload once for warm.

The timing numbers came from `curl` as admin, not from the browser.

---

## User — 2026-11-04 19:13

And what about showing the TOC on the page? Does that come later?

---

## Claude — 2026-11-04 19:13

Yes, the TOC is S4. In the spike doc it reads: "Build the Contents sidebar from the same tree. Pass: TOC link count equals page count; every `#shanti-texts-<nid>` anchor resolves."

S2 only covered the body column. In D7 the sidebar is a separate view, `single_text_toc`, with the "About" metadata and "Views" links next to it. Those come later or belong to other tasks.

S4 doesn't depend on anything S2 left unfinished. The anchors it needs are already in the S2 output.

If you want to see it sooner, a basic TOC is a small addition to the throwaway module. I'd build a nested list of links to `#shanti-texts-<d7nid>`, using the same path-ordered rows and `depth` I already fetch. I'd put it in a sidebar next to the text, as D7's embed layout does. That would let you click through and check that the anchors resolve on the 374-page book, and S4 would then be mostly measuring and recording.

Want me to add it now, or keep to the S2, S3, S4 order?

---

## User — 2026-11-04 19:13

And at this point we are not concerned with exact styling to match the origin yet, right?

---

## Claude — 2026-11-04 19:14

Right. None of S1–S6 is about visual styling. They test structure, behavior and performance: outline, render order and ids, cache and access, TOC and anchors, footnotes and alternative formats. The pass criteria are all of that kind (identical order, link counts, anchors resolving, timings).

Markup, though, does matter more than styling. I kept D7's section classes and ids (`shanti-texts-section`, `text-level-N`, `#shanti-texts-<nid>`). Existing links, the legacy `texts.js`, and any embeds that depend on those selectors would break if they changed. If a TOC is added it should keep the same anchor scheme, so the anchors resolve the way D7's do.

Visual matching (typography, Tibetan font handling, the Sarvaka theme treatment) belongs to Sprint 5 implementation, not this spike. I haven't checked how the sprint plan splits that up.

---

## User — 2026-11-04 19:15

And what does this mean "Order finding for TX3. D7's whole-book view sorts by the creation-order path (p1..p9), not by sibling weight. Book's own tree order, which is weight then title, puts 14158's sections in a different
  order in one region. D11's p-order matches D7 exactly, so the renderer uses it by default, and ?order=outline gives Book's order. TX3 should record that "match D7" means path order.
"

---

## Claude — 2026-11-04 19:15

In a D7 book, each page has two things that could decide its position among its siblings:

- **Weight:** a number editors set in the outline UI. It sorts siblings, with ties broken by title. This is what "Child Order" in the D11 book admin shows, and what Book's own tree builder uses.
- **Path (`p1..p9`):** a column set on each outline row that holds the ids of its ancestors, ending in its own id. Sorting by these columns orders siblings by their own row id, which mostly means the order they were created in. Weight plays no part.

D7's whole-book view (`single_text_body`) sorts by path. The D7 Book module's own navigation sorts by weight. When editors changed a weight without the pages' creation order matching, the two orders disagree.

**What I found.** In 14679 the two orders agree, so you wouldn't notice. In 14158, using Book's weight order moved five sections (14220, 14225, 14221, 14211, 14222, among others) to a different place than D7 renders them. Sorting by D11's path columns reproduces D7's view exactly.

**Why TX3 needs to record it.** The goal is to match D7 ("migrate, don't improve", ADR 008). A natural implementation would use Book's tree API, and it would silently reorder some texts relative to what readers see today. So "match D7" has to be stated as path order. The alternative is to decide deliberately that weight order is the correct reading order, and accept that some books change.

**Caveats**
- I verified that D11's path order equals D7's view order, on three books. I did not verify that the path order reflects creation order. That's my inference from how the path columns work.
- My attempt to pull the actual weights for those five pages returned nothing, so I haven't shown you that their weights disagree with their creation order. That is the likely explanation, but it's unconfirmed.
- I don't know which order D7's sidebar Contents list uses. If it follows weight and the body follows path, the D7 site is internally inconsistent for these books. That would matter for S4.

Want me to pull the weights and creation order for those pages to confirm the explanation?

---

## User — 2026-11-04 19:19

yes, pull the weights and creation order

---

## Claude — 2026-11-04 19:19

The earlier empty result was my mistake: D7 keeps `weight` on `menu_links`, not `book`, and my `grep` hid the error. I'm re-running with the right column.

---

## Claude — 2026-11-04 19:20

Confirmed, with a more specific cause than I gave you. Weight does not disagree with creation order here. It runs out of range, and the ties then get broken by two different rules.

**The parent behind the diverging region.** It is the section with `plid` 16149 in book 14158, and it has 49 child pages.
- All 49 children were created in the same second (2016-03-28 13:32:29), which looks like one batch import.
- Their weights run in sequence from -15 up, one per page, and top out at 16. The last 18 siblings all have weight 16. Examples are nid 14209 (རི་ནག་…) through 14216 (དགའ་ཚལ་…), which includes the pages that moved.
- Row ids (`mlid`) ascend in the same order as the weights, 16150 through 16189 and on.

**Why the two orders differ.** With 18 siblings tied at weight 16:
- Book's tree sorts ties by title, so those 18 end up in Tibetan alphabetical order.
- D7's whole-book view sorts by path, which is `mlid` order. That keeps the import sequence, which is presumably the intended reading order.

So Book's weight-then-title order is not wrong in general. It scrambles books where an importer filled the weight range and left the tail all tied.

**How far it reaches.** Across all 7,633 Texts pages, weights range from -56 to 53, so the range isn't capped everywhere. 190 pages sit at exactly 16, and 19 at 15. I only looked closely at book 14158, which has 18 tied siblings under that one parent. Other parents in the same book have 7, 3 and 0 pages at 16. I haven't checked how many other books have tie groups.

**Corrections to what I told you:**
- "Creation order" is accurate in the sense of `mlid` order, and for this book it equals import order. The node `created` timestamps are identical, so they can't be used to tell.
- I haven't yet found out which order D7's Contents sidebar uses. If it follows weight and title, D7 shows readers a different order in the sidebar than in the body for these books.

**For TX3.** I'd record two things. "Match D7" means path order for the body. Sibling weights can't be trusted as the sole order, because tied weights lose the original sequence. That affects the D11 outline UI too: if editors reorder using weights, ties will resolve alphabetically.

Do you want me to count how many books have tied-weight sibling groups, and check how D7's sidebar TOC orders them? That would settle whether this affects one book or many.

---

## User — 2026-11-04 19:24

yes, count the tied-weight books and check D7's sidebar TOC order

---

## Claude — 2026-11-04 19:24

The D7 sidebar TOC uses the same path sort as the body, so the two agree. I'm checking its depth and status filters, and fixing my parser, which choked on a title containing a line break.

---

## Claude — 2026-11-04 19:25

The tie explanation I gave you only covered a small part of the divergence. A larger group of books is affected, and for a different reason.

**D7's sidebar TOC** (`single_text_toc` in `shanti_texts_features`) uses the same path sort as the body view. So D7 is consistent with itself. The body and the Contents list always agree, and both ignore sibling weight.

The TOC has other details that S4 will want:
- It shows only published pages and only depth greater than 1, so the root isn't listed.
- It's a nested tree of `<a href="#shanti-texts-[nid]" id="shanti-texts-toc-[nid]">title</a>`.

**How many books differ.** I counted published pages, with 317 books having sibling groups. In 70 of them, Book's weight-then-title order is different from D7's path order.

| Cause | Books |
|---|---|
| Weights contradict creation order, meaning someone reordered pages with weights | 62 (68 parent sections) |
| Tied weights only, as in the 14158 case | 8 (12 parent sections) |
| Order matches (no difference) | the other 247 |

The earlier count of 24 books with any tied-weight group is a different number, since most tied groups don't change the order. Only 8 books diverge purely because of ties.

**What this changes.** 14158 is the tie case I showed you. The larger group of 62 books is the one that matters. In those books an editor changed sibling weights, and D7's body and TOC never reflected it. If those reorders were deliberate, D7 readers have been reading in creation order while the Book outline shows the intended order. If the reorders were incidental, path order is correct.

So the TX3 record needs more than "match D7 means path order". It should note that Book's outline UI and weights will disagree with the rendered order in 70 books, and that someone should decide which is authoritative. I can't answer that from the data. I'd flag it to Yuji and Xiaoming, and perhaps to whoever edits Texts content.

**Not checked:** how D7's own Book navigation (prev/next, "Child Order" in the D7 admin) orders these books. I also haven't checked whether the reorders look deliberate. A quick way to check is to look at one of the 62 books, for example 54836, which has 4 diverging parents, and see whether the weight order reads more sensibly than the path order.

I saved these numbers in my memory notes for this spike.

---

## User — 2026-11-04 19:49

Do the weight match the path? How does the D11 books module work? Does prioritize weight?

---

## Claude — 2026-11-04 19:49

**Do the weights match the path?** It depends on the book.
- **Migration fidelity:** S1 showed the weights and `p1..p9` rows migrate identically from D7 to D11. Nothing changed in migration.
- **Weight order versus path order:** in most books they agree. Of the 317 books with sibling groups, 247 have the same order either way. In 70 they differ, so for those the weight order doesn't match the path order. Of those, 62 are from editors reordering, and 8 are from tied weights only.

**How D11 Book works.** I read the code in `drupal/web/modules/contrib/book/src/BookManager.php`.
- **Path columns:** when a page is saved into an outline, `setParents()` fills `p1..p9` with the ancestor chain, and the entry at the page's own depth is its own `nid`. They're stored, and they're how a subtree is selected and how rows are ordered in the SQL. In D11 they hold nids. In D7 they held mlids, but the idea is the same.
- **Sibling order:** after loading and access-checking a tree, `doBookTreeCheckAccess()` sorts siblings with `ksort` on the key `(50000 + weight) . ' ' . title . ' ' . nid`. So yes, weight takes priority, then the localized title, then nid as a final tiebreak. This is the same rule as D7's Book module.
- **Setting:** `book_sort: weight` in `book.settings`. In the code I read, that setting only affects the list of books (`getAllBooks()`), not the order of pages inside a book.

**So, to your question:** D11 prioritizes weight for the outline tree. D7's body and TOC views ignore weight and use path order. My S2 renderer queries the `book` table directly and sorts by `p1..p9`, so it doesn't use Book's tree sorting at all.

One subtlety: in D11 the path columns hold nids, not creation-order row ids. I sorted by them anyway and matched D7. That works for these books because the D11 nids were assigned in the same sequence as the D7 mlids, since I migrated them in order. Path order on D11 nids reflects D11 creation order, not D7's. A production migration would need to preserve the sequence deliberately, or the order will drift. I haven't tested that, and it's worth adding to the TX3 record.

---

## User — 2026-11-04 19:55

But how does the book UI for ordering sections work in D11? Isn't it by weight? Shouldn't the weight agree with the path for it to work properly?

---

## Claude — 2026-11-04 19:55

You're right that the Book UI works by weight. Your question also exposes a possible gap in my D7 claim: I assumed D7's view sorts by raw `p1..p9`, but the view has a "sort within level" option I never read. I'm checking what it does.

---

## Claude — 2026-11-04 19:57

You were right, and checking your question showed that my "order finding" was wrong. I'm retracting it.

**The Book UI.** The ordering UI is by weight. Dragging sections sets each page's weight, and siblings are displayed by weight, then title. The path columns (`p1..p9`) aren't an ordering at all. They only record ancestry, so they change when a page is re-parented, not when it's reordered. Weight doesn't need to agree with the path, and they're not meant to.

**What I got wrong.** I assumed D7's whole-book view sorted by raw `p1..p9`. The view has "sort within level" switched on, and that Views option sorts each level by weight, then title. It uses the path only as a final tiebreak. I never ran D7's view. I just computed my own ordering from the path columns and called it D7's. So I was comparing Book to something D7 doesn't do.

**What the correct comparison shows**
- Book's tree order (weight, then title) matches D7's real view order in 4 of the 5 books: 14679, 15641, 53126 and 53536.
- Raw path order doesn't match D7 in 14158 or 15641, so my default was the wrong one.
- 14158 still has one page out of place: 14209 sits two positions off. It's within the group of siblings tied at weight 16. D7 breaks ties using MySQL's title collation, and Book uses PHP's byte order. Only one page in one book, but it's a real edge for TX3.
- The earlier "70 books diverge / 62 were reordered by editors" counts are discarded. They compared Book to raw path, not to D7.

**What I changed.** The renderer now defaults to Book's tree order, and `?order=path` gives the raw-path variant. With that, the differing lines versus D7 are 0 for 14679 and 15641 and 2 for 14158 (the one page). Timing is still comfortable: warm 0.04–0.19 s, and peak memory up to 34 MB.

**For TX3.** The conclusion is simpler. Use Book's own tree order. D7 parity holds, apart from the title-collation edge in tied groups. The TX3 note about path order and editor reorders no longer applies. I've recorded the correction in my memory notes so it supersedes the earlier ones.

The earlier timings in my summary were for path order. These new ones are for the order we'll actually use. The browser links I gave you now serve Book order too.

---

## User — 2026-11-04 20:00

So when we do the migration, we want to do it in a way that the books in d11 have sections whose weights and paths match their proper order so that they are consistent with D11 book modules requirements.

---

## Claude — 2026-11-04 20:00

Partly. The goal is right, but the migration probably needs less than that.

**Weights.** The migration already carries D7's weights over unchanged. S1 verified `weight` identical for all 444 rows. Book's tree order matched D7's real order in 4 of the 5 books. So in most cases we don't need to rewrite weights. They're consistent with D11 Book because Book's rule, weight then title, is the same rule D7 used.

**Paths.** There's no ordering requirement on them. They only have to describe ancestry (each page's chain of parents), and Book builds them itself when each outline row is saved (`setParents()`). The migration doesn't set them directly, so the outline migration produces consistent paths by construction. They don't need to "match" the weight order. That was my mistake earlier, and I'd avoid carrying it into the TX3 wording.

**The one thing that might need work.** That's the ties. When siblings share a weight, D7 breaks the tie with MySQL's title collation and D11 uses PHP's byte order. In 14158, one page ended up two positions away from where D7 puts it. Two ways to handle it:
1. **Leave it.** Faithful to the D7 data, matches ADR 008 ("migrate, don't improve"), and a handful of pages may shift slightly within tied groups.
2. **Normalize ties in the migration.** For any sibling group with tied weights, compute D7's real order (weight, collated title, path) and assign strictly increasing weights that reproduce it. Rendering then matches D7 exactly, and editors in D11 get a clean, unambiguous Child Order. The cost is that weights in those groups differ from D7's stored values, which is a deliberate and documented deviation.

I lean toward option 2, because tied weights are fragile in D11. Any later edit, a collation change or a title edit could silently reshuffle a tied group. But it's a decision for you, Yuji and Xiaoming, not me.

**Before deciding, we need the real scale.** I only know that 24 books have tied-weight sibling groups (36 parent sections). I haven't counted how many of them actually order differently under D11's rule versus D7's collation. I can simulate that across all 7,633 pages. It would give TX3 a concrete number: either "N pages in M books would shift" or "essentially none". Want me to run it?

---

## User — 2026-11-04 20:00

yes, run the tie simulation

---

## Claude — 2026-11-04 20:02

The simulation shows the effect is small: 5 sibling groups in 4 books out of the 317 books with sibling groups.

I compared D7's ordering to D11's for every published page's sibling group in the 7,633-page Texts corpus. D7's order is weight, then title in MySQL collation, then row id, and I computed it in SQL. D11's order is `ksort` on the key `(50000 + weight) title nid`, the rule from `BookManager.php`, which I reproduced in Python.

| Measure | Result |
|---|---|
| Sibling groups (parents with 2+ published children) | 980 |
| Groups with tied weights | 36, in 24 books |
| Groups where D7 order differs from D11's | **5, in 4 books** |
| Positions that differ in those groups | 20 |

The five groups are in books 13283, 13871 (two groups), 14158 and 15582. They range from 4 to 62 siblings, so most siblings in each group stay in place and only some pages shift.

**Sanity check.** The simulation flagged the 14158 group under parent 16149, which is the one I'd already found by hand (page 14209). I didn't separately inject a fault to prove the comparison can fail, but it did separate the books that agree from the ones that don't.

**Caveats**
- The D11 side is my Python reimplementation of the sort key. I used the mlid as the last tiebreak because the real D11 nids don't exist for the full corpus yet. It could differ from PHP in rare cases, and it should be re-checked against a real full migration.
- D7's order comes from this local copy of the D7 database. If production's collation differs, the result could differ.
- I used node titles for D11 and menu link titles for D7, since those are what each system sorts on. If they've drifted apart in some books, that would add differences I haven't looked at.
- It covers published pages only, which matches what readers see.

**For TX3.** With 20 position differences in 4 books out of 317, option 1 (leave ties alone) is a defensible default. The cost of option 2 (normalizing weights in the tied groups of those 24 books) is also small, because it only touches 36 groups. The case for it is that the order would no longer depend on a collation quirk. That's a judgment call for the team, and I'd put the numbers in front of them.

I haven't written any of this into the spike doc. I can add the S2 results and this ordering analysis together, which would also cover the retraction. Do you want that?

---

## User — 2026-11-05 12:21

write it all up. Document, memory, and session log. Commit and push. Going to end session.

---
