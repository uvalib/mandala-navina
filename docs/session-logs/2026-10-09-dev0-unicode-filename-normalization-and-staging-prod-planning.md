# Session Log: Dev0 Unicode Filename Normalization And Staging Prod Planning

**Date:** 2026-10-09  
**Participants:** Than Grove (driving), Xiaoming Wang, Yuji Shinozaki, Claude Code  
**Outcome:** Resolved [dev0-unicode-filenames-need-nfc-normalization.md](../deferred/dev0-unicode-filenames-need-nfc-normalization.md) (dev-0's 37 NFD-named paths renamed to NFC live); generalized the fix into a standing per-migration check documented in [migration-cycle-runbook.md](../planning/migration-cycle-runbook.md)'s "Filename normalization check" section, and extended it to cover a future staging environment via [staging-migration-execution-prerequisites.md](../deferred/staging-migration-execution-prerequisites.md) item 3 and [production-migration-planning.md](../deferred/production-migration-planning.md). Commits `df867ee`, `7a281b9`, `605050f`.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-09 15:21

Ok we are in session with Xiaoming, Yuji, and myself. We want to deal with the Dev-0 file renaming issue. Please summarize.

---

## Claude — 2026-10-09 15:21

Here's the summary of dev-0's file-renaming issue for the group:

**The problem:** dev-0's D11 database stores Unicode filenames as **NFC** (composed), but the actual files on dev-0's disk are **NFD** (decomposed). Same visual name, different byte sequences — so any exact-path fetch against dev-0 (`stage_file_proxy`, `mandala:missing-file-audit`'s remote-source lookup, plain curl/rsync) 404s on the literal NFC path even though the NFD-encoded version of the same URL works.

**Evidence (found 2026-10-09, during the `stage_file_proxy` trial):** Confirmed on two files — a Latin-diacritic name (`Tenpé Gyeltsen...xml`) and a Tibetan-script name (`ཞིང་ཁམས།.xml`) — both 404 at their NFC path, both 200 at NFD. This generalizes an earlier single-file observation (`Rangdrol-Rinpoché.jpg`), so it's systemic, not a one-off. Overall scope (how many files affected) hasn't been measured.

**Likely cause:** uploads/migration through a macOS-backed path at some point — HFS+/APFS silently decomposes certain Unicode filenames to NFD on disk regardless of how the DB stores them. Not confirmed with certainty for these specific files.

**Already handled — future recurrence:** PR #314 (merged) added the `mandala_file_hygiene` module, which normalizes filenames to NFC on disk at save time going forward. So this is purely about the **backlog of files already on dev-0 in NFD form**.

**Proposed fix (not started):** one-time rename pass over dev-0's `sites/default/files` — walk the tree, find non-NFC basenames, rename to NFC. Disk-only; no `file_managed` DB rows need to change since those are already NFC. SSH to dev-0 confirmed reachable.

**Why it needs this group's sign-off rather than just doing it:**
1. dev-0 is shared, production-adjacent infrastructure — a mistake hits everyone.
2. Scope is unmeasured — don't yet know how many files, which fields, or whether anything external (legacy hardcoded link, CDN cache, external citation) depends on the current NFD path.
3. Needs a dry run / reviewable diff before touching anything live.

**Open questions for today:**
1. Who owns running it, and does it need a maintenance window or is it safe live?
2. Should it be a one-off script, or a flag added to `mandala:missing-file-audit` (which already discovers file paths)?
3. Does staging/production need the same check before their first file-population pass, or is dev-0 the only place with this history?

Full note: `docs/deferred/dev0-unicode-filenames-need-nfc-normalization.md`. It's currently an unassigned deferred item with no GitHub issue yet.

---

## User — 2026-10-09 15:23

We want to do the one-time pass on the Dev-0 files but we also want to incorporate the conversion into the migration plan for production.

---

## Claude — 2026-10-09 15:24

There's already a `docs/deferred/production-migration-planning.md` — that's the right place to add the NFC conversion step. Let me check it.

---

## Claude — 2026-10-09 15:25

Now I'll write the standalone rename script.

---

## Claude — 2026-10-09 15:26

Now let's run the dry run against dev-0.

---

## Claude — 2026-10-09 15:27

Good — it's a bind mount, so the host filesystem has the files directly at `/mnt/data/mandala-drupal-0/sites/default/files`, and the host has python3. I'll update the script to run on the host instead of inside the container.

---

## Claude — 2026-10-09 15:28

Scope is now measured: 37 paths affected (plus one earlier example — the Tibetan filename `ཞིང་ཁམས།.xml` — turns out to be a false lead; its NFC and NFD forms are byte-identical, so it's not actually part of this bug). Running the live rename now.

---

## Claude — 2026-10-09 15:28

Rename succeeded on all 37 paths. Let's verify with a re-run (should report zero) and spot-check one of the originally-known examples is now fetchable.

---

## Claude — 2026-10-09 15:29

Confirmed — zero remaining non-NFC paths on dev-0. Now let's spot-check the known example over HTTP, then update the docs.

---

## Claude — 2026-10-09 15:29

Now update the open questions section to reflect they've been answered, and add the production migration planning item.

---

## Claude — 2026-10-09 15:30

Now add the planning item to `production-migration-planning.md`.

---

## Claude — 2026-10-09 15:30

Now let's check the `.pages`/README and session-close script requirements, and commit.

---

## Claude — 2026-10-09 15:30

Already listed in `.pages` (from PR #314's earlier work), so no nav update needed. Let's update its title to reflect resolved status for clarity.

---

## Claude — 2026-10-09 15:30

Now let's run the session-close check to catch anything mechanical before committing.

---

## Claude — 2026-10-09 15:30

This is pre-existing (production-migration-planning.md already lacked an `**Issue:**` header before I touched it). The uncommitted changes are expected since I haven't committed yet. Let me commit this work.

---

## Claude — 2026-10-09 15:31

Pushed. Let me update local memory per the session-close checklist, since this resolves a tracked item.

---

## Claude — 2026-10-09 15:31

I'll add a new entry to the top of the "Current state" section for today's resolution.

---

## Claude — 2026-10-09 15:31

Done. Summary:

**dev-0:** Ran `scripts/normalize-dev0-filenames-nfc.sh` live against dev-0 — dry run found 37 affected paths (all under `transcripts/` plus one top-level file), executed, re-ran dry-run to confirm zero remaining. Runs on the dev-0 host via its bind mount (the container has neither `python3` nor PHP's `intl` extension). One correction surfaced along the way: the Tibetan-script example in the original note (`ཞིང་ཁམས།.xml`) turned out not to actually be part of this bug — its NFC/NFD forms are byte-identical.

**Production migration plan:** Added the same NFC normalization pass as a required pre-cutover step in `docs/deferred/production-migration-planning.md`, since production has its own independent upload history and can't be assumed clean just because dev-0 now is.

Both docs updated, the deferred note marked resolved, committed (`df867ee`) and pushed to `main`.

---

## User — 2026-10-09 15:33

We need to make sure that this is run on all the asset type files and any future migrations we do, i.e. Texts and Sources.

---

## Claude — 2026-10-09 15:33

Good — `mandala_file_hygiene` is already enabled in committed config, and its hook fires on every file-entity save, migrations included, so it already prevents *new* NFD filenames for Texts/Sources going forward. What's still missing: confirming today's asset-type coverage, and a standing check so a future migration's *existing* bad files (from D7 source) get caught, not just new uploads. Let me check the migration runbook docs.

---

## Claude — 2026-10-09 15:34

Confirmed — migrations use `entity:file` destination, so `mandala_file_hygiene`'s `hook_file_presave()` already fires during migration and normalizes new file entities going forward. Let's check the Kernel test to make sure migration-path coverage is actually asserted, not just assumed.

---

## Claude — 2026-10-09 15:35

Now let's rewrite the header to reflect it's a reusable, repeatable standing check across environments and migration tracks, not a dev-0-only one-off.

---

## Claude — 2026-10-09 15:35

Now let's add a "Filename normalization check" step to the migration-cycle runbook, since that's the canonical, per-site-replicated pattern doc.

---

## Claude — 2026-10-09 15:35

Now update the two deferred-note references to the renamed script.

---

## Claude — 2026-10-09 15:35

Good, those edits applied cleanly. Now let's also add a cross-reference in `production-migration-planning.md` to the new per-site runbook step, since the user wants Texts/Sources coverage explicitly, not just final prod cutover.

---

## Claude — 2026-10-09 15:36

Now let's verify everything, run session-close-check, and commit.

---

## Claude — 2026-10-09 15:36

Expected (uncommitted = this session's work). Let's stage and commit.

---

## Claude — 2026-10-09 15:36

Let me update local memory to reflect this follow-up.

---

## Claude — 2026-10-09 15:36

Done. Here's where things landed:

**Current state already covers "all asset types":** the script scans the entire `sites/default/files` tree recursively, not just transcripts — the 37 paths found earlier spanned the whole corpus (AV transcripts plus one top-level image), so no additional dev-0 work was needed there.

**For future migrations (Texts, Sources):** checked that `mandala_file_hygiene` already protects them — migrations create files through the same `entity:file` destination / `File::save()` path that uploads use, so its `hook_file_presave()` normalization fires automatically during any future migration run. This is a verification concern, not a gap, so I:

1. **Generalized the script** (`scripts/normalize-dev0-filenames-nfc.sh` → `scripts/normalize-unicode-filenames-nfc.sh`), parameterized by `REMOTE_HOST`/`FILES_DIR` so it can target dev-0, staging, or production without code changes.
2. **Added a "Filename normalization check" step** to `docs/planning/migration-cycle-runbook.md` — the pattern doc every site track replicates per ADR 009 — directing that it be run as a dry-run after every future site's import (Texts, Sources included), expecting zero affected paths; a nonzero result signals something bypassed the hygiene module rather than being routine.
3. **Cross-linked** this into `production-migration-planning.md` so it's clear the check applies at every migration landing, not just the final production cutover.

Committed and pushed (`7a281b9`).

---

## User — 2026-10-09 15:47

Did you do a full run on dev-0 or just a dry run?

---

## Claude — 2026-10-09 15:47

I did a full live execute run on dev-0, not just a dry run.

Sequence from last turn:
1. `./scripts/normalize-dev0-filenames-nfc.sh --dry-run` — found 37 affected paths.
2. `./scripts/normalize-dev0-filenames-nfc.sh --execute` — actually renamed all 37, live against dev-0's disk.
3. Re-ran `--dry-run` afterward to confirm — reported 0 remaining non-NFC paths.

So dev-0's existing Unicode filename backlog is actually fixed on disk right now, not just previewed. What's still dry-run-only going forward is the *standing check* I set up this turn for future Texts/Sources migrations and production cutover — those haven't run yet since those migrations don't exist yet.

---

## User — 2026-10-09 15:47

Also want to include staging along with production, just to make sure.

---

## Claude — 2026-10-09 15:47

Just to make sure I point this at the right thing — "staging" is ambiguous in this codebase right now. There's no D11 staging environment yet (confirmed in the 2026-10-06 session log: "No staging environment exists yet"). There are two things people sometimes call "staging":

---

## Claude — 2026-10-09 15:48

This is the right place to add it. Let's add a numbered prerequisite item here for the filename check once the D11 staging environment exists.

---

## Claude — 2026-10-09 15:48

Now update the runbook and production-migration-planning note so "production" references also mention staging.

---

## Claude — 2026-10-09 15:48

Now update `production-migration-planning.md`'s bullet to mention staging too, and update the script header comment list.

---

## Claude — 2026-10-09 15:48

Now update the script's header comment bullet list to mention staging too.

---

## Claude — 2026-10-09 15:48

Now commit and push.

---

## Claude — 2026-10-09 15:49

Done. Since no D11 staging environment exists yet, there was nothing to run live — I added it as prerequisite item 3 in `docs/deferred/staging-migration-execution-prerequisites.md`, and cross-referenced it from `migration-cycle-runbook.md`, `production-migration-planning.md`, and the script's own header, so whoever stands up staging later knows to run the same dry-run/execute check before trusting its files tree. Committed and pushed (`605050f`).

---

## User — 2026-10-09 15:50

So this is all on main now?

---

## Claude — 2026-10-09 15:50

Yes — working tree is clean, local `main` is at `605050f`, and `origin/main` is at the same commit. All three commits from this session are pushed and live on `main`:

- `df867ee` — ran the live rename on dev-0 (37 files), resolved the deferral
- `7a281b9` — generalized the script, added the standing per-migration check to the runbook
- `605050f` — extended that check to cover a future staging environment

---

## User — 2026-10-09 15:50

Let's close out the session — run session-close-check.sh

---

## Claude — 2026-10-09 15:50

This issue is affected since we added a new prerequisite item 3 today. Let's comment with the current state.

---

## Claude — 2026-10-09 15:50

This session's JSONL is `3159d5ab-f3a1-4060-aa90-d0fc23714584.jsonl`, matching the scratchpad path from the environment reminder.

---
