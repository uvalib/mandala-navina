# ADR 005: Redesign as Drupal single-site rather than multisite

**Status:** Accepted  
**Date:** 2026-06-11  
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The legacy Mandala platform is a Drupal 7 multisite installation running five
distinct sites (AV, Images, Sources, Texts, Mandala Home) from a shared codebase.
Each site has its own database, files directory, and settings.php, but shares
Drupal core and contributed modules.

This multisite arrangement made sense when each collection was managed and deployed
independently, but it creates significant operational overhead:

- Five separate databases to maintain, back up, and migrate.
- Configuration diverges across sites over time, making upgrades and security patches
  harder to apply consistently.
- The Aegir hosting layer that managed the multisite is itself legacy infrastructure
  that is being retired.
- Content relationships between collections (e.g. a text referencing an image) cross
  site boundaries, making cross-collection features difficult.

## Decision

The Drupal 11 rebuild will be a **single Drupal site** that consolidates all five
collections. Collection identity (AV, Images, Sources, Texts, Home) is expressed
through content types, taxonomies, and URL structure rather than separate Drupal
instances.

## Consequences

- One database, one codebase, one deployment unit — simpler operations and migrations.
- Drupal's built-in access control and content modeling handle collection separation.
- Cross-collection relationships (e.g. a text citing a source) are native entity
  references rather than API calls across sites.
- Content migration from D7 must merge five site databases into one, requiring
  careful deduplication of users, taxonomy terms, and configuration.
- URL paths for each collection must be designed to preserve existing external links
  where possible (e.g. `/texts/...`, `/images/...`).
