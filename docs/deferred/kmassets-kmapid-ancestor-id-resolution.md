# kmapid ancestor-ID expansion needs resolved IDs the migrated field doesn't carry

**Area:** solr / kmassets / KMaps / write path
**Raised during:** Sprint 1 (1a.8 — doc-builder fixture validation)
**Jira:** (add when available)
**Priority:** High — affects KMaps faceting/discovery for every asset type

## What we found

The kmassets doc's `kmapid` field is the **ancestor-expanded** list of KMaps IDs
for each tagged term (D7 `_shanti_kmaps_fields_get_ancestor_kmap_terms`), e.g. for
a single tagged `places-18115` the golden carries the whole lineage:

```
["places-13735","places-13740","places-427","places-5279",
 "places-5562","places-69866","places-18115"]
```

The builder expands ancestors from the KMaps field's `path` column — but the
**migrated `path` holds breadcrumb NAMES, not ancestor IDs**:

```
path = "{{Earth}}{{Asia}}{{Bhutan}}{{Bumthang}}{{Tang}}{{Khangrab}}{{Ugyenchhoeling}}"
```

So the builder produces garbage (`places-{{Earth}}{{Asia}}…`) instead of numeric
ancestor IDs. The directly-tagged `kmapid_strict` is **correct** (it needs only the
term's own id/domain); only the ancestor expansion — `kmapid` and the derived
`kmapid_is` — is blocked. Field-iteration **ordering** also differs from D7
(`kmapid_strict`/`_strict_ss` came out terms-first vs the golden's
places/subjects/terms order).

## Why the data isn't there

D7 computed ancestors **live** from the kmterms Solr index
(`ancestor_ids_generic`) at index time; it never stored ancestor IDs on the node.
The D11 KMaps field stores `path` as the D7 breadcrumb-name path (distinct from the
`raw` issue in [kmaps-raw-format-rebuild-on-migration](kmaps-raw-format-rebuild-on-migration.md),
which was about the pipe-composite). Numeric ancestor IDs live only in kmterms Solr.

## Options (decision needed)

1. **Resolve at migration time** — populate `path` (or a new column) with the
   numeric `ancestor_id_path` via the existing
   `shanti_kmaps_fields\KmapsPathResolver` (it already queries kmterms Solr for
   `ancestor_id_path`). The builder then reads IDs directly — no build-time Solr
   dependency. Preferred.
2. **Resolve at build time** — builder calls `KmapsPathResolver` per term. Adds a
   live kmterms-Solr dependency to every doc build; cacheable but slower.
3. Confirm the D11 `path` semantics and pick a single canonical representation.

Also: fix KMaps **field ordering** so `kmapid_strict`/`_strict_ss`/idfacets match
D7's order (the goldens are places → subjects → terms).

Until resolved, the builder's ancestor expansion is correct in *approach* but
starved of input data. Cross-ref
[kmassets-uid-identity-across-migration](kmassets-uid-identity-across-migration.md).
