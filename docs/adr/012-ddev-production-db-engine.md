# ADR 012: Local DDEV runs the production database engine (MySQL 8.4)

**Status:** Accepted
**Date:** 2026-07-07
**Deciders:** Xiaoming Wang (driving Sprint 1 1a.9); adopted as the team dev-environment standard
**Relates to:** [ADR 009](009-migration-sequencing-strategy.md) (migration sequencing), [Migration Cycle Runbook](../planning/migration-cycle-runbook.md), [1a.9 Staging Acceptance Checklist](../planning/1a9-staging-acceptance-checklist.md)

## Context

DDEV defaults to MariaDB, and this project ran **MariaDB 10.11**. The staging/prod
RDS runs **MySQL 8.4** (8.4.8). For most work the difference is invisible, but the
Images migration (Step 1a) has acceptance criteria that are **collation-sensitive** —
transliteration/NFC diacritic fidelity, sort order, and uniqueness — and collation
behavior differs between MariaDB and MySQL 8.

Two concrete symptoms surfaced during 1a.9:

1. The production dumps carry MySQL 8 collation (`utf8mb4_0900_ai_ci`). To import them
   into MariaDB, `scripts/load-d7-source.sh` **rewrote** the collation to
   `utf8mb4_general_ci`. That rewrite is a lossy downgrade — it reintroduces exactly
   the collation infidelity the 1a.9 acceptance run is supposed to *verify against*.
2. Consequently, "validated in DDEV" did **not** transfer to staging for any
   collation-dependent check: the local run exercised a different DB engine and a
   different collation than production.

The 1a.9 plan is to rehearse the migrate → validate → rollback cycle locally, then run
it in staging. That rehearsal is only meaningful if the local engine matches. DDEV
(v1.24) supports `mysql: [5.7 8.0 8.4]`, so matching the exact staging version is possible.

## Decision

**Local DDEV runs the same database engine and version as staging/prod — MySQL 8.4** —
rather than DDEV's default MariaDB. Production dumps import **natively**, with no
collation rewrite.

- `.ddev/config.yaml` → `database: { type: mysql, version: "8.4" }`.
- The load scripts must not rewrite collation (`load-d7-source.sh` had its rewrite
  removed; `load-staging-baseline.sh` imports natively and refuses to run on non-MySQL-8).
- Keep the pinned DDEV version tracking the staging RDS engine: when RDS is upgraded,
  bump `.ddev/config.yaml` in the same change.

## Consequences

**Positive**
- Collation fidelity locally — NFC/diacritic, sort, and uniqueness behave as they will
  in production, so the DDEV migration rehearsal is faithful for those criteria.
- Dumps (D7 source and staging D11 baseline) import natively; no lossy rewrite step.
- Removes a whole class of "works in DDEV, differs in staging" surprises for migration work.

**Costs / boundaries**
- Switching the engine is **destructive** to the existing DDEV database — it requires a
  rebuild (`ddev delete -Oy && ddev start`, or `ddev debug migrate-database`). Adopted
  when no one had an active DDEV checkout (everyone rebuilds from scratch), so no
  coordination cost was incurred; future engine changes carry the same rebuild cost.
- This closes the **collation** fidelity gap only. It does **not** replace the staging
  acceptance run, which still covers the deployed-artifact path, VPC/network reachability,
  and Fargate resource limits — see the
  [staging acceptance checklist](../planning/1a9-staging-acceptance-checklist.md).
- The load scripts now assume MySQL 8; running them against a MariaDB DDEV is guarded
  against (baseline script) or would fail (native `0900_ai_ci` DDL).
