# ADR 009: Migration sequencing strategy — Images pilot, mob-build, then parallel site tracks

**Status:** Accepted  
**Date:** 2026-06-15  
**Deciders:** Yuji Shinozaki (Lead Architect), Xiaoming Wang, Carla Arton, Dave Goldstein, David Germano, Than Grove, Andres Montano  
**Relates to:** ADR 005 (single-site redesign), ADR 008 (MVP is migrate, not improve)

## Context

With the MVP scope fixed by [ADR 008](008-mvp-migrate-not-improve.md), the team still
needs to decide *the order in which work happens* and *how people work together* across
the consolidation. Two proven spikes, six pending spikes, and four deferred items have
to be sequenced into a phased plan.

The hard risks are not evenly distributed across the five sites. The Tibetan risk map
shows that true Tibetan Unicode *script* lives in Texts bodies and AV transcripts (not
pervasive); the bulk of Images metadata is Latin transliteration whose risk is Unicode
normalization fidelity; footnote markup (CKEditor 4→5) lives in Texts; bibliographic
entities in Sources; Kaltura media in AV. The five sites differ sharply in difficulty.

Building every site in parallel from the start would fork the codebase before a shared
migration pattern exists. Building everything strictly serially would waste the
parallel capacity of the team and let human-latency items (e.g. the Solr
cost/architecture conversation) sit idle.

## Decision

Migration is sequenced as **a vertical-slice pilot, built mob-style, then forked to
parallel per-site tracks, with the hardest site last.**

**Phase 0 — Start now, in parallel.** Items with human-scheduling latency and cheap
go/no-go probes begin immediately, alongside the pilot:
- Open the Solr cost/architecture conversation with Dave Goldstein (gates Spike 8's
  final design).
- Run cheap go/no-go probes (footnote transformation determinism, bibcite capability,
  Kaltura upload/playback/entry-ID migration).
- Data triage: confirm D7 transliteration scheme + Unicode normalization form; triage
  the AV transcript format.

**Phase 1 — Mob-build the Images pilot (the spine).** Images is the simplest
*representative* site. Build the foundation together to establish shared mental models
and the migration pattern *before* the codebase forks per-site. The pilot deliberately
isolates the hard content risks (footnotes, Kaltura, bibcite, Tibetan script) but
**does** include the proxy-auth access-control foundation, structured in two steps:
- *Step 1a — public plumbing:* migrate Images' public subset; prove consolidation +
  KMaps + Solr sync + retrieval end-to-end. The early demonstrable win, decoupled from
  auth risk.
- *Step 1b — auth increment:* wire D11 into the *existing* proxy auth contract (do not
  redesign auth, per ADR 004) and prove the security path.

**Phase 2 — Fork to parallel site tracks.** Once the pattern is set, each owner takes an
independent track: Texts (footnotes + Tibetan body round-trip), Sources (bibcite),
Collections (Spike 3 + nesting/access-inheritance), Mandala Home (low risk, slot
wherever capacity allows).

**Phase 3 — Hardest site + cutover gate.** AV (Kaltura + Tibetan transcripts) is
intentionally last, after other risks are retired; Spike 6 (API URL strategy + React
app reconciliation) is the cutover gate.

## Consequences

- The migration pattern (Migrate API consolidation, KMaps field productionization, Solr
  sync via reindeer_x, proxy auth, rollback story) is proven once, together, before it
  is replicated — reducing divergence across per-site tracks.
- Parallelism is deliberate and *late*: it earns its keep only after the shared pattern
  exists, avoiding a premature per-site fork.
- A green Images pilot does **not** retire the Tibetan Unicode *script* risk — that lives
  in Texts and AV and is addressed in their own Phase 2/3 tracks.
- The Phase 1 Solr success criterion is written narrowly per ADR 008 (retrievable via
  existing query patterns, not search quality).
- Access-control coherence is an explicit Phase 1 integration concern: Solr-proxy
  visibility filtering and Group collection access must agree on "who can see what."
- **Open question to resolve before Phase 3 scoping:** the AV transcript format. If
  structured/time-coded/rich, the AV transcript work and Spike 4 (footnotes) are the
  same underlying "structured Tibetan rich-text round-trip" spike and should share a
  proof.
