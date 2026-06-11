# ADR 001: Consolidate all components into a single monorepo

**Status:** Accepted  
**Date:** 2026-06-09  
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The legacy Mandala platform was built across multiple separate repositories:

| Repo | Role |
|------|------|
| `mandala-drupal` | Drupal 7 multi-site codebase |
| `mandala_drupal_docker` | Aegir/Docker hosting environment |
| `mandala-solr-proxy` | Standalone Solr authentication proxy |
| `mandala_s3_synch` | S3 file sync utilities |

These were developed at different times by different people and treated as independent
projects, but they all serve a single application. This created coordination overhead,
made it hard to trace cross-component dependencies, and produced deployment machinery
that was out of step with current UVA Library practices.

## Decision

All components of the Mandala platform are consolidated into a single monorepo at
`uvalib/mandala`. The directory structure maps directly to the old repos:

| Old repo | Monorepo path |
|----------|---------------|
| `mandala-drupal` | `drupal/` |
| `mandala_drupal_docker` | *(replaced by Terraform + Ansible — see [ADR 003](003-terraform-ansible-deployment.md))* |
| `mandala-solr-proxy` | `solr-proxy/` |
| `mandala_s3_synch` | `s3-sync/` |

The legacy repos are preserved as read-only historical reference and migration source
material. Their `CLAUDE.md` files have been updated with a deprecation notice pointing
here. No new features or architectural decisions should be made in the legacy repos.

## Consequences

- All new development happens in `uvalib/mandala`.
- CI/CD pipeline, Terraform infrastructure, and deployment scripts reference the monorepo.
- Legacy repos remain accessible for reading D7 source code during migration spikes.
- Session hygiene: always open Claude Code from `/Users/ys2n/Code/uvalib/mandala`, not
  from the legacy repos, to avoid context confusion.
