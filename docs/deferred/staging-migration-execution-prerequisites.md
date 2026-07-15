# Staging Migration Execution Prerequisites
**Area:** migration / deployment / infrastructure / staging
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9)
**Jira:** (add when available)
**Priority:** High — **blocks the end-of-Sprint-1 staging acceptance run**

> **Deferral decision (2026-07-08):** The staging acceptance run has been deferred from
> 1a.9 close to **end of Sprint 1 (after Step 1b)**. Rationale: (1) the security
> acceptance criterion is already 1b-gated, so a complete run cannot happen until after
> 1b.3 anyway; (2) the local MySQL 8.4 rehearsal has already de-risked migration quality
> (ADR 012); (3) these prerequisites need to be resolved for 1b staging work regardless,
> so resolving them once for a combined 1a+1b acceptance run is more efficient. These
> prerequisites remain blocking — they just target the post-1b run, not 1a close.

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

## 2. Drush execution path in staging — ✅ RESOLVED 2026-07-15

The whole cycle is driven by `drush` (`migrate:import`, `kmassets:index-all`,
`kmassets:audit`, and the script's `DRUSH` override). The CodePipeline
(`pipeline/buildspec.yml`, `deployspec.yml`) runs **no** migrate steps — deployment only
builds and ships the image; migrations are manual. The open question was *how* a drush
command gets invoked against the running container.

**Answered by the first green pipeline run (2026-07-15).** The deploy is Ansible over
SSH onto an EC2 instance — not ECS — so the answer is plain `docker exec`, and
`deploy_backend.yml` already does it:

```
docker exec mandala-drupal-0 {{ drupal_home }}/vendor/bin/drush cr
```

That task ran green against dev-0, so the path is proven, not theoretical. No ECS
`execute-command`, no one-off task, no new IAM. Anyone with the instance key can run
`drush` in the container; the pipeline can too.

The deploy does also run `drush cim -y --partial --source=/var/simplesamlphp/drupal-config`
— but only the SimpleSAMLphp settings. There is still **no** `updb` and no full
`config:import` on deploy (dsf is the same). That is a separate open decision, tracked
in [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md).

**Owner:** DevOps / Yuji (deployment + AWS/IAM plumbing).

## Why tracked here

Both are pre-cutover infrastructure gaps, not application bugs. Resolving them in parallel
with the DDEV rehearsal is what keeps the local→staging promotion from stalling. When Jira
is live, each becomes a ticket.
