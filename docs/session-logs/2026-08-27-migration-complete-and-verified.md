# Session Log: from-scratch migration completed, verified clean; dev-0 baseline finalized

**Date:** 2026-08-27 (continues directly from
[2026-08-26-dev0-rebuilt-from-scratch.md](2026-08-26-dev0-rebuilt-from-scratch.md))
**Participants:** Yuji Shinozaki, Claude Code
**Outcome:** The ~15h from-scratch migration launched yesterday **completed overnight with
zero errors**. The duplicate that motivated the rebuild **did not recur**. Performance was
assessed with real timestamps rather than guesswork. **Steps 7–10 are deliberately not
started** — Yuji will pick them up with Than and Xiaoming at their next meeting.

## 1. Completion, confirmed

| Migration | Result |
|---|---|
| `d7_images_shanti_image` | **111,340 / 111,340** — exact match to D7 source |
| `d7_images_image_collection_membership` | 111,304 / 111,304 |
| `d7_images_url_alias` | 111,301 / 111,301 |
| `d7_images_collection_url_alias` | 171 / 171 |
| All others (collections, subcollections, paragraphs, classifications) | complete, 0 failed |
| Errors in `/tmp/import.log` | **0** |
| Container restarts during the run | **0** (uptime unbroken since launch) |

**`integrity:legacy_nid_dupes = 0`.** This is the answer the whole rebuild was for: D7 nid
`981206`, which existed as two D11 nodes (76584, 76585) on the rolled-back environment,
**did not recur** on the from-scratch build. That confirms the group's diagnosis — the
duplicate was an artefact of a resumed OOM'd migration (`prepareRow()` re-processing rows
already in the map), not a defect in the migration logic itself.

## 2. Overnight operations: VPN drops, not dev-0 problems — twice

Two background-watch aborts happened overnight and this morning, both from the laptop's VPN
disconnecting (once expected — the laptop was closed; once not, for an unexplained ~30 min
gap). **Neither affected dev-0**: the migration runs detached inside the container, with no
dependency on the laptop's network at all, confirmed both times by checking container uptime
(unbroken) immediately after reconnecting.

One monitor script bug surfaced along the way: a `migrate:status --format=json` parse
returned a bogus `0/2` reading that looked like a real anomaly. It was a JSON-parsing break
in the watch script, not a migration event — verified directly by hand before acting on it.
Worth remembering: **a monitoring script's own confusion can look exactly like a real
incident** until checked against the source of truth.

## 3. Performance assessment — real numbers, not spot-checks

Yuji asked directly whether `shanti_image` slowed toward the end, since the visible progress
(90,333 → complete) happened entirely overnight with no status updates in between, which
*felt* slow. Rather than answer from the sparse manual polling, `migrate:status`'s per-migration
`last_imported` completion timestamps gave real segment durations:

| Migration | Rows | Duration | Avg rate |
|---|---:|---:|---:|
| `image_agent` (paragraphs) | 111,345 | 1h40m | ~1,113/min |
| `image_descriptions` (paragraphs) | 55,041 | 45m42s | ~1,204/min |
| **`shanti_image`** | 111,340 | **7h38m17s** | **~243/min** |
| `image_collection_membership` | 111,304 | 7h45m5s | ~239/min |
| `url_alias` | 111,301 | 1h24m54s | ~1,311/min |

**Conclusion: no evidence of a slowdown, and the perception was very likely the overnight
visibility gap, not the migration itself.** The paragraph rates reproduce the historical July
figures (~1,120/min, ~1,250/min) within a few percent, which validates the method. And
`shanti_image`'s ~243/min is **faster than the previously-recorded ~200/min** — which now has
an explanation rather than being a puzzle: that older figure came from a *resumed* run, and
per the existing OOM/resume finding, a resume re-processes every source row regardless of the
migrate map, so it was never a clean measurement. ~243/min is the more honest baseline going
forward. Recorded in
[migrate-large-migration-oom-and-resume-behavior.md](../deferred/migrate-large-migration-oom-and-resume-behavior.md).

**Honest limitation, stated plainly rather than glossed over:** this is a start-to-finish
average, not a rate-over-time profile. Drupal's migrate map stores no per-row timestamps, and
`general_log`/`slow_query_log` were both **off** on the RDS instance — so a genuine mid-run
deceleration, if one occurred, is not reconstructable after the fact from anything logged.
If throughput profiling matters for Texts/Sources/AV planning, a cheap periodic sampler
(cron logging count + timestamp every few minutes) needs to be running *before* a future large
migration starts, not added retroactively.

## 4. Cleanup: the dev-0 baseline is now complete and real

`scripts/baselines/dev-0.txt` previously had four verified keys and eight left as `?` — the
runbook deliberately declined to guess the paragraph/field counts, since they depend on the
source plugins' per-reference join logic, not a simple D7 table count. With a verified-clean
run now behind them (0 errors, exact node-count match, 0 legacy-nid dupes), all twelve keys
are filled from real data:

```
node:shanti_image 111340          field:field_subjects 79337
paragraph:image_agent 111345      field:field_places 68755
paragraph:image_descriptions 55041 field:field_kmap_terms 61668
paragraph:external_classification 9 field:field_kmap_collections 83494
term:external_classification_scheme 2
entity:path_alias 111301          entity:group_path_alias 171
integrity:legacy_nid_dupes 0
```

Also closed the dev-0 follow-up item on
[the alias-scope note](../deferred/d7-alias-preservation-scope-beyond-shanti-image.md) — moot
rather than merely re-checked, since the from-scratch rebuild has no rollback-era membership
data left to be stale.

## 5. What is deliberately NOT done

**Steps 7–10 of the rebuild runbook are outstanding, by design:**

7. Re-link the uid-600 test IdP identity (not a migration; hand-made, identity in
   `mandala-navina-docs` private repo).
8. Full `validate` against the now-complete dev-0 baseline (mechanically expected to pass —
   every key was read from this exact run — but not yet actually run as a gate).
9. kmassets: `delete "uid:images-11-*"` → `index-all shanti_image` → `audit --check-stale`.
   Every existing indexed doc from the pre-rebuild environment is now an orphan (different
   nids), and the fresh content isn't indexed at all yet.
10. URL smoke tests (public 200 / private 403 / bogus 404) against the rebuilt data.

**Yuji: "I will pick up the next steps when Xiaoming and Than and I next meet."** Not left
incomplete by oversight — a deliberate handoff to the group. Whoever picks this up next should
start at step 7 in
[dev0-from-scratch-rebuild-runbook.md](../planning/dev0-from-scratch-rebuild-runbook.md).

## 6. Merge freeze — now lifted

The freeze on `drupal/**`, `package/**`, `pipeline/**` from yesterday held for the duration of
the run. **The migration is complete, so it is lifted** as of this session. Also merged
[#158](https://github.com/uvalib/mandala-navina/pull/158) (the rebuild runbook itself, drafted
yesterday but never landed before execution started) and confirmed
[#162](https://github.com/uvalib/mandala-navina/pull/162) (Than's uniform-endpoint-access
decision doc) is open, clean, and untouched — left for the team.

## Artifacts

| | |
|---|---|
| Merged | [#158](https://github.com/uvalib/mandala-navina/pull/158) rebuild runbook |
| Updated | `scripts/baselines/dev-0.txt` — all 12 keys, real data |
| Updated | `migrate-large-migration-oom-and-resume-behavior.md` — clean-run rate comparison |
| Updated | `d7-alias-preservation-scope-beyond-shanti-image.md` — dev-0 follow-up closed |
| Open, not mine | [#162](https://github.com/uvalib/mandala-navina/pull/162) Than's, for the team |

## Next-session starting point

Runbook step 7, once Yuji/Than/Xiaoming meet. `full validate` (step 8) should pass
mechanically since the baseline was read from this run, but run it as a real gate anyway —
not a formality, per the standing lesson that everything checked only on paper this session
turned out to need a second look when actually run.
