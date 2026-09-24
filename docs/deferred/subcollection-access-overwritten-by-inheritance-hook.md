# Migration overwrote subcollection access with the parent's value
**Area:** migration / Group access / ADR 011 / mandala_group_inheritance
**Raised during:** Sprint 3 AV7 (session 2026-09-24)
**Jira:** (add when available)
**Priority:** High until dev-0 (and any other migrated environment) is backfilled and re-indexed

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

## Fixed in code
- The hook now leaves a migration's (`isSyncing()`) value alone, and records an override
  when it differs from the parent's. New subcollections created in the UI still inherit.
- `drush group:backfill-subcollection-access [--site=audio-video|images] [--dry-run]`
  resets each subcollection to its D7 value and turns the override flag on where it
  differs from the parent's. Idempotent; never clears an existing override.

## Still open
- **dev-0 has not been backfilled** (and any other environment migrated under the old hook):
  run the command with `--dry-run` first, then for real.
- **Re-index member nodes afterwards** (`kmassets:index-all audio|video|shanti_image`):
  a group update does not re-index its members' Solr docs, so search visibility stays
  stale until then.
- **Images has the same defect, confirmed against the production dump
  (2026-06-29; staging 2026-07-07 gives identical results):** 7 subcollections are looser
  in D11 than D7 -- 6 private -> public, 1 UVA-only -> public -- and in every case equal
  the parent's value. **3,171 images** are members, 3,112 of them in a single
  subcollection. Images nodes have no node-level access field, and each of these belongs
  to exactly one group (none is a direct member of the parent), so their visibility comes
  entirely from the affected subcollection -- a larger exposure than AV's. Repaired on the
  local DB only (`--site=images`; second run reported 0 changes). **Run on dev-0 first**,
  then AV.
- Unexplained in the AV comparison: D7 subcollection nid 3 has no D11 group; D11 has
  three AV subcollections (legacy nids 9, 20, 23) absent from the 2026-09-01 dump.
- A fresh production migration at cutover is now correct by construction (hook fix), but
  worth re-running the D7-vs-D11 comparison as a post-migration check.
