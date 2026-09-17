# Session Log: AV13 corrected (real Kaltura player id), AV15 shipped as the real live accordion shape

**Date:** 2026-09-17
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** Sprint 3's last two solo-workable items are done. AV13 corrected a
wrong `uiconf_id` already live on dev-0 since the previous session, tracing D7's
real primary-playback value through two independent sources. AV15 shipped a
technical-metadata presentation for `audio`/`video` nodes — built once against
Images' Sprint 2 B2 modal precedent, then rebuilt the same day after checking the
*real* live production page revealed that precedent didn't apply. A real AV4
migration gap was found and filed along the way. Sprint 3 now has nothing left
that isn't deferred to Than's return (week of 2026-09-22).

PRs merged: [#219](https://github.com/uvalib/mandala-navina/pull/219) (AV13),
[#220](https://github.com/uvalib/mandala-navina/pull/220) (AV15, including the
AV4 deferred note).

---

## 1. Session start: local DB had drifted from the 09-15 afternoon session

`git pull` brought in the previous session's config changes (AV9's gallery
thumbnail, the `/av`/`/images`/`/` landing pages) that the local DDEV DB hadn't
imported yet. `drush config:status` showed the expected drift (`core.extension`,
`system.site`, the new views/displays); `drush config:import` caught it up
cleanly, verified against dev-0's known counts (1,543 users, 4,187 audio, 7,396
video). Standing per-session check, not a new finding.

## 2. AV13: the shipped Kaltura player id was wrong

AV13 was scoped to migrate D7's per-view-mode player config into AV10's preset
registry. Traced the real primary-playback `uiconf_id` two independent ways
rather than trusting either alone:

- **D7 Drupal's own field display config** (`field_config_instance`, read from
  the real `d7_av` database): `field_video`'s `default` view mode configures
  `entry_widget: 31832371`. Traced all the way through `field_kaltura_build_embed()`
  (the actual function building the JS `kWidget.embed()` call) to confirm this
  value is genuinely wired into the live page render, not dead config.
- **The live React app** (`mandala-om/kmaps-app/src/legacy/audiovideo.js`,
  `AudioVideo.DrawPlayer()` — confirmed the *only* reachable code path; a
  sibling `Draw()` method is dead, starting with a bare `return;` from an
  August 2020 refactor): hardcodes `uiConfId='31832371'` for **both** audio and
  video, no per-bundle branch.

Both independently confirmed `31832371`. What AV9/AV10 had actually shipped —
`24762821` — turned out to be `MB_MAIN_PLAYER_ID`, consumed only by
`mb_services_node_player()`, a separate share/embed-redirect endpoint that has
nothing to do with primary in-page playback. AV4's migration had picked it up
by mistake, and it had been live on dev-0 since the previous session.

Fixed by correcting `mandala_kaltura.settings`' single `default` preset in
place (no per-bundle split needed — the live evidence converges on one value,
simplifying the row's original premise rather than expanding it). `delivery`
stays `HTTP`, never `RTMP` (confirmed dead in D7's own embed builder regardless,
and off the table per team decision). Verified live in DDEV against real video
and audio nodes; deployed to dev-0 same session (webhook auto-triggered, no
manual pipeline call needed).

**Also checked, since it mattered for confidence in the fix:** `mb_kaltura`'s
real transcript-sync JS (`transcripts_ui.js`) depends on the standard Kaltura
Player Toolkit API (`kWidget.addReadyCallback` + `kBind`/`sendNotification`),
generic to any compatible uiconf — and React's live player already uses
`31832371` with exactly that API successfully. So the fix de-risks Sprint 4's
future transcript-sync work rather than complicating it. Separately noted: the
*generic* `transcripts_ui` module (the files Spike 11's live-evidence section
cites) expects a plain `<video>`/`<audio>` DOM element — dead for Kaltura
content specifically, since `mb_kaltura`'s override is what's actually live.
Worth Spike 11 building against the real mechanism when that work starts, not
re-derived here.

## 3. AV15: built once, then rebuilt after checking the real page

Scoped from the sprint doc's own instruction to mirror Sprint 2 B2's Images
precedent (a Bootstrap modal, `shanti_images_carousel`'s
`node--shanti-image.html.twig`). Built a new module, `mandala_av_metadata`, as
an extra field (pseudo-field) rather than a full node template override — AV9
already owns the rest of the default view mode via display config. Rendered
paragraph fields **generically**, introspecting each paragraph's field
definitions at runtime rather than hand-listing the ~85 sub-fields across AV3's
14 relevant paragraph types, recursing for the two nested cases
(`av_pbcore_instantiation` → `av_pbcore_format_id`, `av_workflow` → three
`av_workflow_note` streams). Checked AV's actual field permissions before
assuming none applied, as the sprint doc flagged: `field_workflow` is D7
`field_permissions` type 2/CUSTOM, and the real `role_permission` table
confirms anonymous/general-authenticated roles have no view grant at all —
gated on `$node->access('update')` as the D11 stand-in, same reasoning as
Images' `field_private_note` gate. First version verified live in DDEV and
committed as PR #220.

**Then asked to replicate a specific real production page**
(`av.mandala.library.virginia.edu/video/oral-culture-riddles-39-68`) —
and the Images-modal precedent turned out not to match reality at all. That
domain serves **D7's own live theme directly** (`shanti_sarvaka`, jQuery, no
React), confirmed from the page's actual script tags — a different, newer
presentation than the React SPA (`mandala.library.virginia.edu`) or anything
in the locally-checked-out `mandala-om` repo (searched all branches, found
nothing — the AV site's live theme has evolved past what's cloned locally).
The real "technical metadata" surface is a **Field Group accordion** with 5
named panels (Details, People, Rights & Licensing, Availability & Access,
Technical Metadata) plus a "Video Overview" block above the fold. Pulled the
exact field-to-panel mapping from the **live DOM** via browser automation,
since the local `d7_av` dump's own `field_group` table only has edit-form
groups (`mode=default`) — confirming that dump predates this accordion
entirely, a real drift between the migration reference data and current
production.

Rebuilt same session to match: Bootstrap 5 accordion instead of a modal,
fields correctly split per the live mapping rather than lumped together
(Details: coverage + relation; People: creator/contributor/publisher/sponsor
with the **role value used as the row label** — "Cinematographer: Wende Khar",
matching production exactly instead of generic "Creator:"/"Creator role:"
pairs; Rights & Licensing: rights_summary + copyright_owner + year_published;
Technical Metadata: identifier + extension + instantiation's sub-fields
flattened in, not nested). Explicitly demo-priority per direction ("shape and
a glimpse of the data," not full fidelity): "Availability & Access" is an
empty placeholder (its real fields were empty on the live example checked, and
it may belong to AV7's Group-access territory rather than AV15); no "Related
Audio-Video" tab; the transcript panel is stubbed (Sprint 4/Spike 11
territory).

### A real rendering bug, and a real (separate) AV4 data gap

Asked to fix the Details panel after it showed no data on several demo nodes.
Two different things, chased both rather than assuming one explained the
other:

1. **Real bug in this module, fixed:** `field_relation_identifier` (on
   `av_pbcore_relation`) is a plain `entity_reference`, unlike every other
   paragraph-to-paragraph link this module walks (`entity_reference_revisions`).
   The generic renderer only special-cased the latter and silently produced
   nothing for the former (`->value` doesn't exist on an entity-reference
   item) instead of erroring — verified the fix with a synthetic in-memory
   paragraph, confirmed it resolves to the referenced entity's real label.
2. **Real AV4 migration gap, not fixed here, filed separately:** even after
   the rendering fix, the field is genuinely empty — `paragraph__field_relation_identifier`
   has **zero rows** across all 836 migrated `av_pbcore_relation` paragraphs,
   while D7's real source has it populated (spot-checked node 116965 / legacy
   nid 24621: all 9 relation items have a real `field_relation_identifier_target_id`
   in D7, confirmed live on production's own "Related Media" list). This is
   exactly the risk AV3's own paragraph-model doc already flagged — "`field_relation_identifier`...
   needs a second pass or stub-and-backfill" — that pass evidently never
   happened. Filed as
   [`docs/deferred/av4-relation-identifier-not-migrated.md`](../deferred/av4-relation-identifier-not-migrated.md):
   Medium priority, since nothing shipped depends on it yet, but real data
   loss versus D7 that needs its own backfill pass (D7 nid → D11 nid via
   `field_legacy_nid`, not a fresh migration run).

Verified live in DDEV against 5+ real nodes across both bundles: correct panel
population, the role-as-label pairing, nested instantiation/workflow-note
data, and the workflow gate confirmed both ways (present for an admin account,
absent for anonymous) on the same node. No new watchdog errors.

## 4. Sprint 3 status

Everything solo-workable is now done — AV2, AV3, AV4, AV5, AV6, AV8, AV9, AV13,
AV14, AV15, AV16 all shipped. What remains (AV7's `group_access_uva_member`/
`mb_collection_admin` realms, AV11→AV12 uploads) stays deferred to Than's
return, week of 2026-09-22 — a capacity call from the previous session, not a
technical blocker.

## What's next

**Resuming this afternoon to address**
[`av4-relation-identifier-not-migrated.md`](../deferred/av4-relation-identifier-not-migrated.md)
— the backfill pass for `field_relation_identifier` (D7 nid → D11 nid via
`field_legacy_nid`, not a fresh migration run; see that note's Recommendation
section for the approach).
