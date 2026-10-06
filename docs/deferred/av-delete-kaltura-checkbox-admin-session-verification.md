# AV node delete now offers deleting the Kaltura entry too — one open verification item

**Area:** AV / Kaltura / mandala_kaltura / content deletion
**Raised during:** Group session 2026-10-06 (Than, Yuji, Xiaoming) — feature requested live
**Jira:** (add when available)
**Priority:** Low — built and working, one real but non-blocking verification item left

## What shipped

Deleting an `audio` or `video` node now shows a "Delete video on Kaltura" checkbox on
the delete confirmation form, checked by default (uncheckable). If checked, the
node's Kaltura entry (`field_audio`/`field_video`'s `entry_id`) is deleted from
Kaltura when the node is deleted. The checkbox is only offered when the node
actually has a Kaltura entry attached (checked via the field's `entry_id`
property directly, not the field item's own `isEmpty()` — a Kaltura field item
can have `partner_id`/`uiconf_id`/`domain` set with a `NULL` `entry_id`,
confirmed live on node 116968, which the field type's `isEmpty()` does not
treat as empty).

Implementation:
- `mandala_kaltura.module`: `hook_form_alter()` (not `hook_form_FORM_ID_alter()`
  — the node delete form's id is bundle-specific, `node_video_delete_form` /
  `node_audio_delete_form`, one per content type, so there's no single stable
  FORM_ID to target; matches on the form object being a
  `ContentEntityDeleteForm` plus the entity's bundle instead) adds the
  checkbox and appends a submit handler.
- `KalturaSessionService::deleteEntry()`: mints a short-lived (60s), **admin**-scoped
  Kaltura session server-side (never sent to the browser) and calls the SDK's
  `MediaService::delete()`. A failed delete is logged and surfaced as a
  messenger warning but never blocks the Drupal-side node deletion.

## Open item: ADMIN vs USER session type for the delete call

`mintUploadSession()` (AV11) deliberately uses `SessionType::USER`, with an
explicit docblock reason: a USER-type session "cannot perform
partner-administration actions," which matters there because that KS is sent
to the browser. `deleteEntry()`'s session is never sent to the browser, so
that specific risk doesn't apply — but `media.delete` can delete *any* entry
under the partner (not just one the caller owns), which reads as a
partner-admin-type action, so `deleteEntry()` uses `SessionType::ADMIN`
instead. **This has not been verified against a real Kaltura sandbox
account** — whether `ADMIN` is actually required (vs. `USER` with sufficient
entitlement working fine) is unconfirmed, same category of open item
`KalturaSessionService`'s own existing docblock already carries for the
upload session's privilege scoping.

**Not yet exercised end-to-end against the live Kaltura partner.** DDEV's
local Kaltura admin secret is a real, live, production credential (partner
381832 — see `[[feedback-ddev-has-live-kaltura-secret]]`), so the actual
delete call was deliberately **not** triggered during development; only the
form rendering, checkbox visibility logic, and submit-handler wiring were
verified live (checkbox present/absent correctly across a real video node,
a real audio node, and a video node with a partner/uiconf set but no
`entry_id`; submit handler confirmed registered via `drush php:eval`).
Confirm with Than before the first real end-to-end test (ideally against a
Kaltura sandbox account, not production).

## To resolve (open)

- Verify `SessionType::ADMIN` is actually the right choice (or that `USER`
  would work too) against a non-production Kaltura account.
- Exercise the full flow once against a real (ideally sandbox) entry to
  confirm the delete actually succeeds end-to-end, not just that the form/
  submit wiring is correct.
- No ADR exists for "node delete triggers an external Kaltura delete" — this
  was built directly from a live feature request, not a prior design
  decision; flag for the group if a written decision record is wanted later.
