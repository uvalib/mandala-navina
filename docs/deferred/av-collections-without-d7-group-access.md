# AV collections missing from the D7-vs-D11 access comparison (nid 3, 9, 20, 23)
**Area:** migration / AV / Group access / verification
**Raised during:** Sprint 3 AV7 (session 2026-09-24)
**Jira:** (add when available)
**Priority:** Low -- investigated, no exposure found; two small follow-ups remain

## Question
Comparing every AV collection/subcollection's `group_access` between the 2026-09-01
production D7 dump and D11 (matched by `field_legacy_site` + `field_legacy_nid`, see
[subcollection-access-overwritten-by-inheritance-hook.md](subcollection-access-overwritten-by-inheritance-hook.md))
flagged four collections that did not line up: D7 nid 3 present in D7 but missing from D11,
and D11 nids 9, 20 and 23 present in D11 but missing from D7. Than asked that this be
investigated and tracked.

## Findings
- **nid 3 -- not a real gap.** It is the "Tibetan and Himalayan Library" top-level collection,
  present in D11 as group 172 with the same value (public, 0) as D7. It dropped out of the
  comparison because of a bug in the ad-hoc export used to compare, not because it is missing.
- **nids 9, 20, 23 -- empty, unpublished D7 test collections** ("Twm4g Test Collection",
  "Chris's Test Collection", "Gingko Art"; D7 `status = 0`). Of D7's 152 AV collections, these
  are the only three with **no `group_access` value at all** (149 have one), which is why the
  D7 side had nothing to compare. All three migrated as D11 groups (174, 176, 177) with
  `field_group_access` = 0 (public), the field's default.
- Their D7 `og_membership` rows (4 in total) are **user** memberships (uids 2, 8, 77), not
  content. D11 has **no** content members in any of the three, so the public default exposes
  nothing today.

## Still open
- **Public default for value-less collections.** A collection with no D7 `group_access` becomes
  public in D11. Harmless here (empty, and unpublished in D7), but it is the same "default
  loosens" shape as the subcollection defect; worth a deliberate choice (default private?) before
  a production migration, where a value-less collection might hold content.
- **Test collections.** Decide whether these three (and any other test data) should migrate at
  all or be dropped; nobody has said. Not blocking.
- Whether the four user memberships (uids 2, 8, 77) migrated was not checked.
