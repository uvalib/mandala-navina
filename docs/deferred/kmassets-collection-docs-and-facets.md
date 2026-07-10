# kmassets collection docs, facets, and the "subscribable" visibility mapping

**Area:** solr / kmassets / Group collections / write path
**Raised during:** Sprint 1 (1b.1 — CollectionFieldContributor implementation)
**Jira:** (add when available)
**Priority:** Medium — not required by Sprint 1's stated security acceptance
criterion, but needed for D7 collection-browsing parity

## What's scoped vs. not

`CollectionFieldContributor` (`mandala_kmassets_sync`) now populates the
security-critical subset of contract table B on **image docs**:
`visibility_i` / `visibility_s` (resolved from the owning Group's
`field_group_access`) and `collection_uid_s` (the owning collection/
subcollection's kmasset uid). This is what Sprint 1's acceptance criterion
needs — *"a restricted Images item is non-retrievable... via the D11 search
path."*

Deliberately **not** done:

1. **Collections indexed as their own kmassets docs** (`asset_type:collections`,
   carrying `members_uid_ss` per user membership). D7 indexed collection
   nodes as first-class docs; D11 collections are Group entities, and
   `mandala_kmassets_sync` only maps node bundles today
   (`mandala_kmassets_sync.settings.yml` `bundles:` has no group-entity
   equivalent) and only syncs on `hook_node_*` (no `hook_group_*` /
   `hook_group_relationship_*` triggers exist). This is materially more than
   a stub fill-in — it's indexing a new entity type, with its own sync
   triggers for both group-field changes and membership changes.
2. **Breadcrumb/facet fields**: `collection_uid_path_ss`,
   `collection_title_path_ss`, `collection_nid_path_is` (ancestor path for
   subcollections), `collection_idfacet` (format underspecified in the
   contract doc — "collection facet, group-access prepended", no worked
   example given the way `kmapid_*_idfacet`'s `header|domain-id` has), and
   `subcollection_uid_ss` / `subcollection_idfacet_ss` (only applies when a
   *collection itself* is the doc being built, which requires #1 first
   anyway). None of these gate access — they're UI/faceting metadata.

ADR 014's proxy fq (`mandala_solr_fq:{uid}`) also references
`members_uid_ss:user-{uid}` as one of its OR clauses. Until #1 lands, that
clause is a harmless no-op (no doc in the index carries the field yet) — it
doesn't break anything, but it also doesn't do anything.

## Open decision: field_group_access → visibility_i mapping

`field_group_access` (ADR 011): 0=public, 1=private, 2=subscribable.
kmassets `visibility_i` (contract §6): 1=public, 2=private, 3=uva.

There's no clean correspondence — kmassets has no "subscribable" concept, and
"uva" (network-authenticated-but-not-collection-member visibility) has no
Group equivalent. `CollectionFieldContributor` currently maps `subscribable`
to `private` (fail closed) rather than guessing it means `uva`. This should
be a real decision, not a default — worth surfacing when subscribable
collections actually get exercised (none in the current Images dataset carry
that access level based on Sprint 1 samples).

## Cross-references

- [kmassets-uid-identity-across-migration](kmassets-uid-identity-across-migration.md)
  — the `{service}-11-{d11-id}` uid pattern `groupKmassetUid()` follows
- [ADR 011](../adr/011-group-collections-inheritance.md) — Group collections
  inheritance model
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the proxy fq that
  consumes `collection_uid_s` / `members_uid_ss`
