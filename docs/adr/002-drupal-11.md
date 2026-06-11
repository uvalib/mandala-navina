# ADR 002: Target Drupal 11 (not Drupal 10)

**Status:** Accepted  
**Date:** 2026-06-09  
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The project brief described this as a "Drupal 10 rebuild." At the time planning began,
Drupal 10 was the current stable release. Drupal 11 was released in late 2024 and is
now the actively developed branch. Drupal 10 will reach end-of-life in January 2026.

Since Mandala is a greenfield rebuild (not an in-place upgrade), there is no existing
Drupal 10 installation to protect.

## Decision

The target is **Drupal 11**, not Drupal 10.

Drupal 11 requires **PHP 8.3+**. All Dockerfiles, DDEV configs, and pipeline specs
should use PHP 8.3 or later.

## Consequences

- Spike work (e.g., KMaps field type) is validated against Drupal 11.
- The production Dockerfile base image will be `drupal:11.x`.
- DDEV config uses `type: drupal11`, `php_version: "8.3"`.
- Contrib modules must be confirmed compatible with Drupal 11 before adoption.
- Documentation and CLAUDE.md files refer to "Drupal 11" throughout.
