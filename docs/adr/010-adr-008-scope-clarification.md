# ADR 010: Clarify the scope of "migrate, not improve" (refines ADR 008)

**Status:** Accepted
**Date:** 2026-06-16
**Deciders:** Yuji Shinozaki (Lead Architect), Xiaoming Wang, Carla Arton, Dave Goldstein, David Germano, Than Grove, Andres Montano
**Relates to / refines:** [ADR 008](008-mvp-migrate-not-improve.md) (MVP is migrate, not improve), [ADR 004](004-solr-source-of-truth.md)

> ADRs are immutable; this ADR does not edit ADR 008. It refines how ADR 008's
> "migrate, not improve" principle is to be *applied*. ADR 008's decision stands as
> written and is read through the scope boundary set here.

## Context

[ADR 008](008-mvp-migrate-not-improve.md) established "the MVP goal is faithful migration,
not improvement." As written, every example in ADR 008 — its context, its scope table,
and all of its consequences — concerns **search and Solr quality**: Tibetan tokenization,
transliteration diacritic folding, mixed Latin/Tibetan field handling, the Solr analysis
chain. ADR 008 says nothing explicit about internal data modeling or application
architecture.

In practice the principle began to be cited more broadly — e.g., to argue that the D7
Images entity graph (a primary node plus inline-referenced agent / description /
classification satellite nodes) must be migrated as-is rather than remodeled, because
remodeling would be "improvement." (See the
[Images content-model audit](../planning/images-content-model-audit.md).)

That broad reading was not the original intent. ADR 008 was meant to fence off
**user-facing quality work** — most visibly search quality — from the migration, so that
an open-ended quality initiative could not balloon the consolidation. It was not meant to
freeze internal architecture, where migrating a known-suboptimal model forward can itself
*create* cost (operational debt now, a second migration later).

Without an explicit boundary, "migrate not improve" is ambiguous exactly where it bites
hardest: data-model and architecture choices made fresh during the D11 build.

## Decision

**"Improve," in ADR 008, refers to user-facing features and behavior — not internal
architecture, data modeling, or implementation.**

The scope boundary for the MVP guardrail:

| ADR 008 defers (out of MVP scope) | ADR 008 does NOT constrain |
|---|---|
| User-facing quality improvements (search quality the canonical case) | Choice of D11 data model for migrated content (e.g., nodes vs. paragraphs/sub-entities) |
| New user-facing features not present in D7 | Internal architecture, module structure, storage shape |
| Changes to the Solr schema / analysis chain (also per ADR 004) | Implementation modernization that is invisible to end users |
| Behavioral changes users would notice | Correctness/maintainability improvements with no user-visible behavior change |

Operative test: **if an end user could perceive the change as a different or better
feature, it is "improvement" and deferred. If the change is invisible to users — an
internal modeling or architecture choice — ADR 008 does not gate it; decide it on
engineering merits.**

Guardrails on the freedom this grants:
- Internal remodeling must still satisfy **faithful migration** (ADR 008's hard
  requirement): content round-trips identically and remains retrievable via existing
  query patterns. The model may change; the data and its retrievability may not regress.
- Internal remodeling must not become a backdoor for user-facing scope creep, and must
  not change external API response shapes that the React app depends on (those are a
  Phase 5 compatibility concern, not licensed by this ADR).
- "Decide on engineering merits" means an explicit migration-risk vs. correctness call,
  recorded where the decision is made — not an automatic license to remodel everything.

## Consequences

- The **Images satellite entity graph** keep-as-nodes vs. collapse-to-paragraphs question
  is reopened as a genuine engineering decision, no longer foreclosed by ADR 008. Its
  trade-offs (node-space suppression, access propagation, orphan lifecycle, a possible
  second migration) are documented in the
  [Images content-model audit](../planning/images-content-model-audit.md) and decided there.
- Faithful migration remains the hard floor; ADR 004 (Solr source of truth) and ADR 008's
  search-quality deferral are unchanged.
- Other per-site tracks inherit the same clarified latitude: they may modernize internal
  models where doing so is user-invisible and migration-faithful.
- Ratified by the team on 2026-06-16 (same deciders as ADR 008). The
  [Images content-model audit](../planning/images-content-model-audit.md) now treats the
  ADR 008 *scope* question as settled, leaving the entity-graph keep-vs-collapse choice
  as a pure engineering decision.
