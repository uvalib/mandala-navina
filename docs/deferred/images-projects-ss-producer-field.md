# `projects_ss` producer field not yet implemented in the D11 builder

**Area:** solr / kmassets / Images / write path
**Raised during:** Sprint 1 (1a.8 — doc-builder fixture validation)
**Jira:** (add when available)
**Priority:** Medium

## What we found

Two of the three golden fixtures carry a `projects_ss` field:

```
"projects_ss": ["tibet"]
```

The D11 builder does not emit it — `projects_ss` is not in the contract's
extracted Images field inventory (tables A–C of the
[doc contract §7](../planning/kmasset-solr-doc-contract.md)), so it was never
mapped. The fixture without it (`sample-image-multidesc.json`, a Bhutan image)
suggests the value is project-scoped, not universal — likely a project tag
(`tibet`) derived from a D7 field or association not yet traced in the producer
chain.

## What's needed

- **Trace the D7 source** of `projects_ss` (candidate: `field_project_name`, a
  project/group association, or a kmaps-derived tag) and confirm cardinality.
- **Map it in the builder** (a `*_ss` multivalued string — no schema change
  needed, matches the dynamic-field grammar) if it's in scope for retrieval parity.

Low-risk to add once the source is identified; flagged so it isn't silently
dropped. Cross-ref the [doc contract §7.2](../planning/kmasset-solr-doc-contract.md).
