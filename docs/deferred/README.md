# Deferred Notes

Items noted during spike work or development that need to be addressed in downstream
implementation. Each file is one logical issue. When Jira is available, each file
should map to a ticket (add the ticket key to the file's header at that time).

## Naming convention

`AREA-short-description.md`

Examples: `kmaps-widget-ux.md`, `migration-tibetan-unicode.md`, `api-url-strategy.md`

## File header

```
# Title
**Area:** module / feature area
**Raised during:** Spike N / Phase N
**Jira:** (add when available)
**Priority:** High / Medium / Low
```

## Open items

| File | Area | Raised | Priority |
|---|---|---|---|
| [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) | solr / kmassets / kmterms | Session 2026-06-12 | High |
| [solr-pipeline-cost-discussion.md](solr-pipeline-cost-discussion.md) | solr / infrastructure | Session 2026-06-12 | High |
| [tibetan-search-quality.md](tibetan-search-quality.md) | solr / search / i18n | Session 2026-06-15 | Low (post-MVP) |
| [reindeer-x-aws-credential-strategy.md](reindeer-x-aws-credential-strategy.md) | reindeer_x / infrastructure / IAM | Spike 8 | High |
| [images-prod-packaging-monorepo-pass.md](images-prod-packaging-monorepo-pass.md) | deployment / packaging / CI | Sprint 1 (1a) | High |
| [images-agent-name-paragraph-title-mapping.md](images-agent-name-paragraph-title-mapping.md) | migration / Images / paragraphs | Sprint 1 (1a) | Medium |
| [iiif-cantaloupe-404-information-disclosure.md](iiif-cantaloupe-404-information-disclosure.md) | infrastructure / security / IIIF | Sprint 1 (1a.5) | Medium |
| [iiif-prefix-alignment-mandala-vs-canonical.md](iiif-prefix-alignment-mandala-vs-canonical.md) | IIIF / configuration | Sprint 1 (1a.5) | Low |
| [migrate-drupal-noise-site-specific-dump.md](migrate-drupal-noise-site-specific-dump.md) | migrate / DX | Sprint 1 (1a.6) | Low |
| [kmaps-raw-format-rebuild-on-migration.md](kmaps-raw-format-rebuild-on-migration.md) | migration / KMaps | Sprint 1 (1a.7) | High |
| [images-field-image-binary-migration.md](images-field-image-binary-migration.md) | migration / Images / files | Sprint 1 (1a.7) | Low |
| [image-descriptions-summary-length.md](image-descriptions-summary-length.md) | migration / Images / content model | Sprint 1 (1a.7) | Low |
| [images-rotation-field-support.md](images-rotation-field-support.md) | solr / kmassets / Images / write path | Sprint 1 (1a.8) | Medium |
| [kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md) | solr / kmassets / migration / identity | Sprint 1 (1a.8) | High |
| [kmassets-uid-consumer-analysis.md](kmassets-uid-consumer-analysis.md) | solr / kmassets / migration / identity / clients | Sprint 1 (1a.8) | High |
| [kmassets-kmapid-ancestor-id-resolution.md](kmassets-kmapid-ancestor-id-resolution.md) | solr / kmassets / KMaps / write path | Sprint 1 (1a.8) | High |
| [images-description-text-format-fidelity.md](images-description-text-format-fidelity.md) | migration / Images / text formats | Sprint 1 (1a.8) | Medium |
| [images-projects-ss-producer-field.md](images-projects-ss-producer-field.md) | solr / kmassets / Images / write path | Sprint 1 (1a.8) | Medium |
| [images-iiif-thumb-size-format.md](images-iiif-thumb-size-format.md) | IIIF / kmassets / Images / write path | Sprint 1 (1a.8) | Low |
| [kmassets-sync-error-management.md](kmassets-sync-error-management.md) | solr / kmassets / sync / observability | Session 2026-06-26 | Medium (own downstream sprint; not Sprint 1) |
| [jira-issue-tracking-integration.md](jira-issue-tracking-integration.md) | process / project tooling / tracking | PM session 2026-06-25 | Medium → High (start after Sprint 1) |
| [staging-migration-execution-prerequisites.md](staging-migration-execution-prerequisites.md) | migration / deployment / infrastructure / staging | Session 2026-07-07 (1a.9) | High (blocks 1a.9 staging run) |
| [kmassets-audit-hardening.md](kmassets-audit-hardening.md) | solr / kmassets / audit / DX | Session 2026-07-07 (1a.9) | Low–Medium |
| [load-staging-baseline-false-clean-on-nonzero-schema.md](load-staging-baseline-false-clean-on-nonzero-schema.md) | migration / tooling / DX / scripts | Session 2026-07-07 (1a.9) | Medium |
| [oauth-openid-scope-client-credentials-crash.md](oauth-openid-scope-client-credentials-crash.md) | auth / simple_oauth / OAuth2 | Spike 10 | Medium |
| [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md) | migration / process / DX | Session 2026-07-10 | High |
| [kmassets-collection-docs-and-facets.md](kmassets-collection-docs-and-facets.md) | solr / kmassets / Group collections / write path | Session 2026-07-10 (1b.1) | Medium |
| [d7-shared-user-database.md](d7-shared-user-database.md) | migration / users / infrastructure | Session 2026-07-10 (1b.1 planning) | High — blocks user migration + everything gated on it |
| [saml-alb-routing-assumes-mod-shib.md](saml-alb-routing-assumes-mod-shib.md) | deployment / infrastructure / SAML / NetBadge / terraform | Session 2026-07-13 (1b.1 part 4) | High — mandala ALB routing assumes mod_shib but SP is SimpleSAMLphp; blocks NetBadge on AWS deploy |
| [redis-enterprise-store-location.md](redis-enterprise-store-location.md) | infrastructure / Redis / ADR 014 / SAML session store / Drupal object cache | Session 2026-07-14 (1b.1 part 4) | Medium — TWO stores required (sessions + bigger object cache); dev settled on-box, enterprise location deferred |
| [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) | deployment / CI-CD / ECR / CodePipeline | Session 2026-07-14 (1b.1 part 4) | High — no ECR repo, no CodePipeline; buildspec/deployspec are orphaned, blocks part-4 validation |
| [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md) | reindeer_x / deployment / CI-CD / ECR / cutover | Session 2026-07-14 (1b.1 part 4) | High — hand-built on dev-0 with no ECR/pipeline/specs; does not survive the in-place dev-0 replacement |
| [rdx-alb-target-unhealthy-in-production.md](rdx-alb-target-unhealthy-in-production.md) | reindeer_x / rdx / ALB / production defect | Session 2026-07-14 (1b.1 part 4) | High — rdx target unhealthy in PRODUCTION and dev (9001 vs live 9000); live defect, independent of the D11 rebuild |
| [fail2ban-need-and-ownership.md](fail2ban-need-and-ownership.md) | infrastructure / security / scraper mitigation | Session 2026-07-14, updated 2026-08-05 | Low — an emergency measure vs an active load problem, NOT an architecture; decoupled from D11. Load DID return (2026-08-04 outage) but in a shape fail2ban doesn't address — robots.txt + cache-TTL fixed it instead; see file for detail before assuming "load returned" = "revive fail2ban" |
| [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md) | deployment / database / migration / dev environment | Session 2026-07-15 (1b.1 part 4) | **High — bootstrap (A) + D7 source dump/load DONE 2026-07-17**; migrate:import now running on dev-0; `MIGRATE_SOURCE_DATABASE`/`MIGRATE_USERS_DATABASE` still need persisting into dev-0's container env (currently ad-hoc per-invocation) |
| [migrate-group-import-aborts-on-partial-failure.md](migrate-group-import-aborts-on-partial-failure.md) | migration / DX / tooling | Session 2026-07-17 (first live dev-0 migrate:import) | Medium — `--group` aborts the whole remaining sequence on any migration's partial failure; blocks a full `--group` run every time until user migration lands |
| [migrate-shared-vs-migrate-users-connection-duplication.md](migrate-shared-vs-migrate-users-connection-duplication.md) | migration / users / infrastructure / DX | Session 2026-07-17 (PR sweep) | **High — blocks PR #45 from merging as-is**; PR #45's `migrate_shared` connection duplicates PR #49's already-merged `migrate_users` mechanism; flagged to Than on the PR |
| [migrate-large-migration-oom-and-resume-behavior.md](migrate-large-migration-oom-and-resume-behavior.md) | migration / infrastructure / DX | Session 2026-07-17/18 (dev-0 first live run) | **High — will recur on every large migration until CLI `memory_limit` is raised persistently**; 128M exhausted mid-`shanti_image`; `migrate:reset-status` needed to resume; resume re-iterates the FULL source count (no faster than a fresh run) |
| [dev-migration-slower-than-ddev-cross-az-latency.md](dev-migration-slower-than-ddev-cross-az-latency.md) | infrastructure / migration / performance | Session 2026-07-17/18 (dev-0 first live run) | Low — **decided to live with it (2026-07-18, Yuji)**; cross-AZ latency (uniform across dev/staging/prod), not CPU; laptop-then-upload and RDS durability-relaxation both investigated and rejected |

## Resolved / superseded

| File | Resolved by |
|---|---|
| [pipeline-triggers-on-every-monorepo-commit.md](pipeline-triggers-on-every-monorepo-commit.md) | terraform-infrastructure commit `8b753bff1`, applied 2026-07-16 (`trigger_paths` added) |
| [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
| [group-access-inheritance-subcollections.md](group-access-inheritance-subcollections.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
| [group-relationship-delete-broken-no-data-field.md](group-relationship-delete-broken-no-data-field.md) | `fix/group-relationship-delete-inherited-field` (2026-07-16) — `mandala_inherited` base field |
| [kmassets-sync-hook-fires-during-migration.md](kmassets-sync-hook-fires-during-migration.md) | PR #51 (`MigrateSyncSubscriber`, 2026-07-16) — DDEV-verified then; confirmed live on dev-0's first real `migrate:import` 2026-07-17 |
