# Migration Roadmap & MVP Sequencing

**Status:** Accepted — ratified by [ADR 008](adr/008-mvp-migrate-not-improve.md) and [ADR 009](adr/009-migration-sequencing-strategy.md)
**Drafted:** Session 2026-06-15

This roadmap reorganizes the two proven spikes, six pending spikes, and four
deferred items into a phased plan built around the MVP goal and a deliberate
sequencing strategy: what to build first, who works in parallel, and — critically —
what is **not** in scope.

It does not supersede any spike or ADR. It sequences them. The two decisions embedded
below have been ratified as ADRs (see *Decisions to ratify*).

## Driving decisions

- **MVP = migrate, don't improve.** Consolidate the five D7 sites (AV, Images,
  Sources, Texts, Mandala Home) into one D11 instance with *faithful* content
  migration. Quality improvements — especially Tibetan/Solr search — are explicitly
  deferred.
- **Pilot-first via a vertical slice (Images), built mob-style, then fork to
  parallel site tracks.** Build the foundation together to establish shared mental
  models and the migration pattern *before* the codebase forks per-site;
  parallelize only once that pattern is set.
- **De-risk cheaply in parallel.** The go/no-go spikes run as narrow probes (not
  full builds) alongside the pilot, to generate understanding of downstream work.

## Scope boundary (the most important guardrail)

| In scope (MVP) | Out of scope (deferred) |
|---|---|
| Faithful migration: Tibetan in = identical Tibetan out | Improving Tibetan **search** quality |
| Transliteration + diacritics preserved at correct Unicode normalization form | Mixed Latin/Tibetan field search handling |
| Content indexes and is **retrievable via existing query patterns** | Transliteration diacritic folding; Tibetan tokenization/analysis |

"Docs land in Solr" (MVP ✓) is **not** "Tibetan search works well" (deferred). The
pilot's Solr success criterion must be written narrowly. The deferred column sits
behind [ADR 004](adr/004-solr-source-of-truth.md) (Solr as source of truth; defer
the refactor) and is tracked in
[deferred/tibetan-search-quality.md](deferred/tibetan-search-quality.md).

## Tibetan risk map (where each risk actually lives)

- **True Tibetan Unicode *script*** → Texts bodies + AV transcripts. Per-site, NOT
  pervasive. A green Images pilot does **not** retire this risk.
- **Latin transliteration (EWTS/Wylie)** → bulk of Images metadata. The risk is
  **Unicode normalization fidelity** (NFC vs NFD) of diacritics through
  Migrate API → MySQL collation → Solr. Subtle, silent, and must be verified.
- **Footnote markup (CKEditor 4→5)** → Texts. **Bibliographic entities** → Sources.
  **Kaltura media** → AV. **Structured Tibetan rich-text** → Texts footnotes and
  *possibly* AV transcripts (see open question).

## Phased plan

### Phase 0 — Start now, in parallel (human-latency + cheap probes)

- **Open the Solr cost/architecture conversation with Dave Goldstein.** Gates Spike
  8's final design; this has human-scheduling latency, so it cannot wait. (deferred:
  [solr-pipeline-cost-discussion.md](deferred/solr-pipeline-cost-discussion.md),
  [solr-sync-architecture-d11.md](deferred/solr-sync-architecture-d11.md))
- **Cheap go/no-go probes** (understanding-generators, days not weeks): footnote
  transformation determinism (Than), bibcite capability for D7 reference types
  (Xiaoming), Kaltura upload + playback + entry-ID migration (owner TBD).
- **Data triage:** confirm D7 transliteration scheme + Unicode normalization form;
  triage the **AV transcript format** (resolves the one-spike-vs-two question).

### Phase 1 — Mob-build the Images pilot (the spine)

Images is the simplest *representative* site. The pilot proves the foundation
end-to-end. It deliberately isolates from the hard *content* risks (footnotes,
Kaltura, bibcite, Tibetan script) — but **does** include the access-control
foundation, because Images has substantial proxy-auth-gated content.

- **Migrate API for the 5→1 consolidation:** node-ID collision, vocabulary merge,
  KMaps term-reference survival, user dedupe, file/media handling.
- **KMaps field productionization** (Spike 1 tail): write path, ancestor-path
  resolution, migration mapping. (deferred:
  [kmaps-widget-ux.md](deferred/kmaps-widget-ux.md))
- **Solr write/sync via reindeer_x** (Spike 8 parts A/B), informed by Phase 0.
- **Proxy auth integration** (Spike 2 deferred — flagged "blocking"): **required**,
  not optional. Wire D11 into the *existing* proxy auth contract; do **not**
  redesign auth (ADR 004 / migrate-not-improve). **Two-step structuring:**
  - *Step 1a — public plumbing:* migrate Images' public subset and prove the
    foundation end-to-end (consolidation + KMaps + Solr sync + retrieval). This is
    the early demonstrable win, decoupled from auth risk.
  - *Step 1b — auth increment:* wire in proxy auth and prove the security path.
- **Access-control coherence:** Solr-proxy visibility filtering and Group collection
  access ([Spike 3](spikes/spike-03-group-collections.md) +
  [group-access-inheritance-subcollections.md](deferred/group-access-inheritance-subcollections.md))
  are two mechanisms answering the same "who can see what" question. Search-visible
  results and node-level access must agree — flagged as an integration concern.
- **Mob the first content-type migration end-to-end** (establish pattern + test +
  rollback story); individuals then replicate the pattern for remaining types.

**Verification**
- Transliteration diacritic normalization preserved (NFC/NFD fidelity).
- Content indexes and is retrievable via existing query patterns (NOT search quality).
- **Security:** a restricted Images item is non-retrievable by an unauthorized user
  via the D11 search path, and retrievable by an authorized one.

### Phase 2 — Fork to parallel site tracks

Now the deliberate parallelism earns its keep — each owner takes an independent track.

- **Texts** (Than): footnote markup transformation + Tibetan Unicode body round-trip.
- **Sources** (Xiaoming): bibcite + Tibetan-in-references if present.
- **Collections** (owner TBD): finalize Spike 3 on D11 + implement the Option D
  custom hooks for nesting and access inheritance. (deferred:
  [group-subgroup-nesting-approach.md](deferred/group-subgroup-nesting-approach.md),
  [group-access-inheritance-subcollections.md](deferred/group-access-inheritance-subcollections.md))
- **Mandala Home:** low risk; slot wherever capacity allows.

### Phase 3 — Hardest site + cutover gate

- **AV** (Kaltura + Tibetan transcripts): the convergence of the most risk vectors —
  intentionally last, after other risks are retired. **Ordering superseded 2026-09-04
  by [ADR 018](adr/018-av-track-starts-in-parallel-not-strictly-last.md):** AV starts
  as a parallel track now (personnel availability, not a revised risk call — the risk
  analysis below is unchanged), beginning with the two still-open Phase 0 items,
  Spike 7 and the AV transcript format triage.
- **Spike 6 — API URL strategy** + React app reconciliation: the cutover gate
  (single domain vs. subdomain aliases vs. 301 redirects).

## Open question (resolve before Phase 3 scoping)

**AV transcript format** — plain text vs. structured / time-coded / rich markup. If
structured, [Spike 4b](spikes/spike-04b-ckeditor5-footnotes.md) (footnotes; split
from the original Spike 4 on 2026-07-10 — see
[spike-04-ckeditor5-footnotes.md](spikes/spike-04-ckeditor5-footnotes.md) for why)
and the AV transcript work are the *same* underlying spike ("structured Tibetan
rich-text round-trip") and should share a proof.

## Decisions to ratify (ratified ADRs)

Per team practice, ADRs are accepted collaboratively. Both decisions embedded in this
roadmap have been ratified:

1. **MVP scope = migrate, not improve** — faithful migration in scope; Tibetan/Solr
   search improvement deferred. Complements ADR 004.
   → [ADR 008](adr/008-mvp-migrate-not-improve.md) (Accepted).
2. **Migration sequencing strategy** — Images two-step pilot → mob → parallel site
   tracks → AV last.
   → [ADR 009](adr/009-migration-sequencing-strategy.md) (Accepted).
