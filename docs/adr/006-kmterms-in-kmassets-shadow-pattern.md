# ADR 006: Maintain kmterms-in-kmassets shadow entries for subjects, places, and terms

**Status:** Accepted  
**Date:** 2026-06-12  
**Deciders:** Yuji Shinozaki (Lead Architect)  
**Relates to:** ADR 004 (Solr as source of truth)

## Context

The `kmassets` Solr index serves two distinct populations of documents:

| Population | Asset types | Origin |
|---|---|---|
| KMaps-derived | `subjects`, `places`, `terms` | Generated from `kmterms` |
| Drupal content | `audio-video`, `images`, `texts`, `visuals`, `sources`, `collections` | Generated from Drupal nodes |

The KMaps-derived population exists because subjects, places, and terms must be
discoverable and navigable as first-class assets within the Mandala platform — they
have their own pages, appear in search results alongside content, and are managed
conceptually as assets. Without shadow entries, the platform would need to:

- Maintain a separate query path for taxonomy terms vs. content
- Handle two different document schemas in the React front-end
- Route subject/place/term page requests to a different backing service

The shadow pattern — one kmasset document per kmterm — unifies all of this: a single
Solr index, a single document schema, a single React component model.

## Decision

**The kmterms-in-kmassets shadow pattern is retained for D11.** Every `kmterms`
entry (subject, place, or term) has a corresponding `kmassets` entry with the same
`uid` and `asset_type` equal to the kmterm domain. These entries are kept in sync by
the [`mandala-reindeer_x`](https://github.com/uvalib/mandala-reindeer_x) service.

The sync is 1:1 and unidirectional: `kmterms` is authoritative; `kmassets` shadow
entries are derived and replaceable. The `reindeer_x` service is responsible for
detecting changes and propagating them.

## How the sync works

```
KMaps Rails → S3 (kmterms-inbound)
  → SQS → ECS transform → ECS kmterms Solr update
    → [planned: SQS completion notification]
      → reindeer_x queries kmterms Solr
        → writes/updates kmassets (subjects/places/terms)
```

Currently the trigger is a UDP ping from KMaps Rails. This has a known race condition
(ping fires before ECS update completes) that causes silent stale writes. The fix —
replacing UDP with an SQS completion event from the ECS task — is planned in
[Spike 8](../spikes/spike-08-reindeer-x-consolidation.md).

## Consequences

- A running `reindeer_x` instance is required for subjects/places/terms pages to
  be current. Lag between kmterms update and kmassets sync is acceptable (eventual
  consistency), but indefinite staleness is not.
- The `reindeer_x` service must be included in the D11 deployment topology alongside
  Drupal, the Solr proxy, and the ECS ingest pipeline.
- Schema changes to `kmterms` may require corresponding changes to the `reindeer_x`
  transform logic and the `kmassets` schema. These must be coordinated across the
  KMaps Rails team (Andres Montano / Than Grove) and the Mandala team.
- The React front-end application depends on the `asset_type` field to distinguish
  shadow entries from content entries. This field is part of the stable interface
  contract (see ADR 004).
