# kmassets:audit Hardening Follow-ups
**Area:** solr / kmassets / audit / DX
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9, PR #19)
**Jira:** (add when available)
**Priority:** Low–Medium — non-blocking polish on the working `kmassets:audit` command

Two follow-ups flagged during the 1a.9 `kmassets:audit` build. The command works and is
verified end-to-end; these are hardening items, not correctness bugs.

## 1. Confirmation guard for large `--fix` runs

`kmassets:audit --fix` reindexes **every** missing doc. Against a freshly-migrated staging
index that is the whole bundle — potentially ~111k writes to the **shared** staging Solr
master. That is legitimate as the deliberate bulk-index, but there is currently no guard
against running it unintentionally. Add a confirmation prompt (or a `--yes` / dry-run
`--report-only` split, or a threshold above which it refuses without `--force`) so a large
`--fix` is always deliberate.

## 2. Pass A performance — avoid full entity loads

The missing/stale pass (`KmassetAuditor`, Drupal→Solr) loads **full node entities** in
batches just to read `id` + `changed` — ~1.5 min for 111k on DDEV. A targeted query for
`nid` + `changed` (skipping full entity hydration) would cut that dramatically. Worth doing
if the audit is run frequently or against larger asset types than Images.

## Context

Both live in `drupal/web/modules/custom/mandala_kmassets_sync/` (the `KmassetAuditor`
service + `kmassets:audit` Drush command). See
[kmassets uid identity](kmassets-uid-identity-across-migration.md) for the surrounding
identity/audit design.
