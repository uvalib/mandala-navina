# Session Log: Kmasset Contract Images Inventory

**Date:** 2026-06-25  
**Participants:** Yuji Shinozaki, Claude Opus 4.8  
**Outcome:** Completed **Phase 1** of the 1a.8 [KMasset Solr Doc Contract](../planning/kmasset-solr-doc-contract.md) — the Images field inventory (§7), extracted from the D7 producer chain and reconciled against **live kmassets docs sampled via the proxy on VPN**. Key results: the Images doc is built by a **3-module alter chain** (`shanti_kmaps_fields` base + `shanti_collections` access-path fields, the 1a.8↔1b seam, + `shanti_images`), not one builder; images carry **no `*_tibt`/`*_bo` or `names_txt`** fields and encode language via `*_lang_s` companions (resolving the §8/§10 Q3 open question for Images); fields are largely **dynamic-field realized** (suffix is load-bearing; suffix-matching fields like `rotation_s` need no schema deploy); and §7.2-D **confirmed against `schema.xml`** (prod == staging, byte-identical) — `ids`/`str`/`titles`/`names`/`creators`/`descriptions` are `copyField` aggregations the writer must not emit. Added 3 golden JSON fixtures as the writer's acceptance check, and a deferred note for the never-emitted-but-wanted `rotation_s` (D7 `$sdog` typo). Committed + pushed as `597db2b`.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-25 15:22

OK.  What should we implement next in the current spike?

---

## Claude — 2026-06-25 15:22

Let me orient myself per the session-startup ritual before recommending anything.

---

## Claude — 2026-06-25 15:22

The branch and recent commits are all about the kmasset Solr doc contract. Let me see what's actively in flight — the latest session log and the most relevant spike docs.

---

## Claude — 2026-06-25 15:22

Let me read the two most recent session logs to understand exactly where we left off and what was teed up next.

---

## Claude — 2026-06-25 15:22

I have full context now. Let me check the current state of the contract doc's §7 (the part explicitly marked as the next-step TODO).

---

## Claude — 2026-06-25 15:23

Got it — Sprint 1, not Spike. That actually makes the answer cleaner, because everything from the last two sessions was groundwork for one specific sprint task.

## Where Sprint 1 stands

Step 1a (Images pilot, public plumbing) is essentially done — 1a.1–1a.7 all landed on `main`. **Two tasks remain open:**

- **1a.8** — Solr write/sync (image docs land in `kmassets` and are retrievable)
- **1a.9** — rollback story (staging prod-DB run → validate → rollback)

1a.8 is the one you've been actively de-risking. The last two sessions produced the **foundation** half of it: `docs/planning/kmasset-solr-doc-contract.md` — topology (settled), write transport (open A/B/C), the source chain, the generative grammar, and the schema type layer. And you corrected the two factual errors (kmassets are flat; mediabase is A/V).

## What to implement next: Phase 1 of the golden doc

The contract doc's **§7 (Images field inventory) is still marked "Phase 1 TODO."** That's the literal next step, and it's the highest-value one because it converts the contract from *understanding* into a *testable spec* — which is what makes the eventual A/B/C transport choice low-stakes.

Concretely, Phase 1 is:

1. **Open the D7 producer** — `shanti_images` (Images builder) + shared `shanti_kmaps_solr`, find the function that assembles the JSON written to `solrdocs/`, and extract the Images field-by-field derivation. (Not `mediabase` — that's A/V, per the correction.)
2. **Reconcile** against the three other authorities already mapped: the 7.x `kmassets` schema (types), mandala-om (consumer reads), and reindeer_x `kmassetSync` (shared core) — flagging the known open items (`caption_eng` vs `*_en`; the access fields `node_user`/`collection_uid_path_ss` that are the 1a.8↔1b seam).
3. **Capture a live kmassets Images sample** as a golden fixture (needs you on VPN, or a `! curl` dump — flagged as the one thing requiring you).
4. **Write §7 + commit the fixture** as the acceptance test for the D11 writer.

This is **flat-doc, Images-only** work now — no block-join, no mediabase — so it's narrower than the foundation sessions implied.

The thing I genuinely need from you before going deep is the live-index reachability question — otherwise I build §7 from the D7 builder + schema (the *defining* authority anyway) and treat live-sampling as a later verification pass.

Want me to kick off Phase 1 now (open the `shanti_images` doc-builder and draft §7), and how do you want to handle the live sample — VPN, a curl dump, or defer it?

---

## User — 2026-06-25 15:24

I'm on VPN, so live queries would be great.

---

## Claude — 2026-06-25 15:25

Let me confirm reachability with a small query and simultaneously locate the D7 `shanti_images` doc-builder.

---

## Claude — 2026-06-25 15:25

Live queries work — that's a real Images doc from the index. Let me pull the complete document and locate the D7 builder code.

---

## Claude — 2026-06-25 15:26

61 fields in the live doc. Now let me find where that doc gets built in D7 — the `pogrified_s: "fields_module"` hint suggests a shared builder rather than `shanti_images` itself.

---

## Claude — 2026-06-25 15:26

The `pogrified_s`/`mogrified_s` fields are distinctive — let me trace them straight to the builder, and check `shanti_images.module` for the doc assembly.

---

## Claude — 2026-06-25 15:27

Key architectural find: the doc builder lives in **`shanti_kmaps_fields`** (the pogrify/mogrify source), and `shanti_images` only *alters* the base doc via `hook_kmaps_fields_solr_doc_alter()`. That's the same module name that exists in D11 today — but D11 only has its read side. Let me read both halves.

---

## Claude — 2026-06-25 15:27

`_shanti_kmaps_fields_get_solr_doc()` at line 1039 is the core builder. Let me read it in full, plus the asset-type definition and the image alter hook.

---

## Claude — 2026-06-25 15:27

This is the generative core — flat doc, site-configured asset_type/URLs, extra-fields mapping, then `drupal_alter('kmaps_fields_solr_doc')`. Now the Images-specific layer and the extra-fields definition.

---

## Claude — 2026-06-25 15:27

I now have the complete producer picture: the flat base builder + the Images alter hook. Let me pull a few representative live fixtures — multi-description and Tibetan/diacritic docs — to lock the contract's edge cases.

---

## Claude — 2026-06-25 15:28

The `caption_lang_s` facet reveals a real data-quality landmine: language is stored inconsistently — both `en` and `English`, both `Tibetan` and `Standard Tibetan`. That's the `field_language->header` vs default-`en` split from the builder code. Let me confirm whether Images docs carry any Tibetan-script (`*_tibt`/`*_bo`) fields at all, then read the §7 placeholder to match the doc's style.

---

## Claude — 2026-06-25 15:28

Decisive finding: **Images docs carry no Tibetan-script (`*_tibt`/`*_bo`) fields at all — and not even `names_txt`.** So the Images writer emits plain Unicode `title`/`caption`/`summary`/`description` + `_lang_s` companions; NFC fidelity applies to those, not to script-suffixed fields. `asset_subtype` is real and meaningful (mostly `photograph`). Let me read the current §7 placeholder and surrounding sections to match style before writing.

---

## Claude — 2026-06-25 15:29

The live doc has collection access-path fields (`collection_uid_path_ss`, `collection_uid_s`, `collection_nid_path_is`) that the base builder does *not* write — so a third alter hook populates them. Given `shanti_collections` was the other pogrify/mogrify source, let me confirm it owns the §9 access-control fields.

---

## Claude — 2026-06-25 15:30

Confirmed — `shanti_collections` is a **third** producer in the alter chain, and it owns exactly the §9 access-control fields. I now have the complete, evidence-grounded picture. Let me write §7.

---

## Claude — 2026-06-25 15:39

Now let me reconcile the open questions in §8/§10 with what Phase 1 resolved, and save the fixtures into the repo so they're not stranded in scratchpad.

---

## Claude — 2026-06-25 15:41

Let me give the fixtures a home in the repo so they're not stranded, and point §7.4 at it.

---

## Claude — 2026-06-25 15:41

Phase 1 is done — the contract's §7 is no longer a TODO. Here's what the live-query extraction produced.

## What I found (grounded in producer code + live index)

**The Images doc is built by a 3-module chain, not one builder** — this is the headline for the eventual D11 writer:

1. `shanti_kmaps_fields` → common-core base (identity, KMaps linkage, visibility, URLs)
2. `drupal_alter()` fans out to **two** more producers:
   - `shanti_collections` → the **collection access-path fields** (`collection_uid_path_ss`, etc.) — i.e. the §9 ↔ Sprint 1b security seam. *Not* shanti_images.
   - `shanti_images` → caption/description/IIIF/dimensions
3. post-alter normalization (sort keys, URL rewrite, NFC entity-decode)

A writer that only ports the `shanti_images` layer would silently drop the access-control fields and break 1b. That coupling is now documented.

**Live-index findings that sharpened the contract:**
- **Images carry no `*_tibt`/`*_bo` script fields — and no `names_txt`** (all verified 0 of 111,326 docs). NFC fidelity for Images lives in plain `title`/`caption`/`summary`/`description`, not in script analyzers. This **resolves the §8/§10 `_eng`-vs-`_en` open question for Images** (that grammar is kmterms-only; Images uses `*_lang_s` companion strings).
- **`*_lang_s` values are dirty** — `en` (41,561) *and* `English` (6,541), `Tibetan` *and* `Standard Tibetan`. A normalize-or-preserve decision for the search phase.
- **A latent D7 bug**: `rotation_s` is never written (`$sdog` typo at `shanti_images.module:1747`) — flagged so we don't reproduce it.

## What changed in the repo (working tree, branch `docs/kmasset-solr-doc-contract`, uncommitted)

- `docs/planning/kmasset-solr-doc-contract.md` — §7 fully written (3-layer chain + field inventory tables A/B/C/D), §8 + §10 Q3 reconciled with the resolution.
- `docs/planning/kmasset-fixtures/` — 3 golden JSON fixtures (baseline, multi-description, Tibetan/diacritic) as the writer's acceptance check.

**One scope note for your eye:** §7.2-D flags `ids`/`str`/`titles`/`creators` as *probable* schema `copyField` destinations the writer must not emit — I inferred that from the builder not writing them, but haven't confirmed against the `copyField` directives in `schema.xml`. That's a 5-minute verification if you want certainty before it hardens into the contract.

Want me to (a) verify those copyFields against schema.xml and then commit, (b) just commit as-is, or (c) hold for your review first?

---

## User — 2026-06-25 15:47

notes / questions:  1. rotation_s is something we want to support in the future (regardless of the bug). 2. we didn't see an alt_description_* field in your lists of fields but you mention it in your discussion.

---

## Claude — 2026-06-25 15:47

Good catches — let me verify both against the actual data.

---

## Claude — 2026-06-25 15:48

Both verified — here's the precise picture:

**On the alt fields:** the field names are `{base}_alt_{suffix}` (e.g. `caption_alt_txt`, `description_alt_txt`, `summary_alt_txt`, `*_alt_lang_ss`, `description_alt_type_ss`, `description_alt_authors_ss`) — those *are* in my §7.2 table C. The reason you didn't see them in the field dump: that dump was the **baseline doc 1028396, which is single-description**, so the builder writes none of them. They appear **only on multi-description images** — the 1087551 fixture carries the full set (shown above). So it's a presence-conditional family, which the inventory should state explicitly rather than imply.

**On `rotation_s`:** good — that reframes it from "don't reproduce the bug" to "the D7 typo is *why* it's absent in all 111k live docs, and the D11 writer should populate it correctly." Let me fix both in the doc.

---

## User — 2026-06-25 15:50

but be aware that we also have used dynamic fields

---

## Claude — 2026-06-25 15:50

That's an important constraint to bake in — and it actually changes the §7.2-D framing and the `rotation_s` story. Because the image fields are largely realized through **dynamic-field suffix matching** (not static declarations), the suffix on each field is load-bearing (it sets the type), and *adding* a suffix-matching field needs no `schema.xml` deploy. Let me correct the `rotation_s` finding and make the dynamic-field reality explicit in §7.

---

## Claude — 2026-06-25 15:52

Now let me make the dynamic-field reality explicit at the top of the inventory and sharpen the §7.2-D distinction (suffixed = dynamic-matched; unsuffixed aggregations = static/copyField).

---

## Claude — 2026-06-25 15:54

Now the deferred note for `rotation_s` and its registration. Let me check the deferred `.pages` and an existing note's header format.

---

## Claude — 2026-06-25 15:56

Now register the new note in both the README table and `.pages`.

---

## Claude — 2026-06-25 15:57

Both addressed, plus the dynamic-field point woven through. Summary of the doc changes (all on branch `docs/kmasset-solr-doc-contract`, still uncommitted):

**1. `rotation_s` — now treated as a wanted field, not a bug to omit**
- §7.2-C lists it as "wanted, not yet emitted"; §7.3.4 reframed: the D7 `$sdog` typo is *why* it's absent in all 111k docs, and the D11 writer should populate it from `$siimg->rotation`.
- New deferred note `docs/deferred/images-rotation-field-support.md`, registered in the README table and `.pages`.

**2. The `*_alt_*` description fields — clarified, not missing**
- They *were* in §7.2-C (`caption_alt_txt`, `description_alt_txt`, `summary_alt_txt`, `*_alt_lang_ss`, `description_alt_type_ss`, `description_alt_authors_ss`) — naming is `{base}_alt_{suffix}`.
- You didn't see them because the baseline dump (doc 1028396) is **single-description**, so the builder writes none. They appear only on **multi-description images** (≥2 descriptions); fixture 1087551 carries the full set. New §7.3.5 states this presence-condition explicitly, and the table rows are flagged conditional.

**3. Dynamic fields — now explicit (your latest note)**
- New lead paragraph in §7.2: most image fields are **dynamic-field realized**, so the **suffix is load-bearing** (sets type/cardinality) and a suffix-matching field can be added with **writer code alone, no `schema.xml` deploy**.
- That's exactly why `rotation_s` (`*_s`) is cheap to add — noted in §7.3.4 and the deferred note.
- §7.2-D reframed as the **unsuffixed** fields (`ids`, `str`, `titles`, `creators`, `timestamp`, `_version_`) — the only ones needing static declaration / `copyField`, since they fall outside the dynamic grammar.

The one thing still worth a quick check before this hardens: confirming those unsuffixed aggregations (`ids`/`str`/`titles`/`creators`) really are `copyField` destinations in `schema.xml` (vs. written by some producer I haven't traced). Want me to verify that against the configset and then commit, or commit as-is?

---

## User — 2026-06-25 15:58

yes, let's do the analysis of the copyFields

---

## Claude — 2026-06-25 15:58

Let me pull the `copyField` and field declarations for those unsuffixed aggregations from the deployed 7.x schema.

---

## Claude — 2026-06-25 15:59

Confirmed — but let me make sure I'm reading the **production** configset, not staging (the `find` grabbed staging), and that they agree.

---

## Claude — 2026-06-25 15:59

Confirmed and production-verified. The `copyField` analysis settles §7.2-D — no more hedge needed. Let me finalize that section with the evidence.

---

## Claude — 2026-06-25 16:00

The copyField analysis is done and the result confirms §7.2-D with no hedge remaining.

## copyField findings (production-verified; prod == staging, byte-identical)

All six unsuffixed aggregations are **statically declared + `copyField`-fed** — the producer chain never writes them:

| Aggregation | Fed from |
|---|---|
| `ids` | `uid`, `id`, `*_id_s`, `*_id_ss` |
| `titles` | `title`, `title_*`, `*_title_s`, `caption` |
| `names` | `name`, `name_*`, `names_txt` |
| `creators` | `creator`, `creator_*`, `contributor_*`, `agent_*`, `*_authors_s(s)`, `node_user_*` |
| `descriptions` | `caption`, `summary`, `description`, `caption_*`, `summary_*` |
| `str` | universal catch-all (all title/name/creator/id/caption variants, incl. Tibetan `*_bo`/`*_tibt`) |

Plus `timestamp` (`required`, `default="NOW"`) and `_version_` (Solr-internal).

**The useful half of the finding:** the aggregations are *automatic*, so the writer only emits the **source** fields (tables A–C) and the search-spanning fields build themselves. Concretely — `description_authors_s`/`node_user_full_s` → `creators`; `caption_alt_txt`/`summary_*` → `descriptions`; `shanti_image_id_s` → `ids`. And `caption` deliberately fans into `titles` + `descriptions` + `str`. That's now written into §7.2-D as guidance, not just a prohibition.

## §7 is complete

The contract's Images section is now fully evidence-grounded: producer chain, field inventory (A–D), dynamic-field reality, the resolved language-suffix question, the dirty `*_lang_s` values, conditional `*_alt_*` family, and `rotation_s` as wanted-future.

## Working tree (branch `docs/kmasset-solr-doc-contract`, uncommitted)
- `docs/planning/kmasset-solr-doc-contract.md` — §7 written; §7.2-D confirmed; §8/§10 reconciled
- `docs/planning/kmasset-fixtures/` — 3 golden JSON fixtures
- `docs/deferred/images-rotation-field-support.md` — new note (+ README + `.pages`)

Ready to commit. Want me to commit all of this on the branch (and push), or hold for your review first?

---

## User — 2026-06-25 16:02

commit it and push

---

## Claude — 2026-06-25 16:02

I'll stage, commit, and push everything on the branch.

---

## Claude — 2026-06-25 16:02

Committed and pushed to `docs/kmasset-solr-doc-contract` as `597db2b`.

7 files in the commit:
- `docs/planning/kmasset-solr-doc-contract.md` — §7 written, §7.2-D confirmed
- `docs/planning/kmasset-fixtures/` — 3 golden JSON fixtures
- `docs/deferred/images-rotation-field-support.md` + README + `.pages`

That closes out the golden-doc extraction (Phase 1) for Images. The contract is now a testable spec: when the D11 Images writer gets built, it emits the table A–C source fields, and the fixtures + copyField rules give you a byte-level acceptance diff.

Two natural follow-ons when you next pick up 1a.8, neither needed now:
- The remaining §10 open questions are still the real gates — the write **transport** (A/B/C) pending the Dave Goldstein cost conversation, and the master write endpoint/auth.
- This was Images only; Sources/Texts/AV will each need their own §7-style inventory, but they reuse the common-core (table A) + the copyField behavior you just verified.

---

## User — 2026-06-25 16:03

save the session log

---

## Claude — 2026-06-25 16:03

I'll run the session-end ritual. Let me find this session's transcript and run the save script.

---

## Claude — 2026-06-25 16:04

The current session is `735eb1bf-...` (matches my scratchpad path). Let me see how the script sets the output filename and outcome line.

---
