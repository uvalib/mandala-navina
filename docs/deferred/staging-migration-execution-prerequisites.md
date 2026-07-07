# Staging Migration Execution Prerequisites
**Area:** migration / deployment / infrastructure / staging
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9)
**Jira:** (add when available)
**Priority:** High — **blocks the 1a.9 staging acceptance run**

The 1a.9 migrate → validate → rollback cycle (`scripts/migration-cycle.sh`) works locally
in DDEV, but two pieces of staging plumbing do not yet exist. Both must be resolved before
the cycle can run in staging. See the
[1a.9 Staging Acceptance Checklist §A](../planning/1a9-staging-acceptance-checklist.md) for
where these sit in the run sequence.

## 1. Migrate source DB in staging

The Migrate API reads the D7 source through Drupal's `migrate` DB connection. That
connection is defined **only inside the DDEV block** of `settings.php`
(`$databases['migrate']['default']` → the local `d7_images` DB). In staging there is no
DDEV, so the block is skipped and the connection **does not exist** — `migrate:import`
has nothing to read from.

Two sub-parts:
- **Where the D7 dump is loaded** so the staging D11 container can reach it — a secondary
  schema on the staging RDS instance, or a separate throwaway RDS. Cost / isolation /
  production-data-in-staging-infra decision.
- **An env-driven `migrate` connection for staging** — defined outside the DDEV
  conditional, reading host/db/user/password from environment/secrets the way the rest of
  the staging config does. No such wiring exists yet.

**Owner:** Xiaoming (the app-side connection code) + Yuji / Dave (RDS provisioning + the
production-data-handling decision). Related:
[Images prod packaging](images-prod-packaging-monorepo-pass.md).

## 2. Drush execution path in staging

The whole cycle is driven by `drush` (`migrate:import`, `kmassets:index-all`,
`kmassets:audit`, and the script's `DRUSH` override). The CodePipeline
(`pipeline/buildspec.yml`, `deployspec.yml`) runs **no** migrate/drush steps — deployment
only builds and ships the image; migrations are manual. So the open question is *how* a
drush command is invoked against the running staging container (ECS `execute-command` /
one-off ECS task / Ansible), and that whoever runs the acceptance test has that access.

**Owner:** DevOps / Yuji (deployment + AWS/IAM plumbing).

## Why tracked here

Both are pre-cutover infrastructure gaps, not application bugs. Resolving them in parallel
with the DDEV rehearsal is what keeps the local→staging promotion from stalling. When Jira
is live, each becomes a ticket.
