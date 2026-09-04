# ADR 018: AV migration track starts now, in parallel — supersedes ADR 009's strict ordering (not its risk analysis)

**Status:** Accepted — Yuji Shinozaki (Lead Architect), 2026-09-04. Than Grove and
Xiaoming Wang were not in this session (Than begins a two-week absence the same day
this was decided); to be confirmed with them, not held pending it.
**Date:** 2026-09-04
**Deciders:** Yuji Shinozaki
**Supersedes:** [ADR 009](009-migration-sequencing-strategy.md) — the *ordering* only
(Phase 3's "AV strictly after Texts/Sources close" sequencing). ADR 009's risk
analysis, its Phase 0/1 content, and the "AV is hardest" conclusion are unchanged and
restated below, not revised.
**Relates to:** [Sprint 2](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md)
(closing out today), [AV/Sources/Texts migration complexity comparison](../planning/av-sources-texts-migration-complexity-comparison.md)
(2026-09-01 — the evidence this ADR relies on and does not revise), [Spike 7](../spikes/spike-07-kaltura-av-integration.md)
(Kaltura, still Pending), [Spike 11](../spikes/spike-11-av-transcript-replication.md)
(AV transcript replication, still Pending), [roadmap.md](../roadmap.md) (the still-open
"AV transcript format" Phase 0 item this ADR reactivates). **Forward references added
2026-09-04, same day, once scheduling detail resolved into concrete sprints:**
[Sprint 3](../sprints/sprint-03-av-core-implementation.md) (AV core — this ADR's track,
made concrete) and [Sprint 4](../sprints/sprint-04-av-transcripts.md) (AV transcripts,
split out as a dependent, independently-schedulable sprint — see Sprint 3/4 for the
rationale; this ADR's own Decision text still describes AV as one track, which the
sprint split refines without reversing).

> ADRs are immutable; this ADR does not edit ADR 009's content. ADR 009's Status line
> is updated to point here, matching the precedent set by [ADR 013](013-drupal-source-of-truth-solr-client-compatibility.md)
> superseding [ADR 004](004-solr-source-of-truth.md). ADR 009's Context, Phase 0/1
> content, and consequences remain accurate history and active reasoning — only the
> Phase 3 ordering claim ("AV last, after other risks are retired") is superseded.

## Context

ADR 009 sequenced migration as: Images pilot (Phase 1) → fork to parallel site tracks,
Texts (Than) and Sources (Xiaoming) (Phase 2) → AV last (Phase 3), "intentionally last,
after other risks are retired." That ordering was not a guess — the
[2026-09-01 complexity comparison](../planning/av-sources-texts-migration-complexity-comparison.md),
built from all three sites' real content-model audits, scored AV hardest (3.8/5) vs.
Sources (3.2) and Texts (2.0), and explicitly concluded it **validates** ADR 009's
sequencing rather than revising it.

That risk assessment has not changed. What has changed is team availability:

- Than is closing out Sprint 2 today and will be away for the next two weeks. Than is
  the team's most up-to-speed person on both Phase 2 tracks (Texts and Sources) — those
  tracks stall in his absence regardless of any AV decision.
- Yuji (Lead Architect) is better suited to AV of the two remaining active drivers.
- Sprint 2 (the last piece of Phase 1's Images work, per the roadmap's phasing) closes
  today, so the team is at a natural fork point already.

Two Phase 0 items from the original roadmap were never actually closed out, and remain
open regardless of this ADR: **Spike 7** (Kaltura module landscape / upload-ingest
path — still `○ Pending`, completely unstarted) and the **AV transcript format triage**
(plain text vs. structured/time-coded — the roadmap's one still-open question, gating
whether AV transcript work can reuse Spike 4b's now-*proven* structured-Tibetan
rich-text pattern, closed 2026-08-07, or needs its own proof from scratch).

## Decision

**AV becomes an active parallel track starting now**, alongside whatever pace Texts/
Sources continue at without Than, rather than waiting for Phase 2 to fully close before
Phase 3 begins. This is a resourcing decision, not a revision of the risk call: AV is
still the hardest site by the team's own evidence, and this ADR does not dispute that.

Concretely, AV work begins with the two unclosed Phase 0 items, in the same spirit as
how Images' own pilot began with cheap de-risking probes before real content-type
migration — not with implementation:

1. **Spike 7 (Kaltura integration)** — module landscape survey, playback-path
   prototype, and in particular the upload/ingest path and the Kaltura
   partner/credential re-provisioning question, since that is the one AV risk that is
   an external operational dependency rather than a Drupal-side data-shape problem.
2. **AV transcript format triage** — resolve plain-text vs. structured/time-coded
   before scoping Spike 11, so it's known up front whether Spike 4b's proof transfers
   or a new one is needed.

Real AV migration implementation (content-type migrations, the OG access-realm work,
the `MISSING_TYPE` corrupted-bundle triage) follows only once those land, mirroring
Phase 1's own "probe, then build" structure.

This does not, by itself, decide what happens to Texts/Sources while Than is out —
whether they pause, or someone else covers a reduced slice — that is a separate call
for the team, not resolved here.

## Why this doesn't contradict the 2026-09-01 comparison

That comparison scored AV hardest and said so explicitly to *validate* ADR 009, not
license moving it earlier. Nothing in this ADR disagrees — AV remains the compounding,
higher-risk site (nested `field_collection` entities, an external Kaltura dependency,
two extra OG access realms, the most unresolved unknowns). The reason to start it now
anyway is that the alternative — leaving AV untouched until Texts/Sources fully close —
assumed both of those tracks would have a driver making steady progress throughout, and
that assumption no longer holds for the next two weeks. Two Phase 0 probes (not
content-type migration) is exactly the calibrated-risk shape ADR 009 itself already
established as the right way to start a hard, unknown-heavy track.

## Consequences

- **AV's Sprint 2 content-model audit (C1) already ran ahead of the original per-owner
  sequencing**, as a pulled-forward group effort (2026-08-28 team decision, recorded in
  the Sprint 2 doc). This ADR continues that same trajectory into real spike work; it
  is not the first time AV has moved earlier than ADR 009's letter.
- **The single-shared-pattern rationale from ADR 009's Phase 1** (build the pattern once,
  mob-style, before forking) is unaffected — AV still reuses the Migrate API
  consolidation pattern, KMaps productionization, and proxy-auth integration Phase 1
  already proved. Starting AV's *probes* earlier in calendar time doesn't mean
  reinventing that foundation.
- **Kaltura re-provisioning is a live, unretired risk** (Spike 7's central question) —
  if it turns out non-trivial, that could stall the newly-parallel AV track on its own,
  independent of anything Texts/Sources-related. This was always a known risk under
  ADR 009; it is simply reachable sooner now.
- **The roadmap's open "AV transcript format" question must actually be answered** as
  part of this track's first work, not deferred again — it directly gates Spike 11's
  scope.
- If Than's absence resolves faster than two weeks, or Texts/Sources need a driver
  sooner than expected, revisit — this ADR reflects the circumstances of 2026-09-04,
  not a permanent reprioritization.

## Verification

Not yet started — this ADR authorizes Spike 7 and the AV transcript format triage to
begin; neither has run as of this writing.
