# Session Log: Local content drift vs. dev-0 made informational, resync confirmed pull-only

**Date:** 2026-10-13  
**Participants:** Than Grove, Claude Code (session also informed an in-person/verbal group decision with Yuji Shinozaki and Xiaoming Wang)  
**Outcome:** PR #324 merged. Ran the session-start ritual (pulled in Yuji's 2026-10-12 legacy-redirect work, ADR 016 now Accepted, config re-synced locally). Investigated the local-vs-dev-0 content drift flagged two sessions earlier and found it was three distinct things, not one blob: (1) legitimate issue #289 orphaned-content sweep work-in-progress still needing to be redone on dev-0, (2) unexplained test groups/content ("Supernovae", "Test of a Test Collection", a new video node) created locally by the shared `ShantiAdmin` account and by David Germano's account — flagged for the group to recognize or clean up, not acted on unilaterally, and (3) a minor side effect of (2). Brought this to Than/Yuji/Xiaoming's group session, who made two decisions: individual local DBs are expected to diverge from dev-0 in content and that's normal (not drift to flag or clean up on its own) — config parity remains a hard gate; and the existing `update-db-from-remote.sh dev` resync script is confirmed **pull-only** (dev-0 → local) with no push-to-dev-0 path, by design. Updated `CLAUDE.md`, `docs/dev-notes/howto-local-dev.md`, and downgraded `scripts/session-start-check.sh` step 3b from a FAIL to an informational note to match. Shipped as PR #324 (2 commits), reviewed verbally by Yuji/Xiaoming, merged same day.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-13 13:48

ok do session start ritual

---

## Claude — 2026-10-13 13:48

I'll run the session-start check script first.

---

## Claude — 2026-10-13 13:50

The git pull brought in new commits (legacy redirect subscriber work, merged via PR). The config drift is expected — new config landed in `config/sync` that the local DB hasn't imported yet. Let me import it.

---

## Claude — 2026-10-13 13:51

config:import uninstalled `stage_file_proxy` as a side effect (it's not supposed to be in config/sync). Let me re-enable it as the dev convenience module it's meant to be.

---

## Claude — 2026-10-13 13:51

That's the expected state per CLAUDE.md (stage_file_proxy enabled locally only, discounted by the drift check). Let me re-run the full session-start-check to confirm it now passes (minus dev-0, which is unreachable — likely VPN is off).

---

## Claude — 2026-10-13 13:51

Local checks are clean now (VPN appears down, so dev-0 comparison was skipped via `--local-only`). Let me read the orientation docs.

---

## Claude — 2026-10-13 13:52

Session start is complete. Summary:

**Pulled in:** 5 new branches + merged work from Yuji's 2026-10-12 session — ADR 016 now **Accepted**, `mandala_legacy_redirect` module (host-aware legacy URL redirects, PR #319) deployed/verified on dev-0, and issue #291 (trid_i docValues) resolved into new issue #321.

**Fixed locally:** `config:import` brought local DB in sync with the new config (installed `mandala_legacy_redirect`, which also uninstalled `stage_file_proxy` as a side effect) — re-enabled `stage_file_proxy` per the documented local-dev convention. `config:status` now clean.

**Couldn't reach dev-0** (VPN appears off) — skipped the DB/identity parity check via `--local-only`. Local counts: 1543 users, 415 groups, 111340 images, 7397 videos, 4187 audio.

**Open deferred issues** — 9 open, only #300 (periodic review of doc-only deferrals) is unassigned/pickup-able; rest have owners or are blocked on triggers.

**Handoff from 2026-10-12 session log**, next steps in priority order:
1. Contact Dave about how Solr config deploys (#321)
2. Open PR against `solr-shanti-configsets` to add `docValues="true"` for `trid_i`
3. Decide on #290(a) sequencing (trid_i writer vs. T2 transcript entity)
4. Then stand up transcript core on dev Solr, verify #315 (solr-proxy join filter, draft PR) end-to-end
5. #316 leftovers: test subscriber's group lookup, plan public-edge Host recheck at cutover
6. Optional: rebase local DB on dev-0 once VPN's back

What would you like to work on? If VPN is down for you too, I can still make progress on #321's docs/PR prep or review the #316 group-lookup test while we wait.

---

## User — 2026-10-13 13:52

ok on vpn now

---

## Claude — 2026-10-13 13:53

VPN's up now — let me re-run the dev-0 comparison to complete the session-start check properly.

---

## Claude — 2026-10-13 13:54

VPN's up, re-ran the full check. One FAIL, but it matches something already noted in yesterday's session log:

**3b (local vs dev-0):** local is *ahead* of dev-0 by 2 groups, 2 group_relationships, and 1 video — not behind. The 2026-10-12 session log already flagged this exact gap ("probably the 2026-10-09 group-edit work... Not rebased"), so this isn't new drift, just still-unresolved from two sessions ago. Since local is ahead, `update-db-from-remote.sh dev` (which rebases local onto dev-0) would be the wrong direction here and isn't appropriate.

Everything else passes: git synced, config:status clean, config/sync matches origin, and the legacy-id spot-check (25 samples) matches between environments.

What would you like to work on? Based on the handoff, next up is either contacting Dave about Solr config deployment (#321) or opening the `solr-shanti-configsets` PR for `trid_i` docValues — let me know which, or if you want to dig into the local/dev-0 group discrepancy first.

---

## User — 2026-10-13 13:59

We are about to have a group session with Xiaoming, Yuji, and I. What should we work on together?

---

## Claude — 2026-10-13 13:59

Top candidate: **decide #290(a)** — whether to build the `trid_i` writer against the D7 mapping now or wait for the T2 transcript entity first. It's explicitly flagged as a judgment call in yesterday's handoff, it's blocking both the AV transcript spike and the draft PR #315 (solr-proxy join filter), and it needs input from whoever's building T2 (Yuji) plus whoever understands the writer's data shape — a live group call beats someone guessing solo. Tradeoff: building the writer first unblocks #315 sooner but risks rework if T2's node-to-trid source doesn't match what you assume now; waiting for T2 is slower but builds the writer against a settled interface.

Secondary, cheaper to also fold in: the unresolved local-vs-dev-0 drift (2 groups / 2 group_relationships / 1 video, flagged two sessions ago and still not rebased) — worth 5 minutes to confirm it's still just the known 2026-10-09 group-edit gap and decide whether to finally sync it, since it's been dangling.

Want me to pull up the relevant spike/sprint docs (spike-11, sprint-04) before the call so you walk in with the tradeoffs in front of you?

---

## User — 2026-10-13 14:19

let's talk about the unresolved local-vs-dev-0 drift

---

## Claude — 2026-10-13 14:19

Let me dig into the actual local-only records so we walk into the session with specifics, not just counts.

---

## Claude — 2026-10-13 14:21

Dug into what's actually different — it's not one blob of drift, it's three distinct things:

**1. Real work-in-progress for issue #289 (orphaned content sweep)** — groups 415/417/418 ("Orphaned Content: AV/Texts/Sources", ~72 relationships total) match exactly what the deferred note `orphaned-content-temp-group-on-migration.md` describes: you ran the AV sweep locally per the documented process, and the note itself says *"Still needed: run the sweep on dev-0 (its own review group(s) must be created there first)."* This isn't drift to fix by syncing — the workflow is dev-0-is-canonical/laptops-pull, so there's no "push" path. The real action item is: recreate the holding groups on dev-0 by hand and re-run the sweep there, same as you did locally. That's a legitimate agenda item — worth confirming with Yuji/Xiaoming that this is still Than's to do and get it scheduled.

**2. Something odd: groups 419 "Supernovae" and 420 "Test of a Test Collection"**, plus a new unpublished video node (115529, "Song 8: Tibet University Nangma Group") — all created locally on 2026-10-08/09 by uid 1 (the shared `ShantiAdmin` account) and uid 4, which is **David Germano's account** (`dfg9w`). Timing lines up: test group created 09:18, Than added as a member, then David's new video node created 09:32 and related into the real "Tibetan and Himalayan Library" collection (gid 172). This looks like David was exploring the Group/content UI locally, not a mistake — but it's worth asking the group directly whether anyone recognizes this, since otherwise it's orphaned test content sitting in a shared local DB with no issue tracking it.

**3. One extra video relationship** (node 115528, old 2011 content) tied to the "Test of a Test Collection" group — just a side effect of #2's testing, not separate.

For the meeting: I'd frame it as two items — (a) schedule the dev-0 recreation of the #289 sweep, and (b) ask whether anyone knows about David's test content so you can decide to keep, document, or clear it before it muddies the next drift check.

---

## User — 2026-10-13 14:54

We need to make a principle that people's individual local hosts can have differences in data from Dev-0 and that this is ok because these users will be testing the site locally and need to create content to do that. So there is no need to flag a difference in data between localhost and dev-0 unless it creates known problems for development. However, we also need a mechanism whereby a individual user can quickly resync their data to the dev-0 site, when they want to.

---

## Claude — 2026-10-13 14:54

This matches the existing `update-db-from-remote.sh dev` resync script — good, we don't need to build that mechanism, just formalize and surface it. Let me look at where this policy needs to land: the check script's step 3b logic and the CLAUDE.md checklist text.

---

## Claude — 2026-10-13 14:54

Now let me look at the relevant script section and script header in full to make the edit correctly.

---

## Claude — 2026-10-13 14:55

This is the right home for the policy — a "Local content drift" section plus a pointer to the existing resync script. Let's also check `rituals-session-workflow.md` since it likely mirrors CLAUDE.md's checklist.

---

## Claude — 2026-10-13 14:55

Now let me make the edits — CLAUDE.md first.

---

## Claude — 2026-10-13 14:55

Now update the script's step 3b to stop failing on content drift, and the top-of-file comment.

---

## Claude — 2026-10-13 14:56

Now change the actual `fail` call to a non-gating note when counts differ:

---

## Claude — 2026-10-13 14:56

Now let's verify the script still runs correctly after edits.

---

## Claude — 2026-10-13 14:57

That FAIL is expected and unrelated — it's just because I have uncommitted changes on this feature branch. The 3b logic itself is confirmed working as informational-only (no FAIL from content drift now). Let me commit this work.

---

## Claude — 2026-10-13 14:57

Committed to the `docs/local-dev-drift-policy` branch. Want me to push it and open a PR now, or hold it for review in your group session first?

---

## User — 2026-10-13 14:58

Is there documentation on how to sync one's localhost data to dev-0?

---

## Claude — 2026-10-13 14:59

Yes and no — there's solid documentation for one direction, but not the other, and it's worth being precise about which:

**Pull (dev-0 → local): documented.** `./scripts/update-db-from-remote.sh dev` is the script, and it's now referenced in three places:
- `CLAUDE.md` (Session startup, step 3)
- `docs/dev-notes/howto-local-dev.md` (the section I just added, "Local content vs. dev-0: divergence is expected")
- The script's own header comment

This dumps dev-0's DB, imports it locally, and reasserts committed config on top — so it makes **local match dev-0**, discarding local-only content in the process.

**Push (local → dev-0): not documented, and I don't think it exists.** I grepped the repo and found nothing — no script, no how-to. This matches the team's standing workflow decision (`docs/deferred/d11-dev-database-bootstrap-and-migration-source.md`, 2026-07-16): *dev-0 is the canonical source, laptops pull from it, content comes from migration* — a deliberately one-directional model. There's no sanctioned path for a laptop's local-only content to reach dev-0 as data. That's exactly why the orphaned-content sweep groups we found earlier (#289) have to be *manually recreated and re-run on dev-0 itself*, rather than exported from local and pushed up.

So if "resync to dev-0" in your new principle means *pull dev-0's state down*, that's already built and now documented. If it means *push local content up*, that's a real gap — nothing exists for it today, and building it would cut against the one-directional model the team already chose. Worth raising in your group session: which direction does the mechanism actually need to support?

---

## User — 2026-10-13 15:02

yes dev-0 is canonical source. For now unless we explicitly say otherwise, we do not want push a local db up to dev-0.

---

## Claude — 2026-10-13 15:02

Let me make that explicit in the docs so it's not ambiguous for the next person reading "resync."

---

## Claude — 2026-10-13 15:02

Also tightening the same point in CLAUDE.md's checklist, where "resync mechanism" is introduced:

---

## Claude — 2026-10-13 15:02

Now let me save this as a memory and commit the doc update.

---

## Claude — 2026-10-13 15:03

I'll update the existing dev-db-workflow memory with this confirmed policy rather than creating a duplicate.

---

## Claude — 2026-10-13 15:03

Confirmed and documented. Summary of where this landed:

- **CLAUDE.md** and **`docs/dev-notes/howto-local-dev.md`** now state explicitly: `update-db-from-remote.sh dev` is pull-only (dev-0 → local), there's deliberately no push path, and local content built that dev-0 needs (like the #289 orphaned-content sweep) gets redone there directly.
- **`scripts/session-start-check.sh`** step 3b downgraded from FAIL to informational-only.
- All committed on branch `docs/local-dev-drift-policy` (not yet pushed/PR'd).
- My memory updated to reflect this as a confirmed team decision for future sessions.

Want me to push the branch and open a PR now, or hold it until after today's group session in case Xiaoming/Yuji want changes?

---

## User — 2026-10-13 15:24

push it and open the PR

---

## Claude — 2026-10-13 15:24

Pushed and opened: **PR #324** — https://github.com/uvalib/mandala-navina/pull/324

Ready for Xiaoming and Yuji to review in today's session.

---

## User — 2026-10-13 15:24

ok. I will let them know. Yuji is driving this session.

---

## Claude — 2026-10-13 15:25

Sounds good. PR #324 is up with the three docs changes (CLAUDE.md, session-start-check.sh, howto-local-dev.md) for Yuji to review/merge when he picks up driving. Let me know if you want me to do anything else before you hand off.

---

## User — 2026-10-13 15:25

switch back to main

---

## Claude — 2026-10-13 15:25

That's expected — these three files are just reverting to `main`'s version (pre-PR #324) now that I've switched off the `docs/local-dev-drift-policy` branch; the changes live in that branch/PR, not lost. Now on `main`, clean and up to date.

---

## User — 2026-10-13 18:16

check PR #324 status

---

## Claude — 2026-10-13 18:16

**PR #324** is still **open**, no reviews yet.

- Mergeable: clean, no conflicts with `main`
- CI: GitGuardian security check passed ✓ (only check configured, since this is docs/scripts-only — matches the path-filtered pipeline, no Drupal deploy triggered)
- 2 commits, both present as expected

Just waiting on Yuji/Xiaoming's review.

---

## User — 2026-10-13 18:17

They verbally approved. Merge it please and pull main

---

## Claude — 2026-10-13 18:17

PR #324 merged and squashed into `main`, remote branch deleted, local `main` fast-forwarded. Also picked up a new branch on the remote — `docs/321-kmassets-schema-deploy-runbook` — someone else's work in progress, not pulled in (just visible as a ref), likely Yuji or Xiaoming working on issue #321 from the handoff list.

---

## User — 2026-10-13 18:18

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-10-13 18:18

<command-name>/exit</command-name>
            <command-message>exit</command-message>
            <command-args></command-args>

---

## User — 2026-10-13 18:18

<local-command-stdout>Goodbye!</local-command-stdout>

---
