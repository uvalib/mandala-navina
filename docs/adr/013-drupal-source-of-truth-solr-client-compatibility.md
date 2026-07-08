# ADR 013: Drupal is the source of truth; Solr/client compatibility is an essential active requirement

**Status:** Accepted
**Date:** 2026-07-08
**Deciders:** Than Grove, Yuji Shinozaki (Lead Architect)
**Supersedes:** [ADR 004](004-solr-source-of-truth.md) — corrects the "Solr as source of truth" framing and reframes the Solr obligation from passive deferral to active compatibility requirement

> ADRs are immutable; this ADR does not edit ADR 004. It supersedes ADR 004's
> decision and consequences in full. ADR 004's context section (the two-index
> overview, the write-path gap note) remains accurate history.

## Context

ADR 004 stated: *"Solr is treated as a stable external dependency and source of truth
for this development phase."* This framing was adopted early in the rebuild, when the
`kmassets` write path had not yet been ported from D7 and Solr was the only live system
serving content to users. The intent was to avoid a Solr infrastructure refactor
blocking the Drupal rebuild.

Working through Sprint 1 — specifically building `mandala_kmassets_sync` (1a.8) and the
rollback/audit cycle (1a.9) — has clarified the actual relationship:

1. **Drupal is authoritatively correct.** Content field values, access control (Group
   membership, visibility), and node lifecycle (create/update/publish/delete) all
   originate in Drupal. Solr reflects what Drupal says, not the other way around.

2. **Solr is not a source of truth; it is a derived client-compatibility index.**
   Direct-Solr clients (the React KMaps app, any future consumers) depend on `kmassets`
   being accurate and schema-stable. That is a real, ongoing obligation — but it is an
   obligation Drupal upholds *by writing to Solr*, not by reading from it as authoritative.

3. **"Defer the Solr refactor" understated the obligation.** `mandala_kmassets_sync`
   exists not as deferred cleanup but as the active mechanism that keeps `kmassets`
   correct for clients. Treating Solr sync as technical debt mischaracterizes what it is.

ADR 004 also described the kmassets write path as "accepted technical debt." That gap is
now closed: `mandala_kmassets_sync` (merged Sprint 1a.8) implements synchronous
node-lifecycle hooks and the `kmassets:index-all` / `kmassets:audit` Drush commands.

## Decision

**Drupal is the source of truth for content and access.** Solr/client compatibility is
an essential, active requirement that Drupal upholds.

Specifically:

- **Drupal owns content and access.** Field values, node lifecycle, and access control
  (Group visibility, node access) are authoritative in Drupal. Downstream systems derive
  their state from Drupal, not vice versa.

- **kmassets is a derived, client-compatibility index.** The `mandala_kmassets_sync`
  module has a standing obligation to keep `kmassets` accurate and schema-compatible
  for direct-Solr clients. This is a first-class requirement, not deferred work.

- **The Solr infrastructure is not refactored as part of this rebuild.** This remains
  true — but the reason is client compatibility, not Solr authority. Changing the
  `kmassets` schema or infrastructure would break existing clients and requires
  cross-team coordination (see below).

- **kmterms is unchanged.** The `kmterms` index is owned and maintained by the Rails
  KMaps application (Andres Montano). Drupal queries it for KMaps autocomplete but does
  not write to it. This is unaffected by this ADR.

- **Drupal's native access controls are authoritative for D11 users.** When D11 renders
  a page or search result, Group/node access governs visibility — not Solr's
  `visibility_i` filter. `visibility_i` in `kmassets` exists for direct-Solr clients
  only; Drupal does not delegate access decisions to it.

- **`visibility_i` must reflect Drupal's access state.** When a node's Group visibility
  changes, `mandala_kmassets_sync` must update `visibility_i` in the corresponding
  kmassets doc so that direct-Solr clients see the correct visibility. This is part of
  the compatibility obligation.

## Consequences

- **`mandala_kmassets_sync` is load-bearing infrastructure**, not a nice-to-have. Sync
  failures leave direct-Solr clients seeing stale or incorrect content. The
  `kmassets:audit` command exists to detect and repair drift.

- **1b.1 (proxy-auth wiring)** is reframed: D11 does not pass user sessions to the
  Solr proxy. D11 enforces access natively (Group/node access). The proxy's
  `visibility_i` filter serves direct-Solr clients; D11 is responsible for writing
  `visibility_i` accurately, not for consuming it as an access gate.

- **Schema evolution** remains a cross-team interface change. The known direct-Solr
  clients are: the React front-end (Than Grove), the `solr-proxy/` component, and any
  consumers of the public kmassets API. Any proposed schema addition or change must be
  coordinated across all clients before landing.

- **VPN access** is still required for local development involving Solr (both `kmterms`
  autocomplete and `kmassets` read/write). This is an infrastructure constraint, not a
  design decision.

- ADR 004's framing of Solr as "source of truth" is retired. References to ADR 004
  in code comments, runbooks, or planning docs should be read in light of this ADR.
