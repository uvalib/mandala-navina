# Session Log: Transcript Timecode Triage And Repair Plan

**Date:** 2026-10-20 / 2026-10-21  
**Participants:** Than Grove, Claude Code (group session with Yuji Shinozaki and Xiaoming Wang)  
**Outcome:** Transcript timecode triage and repair plan; see [transcript-bad-timecodes-investigate-and-fix](../deferred/transcript-bad-timecodes-investigate-and-fix.md) (Issue #353).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-10-20 16:28

session start. We are beginning a group session (Yuji, Xiaoming, and I). Yuji is leading with his version of claude. I'm just participating and will only need you for questions and side investigations.

---

## Claude — 2026-10-20 16:28

Understood. Yuji drives, and I'll stay on questions and side investigations. I'll do the quick repo sync from the startup checklist so I'm not answering from stale context.

---

## Claude — 2026-10-20 16:28

Local `main` is clean and already up to date with origin.

Two docs-only PRs are open and unmerged:
- **#354:** session log 2026-10-19
- **#351:** session log 2026-10-16 (T2 phases 2-6)

Yuji's session may want to merge them.

I skipped the full `session-start-check.sh`, since it includes the dev-0 parity checks and Yuji's session is covering that. I can run it with `--local-only` if you'd like.

Tell me what you want investigated.

---

## User — 2026-10-20 16:34

yeah run it with local-ohly

---

## Claude — 2026-10-20 16:35

The `--local-only` check passed and found nothing that needs attention.

- **Git:** the pull fast-forwarded one commit, `17461b6`. It changes `pipeline/deployspec.yml` by 3 added lines and 1 removed.
- **Config:** local `config:status` is clean. The only differences are the expected `stage_file_proxy` ones, which are local-only.
- **`drupal/config/sync`:** it matches `origin/main`, with nothing uncommitted.
- **Skipped:** the dev-0 content comparison and the same-node-id spot-check. Local counts are printed but there is nothing to compare them against.

**Open deferral issues**
- **#353:** transcript bad timecodes. It's open and probably the likely topic for today.
- **#290:** Spike 11 (T9), the `trid_i` writer and join-based access filter. It was touched on 10-19.
- **Unassigned and free to pick up:** #336 (solr-proxy on an end-of-life PHP 7.4 / Debian 11 base image) and #300 (periodic review of doc-only deferrals).

The other open items are #304, #297, #296, #295, #294, #292 and #289.

The latest session log is `2026-10-19-t2-review-meeting-and-bad-timecode-findings.md`. Your memory also has a note that the D7 bad-timecodes workbook review is deferred to your next solo session, not a group one. Today is a group session, so that stays deferred.

---

## User — 2026-10-20 16:45

Ok so I want to start looking at the transcript issues. Can you bring up your memory regardig those?

---

## Claude — 2026-10-20 16:46

Here's what my memory holds on the transcript timecode issue.

**Workbook to-do** (`project-d7-bad-timecodes-workbook-todo`)
- **File:** `~/Sandbox/Mandala/AV/D7-bad-timecodes-for-review.xlsx`, also in Teams. It has three sheets: README, Transcripts (~682 rows) and Units (~4,326 rows).
- **Contents:** it contains transcript text, so it shouldn't be committed or linked in the repo.
- **Source:** it was built from a public claude.ai artifact made outside the org, so I treat it as untrusted data.
- **Review status:** neither of us has analyzed it. I only looked at the headers.
- **Five error classes** (counts from the first version):

| Class | Units | Transcripts |
|---|---|---|
| Inverted (end before start) | 904 | 414 |
| Zero-length, other | 188 | 175 |
| Last unit has no end | 66 | n/a |
| Implausible end | 56 | 11 |
| Zero-length shared start (untimed) | 3,111 | 168 |

- **Hand-checked:** only 3 files were checked by hand: Tale of Tsar Saltan, Tibetan Pottery and Session III. The other causes are inferred.
- **Newer artifact version:** it adds an untimed breakdown (3,039 units in 163 transcripts) and one negative start (nid 554).
- **Why it matters:** this is the evidence for the T2 timecode repair policy decision. That decision needs you or AV staff.

**Project state**
- **Earlier decision:** carry bad timecodes as found and don't fix them at migration. Your proposed heuristic is noted there, and the transcript unit gains `timecode_status` and `end_original` fields instead.
- **Spike 11 numbers:** 245,158 units in 5,343 transcripts, with 4,269 bad-timecode units concentrated in 679 transcripts. WebVTT requires end > start, so the bad units would violate it.
- **T2:** Yuji's phases 1-6 are merged, including the timecode classifier, along with ADR 019. Issue #353 tracks the investigation.
- **Deferred notes:** `transcript-bad-timecodes-investigate-and-fix` and `transcript-source-file-accounting`.

One conflict: my note says you wanted to handle the workbook in a **solo** session, not a group one. If you want to start now anyway, I can read the repo's T2 plan, ADR 019 and the #353 note, then summarize the workbook per error class. Should I do that?

---

## User — 2026-10-20 16:47

OK accoring to the sheet this file '/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/sites/default/files/transcripts/Gebchak Wangdrak interview_audio-video-42259-fixed.vtt' has 7 inverted timecodes, can you list them for me?

---

## Claude — 2026-10-20 16:48

The file has 170 cues, and 7 of them are inverted. That matches the sheet.

| Cue | Start → End | Speaker |
|---|---|---|
| 79 | 00:22:48.001 → 00:22:02.000 | དབང་གྲགས་རིན་པོ་ཆེ། |
| 84 | 00:24:05.001 → 00:22:07.000 | Elizabeth |
| 134 | 00:41:15.001 → 00:41.000 | དབང་གྲགས་རིན་པོ་ཆེ། |
| 149 | 00:46:12.001 → 00:45:30.000 | དབང་གྲགས་རིན་པོ་ཆེ། |
| 150 | 00:46:31.001 → 00:43:37.000 | Elizabeth |
| 159 | 00:49:21.001 → 00:48:24.000 | Elizabeth |
| 167 | 00:50:50.001 → 00:40:51.000 | Elizabeth |

- **Digit-dropped ends:** the end times look like typos. Cue 134's end, `00:41.000`, has lost its hours or minutes field. Cue 167's end, `00:40:51.000`, looks like `00:50:51.000` with a dropped or changed digit.
- **Start-time offset:** every start ends in `.001`. That looks like a generated offset, probably added to keep starts strictly after the previous cue's end.
- **Possible fix:** most of these ends are probably recoverable from the next cue's start. I haven't checked that. Cue 79's end, `22:02`, could be `23:02` or similar.

I can print the neighboring cues so we can see which fix each one implies.

---

## User — 2026-10-20 16:53

In this transcript I have confirmed that the inverted end could use the following line's start minutes, keeping the seconds as they are. Can you list out the inverted cues with the following starts for this transcript in the same folder: TG_SHONGPHU_CHANGMI_KHARMI_2014_09_09_A_STORY_ABOUT_HUMAN_MIND_CHANU_0073.vtt

---

## Claude — 2026-10-20 16:53

Here are the 11 inverted cues in `TG_SHONGPHU_…_0073.vtt`, out of 28 cues. For each one I've added the following cue's start and the end your rule would produce.

| Cue | Start | Current end | Next cue's start | End by your rule |
|---|---|---|---|---|
| 1 | 00:00:38.633 | 00:00:24.732 | 00:00:24.733 | 00:00:24.732 (unchanged) |
| 4 | 00:00:48.467 | 00:00:35.899 | 00:00:35.900 | 00:00:35.899 (unchanged) |
| 6 | 00:01:02.467 | 00:00:43.832 | 00:00:43.833 | 00:00:43.832 (unchanged) |
| 13 | 00:01:39.933 | 00:01:32.966 | 00:01:32.967 | 00:01:32.966 (unchanged) |
| 14 | 00:01:32.967 | 00:01:19.166 | 00:01:19.167 | 00:01:19.166 (unchanged) |
| 16 | 00:01:47.467 | 00:01:42.332 | 00:01:42.333 | 00:01:42.332 (unchanged) |
| 17 | 00:01:42.333 | 00:01:40.299 | 00:01:40.300 | 00:01:40.299 (unchanged) |
| 19 | 00:02:00.900 | 00:01:52.632 | 00:01:52.633 | 00:01:52.632 (unchanged) |
| 22 | 00:02:24.267 | 00:02:24.199 | 00:02:24.200 | 00:02:24.199 (unchanged) |
| 23 | 00:02:24.200 | 00:02:13.232 | 00:02:13.233 | 00:02:13.232 (unchanged) |
| 26 | 00:02:20.800 | 00:02:18.932 | 00:02:18.933 | 00:02:18.932 (unchanged) |

This file doesn't behave like the Gebchak one, and your rule can't fix it.

- **Rule has no effect here:** in all 11 cues, the end is already the next cue's start minus 1 ms, so the minutes already match. Applying the rule leaves every cue inverted.
- **The start is the wrong field:** the next cue starts before this cue's start. For example, cue 1 starts at 38.633 and cue 2 starts at 24.733. The ends look right, and the inverted cue's start is too late.
- **Chained starts:** some starts equal the previous cue's end plus 1 ms. Cue 14 starts at 1:32.967, which is cue 13's end plus 1 ms. A bad end therefore appears to propagate into the following cue's start, so the real error may begin earlier in the chain.
- **Likely cause:** this looks like a generated start for each cue, derived from the previous cue's end. The 11 inverted cues may share a different root cause from the Gebchak file's typo'd ends.

I haven't looked at the source audio or the original timing. I can print all 28 cues in sequence to show where the timeline goes backwards, if you'd like to work out what the true starts should be.

---

## User — 2026-10-20 16:56

are we in a branch

---

## Claude — 2026-10-20 16:56

No, we're on `main`, in sync with `origin/main`, and the working tree is clean. I haven't changed any files. The VTT analysis was read-only.

---

## User — 2026-10-20 16:59

Can you open that file in Sublime Text editor

---

## Claude — 2026-10-20 16:59

I opened `TG_SHONGPHU_CHANGMI_KHARMI_2014_09_09_A_STORY_ABOUT_HUMAN_MIND_CHANU_0073.vtt` in Sublime Text. I assumed "that file" meant this one. If you meant the Gebchak file, say so and I'll open that too.

---

## User — 2026-10-20 17:09

Can you look at all the inverted transcripts and separate them into ones that can be fixed by following starts and ones that are trully anamolous like this last one?

---

## User — 2026-10-20 17:09

[Request interrupted by user]

---

## User — 2026-10-20 17:10

Can you look at all the inverted transcripts and make a list of the ones that can be fixed by following starts and anoher list of ones that are trully anamolous like this last one?

---

## Claude — 2026-10-20 17:11

I scanned all 5,232 timed transcripts (vtt, srt and xml) and sorted the 448 with inverted cues into buckets. I used your rule: take the following cue's start minutes and keep the end's own seconds. A cue counts as fixable if that end lands between its own start and the next cue's start.

| Bucket | Files | Cues |
|---|---|---|
| **1. Fixable by following start** | 1 | 7 |
| **2. Placeholder or last-cue only** (no real anomalies) | 350 | 372 |
| **3. Truly anomalous** | 97 | 627 |

**Bucket 1** is only the Gebchak Wangdrak interview file, the one you confirmed. Your rule fixes almost nothing else.

**Bucket 2: placeholder or last cue.** These aren't fixable by the rule, but they aren't strange either.
- **Placeholder cues, 526 cues:** the end is one unit before the start (1 ms in vtt, 10 ms in xml), and the next cue has the same start. These look like untimed units that inherited the previous cue's end.
- **Last cue, 301 cues:** the final cue is inverted and has no following start. Often its end is `00:00:00.000` or a garbage value like `00:03:05.292`.

**Bucket 3: truly anomalous.** This is the group the `…_0073.vtt` file belongs to.
- **Next start earlier than this cue's start, 158 cues:** the 0073 pattern.
- **Other, 12 cues:** the rule gives an end outside the allowed range. These are in `Gyeltshen Rinpoche interview…-fixed`, `vrtr-pt2-fixed2.vtt`, `Tshona-Lhasa Neighborhood History.xml`, `Tshona-Lingkas and Old Lhasa Places.xml`, `Two Excellent Friends_0.xml` and one `HA_KARTSHOG_…` file.
- **Mixed files:** about 33 of the 97 also contain placeholder cues.

The percentages for the placeholder and last-cue counts are rough. I defined "placeholder" as end within 1 unit of start with a shared next start, and I only spot-checked the samples. I haven't verified that the 0073 pattern has one cause across all 158 cues.

The per-file lists are in the scratchpad (`1_fixable_by_following_start.txt`, `3_placeholder_or_last_cue_only.txt`, `4_anomalous.txt`, plus `classify.py`). They hold filenames and cue counts only, no transcript text, and nothing is in the repo.

I can write the 97 anomalous files out as a table sorted by cue count, if that helps.

---

## User — 2026-10-20 17:22

show me examples of bucket 2

---

## Claude — 2026-10-20 17:22

Bucket 2 has two kinds of cue. Each example shows the cue number and its start and end.

**Placeholder cues**

```
Describing Someone .vtt
  233  06:54.400 → 06:55.599
  234  06:55.600 → 06:55.599   ← inverted by 1 ms
  235  06:55.600 → 06:58.966   (same start as 234)
  236  06:58.967 → 07:00.832
```

```
TUR 247A.vtt
   9   08:38.748 → 09:51.484
  10   09:51.485 → 09:51.484   ← inverted by 1 ms
  11   09:51.485 → 09:56.366   (same start as 10)
```

The same pattern appears in the other two examples:
- `Architecture Department at Tibet University`
- The `HA_KARTSHOGH_…` file, which uses 10 ms units: `03:17.29 → 03:17.28`.

In each case the inverted cue:
- starts 1 ms after the previous cue's end,
- ends at that previous end,
- shares its start with the next cue.

That means it has no real timing of its own. My guess is that something stamped it with a start and left the end as the previous end.

**Last cue with a garbage end**

```
BU_URA_2014_08_27_HISTORY_OF_CHORTEN.vtt   (118 cues)
  117  17:22.159 → 17:23.574
  118  17:23.575 → 00:00:00.000   ← end zeroed

DA_TSHANGKHA_…                              (25 cues)
  24   08:00.260 → 08:18.240
  25   08:18.240 → 00:03:30.390   ← unrelated value

ZH_NGANGLA_…                                (66 cues)
  66   04:56.658 → 00:03:05.292
```

The other two examples are `MO_NGATSHANG_…` (end `00:00:00.000`) and `TS_DAMPHU_2016_…` (end `00:00:56.628`).

- **Reused end values:** the ends look like values left over from somewhere else. `00:03:30.390` and `00:00:56.628` each show up in more than one file.
- **Likely fix:** these can probably be repaired from the media duration. The previous cue's end is another option.

Neither kind is the 0073 pattern. In that pattern the following start comes before the cue's own start, so the timeline itself runs backwards.

---

## User — 2026-10-20 17:22

Open th Describing Someone .vtt in Sublime

---

## Claude — 2026-10-20 17:23

I opened `Describing Someone .vtt` in Sublime Text. The inverted cue is cue 234, at 06:55.600.

---

## User — 2026-10-20 17:28

what is an example of zero_length_shared_start?

---

## Claude — 2026-10-20 17:28

`zero_length_shared_start` is a classifier status from `TimecodeClassifier.php` (Yuji's T2 phase 6). It applies to a cue whose **end equals its start** and whose **next cue starts at that same instant**.

**Example from the files** (`BU_CHAMKHAR_2014_08_28_ABOUT_ANCIENT_TAXATION_BY_NGAWANG_SAMTEN.01.vtt`):
```
100  00:24:00.426 → 00:24:02.154
101  00:24:02.154 → 00:24:02.154   ← zero-length, shares its start with cue 102
102  00:24:02.154 → 00:24:15.311
103  00:24:15.311 → 00:24:32.509
```
Cue 101 has no duration, and cue 102 starts at the same moment. That's why 101 reads as "shared start". The other VTT example was the Gyeltshen Rinpoche file, where cue 9 sits at 06:20.000 → 06:20.000.

**How it differs from the placeholder cues I showed earlier:** the placeholder cues were *inverted*, with the end 1 ms before the start. Here the end equals the start exactly. A zero-length cue whose next cue starts *later* is classed `zero_length_other` instead, for example a point cue or a last unit.

**Scale in D7:** the classifier docs say 3,111 units in 168 transcripts have this status. 3,039 of them are untimed units sitting at `0:00`, so they are cues that never got a time. In the exported files on disk, only 22 VTT files have this pattern, and none of them are at 0:00. The untimed ones appear to be in the D7 database rather than in these files. I haven't checked the XML or SRT files for it.

---

## User — 2026-10-20 17:29

Open that file in sublime

---

## Claude — 2026-10-20 17:29

I opened `BU_CHAMKHAR_2014_08_28_ABOUT_ANCIENT_TAXATION_BY_NGAWANG_SAMTEN.01.vtt` in Sublime Text. The zero-length cue is cue 101, at 00:24:02.154.

---

## User — 2026-10-20 17:31

Show me last_unit_no_end and open an example document.

---

## Claude — 2026-10-20 17:31

`last_unit_no_end` is the classifier status for the final cue of a transcript when it has no usable end. D7 stored `0` to mean "unknown", so the status applies when the end is NULL, or 0 while the start is positive. The cue must also have no next unit. The same condition on a non-final unit is classed `inverted`.

**Example** (`BU_URA_2014_08_27_HISTORY_OF_CHORTEN.vtt`, which I opened in Sublime Text):
```
117  00:17:22.159 → 00:17:23.574
118  00:17:23.575 → 00:00:00.000   ← last cue, end is 0
```

The classifier docs count 66 such units, one per affected transcript. On disk, 65 VTT/SRT files end this way. Two other files showed the same zero end: `1_VIDEO - Interview w_ Gyeltshen Rinpoche about Vajrayana…Revised_3.vtt` (cue 137) and `A_HISTORY_OF_URA_DUNG_NAGPO_BY KARMA.vtt`.

Some of the "last cue" cases I put in bucket 2 earlier had non-zero garbage ends instead, such as `00:03:30.390`. Those probably fall under `inverted` or `end_implausible` rather than `last_unit_no_end`. I haven't checked how the classifier labels them.

---

## User — 2026-10-20 17:32

In that example do you know the length of the video? Can you calculate what the last timecode would be?

---

## Claude — 2026-10-20 17:32

Yes. The local Drupal database has the video's duration, so I can estimate the missing end.

**The video:** the file maps to the local node titled "History of Chöten Jarung Khashor of Nepal", with `field_kaltura_duration` = **1070 seconds**, which is 00:17:50. I matched it through the transcript file attachment, not by title.

**Last cue:**
```
118  00:17:23.575 → 00:00:00.000   (current)
118  00:17:23.575 → 00:17:50.000   (estimated from duration)
```

- **Cue length:** the cue would run about 26.4 s. That's plausible, since cue 117 ran only 1.4 s but earlier cues in the file are longer.
- **Rounding:** the duration is stored as whole seconds, so the end is accurate to about ±1 s. It is also the media length, so the true speech may end a little earlier.
- **Local only:** I read this from the local DDEV database. The node id is that environment's own, and I didn't call Kaltura.

The same approach would repair all 65 `last_unit_no_end` files, wherever their nodes have a duration. I can check how many do, if you want.

---

## User — 2026-10-20 17:33

check how many of the 65 have a duration

---

## Claude — 2026-10-20 17:33

**62 of the 65 files have a duration** in the local database. None of the 62 has a duration shorter than the last cue's start, so each one gives a usable estimated end.

**The other 3** have no node match locally. They are:
- `1_VIDEO - Interview w_ Gyeltshen Rinpoche about Vajrayana…`
- `6-04_0.vtt`
- `SA_DOPCHHOGCHEN_JIGME_2015_07_18_AN_ACCOUNT_O…`

I haven't looked into why. The cause could be a renamed or differently normalized filename, or a node that is missing locally. The mapping went through the transcript field's file attachment, comparing names after Unicode normalization.

**Caveats**
- **Local only:** this came from the local DDEV database. dev-0 may differ, and I didn't touch it.
- **Rough accuracy:** durations are whole seconds, so the estimated ends are accurate to about ±1 s. They are also the media length, not necessarily where speech stops.
- **Spot-check:** I haven't compared the 62 durations against the actual last cues beyond the one example.

I can list the 62 as filename, last start and duration, or compute the proposed end for each.

---

## User — 2026-10-20 17:33

Let's investigate those three are there any similar file names?

---

## Claude — 2026-10-20 17:34

Those three do exist locally, and each has a duration. My matching was wrong, not the data.

When a filename collided on upload, Drupal added a suffix to the file on disk. It left the original name in `file_managed.filename`, so I matched against the wrong column.

| On disk | `filename` in the database | Node | Duration |
|---|---|---|---|
| `6-04_0.vtt` | `6-04.vtt` | 122345 | 957 s |
| `…Revised_3.vtt` (Gyeltshen Rinpoche, Vajrayana) | `…Revised.vtt` | 122671 | 4173 s |
| `SA_DOPCHHOGCHEN_…_DOYA_0.vtt` | `…_DOYA.vtt` | 119144 | 178 s |

The match goes through the `uri` column (`public://transcripts/6-04_0.vtt` and so on), which carries the real on-disk name.

- **Count:** all 65 files have a duration, not 62.
- **Earlier counts:** the other file counts I gave you used `file_managed.filename`. Any file with a `_N` suffix on disk was probably mismatched or missed. That includes the bucket sizes and the "only 22 files" shared-start figure. The classification itself reads the files directly, so it isn't affected. The node and duration lookups are.
- **Suffixed files:** the `_0` and `_3` files exist because of collisions. Another file may share the original name, for example another `6-04.vtt`. The `uri` match is the reliable one. I haven't checked whether other nodes point at the unsuffixed copies.

I can redo the 65-file lookup via `uri` and list each file's proposed end, if you want.

---

## User — 2026-10-20 17:35

Ok let's start a branch that will be a PR on dealing with transcripts and let's add that the solution to the zero end problam is to use the duration.

---

## Claude — 2026-10-20 17:36

I created the branch `docs/transcript-timecode-last-unit-duration` and committed one change (`9aed51c`). It isn't pushed and there's no PR yet.

The change is a new "2026-10-20 update: repair rule for `last_unit_no_end`" section in `docs/deferred/transcript-bad-timecodes-investigate-and-fix.md`. It records:
- **Decision:** the repair for `last_unit_no_end` is to use the node's `field_kaltura_duration` as the end. I attributed it to you in a group session with Yuji and Xiaoming. David Germano's sign-off is still listed as required.
- **How it would work:** the status becomes `corrected`, the old end is kept in `end_original`, and the rule only applies when the duration is at or after the unit's start. Otherwise the unit is left as found and flagged for a person.
- **Evidence:** all 65 affected files have a duration, with the `BU_URA_…` worked example. The evidence is from local DDEV only, not dev-0.
- **Matching gotcha:** match files to nodes by `file_managed.uri`, not `filename`, because Drupal renamed colliding uploads.

I changed only that one note, which already has its `**Issue:** #353` header and its README entry. Yuji's session is also working in this repo, so check that the branch doesn't overlap with anything he has in flight.

I can push and open the PR when you're ready. Any other transcript findings to include, such as the bucket breakdown or the 0073 anomaly, can go on this branch first.

---

## User — 2026-10-20 17:36

You can push the branch but don't open a pr yet.

---

## Claude — 2026-10-20 17:36

I pushed `docs/transcript-timecode-last-unit-duration` to origin and set it to track the remote. I haven't opened a PR. GitHub printed a "create PR" link, which I didn't use.

---

## User — 2026-10-20 17:37

Now let's plan how would we fix this so that the fix carries through to the final migration of AV to production?

---

## Claude — 2026-10-20 17:37

I've sent an Explore agent to trace how transcript timecodes get from D7 into D11 and where a repair could live. I'll wait for its report before drafting the plan.

---

## User — 2026-10-20 17:38

<task-notification>
<task-id>a7c77f9417b8783c8</task-id>
<tool-use-id>toolu_01WkHeenwUvKEcJbH1BSVzmr</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/42caf695-69d8-4223-ae00-714958847230/tasks/a7c77f9417b8783c8.output</output-file>
<status>completed</status>
<summary>Agent "Explore transcript migration pipeline" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>**Main finding: the T5 transcript migration does not exist yet.** The `av_transcript` and `av_tcu` entities, the classifier and the tests are built (T2). No migrate yml, source plugin, process plugin, drush command, or `transcripts:timecode-audit` exists for transcripts. Nothing populates `timecode_status` or `end_original` today.

## Files I read
- Module files, all in `drupal/web/modules/custom/mandala_av_transcripts/`:
  - `src/TimecodeClassifier.php` (full)
  - `src/Entity/AvTcu.php` (lines 70-135 only)
  - `tests/src/Unit/TimecodeClassifierTest.php` (grep only)
  - `tests/fixtures/d7-timecodes.json` (head only)
  - `mandala_av_transcripts.install` (grep only)
- `drupal/web/modules/custom/mandala_migrations/src/Drush/Commands/AvKalturaDurationBackfillCommands.php` (first 150 lines)
- `drupal/config/sync/migrate_plus.migration_group.mandala_av.yml` (full) and `migrate_plus.migration.d7_av_workflow.yml` (head)
- Docs:
  - `docs/deferred/transcript-bad-timecodes-investigate-and-fix.md` (full)
  - `docs/planning/t2-transcript-entities-plan.md` (lines 70-115 and 155-200, plus greps)
  - `docs/sprints/sprint-04-av-transcripts.md` (grep only)
  - `docs/adr/019*.md` (grep only)
  - `docs/sprints/sprint-03-av-core-implementation.md` (grep only)
- Scripts:
  - `scripts/run-av-migration.sh` (first 120 lines)
  - `scripts/migration-cycle.sh` (grep only)
  - `scripts/verify-av-migration.sh`, `scripts/load-transcript-test-units.py` (greps or head)
- `docs/planning/migration-cycle-runbook.md` and `docs/planning/dev0-from-scratch-rebuild-runbook.md` (greps only)

I did not read `src/Entity/AvTranscript.php`, `TranscriptReader.php`, the kernel tests, `docs/deferred/production-migration-planning.md` or `staging-migration-execution-prerequisites.md` in full.

## 1. Migration, classifier, entity
- **No transcript migration.**
  - `drupal/config/sync` has no `migrate_plus` file that targets `av_transcript` or `av_tcu`.
  - The only transcript-related ones are `d7_av_workflow` and `d7_av_transcript_workflow_notes`, which are paragraph migrations.
  - `mandala_migrations/src/Plugin/migrate/source/` has only `D7AvNode`, `D7AvFile`, `D7AvFieldCollection`, the image sources and `AvLanguageLayerTrait`.
- **Classifier** (`src/TimecodeClassifier.php`):
  - It is pure and only labels; it never changes a value. Its docblock says "`end_original` and any repair are the migration's business."
  - `classify()` is at lines 71-90 and `classifyAll()` at 100-110.
  - It works in whole milliseconds.
  - `LAST_UNIT_NO_END` is returned when `$e === NULL || ($e === 0 &amp;&amp; $s &gt; 0)` and there is no next unit (line 76-78).
- **Entity** (`src/Entity/AvTcu.php`):
  - `end_original` is a nullable decimal(10,3) base field at line 117, described as "Set only when the end was changed".
  - `timecode_status` is a `list_string` at line 124, default `ok`.
  - Both are revisionable.
  - `TIMECODE_STATUSES` (lines 74-82) lists `ok`, `zero_length_shared_start`, `zero_length_other`, `inverted`, `last_unit_no_end` and `end_implausible`.
  - **`corrected` is not in `TIMECODE_STATUSES`.** The deferred note says "later `corrected`", so adding it needs a code change in the entity. Check whether it also needs an update hook.
  - The comment at line 73 still says "classifier arrives in a later phase" and is stale.
  - `ID_FLOOR` is 2,000,000 (line 70).
- **Idempotency**, from the plan only, since there is no code yet (`t2-transcript-entities-plan.md` lines 162-164):
  - "The transcript import must be idempotent and keyed on the legacy composite key, so a re-run revives the existing transcript and units instead of creating duplicates. Design this before T5."
  - Migration rollback deletes nodes, which soft-deletes their transcripts (ADR 019).
  - Notes for T5 at lines 175-190:
    - Round values before classifying.
    - Wrap the migration in `TranscriptChangeNotifier::suppressed()`.
    - Set D7 `trid` and `tcuid` explicitly.
    - Index with `kmassets:index-all` afterwards.

## 2. `field_kaltura_duration`
- It is **not filled by any `migrate_plus` migration.** It is filled by the drush backfill `av:backfill-kaltura-duration` (`AvKalturaDurationBackfillCommands.php`).
  - It joins D11 `node__field_video` or `node__field_audio` entry ids to D7 `migrate_av.node_kaltura.kaltura_duration` (whole seconds).
  - It saves nodes in place with no new revision, and supports `--dry-run`.
  - It is re-runnable and skips values that are already correct.
- The field exists on both the `video` and `audio` bundles (`field.field.node.{video,audio}.field_kaltura_duration.yml`).
- Consequence: **duration exists only after `d7_av_audio` and `d7_av_video` have run and the backfill has been run.** `scripts/run-av-migration.sh` does not call the backfill. A repair step must therefore run after the backfill. The migration should not assume the duration is present.
- `docs/deferred/av15-pbcore-duration-vs-kaltura-duration.md` says PBCore `field_duration` disagrees with the Kaltura value 41% of the time. Use `field_kaltura_duration`, as the 2026-10-20 decision specifies.

## 3. Post-migration repair and drush mechanisms
- `mandala_av_transcripts` has no `Commands` directory and no `drush.services.yml`. I did not check the `services.yml` for drush services.
- There is no `transcripts:timecode-audit`. It is only planned, in the deferred note (step 4): "re-runnable report... built with the transcript migration (Sprint 4)".
- Existing repair and backfill precedents live in `mandala_migrations/src/Drush/Commands/`:
  - `AvKalturaDurationBackfillCommands`
  - `AvInstantiationWinnerBackfillCommands`
  - `AvRelationIdentifierBackfillCommands`
  - `SubcollectionAccessBackfillCommands`
  - `MissingFileAuditCommands`
- These use the attribute style (`#[CLI\Command]`, `AutowireTrait`, `--dry-run`), which makes them the natural pattern to copy.

## 4. What the docs say about where repairs land
- **T2 plan, "Outcome of the review, 2026-10-19", decision 1** (line ~105): "Bad timecodes do not block the migration. T5 carries units as found, with the classifier status only; **no repair in the migration.** Keep the original start and end values so a later repair is possible. Than owns the transcript fixes." Decided by Yuji.
- **Deferred note, 2026-10-20 update:**
  - Than decided that the `last_unit_no_end` repair uses the media duration as the end. This covers this class only.
  - The end becomes the node's `field_kaltura_duration`, `timecode_status` becomes `corrected`, and the old end goes in `end_original`.
  - It applies only if the duration is at or after the unit's start. Otherwise the unit is left as found and flagged for a person.
  - **David Germano's sign-off is still needed before any correction is applied.**
  - 66 units in 66 transcripts by D7 DB; 65 `.vtt` and `.srt` files; all 65 have a duration.
  - Match files to nodes by `file_managed.uri`, not `filename`.
- **Open question 3** (deferred note, 2026-10-19 update): "Where repairs land after the migration: fix the source files and re-import, or edit the units in D11. A re-import is only clean if the migration can run again safely." This is **still unanswered.**
- **Related constraints:**
  - T7 (revert-to-upload) rebuilds units from the original file. A D11-side repair would be overwritten by a revert unless T7 re-applies it.
  - `end_implausible` is partly mislabelled (nid 3751 has lost starts). Do not apply a "clamp the end" rule to it.
  - The two files with short millisecond fractions would be fixed by zero-padding in the parser.
- **ADR 019:** only the soft-delete and existence-rule parts matter here. It says nothing about repairs.
- **Sprint 4:** T5 is the migration and T6 is the editor. T6 "must tolerate flagged timecodes and offer a review marker."

## 5. Tests and fixtures
- `tests/src/Unit/TimecodeClassifierTest.php` covers:
  - Real D7 rows from `tests/fixtures/d7-timecodes.json` via `testRealD7Rows`.
  - `testFixtureCoversEveryStatus`.
  - `testRules`, `testSubMillisecondNoiseDoesNotDecide` and `testClassifyAllUsesTheNextUnit`.
  - `testStatusesMatchTheEntity` (line 110), which compares the classifier's constants with the entity's status list. It will need updating if `corrected` is added.
- The fixture is a JSON file with `source` and `units[]`. Each unit has `tcuid`, `trid`, `start`, `end`, `next_start` and `expected`, all computed by an independent SQL version over the D7 `tcu` table copy of 2026-10-15.
- Kernel tests in `tests/src/Kernel/` (`AvTcuTest`, `AvTranscriptTest`, `AvTranscriptAccessTest`, `TranscriptEventsTest`, `TranscriptReaderTest`) run on a real AV7 fixture. There are no migration tests.
- I did not find how the tests are run (no phpunit invocation in the docs I grepped). I did not look at `phpunit.xml` or CI config.

## 6. How migrations are run per environment
- **AV order** is documented only in `scripts/run-av-migration.sh`, which is a DDEV-only script (`ddev drush`).
  - Order: tags, files, the inner paragraphs, the outer paragraphs, then `d7_av_audio` and `d7_av_video`, then collections, memberships, user memberships and aliases.
  - The header says "Do NOT reorder casually."
  - It is resumable because migrate skips rows already in the map.
  - It ends with "Next: `drush kmassets:index-all &amp;&amp; drush kmassets:audit`".
  - It has no transcript stage and no duration backfill.
- `scripts/verify-av-migration.sh` is the verification script. Its transcript lines only check workflow notes.
- `scripts/migration-cycle.sh` is the Images-pilot cycle (import, validate, rollback, audit). It supports `DRUSH="drush"` for staging and has OOM and resume notes on dev-0. `docs/planning/migration-cycle-runbook.md` documents it.
- Environment plans live in `docs/deferred/staging-migration-execution-prerequisites.md` and `docs/deferred/production-migration-planning.md`, plus `docs/planning/dev0-from-scratch-rebuild-runbook.md`. I did not read these, so I can't say whether the AV order or the backfill are listed there. The runbook greps showed no AV or transcript order, so check those files.

## Implications for planning
- The cleanest landing for a `last_unit_no_end` repair, consistent with the 2026-10-19 decision, is a separate re-runnable drush command in `mandala_av_transcripts` or `mandala_migrations`.
  - It would run after the T5 import and after `av:backfill-kaltura-duration`.
  - It would be idempotent, so it skips units already `corrected`.
  - It would support `--dry-run`, and flag any unit where the duration is below the start.
  - It would be gated on Germano's sign-off.
- The T5 import must not clobber `corrected` units on re-run. Either that or the repair is re-applied after every re-import.
- Adding `corrected` to `AvTcu::TIMECODE_STATUSES` is needed first. Check whether allowed values need an update hook (`.install` has only `update_10001`).
- Both the repair command and the backfill must be added to the run order and documented for dev-0, staging and production, since neither is in any script today.</result>
<usage><subagent_tokens>52362</subagent_tokens><tool_uses>11</tool_uses><duration_ms>60672</duration_ms></usage>
</task-notification>

---

## Claude — 2026-10-20 18:23

I've written your changes into the plan at `/Users/ndg8f/.claude/plans/now-let-s-plan-how-drifting-otter.md`. Nothing has been built, and I'm still in plan mode.

**What changed in the plan**
- **Post-import repair:** Yuji has confirmed it, so open question 3 is settled. The repair happens in D11 as a re-runnable step after the T5 import.
- **No sign-off:** David Germano's sign-off is dropped as a gate, on the condition that the original transcripts are backed up. I added a requirement to confirm where those backups are and which formats they cover before any apply step.
- **Repair framework:** this is only the first repair. The plan now describes one drush command, `transcripts:repair`, with `--dry-run` and `--rule=<name>`. It runs small pure rules in order, and `last_unit_no_end` → duration is rule #1.
- **Candidate later rules:** I listed them only as candidates, not as decisions. They are short-millisecond zero-padding, the paired-cue pattern (which needs a human decision), lost starts mislabelled `end_implausible`, untimed `0/0` units, the one negative start, and the inverted and placeholder classes.

**Still open**
- Where the backups of the original transcripts live, and whether they cover vtt, srt, xml and txt.
- The T5 import doesn't exist yet. It has to avoid overwriting `corrected` units on a re-run.
- `field_kaltura_duration` is only filled by the `av:backfill-kaltura-duration` drush command, which isn't in `scripts/run-av-migration.sh`. The backfill has to be added to the run order.

I also ran a pointless no-op shell command earlier. It changed nothing.

What questions or adjustments do you have?

---

## User — 2026-10-20 18:24

Does your propose plan identify which transcripts/tcus need this kind of fix and fix them or does it require a list of nodes that need this kind of fixing?

---

## Claude — 2026-10-20 18:24

It identifies and fixes them itself, so it needs no list of nodes. The plan implies this but never says it, so here is how it would work.

**Finding the units:** the repair command would scan `av_tcu` for units whose `timecode_status` is `last_unit_no_end`. The classifier assigns that status during the T5 import, using the same rule you've seen: the final unit, with end NULL or 0 and a positive start. The repair step wouldn't recompute it. This is how the plan's step 3 works.

**Fixing them:**
- It looks up each unit's transcript, then the transcript's node, through the legacy key (`field_legacy_site` + `field_legacy_nid`), then the node's `field_kaltura_duration`.
- If the duration is at or after the unit's start, it sets the end to the duration and the status to `corrected`, and keeps the old end in `end_original`.
- Otherwise it leaves the unit alone and lists it in the report.
- On a second run it skips `corrected` units, so re-runs are safe.

**Dependencies:**
- **Ordering:** it only works if T5 has already classified the units, and the duration backfill has already run. Otherwise units are reported as "no duration" and nothing is fixed.
- **Not built yet:** T5 doesn't exist, so nothing sets `last_unit_no_end` on real units today. Until T5 exists, the command has no input. I could still test it against fixtures.
- **Other rules:** each future rule would select by its own status (`inverted`, `end_implausible` and so on), so every rule can find its own units. A rule that needs a person's judgement, like the paired-cue pattern, could take an explicit list instead. A per-node override list would also be useful for exceptions, such as a transcript someone decides to leave as found.

Do you want the plan to say this explicitly, with the optional allow/deny list for exceptions?

---

## User — 2026-10-20 18:25

Above you talked about bucket 2 Placeholder or last-cue only (no real anomalies) with 350 files in it. Could these be repaired by a script?

---

## Claude — 2026-10-20 18:25

Probably yes for most of them, but the two kinds of cue in bucket 2 are different cases.

**Last cue with a bad end (301 cues)**
- **Zero-end cues:** 65 files end at `00:00:00.000`. The duration rule fixes them mechanically, and all 65 have a duration.
- **Garbage non-zero ends:** the other ~236 have values like `00:03:30.390` that don't fit the file. The same rule would work on them: replace the end with the duration if it's at or after the start. I haven't checked how many of those nodes have a duration. They would also be classed `inverted` rather than `last_unit_no_end`, so they'd need their own rule.

**Placeholder cues (526 cues)**
- **Pattern:** the cue ends 1 ms before it starts, and the next cue has the same start. In my `Describing Someone` example, cue 234 is the placeholder and cue 235, at the same start, carries the real span.
- **Not a plain timing error:** this matches the Tibetan Pottery finding. A Tibetan cue is paired with a partner cue that has the real timing. That looks like a bilingual export.
- **Candidate script fix:** set the placeholder's end to its partner's end, so both cues show together. Setting it to its own start would make it a zero-length cue. Which one is right is a content decision, which is why the note flagged "a human decision is needed".
- **Unverified:** I haven't checked that all 526 placeholders have a partner with a real span. Some may be isolated, like the `HA_KARTSHOGH…` example in 10 ms units.

For the placeholder cues, I'd first check three things:
- how many of the 526 have a partner with a real span;
- whether the partner is a different speaker or language;
- how many are isolated.

That would tell us how many a script can safely repair and how many need review. I can run that now, since it's read-only.

---

## User — 2026-10-20 18:29

For Last cue with a bad end Garbage non-zero ends, I would do those the same way by using duration if available. As for the placeholder cues, you should investigate as you suggest first, because there may be instances where multiple cues all have bad endings until the n-th cue. But my solution would be to use the start of the first one and the end of the last one and divide the time equally between the units involved. It may not be exact but it would be close enough.

---

## Claude — 2026-10-20 18:30

I checked for chains of placeholder cues across all vtt, srt and xml files, and there are none. I also added the two rules to the plan.

**Garbage non-zero last ends:** I put these in the plan as rule 2, using the duration when there is one. I haven't yet counted how many of those nodes have a duration.

**Placeholder cues:** there are 539 runs, and every run is a single cue, so no chains. A "run" here means consecutive placeholder cues, where a placeholder's end is 1 unit before its start and the next cue shares its start.
- **535 of 539:** the placeholder is followed by a cue that shares its start and has a real span.
- **The other 4:** the cue after the placeholder is itself bad. Those need a look.
- **Speakers:** in 509 of the 535, the placeholder and the following cue have the same speaker. They look like one cue split in two, not bilingual pairs. The other 26 have different speakers.

**Your equal-split rule:** it would divide the real cue's span between the two cues.
- **Typical result:** the median is 1.46 s each.
- **Too short:** 52 come out under 0.5 s.
- **Too long:** 5 come out over 30 s, and the largest is 880 s. A single cue that spans 14 minutes is suspicious on its own.
- **Check before applying:** I'd look at those 57 and see whether they're real long cues or other problems.

**What this doesn't cover:** my definition needs the cues to share a start. Chains of cues with different starts, like the 158 "next start earlier than this start" cases in the 0073 file, aren't included. Those may be a different problem, or the multi-cue chain you had in mind.

I can look at the 57 outliers, or at the 158 earlier-start cases, next.

---

## User — 2026-10-20 18:32

What is the URL to an old AV node that has a transcript where the equal split comes out too short?

---

## Claude — 2026-10-20 18:33

Here's an example, **"Lhalung Hermitage 1"**, a video. The file is `Lhalung Hermitage 1.vtt`.

- **Local D11 (DDEV):** https://mandala.ddev.site/video/lhalung-hermitage-1. The node id is 116457, so `/node/116457` also works.
- **Legacy D7:** nid **1191** on the `audio-video` site. I haven't verified the legacy hostname, so I'm giving the nid, not a full URL. If you give me the host, I can build it.

**The short cue:**
- The placeholder is cue 150. Its start is 00:05:16.833.
- The cue after it shares that start but has a real span of only 0.066 s.
- An equal split would give each cue about 0.033 s, which is clearly too short.

**Other examples:**
- **`Tibetan_Pottery_Making_Artisan_Tradition_in_Drongsar.2inqscr.vtt`:** it has 42 of the 52 short splits. It's the same Tibetan Pottery pattern as before, a bilingual export where the partner cue carries the real span. Equal split would be wrong there.
- **Other short splits:** the remaining 10 are scattered across other files, such as `A Monastic Debate at Sera Monastery I_0.vtt` (cue 1002, split 0.166 s each). I can map any of those the same way.

Is the Lhalung one the kind of case you meant?

---

## User — 2026-10-20 18:35

Open the Lhalung transcript in Sublime and tell me which line has the error?

---

## Claude — 2026-10-20 18:35

The error is on **line 749**, in cue 150. I opened the file in Sublime Text.

```
748  150
749  00:05:16.833 --> 00:05:16.832   ← ends 1 ms before it starts
750  >>[Tibetan cue text omitted]
751  >>Questioner (m): Okay.

753  151
754  00:05:16.833 --> 00:05:16.899   (same start, real span of 0.066 s)
755  >>[Tibetan cue text omitted]
756  >>Woman: Yes.

758  152
759  00:05:16.900 --> 00:05:16.899   ← same problem again, line 759
760  >>[Tibetan cue text omitted]
```

**What's happening:**
- **Alternating speakers:** the Questioner says "Okay" and the Woman says "Yes". They are short back-and-forth acknowledgements.
- **Placeholders:** cues 150 and 152 are both placeholder cues that end 1 ms before they start. Only cue 151 has a real span, and it is 0.066 s.
- **Equal split:** splitting that span would give each cue about 0.03 s, which is clearly wrong. The real timing of these overlapping acknowledgements is probably lost, so an equal split there would look precise but be invented.
- **My earlier count:** I counted cue 150 as one run with one terminator. Cue 152 is a second placeholder right after it, so these two placeholders are close together.
- **Different speakers:** the placeholder and the following cue have different speakers here, unlike most of the 509.

This file uses old Mac line endings, with only CRs. A plain `grep -n` reports a single line, so I counted lines with CRs treated as line breaks. Sublime handles them correctly.

I can check whether short, alternating-speaker spans like this are common enough to need their own rule.

---

## User — 2026-10-20 18:36

This one works fine in the transcript play on the old site. So I don't see the need for a fix. Yes see how many others are like it.

---

## Claude — 2026-10-20 18:36

Very short, Lhalung-like cases are rare.

| Placeholder cues with a real partner | 535 |
|---|---|
| Partner span under 1 s | 52 (42 are in the Tibetan Pottery file) |
| Partner span under 0.5 s | 5 |
| Partner span under 0.1 s | 1 (the Lhalung one) |

The 5 cues under 0.5 s are in four files:
- `Tibetan_Pottery_Making_Artisan_Tradition_in_Drongsar.2inqscr.vtt` (2 cues)
- `A Monastic Debate at Sera Monastery I_0.vtt`
- `Lhalung Hermitage 1.vtt`
- `ZH_NANGKOR_2014_12_09_NARRATIVES_ABOUT_THE_ROLES_OF_BONPO…01.vtt`

**Speaker check:** I couldn't test the "alternating speakers" part. My speaker detection only reads `<v Name>` tags, and Lhalung uses `>>` markers, so its speakers all read as blank. The 26 "different speaker" cases I reported earlier come only from files with `<v>` tags.

**Start-time ordering:** 96 of the 535 placeholders are in files where start times never go backwards. The rest are in files with other, separate problems, so I can't read much from that.

**What your result implies:** the old site plays Lhalung fine, which suggests playback depends on start times only. Placeholder ends then look harmless, and the repair would only matter if the D11 viewer needs a valid end. So the placeholder class may only need to stay flagged with its status, not repaired.

I can check the D7 playback code in the `mandala-drupal` repo to confirm it ignores ends, or test whether the D11 viewer needs them. Do you want either? Both are read-only.

---

## User — 2026-10-20 18:37

yes if read only do both.

---

## Claude — 2026-10-20 18:37

Reading the D7 scroller to see exactly how it uses the end time.

---

## Claude — 2026-10-20 18:38

Both checks are done. Neither one ran anything, and the real viewer isn't built yet.

**D7 player** (`transcripts_ui/js/transcripts-scroller.js` in `mandala-drupal`): it does use the end time, so I was wrong to say playback depends on starts only.
- **Highlighting:** a cue highlights only while `begin < now < end`. A cue whose end is before its start can never be highlighted.
- **Clicking:** `playOne` only seeks when `end - begin > 0`. Clicking a bad cue does nothing.
- **Pause at end of cue:** in play-and-stop mode, the player pauses when `now > end` of the current cue.

Placeholder cues like Lhalung cues 150 and 152 are therefore silently skipped in D7. They never highlight and can't be clicked. The neighbouring cue with the real span highlights instead, and nobody notices. That explains why it "works fine", and it means the placeholders were never really working.

**D11 viewer, current state:** the only viewer is the spike demo (`spike_transcript_demo/js/transcript-viewer.js`). It highlights the *last cue whose start ≤ the playhead* and has a comment saying it "handles zero/inverted ends gracefully". It ignores ends. With that rule, a placeholder that shares its start with the next cue gets replaced by the next cue at the same instant.
- **Reader output:** `TranscriptReader.php` returns `start`, `end`, `end_original` and `timecode_status` for each unit, so a real viewer can decide per status.
- **Real viewer:** it's a later task (T6), so nothing is decided yet.

**For the repair process:** leaving placeholders as found would match what D7 visitors saw. An equal-split repair would change D7 behaviour, because the placeholder cues would start highlighting and become clickable. That may be better or worse, and for very short splits like the 0.033 s Lhalung case it adds nothing visible. I'd keep the placeholders flagged and not repaired, and let T6 decide how to treat them. Should I update the plan to say that?

---

## User — 2026-10-20 18:40

yes update the plan accordingly. Note down what we've done so far. What's been handled and not in the plan. Then close out. Push changes to the branch but don't may a pull request. I will pick up tomorrow.

---

## User — 2026-10-21 10:28

[Request interrupted by user for tool use]

---

## User — 2026-10-21 10:28

Write the plan in memory and close out session

---

## User — 2026-10-21 10:28

[Request interrupted by user for tool use]

---

## User — 2026-10-21 10:29

write the plan to memory and close out session

---
