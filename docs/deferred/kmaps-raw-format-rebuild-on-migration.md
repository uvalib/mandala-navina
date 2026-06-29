# KMaps `raw` column must be rebuilt on migration, not copied

**Area:** migration / KMaps / shanti_kmaps_fields
**Raised during:** Sprint 1 (1a.7 — pattern-setting image migration)
**Jira:** (add when available)
**Priority:** High — applies to every site's KMaps migration, not just Images

## What we found

The [KMaps migration mapping](../planning/kmaps-migration-mapping.md) assumed the
D7 `raw` column already holds the pipe-delimited composite
`id|header|domain|path|defids` and could be copied verbatim ("Recompute `raw`?
D7's `*_value` is already the pipe-delimited composite the D11 field expects").

**Production data disproves this.** In the 2026-06-11 Images dump, the D7
`field_*_raw` column is a space-separated, angle-bracket **ancestor-id path**, e.g.

```
field_subjects_raw = "<5805> <1808> <2109> <2149> <2161> <2163> <2163>"
```

not `2163|Flight of small birds…|subjects|{{…}}|`. The id / header / domain /
path live in their **own** D7 columns; `raw` is a different representation.

## Why it matters (silent data loss)

The D11 field type (`KmapsItem`, ported in Spike 1) defines emptiness as:

```php
public function isEmpty(): bool {
  $raw = $this->get('raw')->getValue();
  if (empty($raw)) return TRUE;
  $values = array_map('trim', explode('|', $raw));   // pipe-delimited!
  return !isset($values[0], $values[1], $values[2]);
}
```

The widget (`KmapsTreePickerWidget::itemToRaw()`) likewise treats
`id|header|domain|path|defids` as the **canonical** D11 `raw`. So if the migration
copies D7's angle-bracket `raw`, `isEmpty()` returns TRUE for every term and
Drupal **silently drops all KMaps values on save** — no error, no message. We hit
exactly this: `node__field_subjects` came back with 0 rows until the fix.

## The fix (in 1a.7, reused for all KMaps fields)

Don't copy `raw`. **Rebuild** it from the other columns in the migration process:

```yaml
raw:
  plugin: concat
  delimiter: '|'
  source: [id, header, domain, path, defids]
```

Applied to all six KMaps instances (`field_subjects`, `field_places`,
`field_kmap_terms`, `field_kmap_collections`, `field_agent_place`,
`field_language`). The `id` / `header` / `domain` / `path` / `defids` columns
still copy 1:1; only `raw` is reconstructed.

## Follow-ups

- **Update the mapping doc.** [kmaps-migration-mapping.md](../planning/kmaps-migration-mapping.md)
  §"Schema invariant" / "Recompute `raw`" should be corrected — `raw` is *not*
  copyable; the row-level mapping is a 5-column copy **plus** a rebuilt `raw`.
- **Caveat:** a `header` or `path` containing a literal `|` would corrupt the
  composite. The widget has the same limitation; risk is low (headers are term
  names, paths are `{{…}}`-delimited) but a post-migration audit could flag any.
- **Consider hardening `KmapsItem::isEmpty()`** to key off `id`/`header` rather
  than parsing `raw`, so the field type is robust to raw-format drift. Optional;
  the migration-side rebuild already resolves the data path.

## Related (1a.8) — the `path` column is also not Solr-ready

1a.8 doc-builder validation found a *separate* gap in the same family: the migrated
`path` column holds the D7 breadcrumb-**name** path (`{{Earth}}{{Asia}}…`), not the
numeric ancestor IDs the kmassets `kmapid` field needs. That copy is faithful to D7
but starves the Solr-doc ancestor expansion. Tracked separately in
[kmassets-kmapid-ancestor-id-resolution](kmassets-kmapid-ancestor-id-resolution.md).
