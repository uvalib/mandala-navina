# Session Log: Kmasset Contract Corrections

**Date:** 2026-06-25  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** Yuji caught two factual errors in the 1a.8 [KMasset Solr Doc Contract](../planning/kmasset-solr-doc-contract.md) from the prior session. Both confirmed against source and corrected: (1) **kmassets docs are FLAT, not nested block-join** — the parent/child structure belongs to the `kmterms` index (verified in [Spike 2](../spikes/spike-02-solr-integration.md) and in mandala-om `useKmap.js`, where the `[child]` transformer targets `solr_urls.terms` while asset reads hit `solr_urls.assets` flat); an image kmasset has no child documents. (2) **`mediabase` is the A/V producer, not Images** (`package = Mandala Audio-Video`); `shanti_images` is the Images producer. Fixed §3–§7 of the contract doc (commit `75f0a33`), added a correction banner to the [2026-06-24 session log](2026-06-24-kmasset-solr-doc-contract.md) (`8539fce`), and corrected the persistent memory carrying the same errors. Then traced **Tibetan tokenization** in the deployed kmassets schema and recorded it (no change — ADR 004) by augmenting [tibetan-search-quality.md](../deferred/tibetan-search-quality.md) (`2bd507e`): two field families (`*_tibt`=keyword/not-tokenized vs `*_bo`=ICU), the **BDRC `TibSyllableTokenizerFactory` is present but commented out** (generic ICU fallback live), and the `mapping-Tibetan.txt` shad→tsek rule. Explicitly out of scope to change; not scheduled.

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

## Claude — 2026-06-25 15:04

The current session is `c2229eac…` (most recent, modified today). Running the save script:

---

## Claude — 2026-06-25 15:04

Let me set the Outcome summary line as the script suggests.

---

## Claude — 2026-06-25 15:04

`docs/session-logs/.pages` self-updates (uses `...`), so no `.pages` edit needed. Committing and pushing.

---

## Claude — 2026-06-25 15:04

Done — all pushed to `docs/kmasset-solr-doc-contract`.

Three commits this session:
- `75f0a33` — contract doc corrections (kmassets flat; mediabase A/V)
- `8539fce` — correction banner on the 2026-06-24 session log
- `02d410f` — new 2026-06-25 session log with the Outcome summary linking to the contract doc, Spike 2, and the prior log

The branch is up to date with origin. The session log's `.pages` self-updates via `...`, so nothing further to flush. Memory was already corrected (outside the repo).

---

## User — 2026-06-25 15:06

okay.  we have a new solr-related topic to address:  Do you understand how the tibetan tokenization works in the solr index now?

---

## Claude — 2026-06-25 15:06

Let me look at the actual schema rather than answer from memory. The Tibetan field types (`text_bo`, `*_tibt`, `*_bo`) are defined in the kmassets schema.xml — let me find their analyzer chains.

---

## Claude — 2026-06-25 15:06

The deployed index is `solr7.3.x/production/kmassets`. Let me extract the Tibetan-related fieldType analyzer chains from it.

---

## Claude — 2026-06-25 15:07

Important signal already: line 791 shows the BDRC `TibSyllableTokenizerFactory` is **commented out**. Let me read the full `text_bo` definition and the surrounding context.

---

## Claude — 2026-06-25 15:07

Let me check the `text_kw` type (used by the OLD `*_tibt` fields), the Tibetan copy-field section, and the mapping file.

---

## Claude — 2026-06-25 15:07

Let me check whether the newer solr9 configset (a likely migration target) changes the Tibetan handling — relevant to "now" vs. planned.

---

## Claude — 2026-06-25 15:08

Yes — I now understand it from the actual schema (`solr7.3.x/production/kmassets/conf/schema.xml`, and it's byte-identical in the solr9 configset, so nothing changes on that migration path). Here's the real picture, and it has a notable surprise.

## There are two parallel Tibetan field families, and they tokenize *differently*

**Legacy — `*_tibt` → `text_kw`** (schema line 148, type at 1208):
```
MappingCharFilter (mapping-Tibetan.txt)
KeywordTokenizerFactory          ← whole value = ONE token
ASCIIFoldingFilter (preserveOriginal)
LowerCaseFilter
```
`KeywordTokenizer` means **`*_tibt` fields are not tokenized at all** — the entire field value becomes a single token. So matching is effectively whole-value / prefix only. This is exactly the behavior Spike 2 flagged: `names_txt` and `title` are `text_kw`, which is why consumers must use prefix wildcards (`title:Buddhism*`). The ASCII-folding step is there for transliterated/Wylie Latin content.

**Newer — `*_bo` → `text_bo`** (schema line 152, type at 788):
```
MappingCharFilter (mapping-Tibetan.txt)
<!-- io.bdrc.lucene.bo.TibSyllableTokenizerFactory  ← COMMENTED OUT -->
ICUTokenizerFactory              ← the active tokenizer
```
`*_bo` fields **are** tokenized — into Unicode syllable-ish units. ICU's break iterator segments Tibetan at the tsek (་) boundaries (UAX-29, script-aware). No lowercase/stemming/stopwords (none apply to Tibetan script).

## The surprise: the purpose-built Tibetan tokenizer is disabled

Line 791 — `io.bdrc.lucene.bo.TibSyllableTokenizerFactory` (BDRC's `lucene-bo` library, proper Tibetan syllable/word segmentation) is **commented out**, with the generic `ICUTokenizerFactory` as the live fallback. So "now," Tibetan word segmentation is **approximate** (generic Unicode rules), not the linguistically-aware BDRC segmentation. Re-enabling it would require the `lucene-bo` jar on the Solr classpath. This is identical in both the 7.3.x and 9.x configsets — so it's a standing, unresolved choice, not a migration artifact.

## The shared normalization: `mapping-Tibetan.txt`

The charFilter is on **both** types, and the whole file is one rule:
```
"\u0F0D" => "\u0F0B"     # SHAD => TSEK
```
It folds the **shad** (`།`, phrase/sentence terminator, U+0F0D) into a **tsek** (`་`, the syllable dot, U+0F0B). That normalizes terminal punctuation to a syllable separator so segmentation is uniform whether or not the input ended in a shad. Nothing else is normalized here (no NFC, no other punctuation).

## Copy-field aggregation mixes the two

`titles_bo` (a `text_bo` field) collects from **both** families — `title_bo`, `title_alt_bo`, `caption_bo` (already `*_bo`) *and* `title_alt_tibt`, `title_corpus_tibt`, `title_short_tibt` (legacy `*_tibt`). So values authored as keyword-style `*_tibt` get re-analyzed through ICU tokenization once they land in the `*_bo` aggregate. The two families are not cleanly separated at the aggregate layer.

## What this means for 1a.8 (the D11 writer)

- All of this analysis is **server-side schema.xml** — deployed via configset + Terraform/Ansible, not via Drupal/`search_api_solr`. The D11 writer just writes `*_bo` / `*_tibt` field *values*; the index does the tokenization. So the writer's job is **fidelity**: emit NFC-correct Tibetan into the right suffix fields so the analyzers see clean input (the Sprint 1 transliteration-fidelity criterion).
- The writer must know **which suffix to use** per field — `*_bo` (tokenized, searchable) vs `*_tibt` (keyword/exact). Getting that wrong silently changes search behavior, same class of bug as the `language_field` and string-field pitfalls already in the memory.

---

Two things worth a decision/capture if you want to act on this:
1. **The disabled BDRC tokenizer** — is ICU-only acceptable for MVP, or do we want proper Tibetan segmentation (jar on classpath)? Looks like a deferred/unstated decision.
2. **The `*_tibt` vs `*_bo` split** — is the legacy keyword family being retired, or must the D11 writer keep populating both?

Want me to write this up — e.g. a `docs/deferred/` note on the BDRC-tokenizer decision, and/or fold a "Tibetan tokenization" subsection into the contract doc's §6? Or is this just orientation for now?

---

## User — 2026-06-25 15:13

Well, we don't want to get too deep into changing the current solr schema or content in this effort, but notating it for later is apt.

---

## Claude — 2026-06-25 15:13

Right — capture, don't touch. A `docs/deferred/` note is the correct home. Let me check the conventions there first.

---

## Claude — 2026-06-25 15:13

There's already a `tibetan-search-quality.md`. Let me read it — my findings may belong there rather than in a new file.

---

## Claude — 2026-06-25 15:14

This is the right home — item #3 already covers Tibetan tokenization, but imprecisely ("the existing index does not apply Tibetan-aware tokenization"). My schema dig sharpens it with the actual mechanism. I'll augment this note rather than create a duplicate. No README/`.pages` change needed since the file already exists.

---

## Claude — 2026-06-25 15:14

Committing. The file already exists in the README table and `.pages`, so no index changes are needed.

---

## Claude — 2026-06-25 15:14

Committed and pushed (`2bd507e`).

I folded the findings into the existing `docs/deferred/tibetan-search-quality.md` rather than spawning a duplicate note — it already owned "Tibetan tokenization" as item #3, which was directionally right but vague. The new "How tokenization actually works today (schema-grounded, 2026-06-25)" section records the precise mechanism:

- **Two families**: `*_tibt` → `text_kw` (KeywordTokenizer = not tokenized, whole-value/prefix only — same as `title`/`names_txt`) vs. `*_bo` → `text_bo` (ICU-tokenized).
- **The headline**: BDRC's `TibSyllableTokenizerFactory` is present-but-commented-out; generic ICU is the live fallback.
- `mapping-Tibetan.txt` = one rule, shad→tsek.
- `titles_bo` mixes both families.
- Two latent decisions parked for the eventual spike (enable BDRC? retire `*_tibt`?).

Everything is framed as capture-only under ADR 004, with the explicit note that the writer's obligation is fidelity + correct suffix choice — no schema change proposed. The branch now has five commits; nothing else outstanding on this topic.

---

## User — 2026-06-25 15:18

Please record this, but don't schedule it, its out of scope

---

## Claude — 2026-06-25 15:18

Recorded to persistent memory as `project-tibetan-tokenization.md`, with an index line added to `MEMORY.md`. It captures the durable, non-obvious facts (the two field families, the commented-out BDRC tokenizer, the shad→tsek rule, and the 1a.8 fidelity-only obligation) and cross-links the search-pitfalls and Solr-topology memories.

Nothing scheduled — it's explicitly marked out of scope (ADR 004, migrate-not-improve), with the BDRC-enable and `*_tibt`-retirement questions parked as deferred spike work, not this effort.

---

## User — 2026-06-25 15:19

Can you amend the last session log?  or should we just add nother one?

---
