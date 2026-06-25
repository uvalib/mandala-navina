# `rotation_s` should be populated by the D11 Images writer

**Area:** solr / kmassets / Images / write path (1a.8)
**Raised during:** Sprint 1 (1a.8 — kmasset Solr doc contract, Phase 1)
**Jira:** (add when available)
**Priority:** Medium

## What we found

The D7 Images producer *intended* to emit a per-image `rotation_s` field but never
actually did, because of a typo:

```php
// shanti_images.module:1747  (shanti_images_kmaps_fields_solr_doc_alter)
$sdog['rotation_s'] = $siimg->rotation; // <-- $sdog, not $sdoc — silently dropped
```

As a result `rotation_s` is **absent from all ~111k live image docs** (verified via
the proxy on the kmassets index). So it is a *desired* field that has simply never
shipped — not a field we are choosing to drop.

## What we want

The D11 Images kmasset writer **should populate `rotation_s` correctly** from the
image's rotation value (D7 source: `$siimg->rotation`). The field uses `_s` (string,
"just in case not always an integer", per the original D7 comment).

## Why this is low-cost

`rotation_s` matches the **`*_s` dynamic-field rule** in the kmassets `schema.xml`
(see the [doc contract §6/§7.3.4](../planning/kmasset-solr-doc-contract.md)), so
adding it needs **writer code only — no `schema.xml` change or coordinated Solr
deploy**. It is purely a Population-2 producer concern.

## Notes

- This is an *improvement over D7 behavior*, so it is technically outside strict
  "migrate, not improve" ([ADR 008](../adr/008-mvp-migrate-not-improve.md) /
  [ADR 010](../adr/010-adr-008-scope-clarification.md)). It is small and additive;
  confirm whether it belongs in the 1a.8 writer or a later polish pass.
- Cross-reference: the [kmasset Solr doc contract](../planning/kmasset-solr-doc-contract.md)
  §7.2-C lists `rotation_s` as "wanted, not yet emitted."
