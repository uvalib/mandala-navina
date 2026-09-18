# Session Log: AV15's last real gaps (duration, description translations), a Mandala Home demo, an IIIF data-hygiene finding, and planning for Wednesday

**Date:** 2026-09-18
**Driver:** Yuji Shinozaki (with Claude Code); Xiaoming Wang also present in session
**Outcome:** Closed out AV15's two remaining real gaps (duration, multi-language
descriptions), shipped as PR #224/#225, both deployed to dev-0. Built a curated
24-link demo on the placeholder home page, catching and correcting a subtle
"the node loads but the embedded image doesn't" bug along the way. That bug led to
a genuine, well-scoped IIIF/Cantaloupe finding — 10 known test images, not real
content, 404 on a legacy S3 key layout that predates the D11 migration entirely.
Decided (but did not build) the Mandala Home carousel's architecture. Learned
Than's return slipped from Monday to Wednesday and re-planned accordingly.

PRs merged: [#224](https://github.com/uvalib/mandala-navina/pull/224),
[#225](https://github.com/uvalib/mandala-navina/pull/225),
[#226](https://github.com/uvalib/mandala-navina/pull/226) (docs only)

---

## 1. AV15's last two real gaps

Picked up directly from the prior session's "list of all the translations" /
"duration is also missing" pushback — both were things I'd earlier written off
too quickly, and both turned out to be real, fixable gaps.

- **Description translations.** `field_pbcore_description` is multi-valued, but
  the Overview block only ever read `->first()`, silently discarding every other
  language. The reference node alone has 6 real items across English/Tibetan/
  Chinese. Checked D7's live page directly: it shows the primary description
  inline and hides the rest behind a `.showdesclang` "(Show All Languages)"
  toggle — collapsed by default. Replicated that shape with a native
  `<details>`/`<summary>` (no new JS needed) rather than porting D7's own
  hidden-class/langname-label markup.
- **Duration.** First written off as "read live from the Kaltura entry, out of
  scope." Wrong: D7 caches it locally, `node_kaltura.kaltura_duration`, joined
  via the node's already-migrated Kaltura entry id — a perfectly migratable,
  static value. Confirmed exactly on a live example (194s → "3 min 14 sec",
  matching D7's page). New `field_kaltura_duration` + `drush
  av:backfill-kaltura-duration` (`mandala_migrations`) mirror it into D11; all
  11,485 AV nodes backfilled.
- **Along the way**: also fixed a real Overview-suppression bug (an early-return
  guard was hiding the whole block, including the date, on any node with no
  creator/description/collection — 16 nodes corpus-wide) and re-checked a user
  report of a node showing nothing under "Video Overview" (turned out to be
  browser cache from before that fix).

A full-corpus check asked "so are the two duration values ever out of sync?"
found real data-quality drift: PBCore's own `field_duration` is populated on
only 17% of AV hosts, and of those, 41% disagree with `kaltura_duration` —
sometimes by a second (rounding), sometimes by minutes. Not a D11 bug (D7's UI
never shows `field_duration` either), but a genuine data-cleanup question for
Than/AV cataloging staff, filed as its own doc:
[av15-pbcore-duration-vs-kaltura-duration.md](../deferred/av15-pbcore-duration-vs-kaltura-duration.md).
Also corrected `av15-avinfo-abandoned-fields-review-with-than.md` — `avduration`
was originally filed there as a third "likely abandoned" field alongside
`avuploader`/`avrating`; it's resolved now, those two aren't.

## 2. Mandala Home demo: 24 curated links, tuned live with the user

Asked to add sample content to the placeholder home page under the Audio &
Video / Images links, showing off real fixes from this sprint. Iterated through
several rounds of direct feedback rather than landing on a final list in one
pass:

- Started at 4 AV / 3 Images / 2 groups, then expanded to "about 8 in each
  section" on request, including a deliberate AV/Images mix in the Collections
  section (not just generic ones) — picked the flagship AV node's own actual
  collection/subcollection so the demo page and the node page tell the same
  story.
- Told to lean toward Tibetan/Chinese-language content, matching what the AV/
  Images corpus actually is, rather than picking arbitrary English-titled
  examples for readability. Re-picked most of the list around real Tibetan
  cultural imagery (Lhasa rock carvings, prayer wheels, historic Frederick
  Williamson Collection photos, Buddhist murals) and Tibetan-region AV content
  (Bhutan, Amdo, Kham, Rebgong).
- Every one of the 24 final links was verified live (HTTP 200, and for groups,
  actually public) before being called done — this is what caught the next
  finding.

## 3. Blue Grosbeak: a real image node that loads but doesn't display

User caught that the Blue Grosbeak demo pick (chosen specifically to show off
the IIIF/OpenSeadragon deep-zoom viewer) had a missing image, and separately
flagged the Hummingbird image as broken too. Investigated properly rather than
assuming:

- Confirmed via the live IIIF endpoint that both 404 — with a leaked Cantaloupe
  stack trace already documented in a Sprint 1 deferred doc.
- Checked S3 directly: **the JP2 source files genuinely exist** (2.46MB,
  1.64MB, both from 2020) — not a missing-data problem. Cantaloupe is computing
  a malformed double-slash key for a flat-layout object.
- Scoped it properly, not by guessing: a first correlation (missing
  `field_iiif_mms_id`) suggested this might be corpus-wide (~38% of all Images
  nodes) — disproven by testing a wide random sample that all resolved fine.
  The real, data-confirmed boundary: every real `field_iiif_id` numeric value
  ≤100 404s, everything above resolves. Exactly 10 nodes match, all nid 1-10,
  the very first Images nodes ever migrated.
- Checked whether this was a wider Banksy-specific problem (user's hunch) — the
  other 4 Banksy-titled images all resolve fine, so the broken set stays
  exactly those 10.
- Confirmed against live D7 production directly: the same broken
  `iiif.lib.virginia.edu` URL is embedded verbatim in D7's own page markup.
  Not a D11 regression — this predates the migration entirely.
- User confirmed directly: all 10 are known test/dev images from an early
  ingest batch (John Alexander), not real archival content. Rewrote
  [iiif-cantaloupe-404-information-disclosure.md](../deferred/iiif-cantaloupe-404-information-disclosure.md)
  accordingly — reframed from "10 real broken images, ask DevOps to fix the
  key delegate" to "a data-hygiene question first" (delete the test nodes,
  which makes the infra bug moot for Mandala entirely), infra fix a distant
  second. Dropped Blue Grosbeak from the demo list; the other 7 Images picks
  already had verified real IIIF data, so no replacement was needed.

## 4. A process-management mistake, caught and fixed

Launching the dev-0 backfill, a first attempt used a shell `&` backgrounding
trick, judged (wrongly) not to have survived; a second, properly-tracked
attempt was launched on top of it. Both actually reached dev-0 and ran
concurrently — two writers contending for locks on the same table, which
explained a ~20x slowdown the user noticed and asked about directly. Diagnosed
via `ps aux` on the container (found two real `drush av:backfill-kaltura-duration`
processes, both started at the same time), killed both, and relaunched a single
run properly detached this time (`docker exec -d`, output to a log file inside
the container) so it survives independent of this session and can be checked
via a simple row-count query rather than continuous monitoring.

## 5. Shipped: PR #224, #225, #226

- **#224** — the duration field/backfill, description translations, Overview-
  suppression fix, and the first pass of the home-page demo. Merged, deployed
  clean (`config:status` gate passed).
- **#225** — dropped Blue Grosbeak from the demo + the corrected IIIF deferred
  doc. Merged, deployed clean.
- **#226** — the Mandala Home architecture decision (see below), docs-only, no
  deploy triggered (confirmed against the `trigger_paths` fix from an earlier
  session — only `drupal/**`/`package/**`/`pipeline/**` changes deploy).

Both real deploys were watched through to Deploy by `pipelineExecutionId`, not
`latestExecution` status alone (the same known trap as before — Deploy's
status lags behind which execution it belongs to).

## 6. Planning for Wednesday, not Monday

Mid-session, learned Than's return slipped from Monday 2026-09-22 to Wednesday
2026-09-24. Re-derived what's actually available in the gap from the real
project docs rather than guessing:

- **Genuinely still blocked on Than**: AV7's two remaining access realms
  (`group_access_uva_member`, `mb_collection_admin` — nobody's read the D7
  mechanism yet), AV11→AV12 (Kaltura upload track), Texts (his own track), and
  six deferred docs now queued for his review.
- **Worth reconsidering given two extra days**: Texts/Sources were paused
  2026-09-04 as a team *capacity* decision, not because Sources specifically
  needs Than — that's Xiaoming's own track. Not decided whether to resume it,
  but noted as an open question in `docs/roadmap.md` rather than left
  unexamined.
- **A real gap in the first pass at this**: initially didn't mention AV
  transcripts (Sprint 4) at all. Corrected once asked — Sprint 4 is blocked on
  [Spike 11](../spikes/spike-11-av-transcript-replication.md), still `○
  Pending`, individually owned by Than. Real prior investigation already
  exists in that spike doc (D7's transcript pipeline — Toolbox/SRT/XML → Java
  Saxon XSLT → multi-tier TCUs — already reverse-engineered from reading the
  real module code), so it's not starting from zero, but it's a substantial
  spike with a working-prototype pass criterion, not a quick task, and one of
  its central scope questions may need David Germano, not just Than.

## 7. Mandala Home: decided, not built

Asked to start on Mandala Home's real content system (currently a placeholder
linking to `/images`/`/av`). Before writing code, surfaced the real open
architecture decision already flagged in
[mandala-home-customizable-content-system.md](../deferred/mandala-home-customizable-content-system.md)
(Block Plugin vs. Layout Builder vs. a real content entity for the hero
carousel) and asked directly rather than assuming. Decided: a `mandala_home_slide`
Paragraph type + a `mandala_home_carousel` custom block_content type
referencing it — same structured-Paragraphs pattern already used everywhere
else in this project, not a new mechanism. The two static feature panels use
core's existing Basic block type. Mid-build (had started scaffolding the
Entity API script), told to hold off — **planning only, implementation starts
Monday**. Recorded the decision in the deferred doc and shipped that as its
own docs-only PR (#226) rather than leaving it only in this log.

## What's next

Monday: the regression sweep (deferred from this session so the demo/duration
work could ship first), then start on Mandala Home per the decision above.
Whether Sources resumes before Wednesday is an open team question. AV
transcripts (Spike 11) is a real, substantial candidate for Monday/Tuesday if
someone wants to run it directly rather than wait for Than, with the caveat
above about David Germano. Wednesday: everything queued specifically for Than
— AV7's two realms, AV11→AV12, Texts, and six deferred docs.
