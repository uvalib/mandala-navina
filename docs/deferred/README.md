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
| [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md) | reindeer_x / deployment / CI-CD / ECR / cutover | Session 2026-07-14 (1b.1 part 4) | **Under review (2026-08-11, Yuji) — to be discussed later.** Live-reverified 2026-08-11: reindeer_x has been stopped ~4 weeks (deliberate, not a crash), no live deployment anywhere; the gating "do we need an always-on rdx" question is still open |
| [rdx-alb-target-unhealthy-in-production.md](rdx-alb-target-unhealthy-in-production.md) | reindeer_x / rdx / ALB / production defect | Session 2026-07-14 (1b.1 part 4) | High — rdx target unhealthy in PRODUCTION and dev (9001 vs live 9000); live defect, independent of the D11 rebuild; re-verified still unhealthy 2026-08-11 |
| [solr-proxy-has-no-cicd-pipeline.md](solr-proxy-has-no-cicd-pipeline.md) | deployment / CI-CD / ECR / solr-proxy / ADR 014 | Session 2026-08-11 (CI/CD inventory) | **High — DECIDED (2026-08-11, Yuji): needs a full pipeline.** Dependencies (OAuth2, Redis visibility writer) already merged; production explicitly out of scope for now |
| [s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md](s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md) | deployment / CI-CD / s3-sync / reindeer_x consolidation | Session 2026-08-11 (CI/CD inventory) | **Low — DECIDED (2026-08-11, Yuji): deferred.** `s3-sync/` is empty; its legacy content is already slated for absorption into reindeer_x (Spike 8 Part A) |
| [solr-proxy-uid1-admin-gets-anonymous-filter.md](solr-proxy-uid1-admin-gets-anonymous-filter.md) | solr-proxy / ADR 014 / visibility / docs-vs-behaviour | Session 2026-08-11 (running the proxy to validate pipeline specs) | **Medium — a trap for 1b.1 part 4 validation.** uid=1 gets the *anonymous* filter, not "no filter"; **not a D11 regression (D7 identical)**, but 4 places in the code/docs assert the opposite, and `VisibilityTokenBuilder` deliberately writes no token for uid=1 on that false premise. Admin searching for private content sees nothing → looks like ADR 014 is broken when it isn't |
| [solr-proxy-session-per-anonymous-request.md](solr-proxy-session-per-anonymous-request.md) | solr-proxy / performance / availability | Session 2026-08-11 (checking against the "public is the 90% case" principle) | **Medium** — measured: 20 anonymous requests → 20 session files. Every public request writes a session it never reuses, putting avoidable disk I/O + unbounded file growth in the hot path. Not a regression (D7 identical); scales with bot traffic, which is exactly the wrong shape |
| [solr-proxy-session-id-forwarded-to-solr.md](solr-proxy-session-id-forwarded-to-solr.md) | solr-proxy / hygiene / logging | Session 2026-08-11 (running the proxy to validate pipeline specs) | Low — `sid` is passed through to Solr (the `unset()` in `setSession()` doesn't affect the raw `QUERY_STRING` that `setParams()` re-parses), so session ids land in Solr query logs. No access-control impact; fold the fix into the next `Searcher.php` edit |
| [fail2ban-need-and-ownership.md](fail2ban-need-and-ownership.md) | infrastructure / security / scraper mitigation | Session 2026-07-14, updated 2026-08-05 | Low — an emergency measure vs an active load problem, NOT an architecture; decoupled from D11. Load DID return (2026-08-04 outage) but in a shape fail2ban doesn't address — robots.txt + cache-TTL fixed it instead; see file for detail before assuming "load returned" = "revive fail2ban" |
| [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md) | deployment / database / migration / dev environment | Session 2026-07-15 (1b.1 part 4) | **High — bootstrap (A) + D7 source dump/load DONE 2026-07-17**; migrate:import now running on dev-0; `MIGRATE_SOURCE_DATABASE`/`MIGRATE_USERS_DATABASE` still need persisting into dev-0's container env (currently ad-hoc per-invocation) |
| [migrate-group-import-aborts-on-partial-failure.md](migrate-group-import-aborts-on-partial-failure.md) | migration / DX / tooling | Session 2026-07-17 (first live dev-0 migrate:import) | Medium — `--group` aborts the whole remaining sequence on any migration's partial failure; blocks a full `--group` run every time until user migration lands |
| [migrate-large-migration-oom-and-resume-behavior.md](migrate-large-migration-oom-and-resume-behavior.md) | migration / infrastructure / DX | Session 2026-07-17/18 (dev-0 first live run) | **High — will recur on every large migration until CLI `memory_limit` is raised persistently**; 128M exhausted mid-`shanti_image`; `migrate:reset-status` needed to resume; resume re-iterates the FULL source count (no faster than a fresh run) |
| [dev-migration-slower-than-ddev-cross-az-latency.md](dev-migration-slower-than-ddev-cross-az-latency.md) | infrastructure / migration / performance | Session 2026-07-17/18 (dev-0 first live run) | Low — **decided to live with it (2026-07-18, Yuji)**; cross-AZ latency (uniform across dev/staging/prod), not CPU; laptop-then-upload and RDS durability-relaxation both investigated and rejected |
| [d7-editor-permissions-og-group-scoped-not-migrated.md](d7-editor-permissions-og-group-scoped-not-migrated.md) | migration / users / roles / Group access architecture | Dev-0 investigation 2026-07-22 | **High — D7's real editor permissions are OG group-scoped (per-collection), granted via `og_role_permission`, not core `role_permission` (empty for editor/workflow editor/shanti editor); D11's committed `content_editor` role has zero overlap with real Mandala content types (article/page only) — a sitewide role fix alone can't be faithful** |
| [simplesamlphp-never-configured-in-ddev.md](simplesamlphp-never-configured-in-ddev.md) | infrastructure / DDEV / SAML | Session 2026-08-06 (PR #75 DDEV-readiness check) | Medium — DDEV has only the Drupal-side `simplesamlphp_auth` config; the SP library itself has never been configured locally (no `netbadge-0` equivalent, no `SIMPLESAMLPHP_CONFIG_DIR`); deliberately deferred, but design the fix (mirror dev's example-auth pattern) rather than bolting on an ad hoc local `config.php` later |
| [adr-015-unanswered-questions-at-merge.md](adr-015-unanswered-questions-at-merge.md) | access / users / migration / ADR 015 | PR #75 merge 2026-08-06 | **Q1 RESOLVED** — 1(a) confirmed rid 6 (`shanti editor`) = 0 users on live dev-0 data + 0 code refs (2026-08-06); 1(b) decided (2026-08-07): `content_editor` migrates empty + hand-assigned, 142 rid-4 editors → plain authenticated. Confirms ADR 015 (no superseding ADR / no code change). Its High follow-through moved to the contributor-tier prerequisite. **Q2 DECIDED (2026-08-07):** all asset content is group-scoped — **no role (authenticated, content_editor, admin) may create content outside a group**; grant no core create; faithful to D7's intended model (36 orphans are anomalies → temp review group). **Q3 DECIDED (2026-08-07):** keep `access administration pages` (Option B — editors need the toolbar/`/admin/content`), reword checklist to "no admin *functionality* reachable"; **drop `administer url aliases`** from content_editor (keep `create url aliases` — aliases as content metadata), which removes the one real admin page that was reachable and corrects PR #75's wrong "no administer* / alias denied" claim. Needs DDEV `cim` + route re-verify. **All three questions now resolved; remaining work is downstream implementation** |
| [authenticated-contributor-crud-not-wired-in-d11.md](authenticated-contributor-crud-not-wired-in-d11.md) | access / users / migration / content model | ADR 015 Q1 follow-up 2026-08-07 | **High** — D7 authenticated users are the contributor tier (CRUD-own on all asset types). D11's `authenticated` role grants **none** of it — view-only. Makes ADR 015's "142 editors → authenticated" non-destructive; without it, migrated users can author nothing. **Per Q2 (2026-08-07):** wire as **Group member-role** perms (create within groups), NOT core site-wide create; the site-wide floor stays view-only |
| [orphaned-content-temp-group-on-migration.md](orphaned-content-temp-group-on-migration.md) | migration / Group / content model / access | ADR 015 Q2 decision 2026-08-07 | **Medium–High** — D11 forbids collection-less asset content (Q2), but D7 has orphans (36 `shanti_image`s confirmed in Images prod dump; other types/sites each need a sweep). These anomalies (mistakes / pre-collection legacy) must migrate into a **temporary review group** — not dropped, not force-fit — for human review (reassign or delete). Check whether 1b.2's membership migration already silently drops them |
| [texts-footnotes-production-transform.md](texts-footnotes-production-transform.md) | migration / Texts / CKEditor 5 / footnotes | Spike 4b closeout 2026-08-07 | **Medium** — downstream of the now-Complete Spike 4b (Option 1+3, feasibility proven + prototype). Production build for the **Texts migration**: book-outline-aware `nb{N}`/`n{N}` transform (both markup variants incl. `xmlns:i18n` ~3.3%), Option 3 Notes-list integration + tests, CKEditor 5 render check, the benign "orphan footnote 1" spot-check, and 2 content outliers (`bid=15582`/`15988`). No open technical risk — build-out, not investigation |

## Resolved / superseded

| File | Resolved by |
|---|---|
| [pipeline-triggers-on-every-monorepo-commit.md](pipeline-triggers-on-every-monorepo-commit.md) | terraform-infrastructure commit `8b753bff1`, applied 2026-07-16 (`trigger_paths` added) |
| [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
| [group-access-inheritance-subcollections.md](group-access-inheritance-subcollections.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
| [group-relationship-delete-broken-no-data-field.md](group-relationship-delete-broken-no-data-field.md) | `fix/group-relationship-delete-inherited-field` (2026-07-16) — `mandala_inherited` base field |
| [migrate-shared-vs-migrate-users-connection-duplication.md](migrate-shared-vs-migrate-users-connection-duplication.md) | `feat/user-migration` commit `216579e` (2026-07-21) — dropped `migrate_shared`, repointed `mandala_users` group at PR #49's `migrate_users`; PR #45 itself still draft on its remaining (non-duplication) checklist items |
| [d7-user-role-migration-wipes-committed-role-permissions.md](d7-user-role-migration-wipes-committed-role-permissions.md) | `fix/user-role-permission-wipe` (2026-07-24) — deleted `d7_user_role`; new array-aware `mandala_role_map` process plugin maps rids in-process so no `entity:user_role` save can wipe permissions. Permission-*correctness* (OG group-scoping) still open separately |
| [kmassets-sync-hook-fires-during-migration.md](kmassets-sync-hook-fires-during-migration.md) | PR #51 (`MigrateSyncSubscriber`, 2026-07-16) — DDEV-verified then; confirmed live on dev-0's first real `migrate:import` 2026-07-17 |
| [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) | Already resolved by `terraform-infrastructure` commit `e7bf08615` (2026-07-14) + follow-ups through 2026-07-16 — this note just never got updated. Live-reverified 2026-08-11: pipeline exists, deploys to staging/dev on every relevant `main` merge, most recently succeeded 2026-08-07 |
| [dev-0-code-config-delivery-rebuild-or-pipeline.md](dev-0-code-config-delivery-rebuild-or-pipeline.md) | Same resolution as above — Option B (the real pipeline) is what got built |
