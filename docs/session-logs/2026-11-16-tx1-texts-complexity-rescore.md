# Session Log: Tx1 Texts Complexity Rescore

**Date:** 2026-11-16  
**Participants:** Than Grove, Claude Code  
**Outcome:** Sprint 5 TX1 done: Texts rescored 2.0 → 2.6/5 ([rescore](../planning/av-sources-texts-migration-complexity-comparison.md#texts-rescore-2026-11-16-sprint-5-tx1)), PR #413. Local DB restored from snapshot + `cim`; all start checks clean. Next: TX4 (kmassets naming, #289 sweep plan), then TX5.  

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-11-16 14:09

ok remember where we are?

---

## Claude — 2026-11-16 14:09

Yes. Here's where things stand as of the last session (11-13):

**Just finished**
- **Spike 13 (Texts whole-book) is done.** ADR 021 was accepted with Yuji and Xiaoming signing off, and merged in PR #410. Sprint 5's TX2 and TX3 are done. Page URLs redirect to the book root plus the page's anchor, which matches what D7 does.
- **The Sources gate is lifted.** Yuji and Xiaoming approved ADR 020 (bibcite entities, not nodes). That merged in PR #411 and closed #386, so the Sources build can start.
- Voyant is deferred as #408.

**Next in your queue (Texts track)**
1. TX1: rescore the Texts audit complexity.
2. TX4.
3. TX5: the content model. It's unblocked now that ADR 021 is in.

Sources is also open now, so you can choose which track to take first.

**Open loose end, flagged for you:** we still haven't confirmed whether D7's Texts raw-text route enforces node access. Checking means probing the live D7 site, so I need your go-ahead first. If it turns out to be real, the write-up goes in the private `mandala-legacy-docs` repo, not this public one.

Smaller leftovers: filing the upstream drupal/book issue, and some old branches to clean up (e.g. the remote `spike/6-api-compatibility`).

Local `main` is clean at the #411 merge. I haven't run `./scripts/session-start-check.sh` yet. Want me to run it, then start on TX1?

---

## User — 2026-11-16 14:11

Can you give me a summary of what TX1 through TX5 (or however many there are) are?

---

## Claude — 2026-11-16 14:11

There are 24 TX items, all in `docs/sprints/sprint-05-texts-core-implementation.md`. TX2 and TX3 are done and TX1 is about halfway. The rest haven't started.

**Decide and plan**
- **TX1** (◐ in progress): refresh the Texts audit. The text updates were made on 11-02. What's left is rescoring the complexity, since the old 2.0/5 figure predates the findings that Book is out of core and the display is a whole-book render.
- **TX2** ✅: run Spike 13.
- **TX3** ✅: the decision record from the spike, now ADR 021.
- **TX4**: settle the `kmassets` `texts` bundle naming against the 5,381 existing legacy documents, and plan the Texts orphan review-group sweep (#289).

**Build the content model**
- **TX5**: install contrib `drupal/book` 3.x (export its config from a live Drupal), plus a `book` bundle with the audit's fields, the journal fields and the legacy-id fields. Also an `asset_link` bundle, reusing the existing collection and subcollection group types.
- **TX6**: CKEditor 5 `full_html` with Tibetan fonts, and porting the D7 splitter, which builds a page tree from one paste based on its headings.
- **TX7**: group relationship types, inheritance wiring, `content_editor` permissions, and mapping access levels 0/1/2. It also includes fixing the `NodeQueryAlter::baseTable()` bug from Spike 13 (non-administrators get an SQL error).
- **TX8**: add `book` and `asset_link` to the kmassets sync settings.

**Migrate**
- **TX9**: the `migrate_texts` DB key, plus a scaffold for the migration group.
- **TX10**: the `D7TextsNode` source plugin, covering the field mappings and the 65 unpublished books.
- **TX11**: outline migration, a Mandala copy of `d7_book` that uses `migration_lookup`.
- **TX12**: files (537, of which 228 are PDFs).
- **TX13**: KMaps fields.
- **TX14**: collections and subcollections as Groups, plus memberships.
- **TX15**: body transform, covering footnotes (Spike 4b), inline media and NFC. It works across nodes, so it runs after TX11.
- **TX16**: URL aliases and redirects, including about 2,900 Tibetan-script aliases.
- **TX17**: the run and verify scripts. Rollback order matters here.
- **TX18**: access verification as anonymous, member and editor.
- **TX19**: kmassets reindex and audit.

**Front end and endpoints**
- **TX20**: book display (whole-book render, sidebar, Notes, the Views tab).
- **TX21**: collection and subcollection pages.
- **TX22**: listings and search (`/texts`, `/mytexts`, facets, Solr).
- **TX23**: endpoints, all access-checked: `/api/json`, `node_embed`, raw text, Voyant and PubReader. The D7 paths stay working through redirects.

**Close out**
- **TX24**: acceptance run comparing about 10 books side by side in D7 and D11, plus timing at production scale.

On ordering: TX1 is what's open now, and TX4 and TX5 are unblocked. Most of the migration work hangs off TX5 through TX10.

---

## User — 2026-11-16 14:12

run the session start check, then start TX1

---

## Claude — 2026-11-16 14:14

Now pulling in what Spike 13 and ADR 021 found, since those are what the rescore rests on.

---

## Claude — 2026-11-16 14:14

I have what I need. Here's my proposed rescore. Spike 13 retired most of the uncertainty, but it showed that access and the outline carry real work the September score didn't count.

---

## Claude — 2026-11-16 14:14

I've drafted the TX1 rescore: Texts goes from **2.0/5 to 2.6/5**. It's on a new branch, `docs/tx1-texts-complexity-rescore`, and isn't committed yet so you can review the scores first.

**Session check first.** Git sync passed and `config/sync` matches origin/main. Two things need you:
- **The local DB won't boot Drupal,** so the config gate fails. The DB still has the Spike 13 throwaway module (`spike13_render`) enabled, but that code only existed on the spike branch. The fix is to restore the `pre-spike13-s1-2026-11-02` snapshot, which discards any local content added since 11-02. I didn't run it because it replaces the DB. TX1 doesn't need the DB, but TX5 will.
- **dev-0 is unreachable** (SSH timed out), so VPN is probably off. The dev-0 comparisons were skipped.

**The rescore**, same five dimensions as the September comparison:

| Dimension | Was | Now | Why |
|---|---:|---:|---|
| Storage/schema shape | 2 | 2 | Still plain fields. The new items are small: contrib Book instead of core, page text in `field_book_content`, the missed journal fields, 25 pages wrapped in a whole XHTML document. |
| Entity graph complexity | 2 | **3** | The outline needs our own lookup-based copy of Book's migration, a fixed rollback order and weight normalization. Footnotes resolve across pages, so that transform has to run after the outline migration. |
| Rendering fidelity risk | 3 | 3 | There's more to build (whole-book render, sidebar, four alternative formats), but Spike 13 proved all of it against D7. Only Voyant and timing on staging hardware are still open. |
| Access-model complexity | 1 | **3** | The biggest change. Access is checked per page inside one render. Book's tree API is unusable because of the 1,410 pages under unpublished ancestors. The cache needs a custom membership context, the book bundle needs Group role grants, and the `NodeQueryAlter` bug breaks Book's queries for non-admins. |
| Unresolved unknowns | 2 | 2 | Some were resolved and a few small ones remain. |

Texts is still the easiest of the three (AV was 3.8, Sources 3.2), so the sequencing doesn't change. What changed is the kind of difficulty: little uncertainty left, but more build work, mostly in TX7, TX11 and TX20.

**What I edited:**
- `docs/planning/av-sources-texts-migration-complexity-comparison.md`: a new "Texts rescore" section that keeps the September table as it was. It notes that AV and Sources weren't rescored, and that Sources has moved since then.
- `docs/planning/texts-content-model-audit.md`: correction 6 now points to the rescore.
- `docs/sprints/sprint-05-texts-core-implementation.md`: TX1 marked done.

The doc mentions the private D7 question only as "tracked privately (ask Than)", without saying what it is, as the public-repo rule requires.

The two 3s are judgment calls; access could reasonably be a 4. Do you want to adjust any score before I commit and open the PR? And should I restore the snapshot now?

---

## User — 2026-11-16 14:34

It's all good. I've logged onto VPN. I agree with your assessment and you can restore the snapshot

---

## Claude — 2026-11-16 14:38

The snapshot is older than some config merged since 11-02 (the transcript migrations), so I'm importing the committed config on top.

---

## Claude — 2026-11-16 14:39

TX1 is done. I committed it, pushed the branch and opened **PR #413**.

**Local environment:** I restored the `pre-spike13-s1-2026-11-02` snapshot. That snapshot predates the two transcript migration configs merged since 11-02, so I ran `drush cim` to bring the DB up to the committed config. I re-ran the session start check after that and every automated check passed:
- `config:status` is clean.
- Local counts match dev-0 exactly.
- 25 sampled legacy-identity pairs have the same D11 nid locally and on dev-0.

I've also updated my notes for next session: TX1 is done, next up is TX4 or TX5, and Sources is unblocked.

**Next:** TX4 is planning only: settle the `kmassets` `texts` bundle naming, and plan the orphan review-group sweep for #289. TX5 is the first build step: install contrib Book and set up the `book` bundle, which the restored DB now supports. I'd do TX4 first, since TX8 needs it and it's quick. Which would you like?

---

## User — 2026-11-16 14:44

Ok I need to restart my computer. Write memory, session logs, etc so I can close out and we can pick this up after restart.

---
