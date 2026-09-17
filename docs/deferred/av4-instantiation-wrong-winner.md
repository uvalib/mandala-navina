# AV4: `field_pbcore_instantiation` picked the wrong D7 item on 307 hosts — RESOLVED same session

**Area:** migration / AV / data fidelity
**Raised during:** Session 2026-09-17, evening — investigating a user report that a live node's "Technical Metadata" panel was missing items present on the real D7 page
**Jira:** (add when available)
**Priority:** N/A — found and fixed in the same session
**Status:** RESOLVED 2026-09-17

## What happened

`field_pbcore_instantiation` is AV's only cardinality-1 PBCore field-collection
host field (every other one — identifier, relation, coverage, creator,
contributor, publisher, sponsor, description, title — is multi-valued). A host
with both an `en` and `und` language-layer link therefore needs the migration
to pick exactly one of the two D7 field_collection items
(`AvLanguageLayerTrait::resolveSingleValued()`), scored by
`populatedSubFieldCounts()` — "the item with more populated sub-fields wins."

That scoring function counted `COUNT(*)` rows per sub-field table. A D7
field_collection item with **nothing entered still gets one row per
configured sub-field** — value column NULL for date/list-type fields, but
**empty string `''`** (not NULL) for plain-text ones. `COUNT(*)` counted every
one of those rows as "populated" regardless, so a completely empty item could
out-score, or tie and then win the `en`-preference tiebreak against, a
genuinely populated one.

Confirmed live on node 33126 ("Oral Culture: Riddles (39-68)", D11 nid
126524): D7 item 491001 (`und`, every one of its ~23 sub-fields NULL or `''`)
beat item 495371 (`en`, real Date Created/Digital Format/Media Type/Colors
data — confirmed matching the live production page exactly) this exact way.
307 already-migrated hosts confirmed affected corpus-wide.

## Root cause

`AvLanguageLayerTrait::populatedSubFieldCounts()` used `COUNT(*)` instead of
counting non-NULL, non-`''` values. Two D7 storage quirks made this the wrong
proxy for "has real data": (1) date/list-type fields leave a NULL-valued row
rather than no row at all for an empty item, and (2) plain-text fields save
`''` rather than NULL for the same case. The docblock's own careful upfront
analysis (667 genuinely contested hosts; in every one, one item's data is a
strict superset of the other's) was correct — the *implementation* of "pick
the one with more data" just measured "has more data" wrong.

## Fix

1. **Root cause** (`AvLanguageLayerTrait::populatedSubFieldCounts()`): count
   only rows where the value column is neither NULL nor `''`, skipping the
   `''` check for native DATETIME columns (MySQL errors trying to parse `''`
   as a date; D7's date module never stores `''` there anyway).
2. **Backfill** (`drush av:backfill-instantiation-winner`, new command in
   `mandala_migrations`): for each of the 307 affected hosts, re-scores both
   D7 candidates with the fixed logic, and if the currently-migrated winner
   differs from the correct one, copies every sub-field's value from the
   correct D7 item directly onto the **existing** D11 paragraph (same
   paragraph id/revision the host node already references — no new
   revision, matching the AV4 relation-identifier backfill's established
   pattern). Handles the nested `field_pbcore_format_id` reference via its
   own (independently-run, unaffected) migrate map, `datetime` sub-fields via
   D7→D11 date-string reformatting, and `text_long` (`field_pbcore_annotation`)
   via the value+format pair; everything else is a plain scalar/list copy
   (D7's list allowed-values are stored as `value === label`, confirmed
   earlier this session).

Verified: node 126524's Technical Metadata panel now shows Colors/Date
created/Digital format/Media type, matching the live D7 page exactly (it
previously showed only "Shanti AV Id"). Config sync untouched (code-only
fix + content backfill). No revision drift: every fixed paragraph's
revision id still matches its host node's `target_revision_id`.

## Related

[[project-av4-relation-identifier-not-migrated]] memory / `av4-relation-identifier-not-migrated.md` (the analogous earlier fix — same "second pass on an already-migrated paragraph" pattern, different root cause).
