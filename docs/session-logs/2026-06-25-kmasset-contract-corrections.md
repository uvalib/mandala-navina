# Session Log: Kmasset Contract Corrections

**Date:** 2026-06-25  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** Yuji caught two factual errors in the 1a.8 [KMasset Solr Doc Contract](../planning/kmasset-solr-doc-contract.md) from the prior session. Both confirmed against source and corrected: (1) **kmassets docs are FLAT, not nested block-join** — the parent/child structure belongs to the `kmterms` index (verified in [Spike 2](../spikes/spike-02-solr-integration.md) and in mandala-om `useKmap.js`, where the `[child]` transformer targets `solr_urls.terms` while asset reads hit `solr_urls.assets` flat); an image kmasset has no child documents. (2) **`mediabase` is the A/V producer, not Images** (`package = Mandala Audio-Video`); `shanti_images` is the Images producer. Fixed §3–§7 of the contract doc (commit `75f0a33`), added a correction banner to the [2026-06-24 session log](2026-06-24-kmasset-solr-doc-contract.md) (`8539fce`), and corrected the persistent memory carrying the same errors.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-25 14:52

okay.   We have two questions about your understanding of the overall project structure:  First question: What is your understanding about the solr document structure of kmassets and kmterms.  You talk about child documents, but kmassets should have none.  If we are talking about images, then no child documents should be involved.  correct?

---

## Claude — 2026-06-25 14:52

Good question to nail down before it propagates through the contract docs. Let me verify against what's actually in the repo rather than answer from memory.

---

## Claude — 2026-06-25 14:52

Let me find where "child documents" actually appears in the docs and the relevant Solr source-of-truth context.

---

## Claude — 2026-06-25 14:52

This is exactly the kind of inconsistency worth catching — there's a genuine contradiction between two generations of the docs. Let me read the precise passages.

---

## Claude — 2026-06-25 14:52

The picture is now clear, and the user is right. Let me confirm one more thing — which index the React `[child]` transformer actually hits, since that's the crux of the conflation.

---

## Claude — 2026-06-25 14:53

Confirmed in code. You're right, and I can now show exactly where the recent doc went wrong.

## Short answer: yes, you're correct

**kmasset documents are flat. An image kmasset has no child documents.** The nested block-join structure (`block_type:parent` / `_childDocuments_` / `block_child_type`) lives in the **`kmterms`** index, not `kmassets`.

## The evidence (two independent confirmations)

**1. Empirical — Spike 2, tested against live Solr** (`docs/spikes/spike-02-solr-integration.md:99`):
> `kmassets` documents are flat. The `block_type` / `block_child_type` nested document pattern does NOT exist in kmassets — querying `block_type` returns "undefined field."
>
> The nested document (block-join) pattern exists in the **`kmterms`** index, which is separate.

With hard counts: kmterms has 472,478 `parent` docs + 3,989,633 `child` docs. kmassets has none of that machinery.

**2. The React app itself separates the two cores** — `mandala-om/.../hooks/useKmap.js`:
- `[child parentFilter=block_type:parent …]` transformer → `url: solr_urls.terms` (lines 21, 35) — the **kmterms** core
- asset lookups → `url: solr_urls.assets` (lines 49, 63) — the **kmassets** core, **no `[child]` transformer**

So the app never asks kmassets for child docs. It only does block-join retrieval against kmterms.

## Where my 1a.8 doc got it wrong

The golden doc committed yesterday (`docs/planning/kmasset-solr-doc-contract.md` §5, and the session log) overstated this. It traced `kmaps_engine`'s `Feature#document_for_rsolr` / `nested_documents_for_rsolr` — which is the **kmterms producer** (Andres' Rails engine) — and the `useKmap.js:24` `[child]` transformer, then concluded:

> "Any D11 writer must reproduce this parent/child shape, not a flat field set."

That's a **conflation error**. `useKmap.js:24` is the `terms` URL, not assets. The nested grammar I described is real, but it's the **kmterms** contract, not the **kmasset** contract. For a kmasset writer — and specifically for images — the contract is **flat docs**, full stop.

The one subtlety worth keeping straight: reindeer_x (ADR 006) shadows kmterms *into* the kmassets index as `subjects`/`places`/`terms` shadow asset types. But per Spike 2 those shadow entries are **flattened projections** (flat `kmapid`/`title`/`names_txt` docs) — not the nested block-join structure. So even the taxonomy shadows in kmassets are flat. Nothing an image writer produces involves child documents.

---

This is a real defect in a committed contract doc. Want me to write the correction into `docs/planning/kmasset-solr-doc-contract.md` §5 (re-scoping the nested grammar to kmterms and stating the kmasset/image writer contract is flat)? I'd note it as a correction rather than silently editing, since the doc is already in git.

I'll hold on your second question until you ask it.

---

## User — 2026-06-25 14:56

Good to update the documents to clarify the distinction.   The second question is about your understanding of mediabase.   Mediabase should be for A/V only (audio-video).  But you mention it in the discussion about images.

---

## Claude — 2026-06-25 14:56

Let me verify the mediabase question against the legacy code and find where I mis-attributed it to images.

---

## Claude — 2026-06-25 14:56

Confirmed — you're right on both counts. The module metadata is explicit:

- `mediabase.info` → `package = Mandala Audio-Video`, name "Mediabase Core"
- `sarvaka_mediabase.info` → `body_tag = 'audio-video'`, `icon_class = 'audio-video'`
- Spike 1 log even pairs it correctly: `Audio/Video → Kaltura integration + mediabase content types`

So **mediabase is the A/V media manager**, not an images producer. The images producer is `shanti_images`. I incorrectly lumped `mediabase` into the Images-producer list in the 1a.8 doc (lines 137, 226) and the session log. Let me verify `shanti_images` is the images side, then fix both issues together.

---

## Claude — 2026-06-25 14:56

Both confirmed:
- `shanti_images` → `package = Images` ("Shanti IIIF Images") — the **images** producer
- `mediabase` → `package = Mandala Audio-Video`, with `features/audio_video/` + `mb_kaltura/` — the **A/V** producer (the "image" grep hits are video poster/thumbnail handling, not image-asset production)

Now let me make the corrections. Reading the relevant sections first.

---

## Claude — 2026-06-25 14:57

All four attributions verified. Now I'll correct the planning doc — both the flat/nested distinction and the mediabase mis-attribution. I'll mark them as dated corrections since the doc is already committed.

---

## Claude — 2026-06-25 14:58

Now the core §5 rewrite:

---

## Claude — 2026-06-25 14:58

Now the §6 schema note and the §7 source line:

---

## Claude — 2026-06-25 14:59

Let me check the rest of the doc for any remaining nested-kmasset or mediabase-as-images claims I haven't caught:

---

## Claude — 2026-06-25 14:59

Line 104 still says option C requires Drupal to emit "the exact nested format" — also wrong now. Let me read and fix it.

---

## Claude — 2026-06-25 14:59

Done. Let me verify there are no new files needing a `.pages` update (none added — only edits), and check whether the existing memory should record these two durable corrections.

---

## Claude — 2026-06-25 14:59

The memory itself carries the error (line 23: "nested block-join parent/child docs" attributed to kmassets). Let me correct it so I don't repeat the mistake next session.

---

## Claude — 2026-06-25 15:00

Both questions confirmed in your favor, and the docs + memory are corrected.

## Q2 — mediabase: you're right, it's A/V only

The D7 module metadata is unambiguous:
- `mediabase.info` → `package = Mandala Audio-Video`, "Mediabase Core", with `features/audio_video/` and `mb_kaltura/` (Kaltura) — it's the **audio-video** media manager.
- `shanti_images.info` → `package = Images`, "Shanti IIIF Images" — that's the **images** producer.
- `shanti_kmaps_solr` → a shared KMaps Solr jQuery plugin wrapper (search-side helper), not type-specific.

I had incorrectly lumped `mediabase` into the Images-producer list. For the 1a.8 Images media manager, the source to mine is `shanti_images` (+ shared `shanti_kmaps_solr`); `mediabase` is a separate, later A/V task.

## What I changed in `docs/planning/kmasset-solr-doc-contract.md`

1. **§5 rewritten** (with a dated correction note) — separates the two structures: `kmterms` is nested block-join; `kmassets` is **flat**, including images and the flattened taxonomy shadows. Removed the wrong "any D11 writer must reproduce this parent/child shape" claim. The crux: `useKmap.js:24`'s `[child]` transformer hits `solr_urls.terms`, while asset reads (`useKmap.js:49,63`) hit `solr_urls.assets` with no child transformer.
2. **§4 chain + table** — re-scoped `kmaps_engine` to "upstream — kmterms"; fixed the producer row to `shanti_images` (Images) / `mediabase` (A/V only) / `shanti_kmaps_solr`; clarified mandala-om reads assets flat.
3. **§3 option C** — "exact nested format" → "exact flat doc format."
4. **§6** — noted `_root_`/`parent_uid` exist in schema but are unused in the live kmassets index.
5. **§7** — `mediabase` flagged as A/V, not an Images source.

I also corrected the memory file, which had carried the same "nested kmassets" error into future sessions.

## One thing I did *not* touch

The **2026-06-24 session log** (`docs/session-logs/2026-06-24-…`) still contains the original erroneous "big structural finding: kmasset docs are nested." Session logs are historical records of what happened, so I left it as-is rather than rewriting history. If you'd prefer, I can add a one-line correction pointer at the top of that log linking to the corrected §5. Want me to?

These are working-tree edits — not committed. Say the word and I'll commit them on this `docs/kmasset-solr-doc-contract` branch.

---

## User — 2026-06-25 15:00

yes commit them

---

## Claude — 2026-06-25 15:01

Committed to `docs/kmasset-solr-doc-contract` as `75f0a33` (58 insertions, 30 deletions in the one doc).

The memory-file corrections aren't part of this — they live outside the repo and persist automatically.

Two follow-ups still open if you want them:
- The **2026-06-24 session log** still records the original "nested kmassets" finding (left intact as history) — I can add a correction pointer to it.
- Nothing is **pushed** yet — say the word and I'll `git push`.

---

## User — 2026-06-25 15:02

Amend the session log to note that there is an update.   We will record the updates in this session.

---

## Claude — 2026-06-25 15:02

I'll add a correction banner right after the outcome summary, pointing to this session's corrections.

---

## Claude — 2026-06-25 15:03

Committed as `8539fce`. The banner sits right after the outcome summary, flags both corrections, points to the corrected contract doc (`75f0a33`), and leaves the original reasoning intact below it as the session's historical record. It references "the 2026-06-25 session log for the correction discussion" — which is this session, to be captured when you save it (`scripts/save-session-log.py`).

Two commits now on `docs/kmasset-solr-doc-contract`, still unpushed:
- `75f0a33` — contract doc corrections
- `8539fce` — session-log correction banner

Let me know when you want them pushed, and whether you'd like me to run the session-log save for this session now.

---

## User — 2026-06-25 15:04

push it and save the session log

---

## Claude — 2026-06-25 15:04

Pushed. Now let me find this session's JSONL and run the save script.

---
