# Session Log: AV9/AV10 shipped, a self-inflicted deploy failure found and fixed, an unrelated live SAML bug caught, AV15 scoped, two tracks deferred

**Date:** 2026-09-15
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** AV10 (Kaltura configuration layer) and AV9 (player formatter) are
both real, deployed, verified-on-dev-0 code — not just design docs. Along the
way: caught and fixed two module-registration gaps before they mattered,
broke a real deploy by hand-editing config YAML (the exact mistake this
repo's own deferred notes already warned about) and fixed it properly, found
and fixed an unrelated live SAML bug that was silently force-logging out
non-admin local-password users, scoped a new backlog item (AV15), resolved
AV9's one remaining open question, and deferred two tracks (uploads, and
AV7's remaining Group realms) to Than's return. PRs #206–#215, all merged.

---

## 1. AV10: revised the design, then actually built it

Yesterday's config-entity proposal got reconsidered once implementation cost
was weighed for real: this codebase has zero custom config entity types, and
the entity approach was buying a write path (a live admin UI) nobody
actually wanted. Confirmed with Yuji: engineering-owned, config-only, no UI
— ADR 008 territory, since D7 never had self-service UI for this either.
Replaced with a single `mandala_kaltura.settings` config object (site
constants + a keyed `presets` map) and a read-only `KalturaConfigResolver`
service (PR #206).

Built it (PR #207), verified live in DDEV, and then found two real gaps
before they caused problems on a shared environment:
- Tested via local `pm:enable`, which doesn't touch `core.extension.yml` —
  the module would have stayed inert on any real deploy. Fixed same-day
  (PR #208).
- `mandala_kaltura.settings` only existed in `config/install/`, never
  `config/sync/` — the next real `cim` (which every deploy runs) would have
  **deleted** it, since sync had no entry for it at all. Found while testing
  AV9 (below), fixed in the same PR that needed it.

## 2. AV9: read the real D7 embed code before building anything

Rather than build a richer formatter than D7 ever had, read
`field_kaltura_build_embed()` in the actual legacy `mandala-drupal`
codebase directly. Real finding: D7's production `kWidget.embed()` call
only ever passes `targetId`/`wid`/`uiconf_id`/`entry_id`. `player_height`/
`width` size the *outer* responsive CSS container only; `delivery`/
`rotate`/`stretch`/`thumbsize_*` were never wired to anything real, even in
D7. Built `KalturaConfiguredFormatter` + a new `mandala_kaltura_player`
theme hook to match that exactly (PR #209) — no speculative richness, per
ADR 008's floor.

Wired it live into the `audio`/`video` view displays AV6 built (moving
`field_video`/`field_audio` out of `hidden`), verified end-to-end in DDEV
against real migrated content, twice: once via an in-memory override, once
from only the committed config after a real `config:import` (the actual
deploy path).

## 3. Broke a real deploy, the exact way this repo already warned about

Hand-edited `core.entity_view_display.node.video.default.yml` directly to
add the `field_video` component — inserted it before `field_kmap_terms` in
the `content:` mapping. Drupal sorts that mapping alphabetically by field
name when it saves the entity; `field_video` belongs after `field_subject`.
The deploy's own drift guard (`deploy_backend.yml`'s "verify configuration
import left no drift") correctly caught the mismatch **after** merge and
**failed dev-0's actual Deploy stage** — not a review-time catch, a real
pipeline failure.

This is the exact class of bug `docs/deferred/config-export-drift-hand-edited-yaml.md`
already documented from PR #177 (2026-09-02) — and I had that context
available in this same session, having referenced it explicitly the day
before, and still made the identical mistake. Fixed the safe way (PR #210):
read the active config back out of Drupal after import and wrote its exact
canonical serialization to the sync file, rather than guess at the right
order again. Verified `drush config:status` clean before pushing, and
verified the redeployed pipeline's drift-check actually passed this time.
Documented the recurrence in memory
([[feedback-never-hand-edit-config-structure]]) and in the deferred note
(PR #211) — this is the second real incident from the same root cause, not
the first, and worth the group revisiting the "add a CI check" option with
more urgency than "no option chosen yet."

## 4. An unrelated live bug, found while verifying the fix

Checking `config:status` on dev-0 after the drift fix turned up a second,
completely unrelated item: `simplesamlphp_auth.settings`'s
`allow.default_login_roles` was missing `authenticated` (only had
`administrator`). Traced the actual consequence through
`SimplesamlSubscriber::checkAuthStatus()`: any non-SAML-authenticated user
whose roles don't intersect this allow-list gets **force-logged-out on
their next request**. Live on dev-0, right now, before the fix — meaning
any non-admin user using local password login was being silently kicked
out, the opposite of what the commit that added this entry
(`f680002`/PR #165, "allow local password login... for all users")
intended. Fixed via `config:import`; root cause of the drift (who/what
changed it live) not investigated — flagged as open if it recurs.

## 5. AV15 scoped; AV9's last open question resolved

**AV15** (new): the fields AV6 left `hidden` — `field_workflow`, the full
PBCore paragraph set, `field_transcript` — have no owning backlog item.
Scoped it pointing directly at Images' Sprint 2 B2 technical-metadata modal
as the precedent, rather than inventing a new UI pattern (PR #212).

**AV9's gallery thumbnail question**, resolved rather than left as an
estimate: `video` has no `field_thumbnail_image` (only `audio` does), but
checking D7's real `_kaltura_thumbnail_base_url()` (a predictable per-entry
URL built from the same constants already in `mandala_kaltura.settings`)
against `shanti-thumbnail`'s own template (which already accepts a plain
URL string via `default_image_url` — no image style, no local file, no
Media entity needed) closes the gap entirely. No new field or migration
needed for either bundle's gallery thumbnail (PR #215).

## 6. Effort estimates, and two tracks deferred

Gave rough single-person effort estimates for the remaining backlog,
calibrated against this session's own observed velocity rather than guessed
cold — explicit about which were high-confidence (AV13, mechanism already
proven) versus low-confidence placeholders (AV7's remainder, never actually
scoped from real D7 code the way everything else was).

Two tracks deferred to Than's return (week of 2026-09-22), both capacity
calls rather than technical blockers, matching the precedent already set
for Texts/Sources during his earlier absence:
- **AV11 → AV12** (the upload track) — PR #213.
- **AV7's remaining two realms** (`group_access_uva_member`,
  `mb_collection_admin`) — PR #214, deferred specifically because Than
  understands the D7 OG/Group access requirements best, and nobody has
  read that mechanism yet, unlike everything else currently open.

**AV13 explicitly not deferred** — depends on AV10/AV4 only, benefits AV9
independent of uploads, fair game to pick up solo.

## What's left, and the likely next session

- **AV9's gallery UI** and **AV13** are both now low-risk, well-scoped, and
  independently workable — the two live candidates for this afternoon.
- **AV15** is scoped and workable whenever there's capacity.
- **AV7's remainder and AV11/AV12** wait for Than (week of 2026-09-22).
- Team is out of office for most of tomorrow (2026-09-16) — no session
  expected then.
- No open PRs, `main` clean, nothing running on dev-0, DDEV stopped.
