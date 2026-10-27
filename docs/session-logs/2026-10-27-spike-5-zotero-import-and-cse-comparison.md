# Session Log: Spike 5 Zotero Import and CSE Comparison

**Date:** 2026-10-27  
**Participants:** Than Grove, Claude Code (solo, during a group session led by Yuji)  
**Outcome:** Spike 5 advanced on four fronts, all recorded in [Spike 5](../spikes/spike-05-bibcite-sources.md): bibcite installed locally (DDEV only), custom-type CSL mapping verified (one-to-one, so Obituary and Block Print need unused CSL targets), field-set and CSE output comparisons done, and the Zotero import requirement clarified and proven with a throwaway prototype.

## Handoff: pick up at the start of the next session

Spike 5 is still ◐ Partial. **Than finishes it next session**, then moves on to the Texts
migration. Branch `spike/5-bibcite-local-install` is pushed (docs and prototype only); it must
**not** be merged as is, because the local composer change is uncommitted on purpose (a spike
makes no structural change; the install steps are in the spike doc's "Handoff for the Sources
migration (Xiaoming)").

**Decided this session**
- Zotero import = admin-only (widen to editors later), pull from the Zotero API, each entry a
  record. D7's Feeds importer did this; bibcite has only file-upload import, so the pull is custom.
- Record model = **bibcite's own** `bibcite_reference` entities, not nodes. Xiaoming and Yuji have
  **not yet been told**; it affects Group access, `mandala_node_api`, the Solr/kmassets hooks and
  nid redirects (spike finding 7, consequences, unverified).
- Finding 3's "dormant, criterion 5 dropped" framing is superseded: the capability is wanted.

**Remaining, in order**
1. Tell Xiaoming/Yuji about the entity-not-node model choice.
2. Ask Sources staff: does anyone use Zotero today? (also the D7 feed's library/settings, which live
   in the per-feed Feeds config and likely hold a key; not read).
3. **Criterion 6 broader render pass:** books, chapters, multi-author lists, the four custom types
   and the seven non-CSE styles (apa, chicago, mla, ama, harvard, vancouver, ieee). Pick unused CSL
   targets for Obituary and Block Print here (candidates `article`, `entry`, `pamphlet`,
   `graphic`, `post`). Chase the `original-date` render warning seen on some imported items.
4. Decide with the group: edition (CSE 8th vs 9th), migrate or regenerate `citekey`, whether Sources
   still wants Zotero *collections*, and a dedicated field for the Zotero item key.
5. Verify the unverified consequences if wanted: KMaps field on a reference bundle; Group
   relationship plugin for `bibcite_reference`.
6. Untested: a keyed (private) Zotero library; migrating the 2014 Zotero data on ~1.9k nodes.

**Local state to know about**
- DDEV has bibcite 3.1.2 enabled and `drupal/composer.json` + `composer.lock` modified, uncommitted.
  `ddev drush config:status` will show bibcite config as "Only in DB"; expected here, and
  `session-start-check.sh` may flag it. Do not export it.
- Scratch DB `spike5_d7` (the 2026-06-11 Sources dump) is on DDEV's DB server; drop it with
  `ddev mysql -e "DROP DATABASE spike5_d7"` when done. DDEV snapshot `pre-spike5-test` also remains.
- Other open items untouched: PR #378 (PHP 8.4), PR #381 and issues #380/#382 (Yuji's cutover review).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-27 15:58

pull latest code and review. Yuji is running a session with all of us.

---

## Claude — 2026-10-27 15:58

I'll start with the session startup check, which also does the git sync.

---

## Claude — 2026-10-27 16:02

Main was already current. Two things are new since the last session: the unmerged branch `docs/cutover-review-list` and issues #380 and #382. I'll look at those.

---

## Claude — 2026-10-27 16:02

Main is current (`git pull --ff-only` found nothing new) and the session startup check passed. Local config matches the committed config. Local content differs from dev-0 only by a few rows, which is the expected non-gating kind.

## What's new

**PR #381, `docs: pre-cutover review flag for deferred notes`** (Yuji, open, mergeable, GitGuardian passing). It's docs-only, so merging it won't trigger a deploy.
- It adds a "Cutover review" section to `docs/deferred/README.md`. A note gets flagged with `**Cutover review:** pending` and its issue gets the `cutover-review` label. Decisions are logged as comments on umbrella issue #380. Each decision is `before-cutover` (with an owner), `after-cutover`, or `dropped`.
- It flags one note, the identity-forwarding deferral, and promotes it to issue #382. It replaces the old `**Jira:** (add when available)` header with `**Issue:** #382`.
- The checks I ran found no problems. The Spike 12 link resolves. The note is already in the README index and in `.pages`.
- The #382 header text names the problem only at a general level ("private-collection asset detail views"), which fits the public-repo rule. The note itself is the existing public one.

**Issues #380 and #382** are both unassigned and marked as needing a group decision. They are agenda items for this session, not pickups.
- **#380:** the cutover decision log, which stays open until cutover.
- **#382:** does anything at cutover need private-collection asset detail views in the React app? If yes, build the direction in Spike 12, which resolves `sid`→uid through a Redis key. If no, record it as a known limitation.

**PR #378, PHP 8.4 / Drupal 11.4.11** is still open from 10-22. It touches `drupal/**`, so merging it triggers a real deploy. Check dev-0 for long-running jobs before it goes in.

Nothing needs fixing on my side. Which would you like me to take: record the #382 decision when the group reaches one, or review PR #378?

---

## User — 2026-10-27 16:15

Ok make a note in your memory for me. That my tasks are to finish out Spike 5 and then move on to the Texts migration.

---

## Claude — 2026-10-27 16:15

I saved a memory note: your tasks are to finish Spike 5 first, then move on to the Texts migration. It's in `project-than-task-queue.md` and indexed in `MEMORY.md`. I linked it to the existing Spike 5 and Spike 4b (footnotes) memories, since the footnote work feeds into the Texts migration.

---

## User — 2026-10-27 16:15

There may be more commits to the repo. Make sure we are up to date.

---

## Claude — 2026-10-27 16:16

We're up to date. `main` matches `origin/main` at `02b8b08` (PR #379), the pull found nothing new, and the working tree is clean. PRs #381 and #378 are still open and unmerged, so nothing from them is on `main` yet.

---

## User — 2026-10-27 16:17

start on Spike 5

---

## Claude — 2026-10-27 16:17

The 10-23 handoff lists five steps. Step 1 is a question for Sources staff, so I'll start on step 2, installing bibcite locally. First I'll check the spike doc and the local state.

---

## Claude — 2026-10-27 16:17

DDEV is running and bibcite isn't installed. I'll install it on a spike branch, not on `main`.

---

## Claude — 2026-10-27 16:19

That failed on a module name I guessed that doesn't exist. I'll list the real submodules.

---

## Claude — 2026-10-27 16:19

Bibcite is installed locally and the spike's config is unaffected. Next is step 3: can a custom reference type be mapped to a CSL type? I'll look at the config entity's schema and the shipped types.

---

## Claude — 2026-10-27 16:20

The mapping is a plain string sequence (bibcite type id → CSL type). I'll check how the mapping form enumerates types, then test it live with a throwaway type after a snapshot.

---

## Claude — 2026-10-27 16:20

The mapping is keyed by CSL type, so each CSL type maps to one bibcite type. That could limit the custom types, so I need to see how the mapper is used and whether it has a UI.

---

## Claude — 2026-10-27 16:21

Spike 5 is further along: bibcite is installed locally and the CSL custom-type question is answered. One of the spike's earlier conclusions turned out to be wrong. Nothing is committed or pushed.

**Local install**
- I created branch `spike/5-bibcite-local-install`.
- `drupal/bibcite` 3.1.2 is installed through composer, plus its dependencies.
- The `bibcite`, `bibcite_entity`, `bibcite_import` and `bibcite_export` modules are enabled in DDEV.
- `composer.json`, `composer.lock` and the Spike 5 doc are modified and uncommitted.

**Handoff step 3: does a custom reference type work with the CSL mapping?**
- **Yes, a custom type works.** A config-only `review` type showed up in the mapping and rendered as the CSL type `review-book`.
- **The spike's CSL targets for two of the four types are wrong.** The mapping is one-to-one between CSL type and reference type.
  - Obituary → `article-newspaper` and Block Print → `manuscript` are both already taken by the shipped `newspaper_article` and `manuscript` types.
  - When I mapped Obituary onto `article-newspaper`, `newspaper_article` lost its mapping and silently fell back to the default type.
  - Obituary and Block Print need unused CSL targets. Candidates are `article`, `entry`, `pamphlet`, `graphic` and `post`. The choice is open until the output comparison.
  - Review and Dictionary are unaffected.
- **Spike doc:** I replaced the "Not yet verified" paragraph with these findings and marked the two target rows as taken.

**Cleanup**
- I deleted the two test types and restored the one mapping value I changed.
- I did not restore the snapshot. The classifier blocked a command that included a snapshot restore and cleanup, and I didn't need either.
- The snapshot `pre-spike5-test` is still in DDEV and you can delete it.

**Decision for you:** should the composer change be committed? Merging it would put bibcite and about 10 packages into the production image, so the team should agree first. I'd commit only the spike doc to `main` now and keep the composer change on the branch. Say the word and I'll do that.

**Left to do**
- Step 1, asking Sources staff whether anyone still syncs from Zotero, is yours.
- Step 4, comparing per-type fields against D7 `biblio_field_type_data`.
- Step 5, the CSE output comparison.

---

## User — 2026-10-27 16:49

Is it possible to make custom types for Obituary and Block-print? As for the composer change, can't we just document that this is needed when the Sources migration actually happens? Xiaoming is going to be in charge of the actual migration. Since we are in a spike, we shouldn't make any real structural change, no?

---

## Claude — 2026-10-27 16:49

Yes, custom types for both are possible, and you're right about the composer change.

**Custom types for Obituary and Block Print**
- The type itself isn't the problem. I created an `obituary` type in the test and it saved and rendered fine.
- The constraint is that each type needs its own CSL target for rendering. Candidates that no shipped type uses are `article`, `entry`, `pamphlet`, `graphic` and `post`.
- A made-up CSL type id wouldn't help. The mapping is just strings, so it would accept one, but CSL styles only know the standard vocabulary. I haven't tested it, but I'd expect it to render as a generic fallback.
- The rendering quality of each candidate gets settled in the step 5 output comparison.

**Composer change**
I agree. A spike shouldn't change the production image. I'll add a "Handoff for the Sources migration" section to the spike doc and commit only the doc. It will cover:
- the composer require (`drupal/bibcite:^3.1`) and the modules to enable (`bibcite`, `bibcite_entity`, `bibcite_import`, `bibcite_export`);
- the four custom types and their CSL targets;
- the one-to-one mapping constraint;
- the CSE style files in `docs/spikes/assets/spike-05-bibcite/`.

The composer edits stay uncommitted on the branch so I can finish steps 4 and 5 locally. Before that I'll run `git diff` on the doc edit to confirm nothing else slipped in.

---

## Claude — 2026-10-27 16:49

I committed only the spike doc, as `eb7db71` on `spike/5-bibcite-local-install`. It is not pushed. `composer.json` and `composer.lock` are still modified in the working tree and are not committed.

The doc now includes:
- the verified one-to-one CSL mapping finding;
- the "Handoff for the Sources migration (Xiaoming)" section.

Should I put the doc on `main`, either by cherry-picking it and pushing as we did before or through a small PR? The branch itself can't be merged as is, because it would carry the composer change if anyone added it later. If you'd rather, I can skip that for now and go on to step 4, the field-set comparison against D7.

---

## User — 2026-10-27 16:50

Ok. let's address the open questions

---

## Claude — 2026-10-27 16:50

I'll take the open questions that I can answer with evidence, starting with step 4, the field-set comparison. I need the D7 `biblio_field_type_data` from the dump and bibcite's per-type fields.

---

## Claude — 2026-10-27 16:51

The dump is loaded (25,627 nodes). Now I'll compute which `biblio` columns are populated per D7 type, with counts, and get bibcite's field pool and per-type fields.

---

## Claude — 2026-10-27 16:52

The D7 row has an author ("Moreno, A. J.") yet the D7 CSE baseline printed "[Anonymous]". That is a D7-side oddity worth noting. Now I'll find bibcite's rendering entry point.

---

## Claude — 2026-10-27 16:53

I'll update the header status, add the output-comparison finding, and rewrite the stale "does NOT establish" bullets to say what's now done and what isn't.

---

## Claude — 2026-10-27 16:53

I worked through the open questions I could answer with evidence. Three doc commits are on the branch (`eb7db71`, `689c8ed`, `6012095`) and are not pushed. The composer files are still uncommitted.

**Resolved**
1. **Do custom types work?** Yes. Review, Dictionary, Obituary and Block Print are all config-only, and each needs its own CSL target.
2. **Field sets (step 4).**
   - Nothing is lost in storage, because every bibcite type carries the same 49 fields and every D7 column has a home.
   - bibcite's default edit forms hide fields D7 populates: `citekey` (all rows), `lang`, `notes`, `date`, `remote_db_provider` and `access_date`. Editors couldn't see them, but the data would still store. The fix is form-display config.
   - My first comparison found no gaps and was meaningless, because I'd compared against the wrong config. I redid it against the form displays and noted that in the doc.
3. **CSE output (step 5), sample of one.**
   - Both prepared CSE styles import and render nid 25581, so criterion 3 is met for CSE.
   - The D7 baseline for that node prints `[Anonymous]` although the node has an author, doubles a period and leads the pages with a colon. The bibcite 8th-edition style is the closest in shape and includes the author.
   - That is one node and one type. Books, multi-author lists, the custom types and the other seven styles haven't been rendered.

**Still open**
- **Sources staff:** does anyone still sync from Zotero? This is yours to ask.
- **Obituary and Block Print CSL targets:** they need unused CSL types, and the choice belongs to a broader render test.
- **Which CSE edition** (8th or 9th) Sources wants. That's a stakeholder call.
- **`citekey`:** migrate the D7 values or regenerate them? That's a migration decision, which fits Xiaoming's handoff.

**Cleanup state**
- The scratch database `spike5_d7` (the D7 dump) is still on DDEV's DB server. Say the word and I'll drop it, or keep it if we render more nodes.
- The DDEV snapshot `pre-spike5-test` is still there too.

Should I continue with a broader render test across books, chapters and multi-author nodes, or push the doc to `main` first?

---

## User — 2026-10-27 19:18

Ok we need to discuss Zotero. How do you see it being used?

---

## Claude — 2026-10-27 19:19

From the evidence we have, I don't see Zotero being used live. I see it as a one-time data source, with D7 fields left behind that the migration has to carry over. This is inference from a stale dump, so the staff confirmation still matters.

**What the data shows**
- **The feed itself:** there is one `zotero_feed` node, "Zotero Feed Importer". It was created 2014-04-28 and last changed 2015-12-01. Its connection settings aren't stored on the node, so the dump can't show which Zotero library it points at.
- **The import:** 1,920 nodes carry Zotero fields, and all of them were created between 2014-05-12 and 2014-09-05. That is one import burst, and nothing has come through Zotero since.
- **The rest of Sources:** the other ~23.7k nodes weren't imported from Zotero. They were created mostly in 2019–2022, with the latest in December 2025. Staff add records another way, which I haven't identified.
- **Leftover data:** about 12.8k Zotero tag rows, 6.2k collection rows and 1k attachment rows. The 2020 `changed` batch of ~975 nodes looks like a bulk edit, but I haven't verified that.

**How I'd treat it in D11**
1. **No live Zotero integration.** The migration preserves the imported fields so the data isn't lost, and we don't build sync.
2. **Open question about the leftover fields:** does anything display or search the Zotero tags, collections and attachments, or are they just provenance? I haven't looked at the D7 display side, so I can't say. If they're display-only artifacts, we could store them as plain fields or drop some after checking with staff. If the tags feed facets or search, they need more care.
3. **The case that would change this:** someone still maintains a Zotero library and re-imports it periodically. That would mean a real ingest path, either bibcite's importers or a custom job, plus working credentials. I can't rule it out from the dump, since the feed node's settings are the one thing I couldn't read.

**What I need from you**
- Do you know whether anyone on the Sources side uses Zotero today?
- Do you remember what the imported tags and collections were used for in D7?

If you want, I can look at how the D7 site displayed those fields and whether they're indexed, which would answer the second question from the dump.

---

## User — 2026-10-27 19:20

The old set up was a way to import Zotero data into the Sources app. What we want is a way for ediors only to import Zotero bibliographies into the Sources app, converting each entry into a node. That's the way it functioned before. Though it may only have been admins who could do this.

---

## Claude — 2026-10-27 19:20

I'll start with a read-only look at how the D7 importer worked and what bibcite's import offers. One Explore agent will take the D7 side while I read bibcite.

---

## Claude — 2026-10-27 19:21

The Sources content-model audit and ADR 009 are the likeliest places to say whether Sources references are nodes or bibcite entities. I'll check them.

---

## Claude — 2026-10-27 19:21

Noted: Zotero API pull, admins only to start. While the agent finishes, I'll check whether the D11 repo already has a Sources reference model, either a node bundle or something bibcite-related.

---

## Claude — 2026-10-27 19:21

The agent reports 9 tool uses, so it did read files. I'll spot-check two of its key claims against the source before building a plan on them.

---

## Claude — 2026-10-27 19:22

I have what I need, so I'll write the plan.

---

## Claude — 2026-10-27 19:24

That matches the plan as written, so I'll submit it again with the scope made explicit.

---

## Claude — 2026-10-27 19:24

The plan is approved. I'll begin with the API check, using a public Zotero library with no credentials, and read the D7 item-type map that will seed the mapper.

---

## Claude — 2026-10-27 19:24

The first half worked. The `======` separator tripped zsh, so the API probe never ran. I'll run it on its own and read the rest of the type map.

---

## Claude — 2026-10-27 19:25

The API rejected my combined filter. I'll test which exclusion syntax it accepts.

---

## Claude — 2026-10-27 19:25

The API takes only a single negation, so I'll filter attachments server-side and let the mapper skip standalone notes, which it already does.

---

## Claude — 2026-10-27 19:26

Forty references were created. Now the idempotency tests. A re-run should change nothing. After I edit one record locally, a re-run should update exactly that one.

---

## Claude — 2026-10-27 19:27

The output looks right overall, but I see two oddities: a warning about `original-date`, and the book "1984. 1984. Index." which looks wrong. I'll look at that record.

---

## Claude — 2026-10-27 19:28

I'll update finding 3 to say the capability is wanted, add the new finding with the prototype results, refresh the status line and limits list, and add a short README to the prototype folder.

---

## Claude — 2026-10-27 19:28

The Zotero part of Spike 5 is done and committed as `ee8141d` on `spike/5-bibcite-local-install`. It is not pushed.

**What the prototype showed (local DDEV only, nothing permanent)**
- **API:** Zotero's v3 API works on a public library with no credentials. It supports incremental pulls with `since`, where D7 re-fetched everything. Its `itemType` filter accepts only one negation, so the mapper skips standalone notes instead.
- **Mapping:** 163 of the 164 items in a public library mapped, and the one skipped was a standalone note. Two item types (`blogPost`, `encyclopediaArticle`) fall back to `miscellaneous`, as in D7.
- **Re-runs:** I imported 40 items. A re-run changed nothing. When I edited one record locally, the next run updated only that one. The check compares stored values, so it can fail.
- **Rendering:** imported items render through the CSE style.
- **Tags:** Zotero tags map onto bibcite keywords.
- **Cleanup:** the test records and styles are deleted. `git status` shows only the composer files, still uncommitted, and the prototype folder, now committed.

**Gaps it exposed, all recorded in the doc**
- Production needs a dedicated field for the Zotero item key. The prototype borrowed `bibcite_remote_db_name`, which D7 used on real rows.
- Zotero collections have no stock home in bibcite, so whether to keep them is a decision.
- Rendering emitted an `original-date` warning on some items. I couldn't isolate the cause and logged it as unresolved.
- Only a public library was fetched. A private library with an API key is untested, and I read no keys.

**Changes to the doc itself**
- Finding 3 had concluded "dormant, criterion 5 dropped". I rewrote it, since you want the capability.
- Finding 7 documents the design for Xiaoming.
- The consequences of using `bibcite_reference` instead of nodes are listed there too (Group access, API, Solr, KMaps, nid redirects). They come from reading the code and are not verified.

**For you to decide or do**
- Tell Xiaoming and Yuji about the choice to use bibcite entities instead of nodes, since it affects all of Sources.
- Ask Sources staff whether anyone uses Zotero today.
- The scratch database `spike5_d7` and the snapshot `pre-spike5-test` are still in DDEV, and I can drop them on your word.
- Should I push the docs to `main`, by cherry-pick or small PR?

---

## User — 2026-10-27 20:06

Ok. let's wrap up. write session log, memory, and document this. Then commit and push to this branch. We'll finish spike 5 next time.

---

## Claude — 2026-10-27 20:06

I'll start with the session-close check and look at how the session-log script works.

---
