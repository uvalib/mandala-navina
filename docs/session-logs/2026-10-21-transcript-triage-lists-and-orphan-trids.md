# Session Log: Transcript Triage Lists And Orphan Trids

**Date:** 2026-10-21  
**Participants:** Than Grove, Claude Code (group session; Yuji Shinozaki drove his own Claude, with Xiaoming Wang)  
**Outcome:** Transcripts sorted into review, repair, needs-timecodes and OK lists; new repair rules; orphan trids matched to files. See [transcript-bad-timecodes-investigate-and-fix](../deferred/transcript-bad-timecodes-investigate-and-fix.md) and [transcript-orphan-trids-keep-for-review](../deferred/transcript-orphan-trids-keep-for-review.md).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-21 13:03

do session start ritual. Starting session with Yuji and Xiaoming. Not sure who is driving. I know I have a private branch going for the transcritpt review.

---

## Claude — 2026-10-21 13:05

Session start is done. Everything is clean except the dev-0 comparisons, which I couldn't run.

**Git**
- You're on `docs/transcript-timecode-last-unit-duration`, which is clean and in sync with its remote. It's 2 commits ahead of `main` (`9aed51c`, `35ab52f`), and no PR is open for it. You said last night not to open one.
- `main` has moved on since you branched. Yuji's T2 work landed as PRs #359, #360 and #361 (soft-delete field, node-delete cascade, orphan manager with `av-transcripts` drush commands). Your branch doesn't have these yet.
- Yuji's PR #362 (`test(t2): collection delete leaves transcripts alone`) is open and mergeable.

**Config and DB**
- `config:status` is clean apart from the expected local-only `stage_file_proxy`. `drupal/config/sync` matches `origin/main`.
- Both dev-0 checks (content counts and node-id spot-check) were skipped because the SSH connection timed out. Either VPN is off or the host is unreachable. The content check is informational only. The config check ran locally and was clean.

**Deferral issues**
- **Assigned to you:**
  - #353 (transcript bad timecodes), which is your branch's topic.
  - #292 (AV field audit).
  - #289 (orphan review-group sweep).
- **Unassigned:**
  - #357 (transcript orphans: admin screen, re-attach, editor access) is newly filed and relates to Yuji's T2 work.
  - #336 (solr-proxy on an end-of-life base image).
  - #300 (periodic review of doc-only deferrals).

**Latest session log (2026-10-21)** closed out the transcript timecode triage and repair plan. D7's player ignores placeholder cues, because it only highlights or seeks when `begin < now < end`. So the plan leaves placeholders flagged and unrepaired, and the T6 viewer decides how to treat them. Your memory also notes that 97 anomalous files and the backups question are still open.

I haven't read the full contents of the spikes and deferred READMEs. That's the orientation reading the checklist leaves to judgment, and it's quick if you want it.

Two things to settle with Yuji and Xiaoming:
1. **Who's driving.** If it's Yuji, your branch can stay private. If it's you, I'd rebase or merge `main` into your branch first, because it's now behind Yuji's T2 work.
2. **Whether to get on VPN.** If you want the dev-0 comparison, I can rerun the check.

---

## User — 2026-10-21 13:11

On VPN now

---

## Claude — 2026-10-21 13:11

With the VPN on, dev-0 is reachable and every check passes.

- **Config:** local `config:status` is clean, apart from the expected `stage_file_proxy` difference. `drupal/config/sync` matches `origin/main`.
- **Content counts:** local has 1 more group, 7 more group relationships and 1 more video node than dev-0. That's small, informational and non-blocking. It's most likely scratch test content, so there's nothing to chase.
- **Node ids:** all 25 sampled legacy-identity pairs have the same D11 nid locally and on dev-0, so there's no id drift.

Session start is complete. The open question is who's driving. If you are, I'd bring `main` into your transcript branch first, since it now has Yuji's T2 work (#359–#361).

---

## User — 2026-10-21 13:11

also Yuji is checking in some code

---

## Claude — 2026-10-21 13:11

Noted. When Yuji's code lands on `main`, tell me and I'll fetch and show you what changed. If it touches the transcript or T2 code, I'll check whether it overlaps with your timecode branch.

Your branch only has docs changes, so a merge or rebase onto `main` shouldn't conflict. I'll hold off on syncing until his push is in, so we only do it once.

---

## User — 2026-10-21 13:16

pull main into my branch

---

## Claude — 2026-10-21 13:16

I merged `origin/main` into your branch with no conflicts. The branch is now 9 commits ahead of its remote and I haven't pushed it.

The merge brought in Yuji's T2 work, PRs #359–#362. The changes are in `mandala_av_transcripts`:
- **New code:** the soft-delete field, the node-delete cascade, `OrphanManager` and its drush commands, and `TranscriptLifecycle`.
- **Edited code:** `TranscriptReader.php` changed by 13 lines. That's the file your timecode repair plan reads from, so look at it before building on it.
- **Tests:** new kernel tests for the cascade, orphan manager and soft delete.
- **Docs:** a new session log, `2026-10-20-t2-soft-delete-cascade-and-orphan-manager.md`.

The merge adds new schema fields. Run `ddev drush updb` locally before testing transcript code. If `config:status` shows differences afterward, that explains them.

I can push the branch, or leave it local, which fits your private-branch plan.

---

## User — 2026-10-21 13:17

did you pull latest on main? I think Yuji's new code is up there.

---

## Claude — 2026-10-21 13:17

I just fetched again and `origin/main` has nothing newer than what I merged. Your branch is 0 commits behind it.

The latest commit on `main` is Yuji's merge of PR #362 (`196cc80`), made 6 minutes ago. That's the newest thing there. If that's the code he meant, it's already in your branch.

If he's still checking in more, it hasn't been pushed to `main` yet. It may be on a feature branch or still waiting on a PR. I can list open PRs and remote branches to look for it, or you can ask him which branch it's on.

---

## User — 2026-10-21 13:18

go ahead and push my branch but do not PR or merge it.

---

## Claude — 2026-10-21 13:18

I pushed `docs/transcript-timecode-last-unit-duration` to origin (`35ab52f..9022cef`). I didn't open a PR or merge anything.

---

## User — 2026-10-21 13:38

Yuji is driving. So I want to continue looking at transcripts.

---

## Claude — 2026-10-21 13:39

Your repair plan is picked up from the deferred note and your memory. The files you scanned live on your machine, and my scratchpad scripts from last time are gone. Anything I count now has to be recomputed.

**Decided on 10-20**
- Repair runs after the import as a re-runnable `transcripts:repair` drush command with `--dry-run`.
- Rule 1 sets a last unit's end to the node's duration and marks the unit `corrected`. It applies only when the duration is at or after the unit's start.
- Placeholder cues are left as found and flagged.
- The command needs no Germano sign-off if the original transcripts are backed up.

**Open, in the order I'd tackle them**
1. **The 97 anomalous files.** They have 627 cues, including 158 whose next cue starts earlier than their own start. The end matches the next start, so the start is the wrong value. This is the biggest unknown, and the cause hasn't been diagnosed. My suggestion is to open a few of them, such as the `..._0073.vtt` pattern, and see whether the start was mangled in the export or in D7's parse.
2. **Backups.** I don't know where the original transcripts are stored or whether they cover vtt, srt, xml and txt. That question gates the no-sign-off decision.
3. **Last cues with a stray non-zero end.** There are about 236 of them, and I haven't counted how many have a duration. That's a quick query.
4. **Other repair types you wanted to add:**
   - zero-padding short millisecond fractions (99 timestamps in 2 files)
   - the lost starts mislabelled `end_implausible`
   - untimed 0/0 units (3,039)
   - the negative start (tcu 142269)

**Not built yet:** `corrected` isn't in `AvTcu::TIMECODE_STATUSES`, and there's no repair command. `av:backfill-kaltura-duration` isn't in `run-av-migration.sh`. Yuji's merged T2 changes to `TranscriptReader.php` and the new soft-delete fields (ADR 019) now sit under any repair code, so I'd read those before building.

Where do you want to start? I'd suggest the 97 anomalous files, if you can point me at them. Otherwise, the quick count of last cues with a duration clears one open item.

---

## User — 2026-10-21 13:42

These transcripts are all bad and need to be reviewed by hand: 2067, 3652, 3535, 3553, 4624. So, we want to make 3 lists: bad transcripts that need review, transcripts that can be repaired via drush, and transcripts that are ok as is. We will make a separate Excell workbook with those three sheets once we have covered all the problematic transcripts in our list. One thing I noticed with some of those bad ones is that the first line starts about 30 seconds in. I think we can mark any that are like that as needing review by hand.

---

## Claude — 2026-10-21 13:43

I'm building the three lists from the D7 `tcu` table in the local `d7_av` database, starting with how a transcript id (`trid`) maps to a node id.

---

## Claude — 2026-10-21 13:43

I've confirmed the local D11 database has durations for 11,485 AV nodes. Next I'm joining them to the flagged transcripts and bucketing all 5,343 into the three lists.

---

## Claude — 2026-10-21 13:44

I've made a first pass at the three lists. They're drafts in my scratchpad, as CSVs of ids and reasons, with no transcript text.

| List | Transcripts |
|---|---|
| **Needs hand review** | 536 |
| **Repairable by drush** | 61 |
| **OK as is** | 4,746 |

The total is 5,343 transcripts, the same as the D7 `tcu` table.

**What I used to sort them**
- **Hand review** includes your 5, plus any flagged transcript with one of these:
  - a start earlier than the previous start
  - a first cue at 25 s or later
  - `end_implausible`
  - untimed units
  - a negative start
  - an inverted unit that isn't a placeholder
- **Repairable** means the only problem is `last_unit_no_end`, and the node's duration is at or after that last start. All 61 clear that test.
- **OK** is the 4,661 all-`ok` transcripts plus 85 where the only flags are placeholders or stacked and point cues, which we agreed to leave as found.

**What I found about your late-start idea**
- **The five don't share it.** Their first cues are at 36.6 s (4624), 38.6 s (3553), 19.7 s (3535), 8.9 s (2067) and 0 s (3652).
- **What the five do share** is out-of-order starts, where a cue starts before the one preceding it. All five have at least one.
  - Corpus-wide, 199 transcripts have this and 186 of them are in my review list. So I'd treat it as the stronger signal.
- **A late first cue is common, so I only applied it inside the 682 flagged transcripts.** Across all transcripts, 458 otherwise-fine ones start at 15 s or later, because most first cues sit around 5–15 s.
  - Applying the rule to everything would swamp the review list.
- **The threshold matters little.** Lowering it from 25 s to 15 s moves only 10 transcripts, from 536 to 546 in review.

**Questions for you**
1. **Non-placeholder inverted units.** 366 of the 536 are there only for this reason. They end before they start and the next cue starts later, which is the "end = next start" class. Do they stay in hand review, or should that become a second drush rule?
2. **Untimed transcripts.** 163 are in review (62 fully untimed, 101 partly). That's your open "untimed" question. Should they stay in review for now?
3. **The paired-cue pattern.** About 37 transcripts look like 2067's bilingual one, but only 2067 was opened by hand. Any of those with placeholder-shaped cues are in "OK as is". Do you want them moved to review?

I haven't made the Excel workbook. This machine has no `openpyxl`, so I'll need to install it or write the file another way when you're ready.

I also haven't touched the 2,153 units whose transcript ids have no row in `transcripts_apachesolr_transcript`. I assume they're orphans, but I haven't checked.

---

## User — 2026-10-21 13:47

Let's tackle those questions in order. Give me a detailed description of #1.

---

## Claude — 2026-10-21 13:47

I'll pull numbers for this bucket first so the description rests on the data, not on my summary.

---

## Claude — 2026-10-21 13:48

I got #1 wrong in my earlier summary, and the data changes the answer.

I described the 366 as the "end before start, next cue starts later" class. That is not what the bucket holds. 366 is the count of transcripts that have any non-placeholder inverted unit. For 235 of them, that is the only reason they're in review.

**What those 235 contain**
- They hold 254 units. 222 are the transcript's last unit, with a non-zero end that falls before its start.
- Only 18 are mid-transcript units whose end is before the start while the next cue starts later. That is the "end = next start" class. Another 14 are mid-transcript units with an end of 0.
- Almost every transcript has just one such unit: 229 of the 235 have exactly one.

**The stray ends repeat across transcripts.** Four values account for 215 of the 222 last-unit ends:

| End value | Transcripts |
|---|---|
| 185.292 s | 102 |
| 56.628 s | 55 |
| 210.390 s | 32 |
| 413.250 s | 26 |

Those are identical across unrelated transcripts, so they aren't real timestamps. They look like leftover default or template values written to the last cue, and I haven't identified where they came from. This is the "last cue with a stray non-zero end" class we already decided on 10-20: use the duration for both when one exists.

**Can the duration rule fix them?** Every one of the 222 has a duration on its node. 212 have a duration at or after the last cue's start, so the existing rule would repair them. The other 10 have a duration before the start, so the rule leaves them for a person.
- Some of the 10 are off by only seconds: start 588.25, duration 576.
- Some are far off: nid 4328 starts at 1973 s but has a duration of 1850 s, and nid 20236 starts at 3775 s but has a duration of 309 s.

So the question as I posed it is mostly moot. I shouldn't have put these in review. They are the second half of Rule 1.

**Proposed change.** Extend Rule 1 to cover a last unit with `0 < end < start`, not just `end = 0`. That moves 212 transcripts from review to repair, and the other 10 stay in review for the duration mismatch. The mid-transcript remainder is small, so I'd leave the 18 + 14 in review. That is about 32 units in roughly 30 transcripts.

Should I re-bucket on that basis? If so, I'd also like to look at one or two of the 10 duration-before-start cases first. They could point to the wrong media being attached, which matters more than the timecodes.

---

## User — 2026-10-21 13:51

Open a transcript text example of one of the 212 that could be fixed by extending the rule and point me to the line in question.

---

## Claude — 2026-10-21 13:51

Here is a worked example, D7 node 22611, a Dzongkha/Tibetan story told by Tshewang.

**File:** `drupal/web/sites/default/files/transcripts/TS_DAMPHU_2016_03_06.AN_ACCOUNT_OF_AP_WANG_DRUGYE_BY_TSHEWANG.002.vtt`

**The line in question** is the timing line of the last cue, cue 12:

```
12
00:02:16.100 --> 00:00:56.628
```

- **Line number:** it's line 59 once the line endings are normalised. The file uses CR-only line endings, so a plain `grep -n` or `sed` on the raw file sees it as one line. I used `tr '\r' '\n'` to get a usable number.
- **The start is sound.** Cue 11 ends at `00:02:16.100`, exactly where cue 12 starts. The cues chain cleanly all the way down, with each end equal to the next start.
- **The end is the stray value.** It's `00:00:56.628`, which is 56.628 s. That is one of the four repeated values, and it appears in 55 transcripts. The end sits about 80 s before the cue's own start, so the cue ends before it begins.
- **What the rule would do.** The node's duration is 177 s (`00:02:57`), which is after the start of 2:16.1. The end would become `00:02:57.000` and the cue would run about 41 s. The status would become `corrected`, with `56.628` kept in `end_original`.
- **Is 41 s plausible?** The last cue's text is long, and the earlier cues run about 13 s each. A 41 s cue is longer than the others, but the duration is an upper bound, as the rule already notes.

Two other examples are in the 212 if you'd like a second look: nid 19731 (start 1848.93, stray end 413.25) and nid 23666 (start 661.07, stray end 185.292). Nid 22611 is the smallest file.

I'll re-bucket on the extended rule once you're happy with this example.

---

## User — 2026-10-21 13:52

open that file in sublime text

---

## Claude — 2026-10-21 13:52

I ran the command to open the file in Sublime Text, and it returned without errors.

The file uses CR-only line endings. Sublime may show it as one long line, or it may convert the endings. Check View → Line Endings if it looks odd. The cue 12 timing line is line 59 once the endings are normalised.

---

## User — 2026-10-21 13:52

what is the old av node id for that transcripts video?

---

## Claude — 2026-10-21 13:52

The old D7 AV node id is **22611**.

It comes from `transcripts_apachesolr_transcript` in the `d7_av` database, which maps transcript file id 44346 (this `.vtt`) to node 22611. The node's duration is 177 s. In D11 it is the AV node with `field_legacy_site = audio-video` and `field_legacy_nid = 22611`. I haven't looked up its D11 nid, and it may differ between environments.

---

## User — 2026-10-21 13:55

yes, re-bucket with the extended rule and you can look at one or two of the 10 duration-before-start cases first as you suggested above.

---

## Claude — 2026-10-21 13:55

Two of the short-duration cases show something I didn't expect. Before I re-bucket, I'll read the last cues of a couple more of them.

---

## Claude — 2026-10-21 13:55

The 10 turn out to be a different problem, which I'll explain after the re-bucket. Updating the bucketing script now.

---

## Claude — 2026-10-21 13:56

I re-bucketed with the extended rule and looked at the ten duration-before-start cases.

| List | Before | After |
|---|---|---|
| Needs hand review | 536 | **324** |
| Repairable by drush | 61 | **273** |
| OK as is | 4,746 | 4,746 |

The 273 repairable are:
- 59 with `last_unit_no_end`
- 202 with a stray last end
- 12 with a stray last end that also have placeholders, which are left as found

That is 214 stray-end transcripts, a little above the 212 I quoted. The 212 count left out the ones with placeholders. The ten with a duration before the last start stay in review.

**The ten are not an end-time problem, and the duration rule would have been wrong for them.** In each, the last cue starts after the media has already ended. I opened four of the files:
- **nid 3864:** the last cue is `00:09:48.250 --> 00:09:36.030`, and the duration is 576 s (9:36). The end matches the media length. The start is 12 s past the end of the media.
- **nid 20236:** a 5:09 recording whose last cues are at about `01:02:55`. The end, `00:05:09.243`, matches the duration of 309 s. The start times look about an hour too late: 3775.6 − 3600 = 175.6 s, which fits inside a 309 s recording. That's my inference, not confirmed.
- **nid 4328** (343 cues): the last cue starts at `00:32:53.083` and has the recurring end `00:03:05.292`. The duration is 1850 s (30:50), so the start is about two minutes past the end of the media.
- **nid 19736:** the last cue is `00:03:50.492 --> 00:03:30.390` and the duration is 230 s. The start is only 0.5 s past the duration, which is whole-second rounding. A 1 s tolerance would settle this one.

In four of the ten (3864, 20236, 4627, 23931), the stray end is within a second of the duration. So it may be a correct end, and the start is what's wrong. Setting the end to the duration there would hide a bad start.

**The recurring end values are in the source files.** The stray value `00:03:05.292` appears in 166 of the exported `.vtt` files. So it was typed or exported into the files, and D7 didn't create it.

**Decision for you:** leave the ten in review, and add a 1 s tolerance so 19736 and similar cases (like nid 311, which is 0.5 s short) are handled. For those I'd set the end to `max(duration, start)`, but that gives a zero-length cue. The alternative is to leave them in review too. Which do you prefer?

I think that closes #1. When you're ready, #2 is the untimed transcripts.

---

## User — 2026-10-21 13:58

Leave those ones in review. Explain question #2

---

## Claude — 2026-10-21 13:58

I'll check the untimed units in the data before explaining.

---

## Claude — 2026-10-21 13:58

Question #2 is whether a unit with `start = end = 0` means "no time" or "a cue at 0:00". Here is the data and what it affects.

**What the data shows.** D7 has 3,039 units with `start = end = 0`, in 163 transcripts.
- **62 transcripts are fully untimed.** Together they hold 2,018 units. The median is 19 units each and the largest has 318. Nothing in them syncs to the media.
- **101 transcripts are partly untimed.** They hold 1,021 untimed units, a median of 7% of each transcript's units, with a 90th percentile of 49%. Where the untimed units sit:

| Where the 0/0 units are | Transcripts |
|---|---|
| All at the start | 40 |
| All at the end | 25 |
| In the middle (some or all) | 34 |
| Start and end only | 2 |

- **Most runs are single units.** There are 148 runs of consecutive untimed units, and 94 of them are a single unit. The longest run is 244 units.
- **Some have timed cues on both sides.** 30 of the 0/0 units sit between timed cues in 21 transcripts. For example, nid 4046 unit 10 is a 0/0 unit between a cue at 45.6 s and one at 65.0 s.

**Why it matters for the viewer.** The spike viewer highlights the last cue whose start is at or before the playhead. By that rule:
- In a fully untimed transcript, every cue "starts" at 0, so the last cue would stay highlighted for the whole video.
- A 0/0 unit in the middle of a timed transcript can never be reached in order. Its start of 0 is long before the playhead gets there, so a later cue always wins.

I haven't run the viewer against one of these. That is a prediction from its rule.

**The two readings**
1. **Untimed: no time known.** The unit is stored as found, with a derived flag. It has no highlight and no click-to-seek. The text still shows. This is my recommendation.
2. **A cue at 0:00.** That could be right for the leading ones, such as a title line before the speech. It cannot be right for a unit in the middle of the transcript, because time would have to go backwards.

My view is that the middle ones are untimed, and the leading ones are unclear. I haven't looked at the text of any leading 0/0 unit.

**How it affects the lists.** 92 transcripts are in review only because of untimed units. Drush can't repair any of them, since there is nothing to repair from. The real choice is what the list means:
- **Option A:** they stay in hand review, because someone has to supply times.
- **Option B:** fully untimed (62) moves to "OK as is", flagged untimed, since the transcript is complete except for timing. Partly untimed stays in review, because a missing cue time inside a timed transcript is more likely a defect.

I lean toward B, with the untimed flag recorded as the reason. Tell me which you want, or look at a leading 0/0 unit first. I can open one from the 40 "all at the start" transcripts, such as nid 3938 (1 untimed of 91).

---

## User — 2026-10-21 13:59

Open a fully untimed transcript in Sublime text

---

## Claude — 2026-10-21 13:59

I opened `t2047.xml` in Sublime Text. It's D7 node 502, "Describing a Pilgrim", a 53-second Tibetan audio clip, and it is not a typical untimed transcript.

- **It's an empty stub.** The file has the metadata block (title, speaker, three media records) and then a single sentence unit at the bottom. That unit's Tibetan text is just a lone tsheg (`་`) and its Latin line is blank. So the "untimed transcript" is one empty placeholder with nothing to time.
- **There are 15 stubs like it.** Of the 62 fully untimed transcripts, 15 have exactly one unit. I haven't checked how many of those 15 are empty like this one. The others have 6–20 units (17), 21–100 (26) or more than 100 (4).
- **This file isn't timed XML.** It has no begin or end attributes on the `<S>` element at all. D7 stored 0/0 because there was nothing to read.

So "fully untimed" is a mix of empty stubs and real untimed text. That matters for the Option B call: the stubs probably belong in OK as is, while the long ones are real transcripts that someone might want to time.

To see one of the real ones, I can open `t512.xml` (D7 node 332, 62 units), or `t2038.xml` (node 509, one unit, to check whether it's another stub). Which do you want?

---

## User — 2026-10-21 14:00

Open t512.xml in Sublime Text

---

## Claude — 2026-10-21 14:00

I opened `t512.xml` in Sublime Text. This is D7 node 332, and unlike the stub it holds real text.

- **It has 62 `<S>` sentence units.** Each has Tibetan script and a Wylie transliteration, and a speaker id (`who="N400005"`). The text starts at the `<TEXT>` line, line 40 once the line endings are normalised.
- **None of the units has a time.** The file contains no begin, end or start attribute anywhere. A search for those words found 0 matches. The 0/0 in D7 therefore means the source had no timing, not that a time was lost.
- **There are no media-time hooks.** The header has the usual metadata, but nothing links a sentence to a position in the recording.

This one is a real, complete transcript with no timing at all. It's not a defect, and it has nothing a drush rule could repair from. The only way to give it times is for someone to time the recording by hand, which is new work and not a repair.

That supports Option B for the long ones: leave them as found and flagged untimed, in "OK as is". It's the same for the stubs. I've only opened 2 of the 62, though, so I haven't confirmed that every fully untimed file is this kind of untimed XML. A quick scan of the 62 files for timing attributes would check that. Want me to run it?

---

## User — 2026-10-21 14:04

Yes that one has no timecodes and it doesn't highlight in the reader. So this and all similar ones should go in a bucket "Needs Timecodes".

---

## Claude — 2026-10-21 14:04

I'll scan all 62 source files to confirm none of them carries timing, then update the buckets.

---

## Claude — 2026-10-21 14:04

There are now four lists, and "Needs Timecodes" is the new one.

| List | Transcripts |
|---|---|
| **Needs Timecodes** | 62 |
| **Needs hand review** | 262 |
| **Repairable by drush** | 273 |
| **OK as is** | 4,746 |

The four add up to 5,343, which is every transcript.

**Scan of the source files.** I checked the files for the 62 fully untimed transcripts.
- **Result:** none has timing. I found no `-->` cue lines, no begin, end or start attributes, and no time elements.
- **Formats:** 52 are `application/xml` and 9 are `text/plain`.
- **Count mismatch:** I found 61 files for the 62 transcripts. All 61 are on disk. One transcript's file id has no `file_managed` row, or shares a file with another transcript. I haven't tracked that one down.

**What's in "Needs Timecodes".** All 62 fully untimed transcripts are in it. I tagged 15 of them as likely empty stubs, because they have a single unit. That's a guess from the unit count. I confirmed it only for node 502, whose one unit holds just a tsheg. The tag lets you split them out later if you'd like them elsewhere.

**Review dropped from 324 to 262.** The 62 fully untimed ones left it. 30 transcripts are still in review only because they have some untimed units.

**Not decided yet:** the partly untimed transcripts, which I left in review. They are the 101 with some untimed units, minus the 30 that have other review reasons. Should they go to "Needs Timecodes" too, or stay in review? My view is that the 40 whose untimed units are all at the start, and the 25 whose are all at the end, are the likeliest candidates to move. Those are the ones where the timed cues don't look wrong, and only the leading or trailing units are untimed.

Question #3 is next: the paired-cue pattern like 2067's.

---

## User — 2026-10-21 14:07

Before we do question 3, let's look at the partially untime transcripts. Open a representative transcript in Sublime text, give me the place where there is no timecode(s), and the video old node id.

---

## Claude — 2026-10-21 14:07

I opened `t329.xml` in Sublime Text.

**Old AV node id: 3938.** It's a Tibetan transcript with 91 units. 90 of them are timed, and the last one is untimed.

**Where the timecode is missing: lines 590–593.** This file has normal LF line endings, so the line numbers match what Sublime shows. I also asked Sublime to jump to line 590, but I haven't confirmed that it did.

```
590  <S who="N40001B" id="d380e659">
591     <FORM xml:lang="bo"/>
592     <FORM xml:lang="bo-Latn"/>
593  </S>
```

Every other unit in this file has an `<AUDIO start="…" end="…"/>` line. For example, the unit just above it has `<AUDIO end="194.136" start="192.499"/>`. Unit 91 has no `<AUDIO>` element, which is why D7 stored 0/0.

**This untimed unit is empty.** Both `<FORM>` elements are blank, so the "untimed unit" is a trailing empty sentence with no text. It's probably an artefact of how the file was exported. The transcript itself is fine and fully timed. Its last real cue ends at 194.136 s.

This is probably the most common partly-untimed shape. 94 of the 148 untimed runs are a single unit, and 25 transcripts have untimed units only at the end. I've only checked this one, though. I haven't counted how many partly-untimed units are empty like this.

I can count the empty-text untimed units across all 101 partly-untimed transcripts, to see how many are artefacts and how many are real text missing a time. That count would decide whether the trailing-only ones can simply go to "OK as is".

---

## User — 2026-10-21 14:10

Ok for the 25 transcripts that have untimed units at the end if they are empty, like this one. Just move them to the OK bucket.

---

## Claude — 2026-10-21 14:11

My out-of-order-starts check was miscounting. It treated an untimed unit's start of 0 as going backwards, which put those five in review. I'm fixing it to ignore 0/0 units.

---

## Claude — 2026-10-21 14:11

I moved five transcripts to OK, not 25, and I fixed a bug in my own out-of-order-starts check along the way.

| List | Transcripts |
|---|---|
| **Needs Timecodes** | 62 |
| **Needs hand review** | 257 |
| **Repairable by drush** | 273 |
| **OK as is** | 4,751 |

**Only 5 of the 25 trailing-untimed transcripts are empty.** The untimed units in the other 20 contain real text. I checked each unit's text in `tcu_tier`, and a unit counts as empty only if all its tiers are blank. The 25 transcripts have 238 trailing untimed units, and just 16 of those are empty. The five I moved are nids 3938, 369, 372, 31 and 32.

**The other 20 are not the same kind as 3938.** Their trailing units carry actual Tibetan text, so a time is missing for something real. I left them in review, since they look closer to "Needs Timecodes" than to OK. For the cases where the untimed units run to the end of the transcript, you could treat them as partly needing timecodes.

**The bug.** My out-of-order-starts count treated an untimed unit's start of 0 as a start earlier than the previous one. That is why the five empty-trailing transcripts were in review, even though their timed cues are fine.
- **Fix:** untimed units are now ignored when counting backward starts.
- **Effect:** the number of transcripts in review for that reason dropped from 186 to 141. My earlier figure of 199 transcripts corpus-wide was too high by the same bug. I haven't recomputed the corpus-wide number.
- **The five hand-review transcripts:** they all still show backward starts, so that remains the signal they share. 3652 shows 14.

**Still open from #2.** Of the partly untimed transcripts, 56 are now in review for untimed units alone. Your options for the 20 with trailing text, and the ones with untimed units at the start or in the middle, are:
- send them to "Needs Timecodes"
- keep them in review

Which do you want for those 20? After that we can do #3.

---

## User — 2026-10-21 14:11

Then show me one of the other single untime units not at the end, opening the transcript and giving me the nid.

---

## Claude — 2026-10-21 14:11

I opened `t1044_0.xml` in Sublime Text. The old AV node id is **796**. It's a Tibetan interview with English translation and 185 units. One of them, unit 85, has no time.

**Where the timecode is missing: lines 582–586.** The file uses normal line endings, so these are the line numbers Sublime shows. I also asked Sublime to jump to line 582, but I haven't confirmed that it did.

```
582  <S who="ka" id="d734e569">
583     <FORM xml:lang="bo">[transcript text omitted from this public log]
584     <FORM xml:lang="bo-Latn">[transcript text omitted from this public log]
585     <TRANSL xml:lang="en">[transcript text omitted from this public log]
586  </S>
```

Every unit around it has an `<AUDIO start="…" end="…"/>` line, and this one has none. The unit before it runs 645.356 → 650.530 s, and the unit after it starts at 657.061 s. So the missing unit sits in a 6.5 s gap, between 650.53 s and 657.06 s.

**Unlike nid 3938, this unit has real text.** It has Tibetan, Wylie and an English translation, a sentence of ordinary speech. Nothing about the text looks like a placeholder. The time is missing from the source XML. It looks like someone left the `<AUDIO>` element off, or the timing tool skipped the unit.

**It could be fixable.** The neighbours bracket it tightly, so a person (or a rule) could assign it a plausible slot. I haven't checked whether the 6.5 s gap fits the text, and I wouldn't guess times without a person listening. A rule like "split the gap between the neighbours" would be an invention, not a repair, and that's a call for you.

This is the "in the middle" shape. 34 partly untimed transcripts have it, and the most common case is a single unit. It's closer to "Needs Timecodes" than to OK, but it needs only one unit timed, and the transcript still works around it. What should these do: a "Needs Timecodes" bucket entry, or hand review?

---

## User — 2026-10-21 14:16

Are we looking into the 19 orphaned trids in this conversation are they included?

---

## Claude — 2026-10-21 14:16

No. Those 19 are not in any of the lists. When I joined units to `transcripts_apachesolr_transcript` to get each transcript's node id, any trid without a row there dropped out.

- **Count:** the `tcu` table has 5,362 distinct trids and my lists cover 5,343. The difference is exactly 19, which is the "2,153 units" I mentioned earlier but never pinned down.
- **Size:** the 19 trids hold 2,153 units. The biggest ones are trid 3586 (526 units), 3616 (439), 3551 (305) and 26932 (272).
- **Timing:** none of the 19 has an untimed unit. I haven't classified their timecodes, so I don't know whether any have bad ones.
- **What "orphaned" means here:** I only know they have no row in that table, so I can't tie them to a video node. Whether D7 links them some other way, or whether they're truly stray transcripts, is unchecked. That overlaps with Yuji's orphan work (#357), so it's worth checking against what his `av-transcripts` commands consider an orphan.

I'd suggest a fifth list, "Orphaned trids", and running the same timecode checks on them so we know whether they carry bad timecodes. The next step would be to find out how, if at all, D7 links each one to a node. Want me to do that before we go to #3?

---

## User — 2026-10-21 14:17

yes, find out how D7 links those 19 trids

---

## Claude — 2026-10-21 14:18

The start-time comparison can't test the untimed XML files, so I'll match the units' text against the 41 files directly.

---

## Claude — 2026-10-21 14:18

I'll run a positive control first, to make sure this text match can find a real match.

---

## Claude — 2026-10-21 14:19

16 of the 19 orphans have their text in an existing transcript file. I'll map each of those files to its node and current trid.

---

## Claude — 2026-10-21 14:20

I found how D7 links them: it doesn't. Only two D7 tables have a `trid` column, `tcu` and the tracking table `transcripts_apachesolr_transcript`. The orphans exist only in `tcu`, so the only way to place them is to match their text to files on disk. I did that for all 19 and checked it first with a control.

**Control.** I sampled up to five units per transcript from the database and searched for their text in the same transcript's own file. It found all 33 samples across xml, txt and vtt files. I then searched all 5,378 files on disk for each orphan's samples.

**16 of the 19 orphans are superseded parses of a file that still exists on a node.** All five samples matched one file for each of these. They fall into two patterns:
- **Same file, earlier or partial parse** (nids 4776, 4777, 4778, 4864, 4024, 1188, 1178, 32801, 16826). The orphan trid is a few ids before the node's current trid, for example orphan 2994 against current trid 3004. The orphan has fewer units than the current transcript: 1 against 27, 11 against 22, 175 against 261. I haven't checked why the orphan is shorter.
- **The node's file was replaced after it was tracked** (nodes 1788, 2007, 2012, 1269). Each node's tracking row points to an older file id, while the node's transcript field now points to a newer file. The orphan's text matches the newer file. For example, node 1788 is tracked with fid 14466, but its field now holds fid 14471. Orphans 3551, 3586, 3616 and 23546 follow this pattern.
- **Orphan 23416** matches the file on node 868. I didn't look up that node's tracking row.
- **Orphans 17151 and 26932** matched files with very long names. I didn't map them to nodes.

**3 orphans match nothing: 10266, 10271 and 12361.** They have 3, 135 and 118 units and were all created in August or September 2018. No file on disk contains their text, and no tracking rows exist nearby. These are the only truly unplaced ones, and only they need a human look.

**How reliable this is.** Each match is five unit texts, the first 25 characters of each, found in one file. That is strong evidence of the same transcript, but not proof that the whole unit list matches. 26026 matched two files, the `_0` and `_1` versions of the same recording.

**For the lists.** I'd add a fifth list, "Orphaned trids", with these notes:
- 16 superseded parses, where the current transcript exists and is already in one of the other lists.
- 3 unplaced.

Because ADR 019 says orphans aren't migrated, the open question changes. Only the 3 unplaced ones need a keep-for-review decision. The 16 duplicate transcripts we already have.

I haven't run the timecode checks on the 19. Do you want me to do that, and should I record this in the deferred note `transcript-orphan-trids-keep-for-review.md`? I'd update it, since its open question "whether any belongs to a replaced upload" is now answered.

---

## User — 2026-10-21 14:25

Ok. Let's update the deferred note. The 16 that are superceded can be trashed, and for the 3 remaining we should backup the transcripts by making a zip file of them that I can save on Teams. And then mark the orphaned trids as resolved everywhere, but for now just note that plan down, while we wait for Yuji's branch to merge. Don't take action on it yet.

---

## Claude — 2026-10-21 14:26

I added the findings and the plan to `docs/deferred/transcript-orphan-trids-keep-for-review.md`. The plan section is dated 2026-10-21 and says nothing has been done yet. I haven't committed or pushed it, and I haven't trashed, zipped or marked anything resolved.

**What the note now records**
- **How D7 links them:** it doesn't. Only `tcu` and the tracking table carry a `trid`.
- **How the match was made:** by text, against all 5,378 files, after a control that found 33 of 33 samples in their own files. The start-time comparison found nothing.
- **A table of the groups:**
  - 9 orphans are superseded, with the same file on a tracked node.
  - 4 are superseded because the node's file was replaced after it was tracked.
  - 3 match a file, but I didn't fully trace their node. I counted them as superseded on the text match alone, and the note says so.
  - 3 are unplaced: 10266, 10271 and 12361.
- **The caveat:** each match rests on five sampled units, which is strong evidence but not proof.
- **The plan:**
  1. Trash the 16 superseded trids.
  2. Zip the 3 unplaced ones for you to save on Teams.
  3. Mark the question resolved in this note, the deferred README and any issue that still lists the 19. ADR 019 stays unedited because it's immutable.
  - All of it waits until Yuji's T2 branch has merged.

**Differences from what you said.** The 3 unplaced ones have no source files, because no file on disk contains their text. So the zip will be an export of their units (times and text) from D7, not a copy of files. The note says that. The zip will contain transcript text, so it stays off the repo.

**Two things to settle when we act on it**
- I said "trashed", and I read that as dropping the units from the migration. They're already outside `av_transcript` under ADR 019, so nothing in D11 needs deleting. If you meant something else, tell me.
- The three "not fully traced" trids (17151, 23416, 26932) are treated as superseded on the text match alone. I'd trace their nodes before trashing them.

The note is an uncommitted change on your private branch. Do you want me to commit it, or hold it until later?

---

## User — 2026-10-21 14:26

commit the note to my branch

---

## Claude — 2026-10-21 14:26

I committed the note to `docs/transcript-timecode-last-unit-duration` as `45d63de`. Only that one file changed. The branch is 1 commit ahead of its remote, and I haven't pushed it.

---

## User — 2026-10-21 14:27

Ok back to t1044_0.xml in Sublime Text. The old AV node id is 796. THat node needs review. Didn't seem to work properly in player. Put it in the review bucket adn give me anotehr example of a single missing timecode.

---

## Claude — 2026-10-21 14:27

I put node 796 in the hand-review list and opened another example in Sublime Text.

**Node 796** was already in review for `end_implausible`. It's now there explicitly as a hand-review transcript, with a note that the player didn't work properly. The lists are still 257 review, 273 repair, 62 Needs Timecodes and 4,751 OK. I only updated my scratch script, not a repo file, so the move is not recorded anywhere permanent yet.

**Second example: old AV node id 517**
- **File:** `t2030.xml`, 128 units, with a single untimed unit, number 84.
- **Where the timecode is missing: lines 467–470.** The file has normal line endings, so these are the line numbers Sublime shows. I asked Sublime to jump to line 467, but I haven't confirmed that it did.

```
467  <S who="ka" id="d334e351">
468     <FORM xml:lang="bo">[transcript text omitted from this public log]
469     <FORM xml:lang="bo-Latn">[transcript text omitted from this public log]
470  </S>
```

- **The neighbours:** the unit before it ends at 166.551 s and the unit after it starts at 167.881 s. So the missing unit sits in a 1.33 s gap.
- **What the unit is:** a few syllables from the second speaker (`who="ka"`), between two lines from the first speaker (`who="kha"`). It looks like a short interjection. This file has no English translation line.

The missing time in 796 sat in a 6.5 s gap between long lines. This one is much tighter, a short backchannel in a 1.33 s gap. A mid-transcript untimed unit is in both cases a real unit with text and a clear slot, but no `<AUDIO>` element.

Want me to put 517 in review too, or leave it for the partly-untimed decision? Nine single-unit mid-transcript cases with text turned up in my quick check. I haven't opened the others.

---

## User — 2026-10-21 14:48

Ok. That one could be fixed by using the former and later tcu to calculate its time. Let's make that the decision. Then I need to close out.

---

## Claude — 2026-10-21 14:48

I'll record that as a rule and count what it covers before writing it down.

---

## Claude — 2026-10-21 14:49

I've written the decisions into the deferred note. Next I'm running the session-close check, then saving the session log.

---
