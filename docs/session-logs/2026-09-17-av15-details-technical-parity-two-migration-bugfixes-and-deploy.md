# Session Log: AV15 Details/Technical/Availability parity with D7, two real migration bugs found and fixed, merged and deployed

**Date:** 2026-09-17 (continuing into 2026-09-18)
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** Picked up the AV4 `field_relation_identifier` backfill queued from
the morning session, then kept finding and fixing real gaps as they were
checked against live D7 production, one correction at a time: panel content
that was actually just misplaced (not missing), a per-asset-type theme
identity that had been hardcoded to Images since an earlier sprint, and two
genuine, previously-undiscovered migration bugs -- one of them (a
single-valued-winner scoring defect on `field_pbcore_instantiation`) affecting
307 nodes corpus-wide. Shipped as PR #222, merged, deployed to dev-0, both
backfills run live there, and a same-day regression sweep came back clean.

PR merged: [#222](https://github.com/uvalib/mandala-navina/pull/222)

---

## 1. AV4 backfill #1: `field_relation_identifier`

Per the morning session's own "what's next," built
`drush av:backfill-relation-identifier` (new command in `mandala_migrations`).
Root cause was a migration-ordering chicken-and-egg, not a fresh mystery:
`d7_av_pbcore_relation`'s own `migration_lookup` process targets
`d7_av_audio`/`d7_av_video`, but the relation paragraphs have to exist before
the node migrations that reference them -- so at the point the lookup runs,
its target migrations' id maps are still empty. 100% of lookups were
guaranteed to fail, which matched the observed zero rows exactly. Resolved D7
nid -> D11 nid through each migration's own id map instead and wrote directly
onto the already-migrated paragraphs: 6,114 of 6,116 rows fixed (2 are
pre-existing D7 data-quality gaps -- one item outside the migrated host scope,
one genuinely dangling D7 nid -- logged by the command, not chased further).
Verified against the doc's own reference node in DDEV.

## 2. Fixing what looked like missing content, one panel at a time

Asked to make the Technical Metadata / Availability & Access panels
"good enough" against D7. Each fix followed the same pattern: check the real
production page first, don't guess.

- **Availability & Access** (previously an empty placeholder): confirmed live
  that D7 shows exactly one thing here, a "Visibility" row -- and that both
  underlying D11 fields (`field_group_content_access`, `field_available_from`)
  were *already* fully migrated with real data, just never wired into the
  panel. Wired them in, explicitly scoped as display-only (resolving D7's
  "(group default)" fallback is AV7's real access-enforcement territory, not
  this panel's).
- **Technical Metadata**: found `field_pbcore_extension` was dumping a raw,
  multi-KB embedded PBCore XML blob into the panel -- confirmed against D7's
  own `field_config_instance` that its display formatter is `hidden` there,
  never actually shown. Dropped it. Added `field_legacy_nid` as "Shanti AV
  Id," the row every real D7 panel leads with (confirmed via two live
  examples that the value is literally the node's own D7 nid, not sourced
  from any PBCore field).

## 3. "Looks hardly anything like D7" -- the real gap was theme identity, not the accordion

Pushed to compare directly against D7 instead of trusting an earlier
screenshot. The accordion itself was close; the banner wasn't -- gold/olive
on every D11 page vs. teal on live D7 AV pages (confirmed on both an audio
and a video example, so genuinely per-asset-type, not a fluke). Traced it to
`shanti_sarvaka`'s own committed comments: an earlier pass (A7/A8) hardcoded
Images' banner color/icon/"Explore" title prefix as the sole default,
explicitly flagged there to revisit "once a second asset type needs its own
accent" -- which is exactly the trigger condition now. Added a per-node-bundle
lookup (`_shanti_sarvaka_current_asset_site()`) feeding a new
`hook_preprocess_page()`/`hook_preprocess_page_title()` pair. Verified against
two live D7 AV pages (teal, AV icon, no "Explore" prefix) and confirmed the
front page and a 404 page keep Images' original identity unchanged.

## 4. Markup and CSS cleanup, both from direct user pushback

- **"I don't understand the markup at all: why so many nested field-item
  tags?"** -- real complaint, not a style nitpick. The row markup had borrowed
  Drupal's field/field__items/field__item template structure (to get
  bootstrap5's ported float-based two-column CSS for free), which is meant to
  hold a field's *multiple* values -- applied to rows that only ever held one,
  then doubled when the KMaps fields' own real Drupal field render got
  embedded inside it. Replaced with two plain, self-owned classes
  (`.av-detail-label`/`.av-detail-value`); verified in the live DOM that a
  plain row is now exactly 2 divs, and an embedded KMaps row has no redundant
  wrapper of ours around Drupal's own (correct, unavoidable) field markup.
- **"The white-space is creating the whacky layout"** -- also real. A blanket
  `white-space: pre-line` on the row-value container (added to preserve real
  newlines in multi-line memo fields) was also catching every embedded Twig
  template's own incidental indentation whitespace and rendering it as large
  visible gaps -- this was the actual cause of the "150px+ gap around KMaps
  tags" finding from earlier in the session, which had been wrongly written
  off at the time as "just how the formatter looks." Scoped the rule down to
  a new `.av-detail-text` span used only for the plain-value case.

## 5. Details panel: same pattern, another real gap

Asked directly: "why is there completely different content in the Details
panel?" D7's Details panel shows Collection/Subject/Recording
Location/Language/Terms/Time Period/Related Media; D11's only showed Time and
Related Media. Confirmed `field_pbcore_coverage` (Time) genuinely only has
that one sub-field -- Subject/Recording Location/Language/Terms are separate,
already-migrated *node-level* KMaps fields (`field_subject`,
`field_recording_location_new`, `field_language_kmap`, `field_kmap_terms`),
rendering correctly but unlabeled *below the whole accordion* instead of
inside Details. Moved them in (reusing their existing
`kmap_popover_formatter`, embedded as a live render array rather than a
pre-rendered string -- `renderInIsolation()` was found to drop the
formatter's own `#attached` JS library, which is what the pre-line bug above
was actually exposing) and hid the now-duplicate standalone display component
on both audio and video's default view display, via the Entity API
(`removeComponent()` + save), not a hand-edited YAML structure change.
"Collection" was checked and deliberately left out -- it's the node's owning
Group, not a plain field, already shown in the page's own breadcrumb.

## 6. "Technical Metadata is missing many items" -- a real, previously-undiscovered migration bug

The sharpest finding of the session. Direct comparison against the exact
node whose D7 Technical Metadata had been captured earliest in the session
("Oral Culture: Riddles 39-68") showed D11 rendering only "Shanti AV Id" --
Date Created/Digital Format/Media Type/Colors, all present in the earlier D7
capture, were gone. Traced it fully:

- `field_pbcore_instantiation` is AV's *only* cardinality-1 PBCore field
  (every other one is multi-valued). A host with both an `en` and `und`
  language-layer link needs the migration to pick one D7 item, scored by
  "whichever has more populated sub-fields"
  (`AvLanguageLayerTrait::resolveSingleValued()`/`populatedSubFieldCounts()`).
- That scoring used `COUNT(*)` -- but a D7 field_collection item with nothing
  entered still gets one row per configured sub-field: NULL for date/list
  fields, but **empty string `''`**, not NULL, for plain-text fields.
  `COUNT(*)` counted every one of those rows as "populated" regardless, so a
  completely empty item could out-score a genuinely populated one. Confirmed
  directly on the reference node: item 491001 (`und`, every sub-field empty)
  scored 10 against item 495371's (`en`, the real data) score of 4, and won.
- Fixed the scoring to count only non-NULL, non-`''` values (skipping the
  `''` check specifically for native DATETIME columns -- MySQL errors trying
  to parse `''` as a date, confirmed live when the first version of the fix
  crashed on exactly that). 307 already-migrated hosts confirmed affected
  corpus-wide via a full-corpus SQL check, not a sample estimate.
- Built `drush av:backfill-instantiation-winner`: for each affected host,
  re-scores both D7 candidates with the fixed logic and, where the
  already-migrated winner is wrong, copies every sub-field's value from the
  correct D7 item directly onto the *existing* D11 paragraph (same paragraph
  id/revision the host node already references -- same safe pattern as the
  relation-identifier backfill). Handles the nested `field_pbcore_format_id`
  reference via its own independently-run migrate map, `datetime` sub-fields
  via D7->D11 date-string reformatting, `text_long`
  (`field_pbcore_annotation`) via its value+format pair.
- Verified: the reference node's Technical Metadata now shows exactly what
  D7 shows (Colors/Date created/Digital format/Media type), full-corpus
  render smoke test at 11,583/11,583 nodes with 0 errors, and a direct SQL
  check confirming zero revision-id drift between any fixed paragraph and its
  host node's reference to it.

Documented both bugs in full in `docs/deferred/`:
[av4-relation-identifier-not-migrated.md](../deferred/av4-relation-identifier-not-migrated.md),
[av4-instantiation-wrong-winner.md](../deferred/av4-instantiation-wrong-winner.md).

## 7. Shipped: commit, PR, merge, deploy, backfill on dev-0

Committed as 3 logical commits (the two migration backfills together; AV15
panel content; styling/theme-identity/markup cleanup together), opened
[PR #222](https://github.com/uvalib/mandala-navina/pull/222), merged after
CI passed (GitGuardian, the only configured PR check).

Watched the real CodePipeline execution through to Deploy (not the stale
`latestExecution` status from the *previous* run, which required matching on
`pipelineExecutionId` specifically -- a first polling attempt exited
immediately on the old execution's stale "Succeeded" before catching this).
Deploy succeeded, which per
[deploy-never-imports-config-sync.md](../deferred/deploy-never-imports-config-sync.md)'s
2026-08-17 fix means a full `updb`+`cim` ran and a post-cim `config:status`
gate would have failed the build had our 2 config changes not landed clean.

Ran both backfill commands live on dev-0 (SSH via
`mandala-drupal-dev-0.internal.lib.virginia.edu`, `docker exec
mandala-drupal-0`), dry-run first, matching the DDEV counts exactly (6,114
and 307), then for real. Verified zero revision drift and clean
`config:status` (only the pre-existing, unrelated `simplesamlphp_auth.settings`
drift, which is imported from a separate partial `cim` by design).

## 8. Next-day regression check (2026-09-18)

No new commits from anyone else. On dev-0: `config:status` clean (same
expected drift only), no new watchdog errors since the deploy (only
pre-existing, unrelated mail/Solr connectivity warnings), full-corpus render
smoke test re-run directly on dev-0 -- 11,583/11,583, 0 errors. Real
page-load timing (forced cache misses) came back at 0.2-0.9s, confirming the
smoke test's ~22-minute runtime was an artifact of the test itself (11,583
sequential live-network KMaps-popover calls in one uncached process, not
representative of real traffic) rather than a site-level regression. Images
and the front page confirmed still on their original (unchanged) banner
identity.

## What's next

Nothing queued from this session specifically. Sprint 3's solo-workable AV
scope was already closed out per the prior session; AV7 (Group access
realms) and AV11->AV12 (uploads) remain deferred to Than's return, week of
2026-09-22.
