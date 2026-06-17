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
| [group-subgroup-nesting-approach.md](group-subgroup-nesting-approach.md) | collections / group module | Spike 3 | High |
| [group-access-inheritance-subcollections.md](group-access-inheritance-subcollections.md) | collections / access control | Spike 3 | High |
| [solr-sync-architecture-d11.md](solr-sync-architecture-d11.md) | solr / kmassets / kmterms | Session 2026-06-12 | High |
| [solr-pipeline-cost-discussion.md](solr-pipeline-cost-discussion.md) | solr / infrastructure | Session 2026-06-12 | High |
| [tibetan-search-quality.md](tibetan-search-quality.md) | solr / search / i18n | Session 2026-06-15 | Low (post-MVP) |
| [reindeer-x-aws-credential-strategy.md](reindeer-x-aws-credential-strategy.md) | reindeer_x / infrastructure / IAM | Spike 8 | High |
| [images-prod-packaging-monorepo-pass.md](images-prod-packaging-monorepo-pass.md) | deployment / packaging / CI | Sprint 1 (1a) | High |
| [images-agent-name-paragraph-title-mapping.md](images-agent-name-paragraph-title-mapping.md) | migration / Images / paragraphs | Sprint 1 (1a) | Medium |
