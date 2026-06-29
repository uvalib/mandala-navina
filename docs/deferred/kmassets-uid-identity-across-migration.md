# kmassets `uid` identity must survive the D7→D11 nid reassignment

**Area:** solr / kmassets / migration / document identity
**Raised during:** Sprint 1 (1a.8 — doc-builder fixture validation)
**Jira:** (add when available)
**Priority:** High — affects every asset type's write path and all downstream consumers

## What we found

The kmassets document identity contract is `uid = {service}-{nid}` (e.g.
`images-1028396`), and the live index keys ~111k image docs on it. But the D11
Images migration **does not preserve D7 node IDs** — it lets Drupal assign new
auto-increment nids. Confirmed during 1a.8 fixture validation:

```
D7 nid 1028396 → D11 nid 5
D7 nid 1087551 → D11 nid 6
D7 nid 1243616 → D11 nid 7
```

So the builder emits `id: 5` / `uid: images-5` where the golden (and the live
index) has `id: 1028396` / `uid: images-1028396`.

## Why nids can't simply be preserved

The D11 single-site (ADR 005) consolidates five formerly-separate D7 sites into
one node table. Node IDs are only unique *within* a D7 site, so preserving them
across all asset types would **collide** (an Images nid and an A/V nid can share a
number). That is exactly why `uid` carries a per-service prefix — but the prefix
alone doesn't help if the numeric part is reassigned.

## Why it matters

- **The live kmassets index keys on `images-{D7nid}`.** Re-indexing with new nids
  doesn't update existing docs — it creates parallel duplicates and orphans every
  external reference (reindeer_x, the React app, saved links).
- The doc-builder works from the D11 node, which only knows its D11 nid. It cannot
  recover the D7 nid unless that identity is carried on the node.

## Options (decision needed)

1. **Carry the D7 nid on the node** (e.g. a dedicated `field_legacy_nid`, or via
   `field_other_ids`) and have the builder compose `id`/`uid` from it. Preserves
   the live-index identity without nid collisions. Most likely correct.
2. **Preserve nids per-asset-type** where ranges don't collide — fragile across
   five sites; not recommended.
3. **Accept new identity + full reindex + consumer migration** — high blast radius
   (breaks existing references); only if the index is being rebuilt wholesale.

Until resolved, the fixture diff excludes `id`/`uid` (bucket `identity`). This is
the most consequential open question from 1a.8 — it gates whether migrated content
can write to the existing index at all. Cross-ref
[kmassets-kmapid-ancestor-id-resolution](kmassets-kmapid-ancestor-id-resolution.md)
(another "the migrated node lacks data the doc needs" finding).
