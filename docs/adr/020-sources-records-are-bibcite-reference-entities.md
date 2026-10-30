# ADR 020: Sources records are `bibcite_reference` entities, not nodes

**Status:** Accepted — 2026-10-30, by Than Grove (individual decision, open to review)
**Date:** 2026-10-30
**Deciders:** Than Grove (Spike 5 lead). **Not yet reviewed by Xiaoming Wang or Yuji Shinozaki**, who
should read this before the Sources build starts. Either may reopen it; because ADRs are
immutable, a reversal is a new ADR that supersedes this one.
**Relates to:** [Spike 5](../spikes/spike-05-bibcite-sources.md) (findings 7, 8, 9),
[ADR 011](011-group-collections-inheritance.md) (collection access model),
[ADR 016](016-public-url-structure-single-host.md) (public URLs),
[ADR 017](017-legacy-identity-composite-key.md) (legacy identity),
[ADR 014](014-hybrid-solr-proxy-design.md) (Solr visibility filter)

## Context

Sources is a bibliography. D7 stored each record as a `biblio` **node**. D11's successor module,
`bibcite`, stores each record as a `bibcite_reference` **content entity** (one bundle per reference
type), with its own contributors, keywords, CSL citation rendering and import/export. The Sources
migration therefore had two shapes: bibcite entities, or nodes with bibcite's data attached.

Spike 5 found bibcite covers 94.2% of D7's reference types 1:1 (the four custom types are
config-only to add), renders CSE citations, and supplies no Zotero API pull (custom either way).
The open question was the cost of leaving the node model that AV, Images and Texts share: access,
Solr, URLs and display all assume nodes. Spike 5 findings 8 and 9 measured that with throwaway local
prototypes (`docs/spikes/assets/spike-05-bibcite/`).

## Decision

**Sources records are `bibcite_reference` entities.** Not nodes, and not nodes with an attached
reference. The proof of concept showed the cost of that choice is bounded.

## What the proof of concept showed

- **Legacy identity and URLs work.** `bibcite_reference` is fieldable, so `field_legacy_site` +
  `field_legacy_nid` (ADR 017) attach to every reference type. `mandala_legacy_redirect` resolved
  three real D7 nids to the right records after adding `bibcite_reference` to its lookup list;
  wrong site, raw D11 id and invented nids all returned 404. Path aliases `/sources/{id}` work.
  The id in the path is the new entity id, never the D7 nid.
- **Access works.** A custom Group relation plugin for `bibcite_reference` installs on the
  collection group type. Resolving through `CollectionVisibility` gave the correct result for
  anonymous, an outsider, a private-collection member and admin. Listings needed a port of the node
  query alter and then matched. The test was shown able to fail.
- **Solr works.** The reader side (`VisibilityTokenBuilder`, the proxy) needs no change. A document
  built for a reference agreed with Drupal's access decision in all 12 viewer/record cases.
- **Display works.** Every field on the D7 record page has a source on a reference, including the
  KMaps field type (which attaches to a reference bundle) and Zotero Collections.

## Consequences

**What the Sources build must do** (the measured cost; details in Spike 5 finding 9):

1. `mandala_group_inheritance`: generalize the entity-access hook (its entity-type guard and bundle
   discovery are node-only) and `NodeQueryAlter`, or add a reference twin.
2. `mandala_kmassets_sync`: generalize the doc builder, contributors, direct sink and hooks from
   `NodeInterface` to `EntityInterface`; add a `sources` bundle entry.
3. Group config exported from a live Drupal: a relationship type for `collection` and
   `subcollection`, and the new group permission `view group_bibcite_reference entity` on every role
   that holds the node equivalent. **Without that permission Group forbids every grouped reference
   to everyone.**
4. A reference-aware record display. The canonical route renders the **`table`** view mode. It must
   reproduce the fields of the D7 record page, checked against several subtypes (see the
   [deferred note](../deferred/sources-record-display-parity-check-d7-subtypes.md)).
5. Fields on every reference type: `field_legacy_site`, `field_legacy_nid`, a Zotero Collections
   term reference, a KMaps field, and a dedicated Zotero item-key field.
6. Teach `mandala_legacy_redirect` about references. Add the `/sources/{id}` path to ADR 016.
7. Anything else that assumes a node for Sources (`mandala_node_api`, Views, the Sources API routes
   of Spike 6) needs a reference-aware version. Not measured in the spike.

**What is not built here:** every consequence above is a Sources-build task. The throwaway module
is not to be merged; it is a record of what worked.

**Risks accepted:** Sources differs from the other four sites (entities, not nodes), so views,
theming and editorial workflow need a Sources-specific path. Nobody has built a second path of
this kind yet. References have no per-record visibility override (only AV nodes have
`field_group_content_access`); visibility comes from the owning collection, which matches what D7
Sources displayed.

## Decisions made in the same Spike 5 session (Than, 2026-10-30)

- **CSE 9th edition** is the citation style (`cse_name_year`). Output differs from D7 by the
  trailing period.
- **Migrate the D7 citekeys.** All 25,627 are present and unique in the 2026-06-11 dump. Verify in
  the build that bibcite does not overwrite a supplied citekey.
- **Keep Zotero Collections**, as a vocabulary and a term-reference field.
- **Dedicated Zotero item-key field** (the prototype's reuse of `bibcite_remote_db_name` is not
  production).
- **Zotero import is on-demand only**, admin-only to start, no scheduled re-sync.
