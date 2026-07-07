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

## Resolved / superseded

| File | Resolved by |
|---|---|
| [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
| [group-access-inheritance-subcollections.md](group-access-inheritance-subcollections.md) | [ADR 011](../adr/011-group-collections-inheritance.md) (2026-06-18) |
