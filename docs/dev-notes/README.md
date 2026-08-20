# Developer Notes

Practical, working-level documentation for the team: **how-to guides** for common
tasks and **rituals** that describe how we run sessions and keep shared context.

Unlike [ADRs](../adr/README.md) (which record *why* a decision was made and are
immutable once accepted), developer notes are *living* documents. Update them
freely as our tools and practices evolve.

## What goes here

- **How-to's** — step-by-step task recipes (e.g. local rebuild, exporting config,
  adding a custom module, running the docs site).
- **Rituals** — the shared team practices that keep everyone's context in sync
  (session start/end, ADR/spike/deferred flushing, session logs).

## Naming convention

| Type | Pattern | Example |
|---|---|---|
| How-to | `howto-short-description.md` | `howto-run-docs-locally.md` |
| Ritual | `rituals-short-description.md` | `rituals-session-workflow.md` |

When adding a file, also list it in `.pages` (new files are invisible in mkdocs
until added there) and add a row to the index below.

## Index

| File | Type | Summary |
|---|---|---|
| [rituals-session-workflow.md](rituals-session-workflow.md) | Ritual | Session start/end rituals and shared-context discipline |
| [howto-local-dev.md](howto-local-dev.md) | How-to | Local development & rebuilds with DDEV; config workflow |
| [howto-run-docs-locally.md](howto-run-docs-locally.md) | How-to | Preview and build this docs site locally |
| [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md) | How-to | SSH into dev/staging/production EC2 nodes; docker/DB inspection |
| [howto-long-running-jobs-on-dev-staging.md](howto-long-running-jobs-on-dev-staging.md) | How-to | Dev/staging's nightly 11pm–6am shutdown window; what survives it and what doesn't |
| [howto-verify-oauth2-authenticated-path.md](howto-verify-oauth2-authenticated-path.md) | How-to | Run the ADR 014 OAuth2 regression test (SAML → token → `/oauth/userinfo`); why only step 7 counts, and the deploy timing trap |
| [howto-template.md](howto-template.md) | Template | Starting point for new how-to guides |
