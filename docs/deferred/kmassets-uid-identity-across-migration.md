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

## Why nids can't simply be preserved

The D11 single-site (ADR 005) consolidates five formerly-separate D7 sites into
one node table. Node IDs are only unique *within* a D7 site, so preserving them
across all asset types would **collide** (an Images nid and an A/V nid can share a
number). That is exactly why `uid` carries a per-service prefix — but the prefix
alone doesn't help if the numeric part is reassigned.

## Decision (2026-07-01)

**Versioned uid format with a legacy-uid compatibility field and logged mapping.**

### New uid format

D11 docs use a generation-versioned format:

```
images-11-{d11-nid}    e.g.  images-11-5
av-11-{d11-nid}
texts-11-{d11-nid}
```

D7 docs (existing live index) use the old format:

```
images-{d7-nid}        e.g.  images-1028396
```

**Pattern rule** — unambiguous, trivial regex:
- `{service}-\d+` → D7 generation
- `{service}-11-\d+` → D11 generation

The `uid` field name is unchanged. The generation is embedded in the value itself.

**"11" is a frozen constant.** It identifies the D7→D11 migration-era rebuild, not
the running Drupal version. Drupal upgrades (12, 13, …) must leave this prefix
unchanged — the format endures for the lifetime of this data generation. A new
generation number is only warranted by a future wholesale data migration that
deliberately creates a new uid space.

### `uid_legacy_s` Solr field

A new stored string field `uid_legacy_s` on the kmassets schema carries the old
D7 uid for every migrated doc:

- Migrated content: `uid_legacy_s = images-{d7-nid}` (sourced from `field_legacy_nid`)
- New D11-native content: field absent / empty

This requires a **schema change** — coordinate with Dave Goldstein before the
staging reindex.

### `field_legacy_nid` on `shanti_image`

Add `field_legacy_nid` (integer, optional) to CMI config and populate it during
the `d7_shanti_image` migration (source: `nid`). The doc-builder reads this to
compose `uid_legacy_s`. Also serves URL redirect handling after cutover.

### Compatibility mapping at the proxy

When a consumer presents an old-format uid (pattern `{service}-\d+`), the read
proxy:

1. Detects the old-generation pattern.
2. **Logs the request** — caller identity, old uid, timestamp. This is the primary
   mechanism for identifying clients that still use the legacy format and need to
   be updated. The log is the migration tracker.
3. Rewrites to a `uid_legacy_s:{old-uid}` query, returns the matching doc with the
   canonical new `uid` in the response so the caller can update its reference.

Logging old-uid hits gives an operational window into which clients are lagging.
Once the log goes quiet, the shim can be retired.

### For 1a.8 development / staging

Doc-builder emits `uid = images-11-{d11-nid}`. These do not collide with existing
D7-era entries (different format and different numeric space). After staging
validation, clean up test docs:

```
DELETE /solr/kmassets/update?stream.body=<delete><query>uid:images-11-*</query></delete>
```

### At final cutover

1. Ensure all migrated nodes have `field_legacy_nid` populated.
2. Confirm `uid_legacy_s` schema field is deployed on the staging and production masters.
3. Delete all existing `images-*` (D7-era) entries from kmassets.
4. Full reindex from D11 — all docs get `uid = images-11-{d11-nid}` and `uid_legacy_s = images-{d7-nid}`.
5. Enable the proxy compatibility shim with logging.
6. Wire redirect module to `field_legacy_nid` so D7 node URLs resolve correctly.
7. Monitor old-uid logs until they go quiet; retire the shim.

## Cross-references

- [kmassets-uid-consumer-analysis](kmassets-uid-consumer-analysis.md) — audit of
  all clients that store or rely on D7 uids; must be completed before cutover
- [kmassets-kmapid-ancestor-id-resolution](kmassets-kmapid-ancestor-id-resolution.md)
  — related "migrated node lacks data the doc needs" finding
