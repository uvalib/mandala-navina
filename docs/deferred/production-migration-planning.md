# Plan the production D7 → D11 migration (future, post-staging)

**Area:** migration / deployment / data
**Raised during:** 2026-07-13 (1b.1 part 4 — D11 backend deploy scoping / dev-box audit)
**Jira:** (add when available)
**Priority:** Medium now (planning), High at cutover time

## Strategy assumption (Yuji, 2026-07-13)

**Any migration we run now is NOT the final migration.** We are migrating from the
**staging** D7 database (on `dev-1` / `rds-mysql8-staging`). Staging **lags
production by several months**, but the *shape* of the data is essentially the
same — there is little that is structurally significant in production that is not
also present in staging.

**Consequence:** working against staging now is **sufficient to prove the migration
and drive D11 development** — we do not need production data to validate the
migration code, content model, KMaps round-trip, or the Sprint 1 acceptance
criteria. This keeps development unblocked and off the production data.

## What still must be planned (this note's purpose)

A **separate production migration** is required as future work — a real D7-prod →
D11-prod cutover. It is out of scope for the current staging-based development but
must not be forgotten. Planning items:

- **Fresh extract from D7 production** at cutover time (not staging) — production is
  months ahead; re-run the migration against a current prod copy.
- **Re-validate against prod counts/shape** — the staging-calibrated baselines
  (see `1a.9` runbook + `staging-migration-execution-prerequisites.md`) will need a
  production recalibration pass; expect legitimate count drift, as already seen
  between dump vintages.
- **Cutover / downtime plan** — sequencing D7-prod read-only → migrate → D11-prod
  live; DNS/ALB switch; rollback story (the `migration-cycle.sh rollback` path).
- **Users** — production user data comes via `mandala_shared_*` (the D7 shared user
  DB kludge); the still-open user migration ([[project-d7-shared-user-database]],
  deferred `d7-shared-user-database.md`) must be resolved before a prod cutover, or
  private-collection membership will be incomplete.
- **KMaps / Solr** — reindeer_x + `mandala_kmassets_sync` re-index against the
  production Solr topology; confirm the prod write path (ADR 014).
- **Freeze / delta strategy** — decide whether cutover is a single freeze-and-migrate
  or a bulk-migrate-then-catch-up-delta, given prod keeps changing during prep.
- **Binary-file integrity verification worth keeping as a standing check
  (added 2026-09-21, scope corrected same day).** Found via `drush
  mandala:missing-file-audit` (new command, `mandala_migrations`): 126 of
  210 `group.field_featured_image` references (60%) pointed to a file
  missing from local disk on DDEV. **Correction:** initially reported as
  also confirmed missing on dev-0 -- that was wrong, caused by checking
  dev-0's filesystem at an unverified, incorrect path (`/var/www/html/...`
  instead of the container's real docroot,
  `/opt/drupal/app/drupal/web/sites/default/files/`). Properly re-checked
  via Drupal's own `file_system` service (not a raw path guess): **dev-0
  had 0 missing files, before any fix was run there.** See
  [collection-featured-images-missing-on-production.md](collection-featured-images-missing-on-production.md)
  for the full correction. This was a DDEV-local sync/bootstrap gap, not a
  cross-environment one -- the real migration output (dev-0) was intact
  the whole time. **Softens, doesn't remove, the planning implication:**
  a migration reporting success still doesn't guarantee a *given
  environment's* copy of its binaries stays complete (DDEV's clearly
  didn't), so this audit is still worth running as a standing check after
  any binary-heavy migration or environment rebuild -- just without the
  more alarming "even the canonical environment silently loses files"
  framing this note originally (incorrectly) implied.

## Related

- `staging-migration-execution-prerequisites.md` — running the migration in staging
  (the current track).
- [[project-d7-shared-user-database]] / `d7-shared-user-database.md` — user data.
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5.2 / §6 — where the
  staging-as-source / `dev-0`=dev / `dev-1`=staging facts are recorded.
