# ADR 007: reindeer_x lives as an independent deployable service, not in the monorepo

**Status:** Accepted  
**Date:** 2026-06-12  
**Deciders:** Yuji Shinozaki (Lead Architect)  
**Relates to:** ADR 006 (kmterms-in-kmassets shadow pattern)

## Context

[`mandala-reindeer_x`](https://github.com/uvalib/mandala-reindeer_x) (formerly
`shanti-uva/kmaps-solr-sync`) is the Node.js service that maintains the
kmterms-in-kmassets shadow entries. It runs as the `reindeer_x` Docker container
on the Mandala infrastructure.

During the June 2026 session that established the kmassets architecture documentation,
the code was initially moved into the `mandala-navina` monorepo alongside `solr-proxy/`
and `s3-sync/`. This was reversed upon recognizing a fundamental difference between
these components.

## Decision

**`reindeer_x` is maintained as an independent repository (`uvalib/mandala-reindeer_x`)
and is not included in the `mandala-navina` monorepo.**

The monorepo pattern is appropriate for components that share a deployment cycle —
Drupal, the Solr proxy, the S3 sync utilities, and pipeline configuration all
ship together as a platform release. `reindeer_x` has a different character:

- It is **living production code** currently deployed on the Mandala dev server
- It has a known bug (the kmterms race condition) that can be fixed and deployed
  independently without touching Drupal or waiting for a D11 release
- Its runtime dependencies (Node.js, Redis, Docker) are separate from the Drupal
  stack
- Its release cadence is driven by sync correctness concerns, not Drupal feature work

Coupling it to the monorepo would force sync fixes to wait on Drupal release cycles
and add friction with no benefit.

## Consequences

- `reindeer_x` improvements (including Spike 8 consolidation) can be built, tested,
  and deployed independently. This is the intended path for fixing the race condition.
- `reindeer_x` needs its own CI/CD pipeline within the UVA Library infrastructure
  (buildspec, ECR push, ECS deployment). This is a follow-on infrastructure task for
  Dave Goldstein's team.
- `mandala-navina` references `reindeer_x` in `CLAUDE.md` under "Related Services"
  and in `docs/adr/` and `docs/deferred/`, but does not contain its source code.
- The `shanti-uva/kmaps-solr-sync` repository (original home) is preserved for
  historical reference. Active development proceeds in `uvalib/mandala-reindeer_x`.
- Team members working on the Mandala platform should clone `uvalib/mandala-reindeer_x`
  separately alongside `uvalib/mandala-navina`.
