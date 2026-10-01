# Session Log: Spike 11 Corpus Audit Prototype And Data Model

**Date:** 2026-10-01  
**Participants:** Yuji Shinozaki, Claude Code  
**Outcome:** [Spike 11](../spikes/spike-11-av-transcript-replication.md) moved from Pending to Partial: corpus audit (D7 already stores parsed TCUs in the database, so migration can skip the XSLT pipeline), a working click-to-seek and highlight-on-play prototype on one real node, and a data-model evaluation (provisionally a dedicated transcript entity plus one row per TCU). Seven open questions recorded for Than, whose call the data decisions are. Also fixed `session-close-check.sh`, which false-FAILed on pushed feature branches. [PR #269](https://github.com/uvalib/mandala-navina/pull/269) and [PR #270](https://github.com/uvalib/mandala-navina/pull/270), both merged.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-01 15:46

let's look at spike 11

---

## Claude — 2026-10-01 15:46

I'll start with the session-start check from CLAUDE.md, then read the Spike 11 docs.

---

## Claude — 2026-10-01 15:47

Spike 11 hasn't been started. Its status is ○ Pending, and it's task T1 of [Sprint 4](docs/sprints/sprint-04-av-transcripts.md). Sprint 3 is now closed, so the dependency on real migrated AV nodes is met.

**Session-start check**
- **Git:** up to date.
- **Config drift:** local `config:status` shows `node.audio.default` and `node.video.default` as *Different*. That's the AV12 upload-widget config again. Last session's import was probably on another machine or DB. `ddev drush config:import` would clear it. I haven't run it.
- **dev-0:** unreachable (SSH timeout, likely VPN), so the count comparison was skipped.
- **Sync directory:** `drupal/config/sync` matches `origin/main`.

**What's already recorded in the spike doc** (read from the D7 code on 2026-09-04, not tested)
- **Scope:** only the D7 Drupal pipeline (`transcripts_ui`, `transcripts_apachesolr`, `transcripts_xslt`). The React app's own transcript viewer is out of scope.
- **Format:** transcripts are multi-tier linguistic annotation, not plain captions. The tiers are transcription, morpheme breakdown, gloss and free translation.
- **Input:** three source formats (Toolbox `.txt`, SRT, XML). A Java Saxon XSLT step converts them to time-coded units (TCUs) at runtime.
- **Editing:** there is a live editor UI (`transcripts_editor`).

**Central question (Work item 2):** does D11 have to reproduce the upload → XSLT → TCU pipeline as a live authoring workflow? Or is a one-time conversion at migration enough, with transcripts as migration-only content? That decision shapes the data model, and it probably needs David Germano or Than. The spike doc doesn't say who actually uses the editor today.

**Proposed order of work**
1. **Corpus audit (read-only):** count transcripts per format and sample real files. This is Work item 1. It needs the D7 production DB and files on rds-mysql8-staging, and Than's help for the real sample files.
2. **Authoring question:** find out from the D7 data whether anyone is uploading or editing transcripts recently. `transcripts_editor` activity and recent file timestamps would show it.
3. **Data model and search:** evaluate Paragraphs, a dedicated entity, or sidecar WebVTT. Check what the D7 Solr index actually contains.
4. **Prototype:** click-to-seek and highlight-on-play against a real migrated node.

Do you want to start with the corpus audit and the "is the editor used" check? Or start somewhere else? Nothing is staged on the local DDEV DB for transcripts yet. I'd also run the config import first.

---

## User — 2026-10-01 17:57

yes lets do a corpus audit

---

## Claude — 2026-10-01 17:57

`d7_av` is loaded locally, so the audit can run against real data. First I'll look at the transcript tables and fields.

---

## Claude — 2026-10-01 17:57

There is a `tcu` table, so the spike's note that transcript content lives only in files may be wrong. I'll profile these tables.

---

## User — 2026-10-01 17:58

I just went on VPN, so there may have been a network glitch

---

## Claude — 2026-10-01 17:59

With the VPN on, dev-0 is reachable and the check ran.

- **Counts:** everything matches except videos, 7,396 local against 7,398 on dev-0. The two extra nodes should be Than's 10 MB and 27 MB test uploads, which I'm inferring from the earlier log and haven't checked.
- **Identity:** the 25 sampled legacy-identity pairs have the same D11 node ids in both environments.
- **Config:** still the same two form displays, `node.audio.default` and `node.video.default`. That's the AV12 upload widget, and `ddev drush config:import` would clear it.

Neither difference affects this audit, which reads the `d7_av` source database. I haven't run the config import or rebased the DB.

## Audit findings so far

**1. The spike's premise about where transcripts live is wrong.** The spike doc says the transcript table is tracking metadata only and the content lives in uploaded files. The `tcu`, `tcu_tier` and `tcu_speaker` tables in the D7 database hold the content.

| Table | Rows |
|---|---|
| `tcu` (start and end time per unit) | 245,158 |
| `tcu_tier` (text per tier) | 355,536 |
| `tcu_speaker` (speaker per unit) | 142,445 |

D7 has already converted every source file into rows. D11 can migrate from the database directly, with no need to re-run Saxon XSLT or parse files.

**2. Volume.**
- 5,343 transcripts are tracked, one per node, with no duplicates. 5,362 distinct transcript ids have TCUs.
- 19 of those ids have TCUs but no tracking row. I haven't looked at them yet.
- Every tracked transcript has TCUs. The average is 46 per transcript and the maximum is 1,380.

**3. Source file formats are not what the spike assumed.**
- `.vtt`: 3,227 files
- `.xml`: 1,881
- `.txt` (Toolbox): 136
- `.srt`: 28

WebVTT is the largest format, and the spike doc doesn't mention it. Toolbox, which the spike treats as a headline format, is 136 files, about 2.5%.

**4. Tiers are language-coded, not the Toolbox tx/mb/ge/ft set.**
- Tibetan (`content_bod`): 173,764 tier values
- English: 71,873
- Dzongkha: 35,525
- Wylie: 30,497
- Nepali: 13,625
- Chinese: 5,191
- Smaller ones: Gyalrong, Tsum, Kham and others

The tiers are parallel-language text on the same time span. The `ts_content_gloss` tier has only 3,491 values, so interlinear gloss is a minor part of the data.

**5. Authoring looks mostly dormant.**
- TCUs were created 2015–2020, peaking at about 82,000 in 2018.
- Since 2021 there are only 1,088, 1,281, 1,365 and 222 rows per year.
- No TCU has ever been edited: `changed` never differs from `created`.

That points toward "migration-only" for the Work item 2 question. I haven't checked who made the 2021–2024 additions or whether the editor was used, so I wouldn't treat it as settled.

**6. Data quality flags.**
- 4,269 TCUs have an end time at or before the start time.
- The minimum start is -0.125.
- The maximum end is 81,807 seconds, which is 22 hours and looks like a bad value.

These need a closer look before we say timecodes migrate cleanly.

## Next
1. Quantify the data-quality flags and look at the 19 orphan transcript ids.
2. Check who created the 2021–2024 TCUs, as the dormancy evidence for the authoring question.
3. Check Unicode normalization (NFC/NFD) on the Tibetan tier text, tying back to Spike 4a.
4. Map `field_transcript` (5,380 rows) and the `transcript_input`, `proofed` and `timecoded` fields (2,482 each) to nodes. I don't know yet what the 2,482 subset means.
5. Check how the D7 Solr index is shaped, per TCU or per transcript.

Should I carry on through 1–3 now? I'd then write it up in the spike doc, and that includes correcting the "tracking metadata only" claim.

---

## User — 2026-10-01 19:50

yes proceed

---

## Claude — 2026-10-01 19:50

Authoring is nearly dormant: only 38 transcripts were created after 2021 and none after 2024. Next I'll check Unicode normalization and map the `field_transcript_*` fields.

---

## Claude — 2026-10-01 19:50

I'll record these findings in the spike doc, since it currently misstates where the content lives.

---

## Claude — 2026-10-01 19:51

The audit is done and written into the Spike 11 doc, with the README row updated. It's on branch `docs/spike-11-corpus-audit`, uncommitted. I haven't pushed or opened a PR. The session-close check's only failure is those uncommitted changes.

## Results

- **Content location:** D7 already stores the parsed transcripts in the database (`tcu`, `tcu_tier`, `tcu_speaker`). The spike doc was wrong to say content lives only in files. The D11 migration can read those tables directly. It doesn't need Saxon or a file parser.
- **Volume:** 5,343 transcripts, one per node (4,231 video, 1,112 audio). 5,380 nodes have a transcript file attached, but about 108 were never processed.
- **Formats:** VTT is the largest at 3,227 files, then XML at 1,881. Toolbox is only 136 (about 2.5%) and SRT 28.
- **Authoring:** only 38 transcripts were created after 2021, and the last was in 2024. No unit has ever been edited. The evidence leans toward migration-only, but it isn't settled and needs the AV staff's confirmation.
- **Timecodes (the main risk):** 4,269 units (about 1.7%) have an end at or before the start, across 679 transcripts.
  - 3,299 are zero-length, which may be deliberate point cues.
  - 970 have the end before the start.
  - 190 end after 4 hours, with a maximum of about 22 hours.
- **Unicode:** 933 of 35,525 Dzongkha values (2.6%) aren't NFC. Tibetan has 12 and Wylie has none. None are pure NFD.

## Caveats

- I read the 2026-09-01 dump in DDEV, not live D7 production.
- Whether zero-length units are real needs a check against actual source files.
- I didn't look at the D7 Solr index shape, the `transcripts_editor` feature surface, or the 19 orphan transcript ids.
- My "suspect-corrupt" check on the Tibetan text was too crude to trust, so I left it out of the doc.

## Open

- **Source files:** the audit only covers database rows. Doing the real-file sample needs Than to supply the files.
- **Authoring decision:** it's a question for the people who run AV. I'd ask Than and David Germano, since this is editorial.
- **Memory:** per the close ritual, I'd refresh the `project-adr-018-spike-11-scope-note` memory, because it repeats the wrong "files only" claim.

Do you want me to commit and open the PR, and refresh that memory? Or continue with the remaining audit items first, starting with the Solr index shape and the orphans?

---

## User — 2026-10-01 19:52

So what is the overall conclusion for the spike?

---

## Claude — 2026-10-01 19:52

The spike isn't concluded. The audit settles the data-source question and narrows the design space, but the main decisions are still open.

## What the audit settles

- **Migration source:** migrate from the D7 database tables (`tcu`, `tcu_tier`, `tcu_speaker`). The XSLT pipeline and file parsers aren't needed for existing content. Migration is then a data-mapping job of 245k rows, which is far smaller than the spike assumed.
- **Scope:** this is about 5,343 transcripts on about 5,300 nodes. The data is parallel-language text (Tibetan, English, Dzongkha, Wylie and others) on shared time spans.

## What it points to, but doesn't settle

- **Authoring:** the evidence leans toward migration-only. Authoring has been nearly dormant since 2021 and no unit has ever been edited. If AV staff confirm, T6 (rebuild the editor) can be dropped and Sprint 4 shrinks a lot. The fail-criteria row says to confirm that with AV stakeholders, so this is provisional.
- **Data model:** I lean toward a dedicated transcript structure rather than WebVTT sidecar files. Sidecar files would lose the multi-tier, multi-language structure, and VTT is only the upload format, not the stored one. Paragraphs would work but 245k rows is heavy. I haven't evaluated any of these options, so this is a hunch, not a finding.

## What's still unproven

- **Data model:** the evaluation in Work item 4.
- **Display:** the click-to-seek and highlight-on-play prototype, which the pass criteria require.
- **Search:** how D7 indexes (per unit or per transcript) and what D11 does.
- **Timecode fidelity:** whether the 4,269 odd units are real corruption. That is the main migration risk, and the spike's own fail table says to escalate fidelity risks to David Germano.
- **Unicode:** a round-trip check on the 2.6% non-NFC Dzongkha rows.

## Rough go/no-go

Go. Nothing found so far blocks replicating the transcripts on D11, and the migration looks easier than expected. I wouldn't mark it Proven until the prototype and the search strategy exist. The two questions to put to people are whether AV staff still need to author transcripts, and what the zero-length and inverted timecodes mean.

The next step with the most value is the prototype against a real migrated node. Do you want me to start on that, or commit the audit first?

---

## User — 2026-10-01 19:53

commit and open the PR

---

## Claude — 2026-10-01 19:53

The PR is open: https://github.com/uvalib/mandala-navina/pull/269. It's one commit on `docs/spike-11-corpus-audit` and touches only `docs/`, so merging won't trigger a deploy.

I haven't refreshed the `project-adr-018-spike-11-scope-note` memory, which still says transcript content lives only in files. I haven't run the local config import either, so `node.audio.default` and `node.video.default` still show as different locally.

Do you want me to update that memory and move on to the prototype?

---

## User — 2026-10-01 19:53

yes, update the memory and start the prototype

---

## Claude — 2026-10-01 19:54

Memory is updated and local config is back in sync. Next I'll look at how the player is embedded, which decides how the prototype hooks into playback.

---

## Claude — 2026-10-01 19:54

I'll pick a good test transcript: Tibetan plus English tiers with clean timecodes, and a migrated D11 node with a Kaltura entry.

---

## Claude — 2026-10-01 19:54

I'll use D7 nid 218. It has 41 units across Tibetan, Wylie and English, with clean timecodes over 316 seconds, and it maps to D11 node 115564. Now the throwaway prototype module.

---

## Claude — 2026-10-01 19:55

The module is enabled. Enabling it changes the local `core.extension` config, so I'll uninstall it when I'm done and not export. First a quick server-side check, then the real browser test.

---

## Claude — 2026-10-01 19:55

That's the known cert-trust quirk on `:8443`, so I'll use `:8080` as my notes say.

---

## Claude — 2026-10-01 19:55

The player loads and all 41 TCUs render, with the Tibetan, Wylie and English tiers shown. Now the sync test: click the `0:16` timestamp, which should seek the player and highlight that row.

---

## Claude — 2026-10-01 19:56

The highlight is correct for 0:29, because the next row starts at 0:34. The player paused, so I haven't seen it advance yet. I'll press play and wait past 0:34.

---

## Claude — 2026-10-01 19:56

The config is clean and the prototype is untracked. I'll document the result in the spike doc and add the module as a spike artifact, like `spike_footnotes_demo`.

---

## Claude — 2026-10-01 19:57

The sync prototype works on one real node. I added it to PR #269 (https://github.com/uvalib/mandala-navina/pull/269) and retitled the PR "Spike 11: corpus audit and sync prototype".

The PR now touches `drupal/**`, so merging will trigger a dev-0 deploy. That's harmless, because the module isn't enabled in `config/sync`, but it's a change from the docs-only PR I described earlier.

## What I built
A throwaway module, `spike_transcript_demo`, at `/spike/transcript-demo/{legacy_nid}`.
- **Node lookup:** it finds the migrated D11 node by the legacy composite key, as the CLAUDE.md convention requires.
- **Player:** it uses the existing `mandala_kaltura` preset.
- **Data:** it reads the TCUs straight from the D7 tables, so no file parsing is involved.

## Results
I tested it in Chrome against the real Kaltura entry, using D7 nid 218 (41 units, Tibetan, Wylie and English tiers).
- All 41 units render, and the Tibetan script displays correctly.
- Clicking the 0:16 timestamp sent the player to 0:16, started playback and highlighted that row.
- After I seeked to 0:31 through the player API, the highlight moved from the 0:16 row to the 0:34 row about 3.75 s later (expected 3 s), with no further input.

## Caveats
- **Coverage:** this was one node, one player preset and one browser.
- **Playback:** the player kept pausing by itself during testing, probably because the automated tab wasn't in the foreground. I didn't investigate, and I drove part of the playback through the player API.
- **Untested:**
  - Search and deep-linking to a timecode.
  - Nodes with speakers.
  - The 1,380-unit maximum transcript.
  - Nodes with the bad timecodes.
  - Whether a real user in a foreground tab sees the same behavior.

## Local state
I uninstalled the module locally, so `config:status` is clean. The earlier local config drift is also cleared, because I ran the import. dev-0's two extra videos are still the test uploads I inferred earlier.

The spike has met two of its pass criteria so far: a documented D7 model, and a minimal sync prototype. Still open:
- the data-model recommendation
- the search strategy
- the migration plan
- the Unicode round-trip test
- the authoring-dormancy confirmation from AV staff

Do you want me to take on the data-model evaluation next? Or look at search, starting with how the D7 Solr index is shaped?

---

## User — 2026-10-01 20:41

please check in findings in a PR, then embark on the data-model evaluation

---

## Claude — 2026-10-01 20:41

The findings are already in PR #269. I'll confirm it's current and check what's in the repo that bears on the data model.

---

## Claude — 2026-10-01 20:42

The whole corpus is only about 58 MB of text. Next, how D7 indexed TCUs in Solr and how the code treats `trid`, since that shapes the search design.

---

## Claude — 2026-10-01 20:42

D7 renders the scroller by querying Solr (sorted by `fts_start`), not by reading the DB tables. That makes the index shape matter. I'll read how it builds documents.

---

## Claude — 2026-10-01 20:43

The script is outside the container's mount, so I'll run it inline instead.

---

## Claude — 2026-10-01 20:44

The data-model evaluation is done and written into the Spike 11 doc. It's in PR #269, now retitled "Spike 11: corpus audit, sync prototype, data-model evaluation". The earlier findings were already in that PR, so I added to it rather than opening a second one.

## Recommendation (provisional)
Use a dedicated transcript entity with one row per time-coded unit, which is how D7 itself modeled it.

| Option | Verdict | Main reason |
|---|---|---|
| Paragraphs, one per unit | Reject | At least 245k entities, or about 740k if tiers are nested. Revision copying and a node form with up to 1,380 widgets are the problems. |
| Dedicated entity plus one row per unit | Recommend | Near 1:1 migration that can be checked by row count. It gives a stable per-unit id for deep links and leaves editing possible. |
| One row per transcript with a JSON blob | Viable alternative | Simplest migration, but no per-unit id or editing. It's cheaper if editing is confirmed out of scope. |
| Sidecar WebVTT files | Reject as the store | WebVTT can't represent the 4,269 zero-length or inverted timecodes. It can still be generated later from the model. |
| Kaltura captions | Reject, reasoned only | I didn't look for existing transcripts inside Kaltura. |

## Where my first assumption was wrong
I expected Paragraphs to be slow. They weren't: loading 1,380 of them took 189 ms against 29 ms for the equivalent SQL query. So the doc rejects Paragraphs on entity count, revision copying and editing, not on speed.

## Decisions I made in the sketch
- **Timecodes:** the 4,269 bad timecodes are carried as found, with a computed flag. Whether to correct them is David Germano's call.
- **Precision:** D7 stores times as single-precision floats, so I suggested `DECIMAL(10,3)`. My check for sub-millisecond precision was inconclusive, so that detail isn't verified.

## Caveats
- **D7 search index:** I read from the module code that D7 indexes one Solr document per unit into the AV site's own core. I haven't confirmed that against the live core.
- **Prototype:** it read the D7 tables directly. It hasn't run against the proposed D11 entities.

## Open before this is decided
- **Authoring:** it decides between the one-row-per-unit design and the JSON-blob alternative.
- **Search:** where the index lives and whether it's per unit or per transcript. D11 has flat kmassets documents and the visibility proxy, so I'd want Yuji's input.
- **Review:** the PR has had no review from Than or Yuji yet, and the recommendation is mine alone.

Do you want me to take on the search strategy next? Or hold until Than and Yuji weigh in on the model?

---

## User — 2026-10-01 20:46

Its actually Than's call about the data.   ok right all this up and I will address the questions in the morning.   Let's run the session closing protocol now

---

## Claude — 2026-10-01 20:46

First I'll write up the questions for Than in the spike doc, since it's his call. Then I'll run the close checks.

---

## Claude — 2026-10-01 20:46

The check says local `main` is 4 commits ahead of `origin/main`. That shouldn't be so, because I worked on a branch, so I'll look at it before doing anything.

---

## Claude — 2026-10-01 20:47

The script flags a false positive again for the same reason. Now the memory refresh: a new pick-up block at the top of the state snapshot, and an updated index line.

---

## Claude — 2026-10-01 20:47

That index change is mine from the refresh. One last memory item: the correction that data decisions are Than's belongs with the existing ownership note.

---

## Claude — 2026-10-01 20:47

The session is closed. PR #269 is open with 5 commits, mergeable and not yet reviewed. The working tree is clean and everything is pushed.

## What's recorded
- **Spike 11 doc:** I added a "Questions for Than" section with seven items, framed as his decisions. My data-model recommendation is labelled as a provisional reading, not a decision.
  - Is anyone still authoring transcripts?
  - One row per unit or one JSON row per transcript?
  - What to do with the 4,269 bad timecodes?
  - Real source files for each format.
  - The roughly 108 unprocessed files and 19 orphan ids.
  - Search index shape (Yuji's input too).
  - Whether the React viewer stays out of scope.
- **Session log:** `docs/session-logs/2026-10-01-spike-11-corpus-audit-prototype-and-data-model.md`. I used the script, since nothing in this session touched the privately-tracked material.
- **Memory:**
  - `project-mandala-state` has a new pick-up block.
  - The Spike 11 scope note is corrected.
  - The content-ownership note now says data decisions are Than's.

## Checklist status
- **Docs indexes:** pass.
- **Working tree:** clean.
- **Config:** local `config:status` is clean. The prototype module is uninstalled locally.
- **`session-close-check.sh`:** it reports "local main is 5 commits ahead", which is a false positive. It compares `HEAD` to `origin/main`, so it fails on any feature branch. Local `main` is not ahead. I didn't fix the script. Do you want a small PR for it?
- **PR #269:** it touches `drupal/**`, so merging triggers a dev-0 deploy. That's harmless, because the module isn't enabled anywhere.

## For the morning
1. Walk through the seven questions with Than.
2. Review and merge #269, or hold it until Than has answered.
3. If the answers are in, the next work is the search strategy and the migration plan. The remaining unaudited items are the live Solr index shape, the real source files, and the Unicode round-trip.

---

## User — 2026-10-01 20:48

yes, fix the close-check script in a small PR

---

## Claude — 2026-10-01 20:48

I'll test the cases that matter: a pushed branch ahead of `main`, which was the false positive; an unpushed commit; and a commit made directly on local `main`.

---

## Claude — 2026-10-01 20:48

Cases A, B, C and E behave correctly. Case D ran on `main`, which still has the old script, so it didn't exercise my new `main` check. I'll redo it without leaving the branch.

---

## Claude — 2026-10-01 20:48

All five cases now behave correctly, and local `main` is restored to match `origin/main`. Opening the PR:

---

## Claude — 2026-10-01 20:49

PR #270 is open and fixes the script: https://github.com/uvalib/mandala-navina/pull/270.

**The bug:** it compared `HEAD` to `origin/main` and called the result "local main". Every pushed feature branch printed a false FAIL.

**The fix:** two separate checks.
- **Current branch against its own upstream:** FAIL if it's ahead. If it has no upstream, FAIL only when it holds commits beyond `origin/main`.
- **Local `main` against `origin/main`:** this catches commits made directly on `main`. It also warns on a detached HEAD.

**Tested:**
- A pushed feature branch ahead of main now passes. That was the old false positive.
- An unpushed commit on a pushed branch fails.
- A commit made on local `main` while on a feature branch fails.
- A clean branch passes.
- A new local branch that tracks only `origin/main` fails correctly.

I left `main` restored to `origin/main`, and I'm back on the Spike 11 branch with a clean tree. The change is scripts only, so merging triggers no deploy. I didn't touch the checklist in CLAUDE.md. It says "uncommitted or unpushed work", which the script now implements more accurately.

Both PRs are open and unreviewed: #269 (Spike 11) and #270 (this fix).

---

## User — 2026-10-01 20:49

merge PR 270

---

## Claude — 2026-10-01 20:49

PR #270 is merged into `main` as `0977c5e`, after its one check passed. It only touched `scripts/`, so no deploy was triggered.

The only open PR is #269, the Spike 11 work, which is still waiting on review. My local `main` is now behind `origin/main`, so I'll run `git pull --ff-only` on it at the start of the next session.

---

## User — 2026-10-01 20:49

merge PR 269

---

## Claude — 2026-10-01 20:49

Merging #269 touches `drupal/**`, so it will trigger a dev-0 deploy through the webhook. I'll merge it, then watch that execution without starting one by hand.

---

## Claude — 2026-10-01 20:49

The webhook may take a few seconds to fire, so I'll just snapshot the status now.

---

## Claude — 2026-10-01 20:50

The webhook fired execution `b937d83a` for the #269 merge commit. I'll watch that specific execution in the background. The script is bounded, as my notes require.

---

## Claude — 2026-10-01 20:50

PR #269 is merged and the Spike 11 work is now on `main`. The merge commit is `74fab8810bbe` (merged 2026-10-01T20:49:40Z). Its one check passed.

The webhook started a drupal deploy for it, execution `b937d83a`. It's building now. I didn't start it by hand, and I'm watching that specific execution in the background. I'll report when it finishes.

The module `spike_transcript_demo` ships in the image but isn't enabled anywhere. The deploy should be a no-op apart from restarting the container. After it finishes, I'd check that dev-0's `config:status` shows nothing new. The only drift there should still be the known `simplesamlphp_auth.settings` difference.

Local `main` is behind `origin/main` by two merges (#270 and #269). Run `git pull --ff-only` on it at the start of the next session.

---

## User — 2026-10-01 20:51

check the deploy status, but you can also do the session logs now

---
