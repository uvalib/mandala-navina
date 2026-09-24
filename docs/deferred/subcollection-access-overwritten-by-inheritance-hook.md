# Migration overwrote subcollection access with the parent's value
**Area:** migration / Group access / ADR 011 / mandala_group_inheritance
**Raised during:** Sprint 3 AV7 (session 2026-09-24)
**Jira:** (add when available)
**Priority:** Medium -- dev-0 repaired 2026-09-24; residual items below (search-reader check, UVA enumeration mismatch, other environments)

## What happened
`mandala_group_inheritance_group_presave()` copies the parent collection's
`field_group_access` onto every **new** subcollection (ADR 011's default). A migration
save counts as a new-entity save, so it overwrote the subcollection's own D7 value that
the migration had just set. The override flag (`field_visibility_overridden`) was never set,
so ADR 011's "a subcollection may independently override" path was never used.

Found comparing the 2026-09-01 production AV dump against local D11, matched by
`field_legacy_site` + `field_legacy_nid`: **15 of 85 AV subcollections were looser than D7**
-- 10 private -> public, 2 UVA-only -> public, 3 private -> UVA-only -- and in every case
D11's value equalled the parent's. Top-level collections all matched. 1,143 AV nodes are
members of those 15; the 971 stored as "Use group defaults"
(`field_group_content_access` = 0) take their visibility from the subcollection, so those
are the ones exposed.

(Two different value scales are easy to mix up: collection-level `group_access` is
0 public / 1 private / 2 UVA; node-level `group_content_access` is 0 use group defaults /
1 public / 2 private / 3 UVA only. Both were verified against D7's `field_config`.)

## Confirmed by Than (2026-09-24)
The subcollection-specific values in D7 **were enforced**, so restoring them (rather than
letting subcollections inherit the parent's) is correct.

## Fixed in code
- The hook now leaves a migration's (`isSyncing()`) value alone, and records an override
  when it differs from the parent's. New subcollections created in the UI still inherit.
- `drush group:backfill-subcollection-access [--site=audio-video|images] [--dry-run]`
  resets each subcollection to its D7 value and turns the override flag on where it
  differs from the parent's. Idempotent; never clears an existing override.

## Done on dev-0 (2026-09-24, run by Xiaoming as `xw5d`)
- **Backfill.** `group:backfill-subcollection-access` was dry-run first: dev-0 showed exactly
  the same subcollections as the local DDEV (same group ids), so it was migrated the same way.
  Then run for real: **Images 7, audio-video 15**. Verification dry runs: 0 left to update
  (116 and 85 already correct).
- **Solr re-index, scoped.** Rather than `kmassets:index-all` (which would rewrite all ~111k
  `shanti_image` docs), only the members of the 22 repaired subcollections were re-indexed
  through the same `indexNode()` path: **4,314 nodes (3,171 images + 1,143 AV), 0 skipped,
  0 errors**, ~20 minutes. Visibility is derived from the collection's `field_group_access`
  at index time (`CollectionFieldContributor`), so the docs now reflect the repaired values.
- Merged and deployed first: PR #248 (deploy `abf0394a-...` succeeded).

## Still open
- **Verify on the search reader.** The re-index writes to the write master; whether the
  reader the live app queries reflects it was not checked (see
  [kmassets-audit-checks-master-not-search-reader.md](kmassets-audit-checks-master-not-search-reader.md),
  where the reader was already missing 70 docs). Spot-check a few of the 4,314 nodes.
- **`field_group_access` = 2 is mis-enumerated in the kmassets contributor.**
  `CollectionFieldContributor::ACCESS_TO_VISIBILITY` documents 2 as "subscribable" and maps it
  to private (fail closed). D7's real meaning, confirmed against `field_config` in the prod
  dumps, is **2 = UVA**. Nothing is exposed (private is stricter), but UVA-only collections are
  indexed as private -- three subcollections today (Ariana Maki on Images; Hoefer Films and
  Ethics Case Presentation on AV) -- so UVA users cannot find that content through search.
  Fix belongs with the AV7 UVA tier (kmassets `visibility_i` 3 = uva, and the Solr `fq`
  token in `VisibilityTokenBuilder`); the enumeration comment there needs correcting too.
- **Other environments migrated under the old hook** (staging, and any future one) need the
  same dry-run -> run -> scoped re-index. Nothing else is known to exist yet.
- The four collections that did not line up in the AV comparison (D7 nid 3; D11 nids 9, 20,
  23) are explained and harmless -- see
  [av-collections-without-d7-group-access.md](av-collections-without-d7-group-access.md).
- A fresh production migration at cutover is now correct by construction (hook fix), but
  worth re-running the D7-vs-D11 comparison as a post-migration check.
