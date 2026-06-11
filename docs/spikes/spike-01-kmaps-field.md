# Spike 1: KMaps Field Type on Drupal 11
**Status:** Proven  
**Date:** 2026-06  
**Commit:** 038fd1c (deep work), aa5e47b (htaccess fix)

## Theory
The D7 `shanti_kmaps_fields_default` field type — with its 6-column schema and
dependency on an external KMaps taxonomy — can be ported to the Drupal 11 Field
API without architectural blockers.

## Demo
DDEV site: `https://mandala.ddev.site:8443`  
Login: `ddev drush uli` (generates a one-time link)

| What | URL |
|---|---|
| Node view — terms rendered as linked tag pills | `/node/1` |
| Node edit — autocomplete picker against live Solr | `/node/1/edit` |
| KMaps admin config | `/admin/config/content/shanti-kmaps-admin` |

Demo node has two verified real KMaps terms:
- **Buddhism** (`subjects-2610`) — path `6403/272/282/2610`
- **Tibet** (`places-5226`) — path `13735/13740/13734/5226`

Autocomplete queries the live `kmterms` Solr index (requires UVA VPN).

## Findings

- The 6-column schema (`raw`, `id`, `header`, `domain`, `path`, `defids`) maps
  directly to D10 Field API column definitions in `FieldType::schema()`. No
  schema changes needed.
- All three plugin types register and function correctly: `FieldType`,
  `FieldWidget`, `FieldFormatter`.
- Multiple KMaps terms per node (mixed domains: subjects + places) save and
  reload correctly from MariaDB.
- A PHP autocomplete controller can proxy queries to the `kmterms` Solr index
  and return results in D10 autocomplete format. VPN access is sufficient; no
  special infrastructure is needed for local development.
- `shanti_kmaps_admin` server configuration ports cleanly to D10 `ConfigFormBase`.
- The formatter renders terms as linked tags pointing to the live KMaps explorer.

## What this does NOT establish

- **Migration path.** This spike proves the D10 field can hold KMaps data, not
  that D7 content can be moved into it. A separate migration spike is needed to
  prove that Drupal Migrate can read D7 `field_data_*` tables and write correctly
  into D10 field instances.
- **Full widget UX.** The autocomplete picker is functional but not production-ready.
  See deferred note below.
- **Solr indexing.** The D7 module wrote KMaps-enriched documents to the `kmassets`
  Solr index on node save. This write path has not been ported; Spike 2 covers
  read-only integration with the existing index.
- **KMaps ancestor path resolution.** The D7 module called the KMaps API to resolve
  ancestor paths at save time. The D10 widget currently relies on the autocomplete
  result to supply the path. Full ancestor resolution against the KMaps API has not
  been implemented.

## Deferred notes

- [`docs/deferred/kmaps-widget-ux.md`](../deferred/kmaps-widget-ux.md) —
  Autocomplete UX: partial edits of the label string trigger Solr errors in the
  dropdown. Needs read-only display field + separate search input before production.
