# AV migration verification: orphaned-paragraph count drifted from documented "3 expected" to 2

**Area:** migration / AV / data fidelity / verification tooling
**Raised during:** Session 2026-09-21 (regression sweep after Mandala Home carousel work)
**Jira:** (add when available)
**Priority:** Low — pre-existing drift, not caused by this session's changes, no known user-facing symptom

## What happened

Running `scripts/verify-av-migration.sh` on DDEV (as part of an otherwise
unrelated regression sweep) surfaced 2 failures, previously undetected:

```
FAIL orphaned av_* paragraphs (3 expected -- excluded instantiation hosts) expected 3, got 2
FAIL instantiation -> format_id refs                expected 2249, got 2250
```

Both checks originate from the same documented edge case
(`docs/planning/av-node-migration-notes.md` §3): `field_pbcore_instantiation`
is cardinality-1, so 667 hosts with both an `en` and `und` D7 item needed a
single winner picked; the losing item's own D7-source `field_pbcore_format_id`
nested item still gets migrated as a paragraph (D7AvFieldCollection processes
nested collections independently of their eventual host), but with no parent
— hence "3 expected orphans," one per named host (3556, 7408, 420971).

Live now: only **2** `av_*` paragraphs have `parent_id IS NULL` (ids 166484,
166974, both `av_pbcore_format_id`), and the D7-computed expected
`format_id` reference count is one *fewer* than the live D11 count — i.e.
one paragraph that should be an orphan per this rule now has a parent, and
its reference is being counted where it wasn't before.

## What this is NOT

Checked directly before filing this, since it's tempting to blame the
2026-09-17 `av:backfill-instantiation-winner` fix
([[av4-instantiation-wrong-winner]]) — that fix explicitly **overwrites
sub-field values on the existing, already-referenced paragraph in place**;
it never changes which item is referenced or re-parents anything. So it
cannot be the cause of a parent_id changing on one of these three specific
paragraphs.

Also checked: this session's own work (Mandala Home carousel,
`mandala_home`/`mandala_kaltura` modules, docs) touched zero AV
paragraph/node data — no `mandala_home_slide`/`mandala_home_carousel`
script came anywhere near `paragraphs_item_field_data`,
`field_pbcore_instantiation`, or `field_pbcore_format_id`. This drift
predates today's session; the regression sweep just happened to be the
first time anyone re-ran this specific check since it was added.

## What's still unknown

- Legacy nid lookups for 2 of the 3 named hosts (7408, 420971) return
  **no match at all** in `node__field_legacy_nid` on this DDEV DB — only
  3556 resolves (and ambiguously: two D11 nodes share that legacy nid,
  53 and 122373, only one on the `audio-video` site — the known
  [[migration-legacy-nid-required-convention]] non-uniqueness issue).
  Whether that's expected (a scoping mismatch in how the "3 hosts" were
  originally identified) or itself part of the drift is unconfirmed.
- No prior session log shows this specific check (added alongside the
  2026-09-17 instantiation-winner fix, since it's evaluating the same
  §3 language-layer edge case) having been run and passed once
  end-to-end — it may never have been genuinely green since it was
  written, rather than having "drifted" from a real prior-passing state.
- Whether dev-0/staging show the same 2-vs-3 count (not checked this
  session — this was a DDEV-only regression sweep).

## Suggested next step

Not urgent (no known user-facing symptom — the "extra" paragraph having a
parent instead of being orphaned, if anything, sounds like an
*improvement* over the documented 3-orphan state, not a regression). Worth
a short investigation to either (a) confirm the "3" in
`scripts/verify-av-migration.sh` and its sibling doc note should just be
updated to "2" reflecting genuinely-current reality, or (b) find what
actually changed one specific paragraph's parent and whether the other 2
orphans are still correctly the intended, documented ones.
