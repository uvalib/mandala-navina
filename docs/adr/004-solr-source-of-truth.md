# ADR 004: Treat existing Solr infrastructure as source of truth; defer Solr refactor

**Status:** Accepted  
**Date:** 2026-06-09  
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

Solr is a central component of the Mandala platform. The legacy system maintains
two primary indices:

| Index | Role |
|-------|------|
| `kmassets` | Content discovery — Drupal nodes indexed with KMaps-enriched metadata |
| `kmterms` | KMaps taxonomy — subjects, places, and other term domains; drives autocomplete |

The `kmterms` index is owned and maintained by the Rails KMaps application
(developed by Andres Montano), not by Mandala Drupal. The `kmassets` index is
written by the Drupal application on node save.

Refactoring Solr (infrastructure, schema, indexing strategy) is a large, independent
concern that would block or complicate the Drupal rebuild if tackled simultaneously.

## Decision

**Solr is treated as a stable external dependency and source of truth for this
development phase.** Specifically:

- The existing Solr infrastructure is not modified as part of this rebuild.
- The new Drupal 11 application reads from the existing `kmterms` and `kmassets`
  indices using the existing schema.
- The `kmterms` index is authoritative for KMaps taxonomy data; the Drupal application
  queries it but does not own it.
- The Solr write path (indexing Drupal content into `kmassets` on node save) is
  a known gap — it was present in D7 but has not been ported. This is accepted
  technical debt to be addressed in a future spike, not a blocker for the rebuild.

## Consequences

- Spikes that involve Solr (e.g., Spike 2: asset search integration) target read-only
  queries against the existing indices.
- The `solr-proxy/` component in this monorepo proxies authenticated access to the
  existing Solr instance; its interface contract is not changed.
- A future spike will be needed to prove the `kmassets` write path (node save →
  Solr indexing) before production readiness.
- VPN access to the UVA network is required for local development involving Solr
  (both `kmterms` autocomplete and `kmassets` search).

## Caveat: schema evolution

Solr schema changes are within scope for this rebuild — the "source of truth" framing
applies to the current phase, not permanently. If the `kmassets` schema is updated
(e.g., new fields, renamed fields, changed tokenization), all Solr client code must
be updated in coordination. Known clients include:

- The Drupal application (search queries, facets, indexing)
- The React front-end application (Than Grove) — queries `kmassets` directly
- The `solr-proxy/` component — may need updated field allowlists or query handling

Any proposed schema change should be treated as a cross-team interface change, not
a unilateral Drupal-side decision, and should be tracked as its own spike or ADR.
