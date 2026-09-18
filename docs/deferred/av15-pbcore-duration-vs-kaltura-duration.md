# AV: `field_duration` (PBCore) and `kaltura_duration` disagree on 41% of the hosts that have both — a data-cleanup question, not a D11 defect

**Area:** migration / AV / content model / data fidelity
**Raised during:** Session 2026-09-17/18, wiring up the "Video/Audio Overview" duration row (AV15) after the earlier `av15-avinfo-abandoned-fields-review-with-than.md` audit wrongly wrote `avduration` off as unmigratable/live-API-only
**Jira:** (add when available)
**Priority:** Low-Medium — not blocking, D11's own display already uses the
correct (Kaltura) source; the open question is whether/how to reconcile
PBCore's own `field_duration` catalog value where it disagrees.

## What was found

D7's `.avduration` row is not read live from the Kaltura API and is not
sourced from PBCore at all. It's D7's own local cache:
`node_kaltura.kaltura_duration` (an `int`, seconds), joined to a node via
`field_video`/`field_audio`'s Kaltura entry id. Confirmed directly against
D7 nid 33126: `kaltura_duration = 194` matches the live page's "3 min 14
sec" exactly.

Separately, AV's PBCore data model *also* has a duration concept:
`field_duration`, a sub-field on the `av_pbcore_instantiation` field
collection (the same cardinality-1 host field whose single-valued-winner
scoring bug was fixed earlier this session —
[av4-instantiation-wrong-winner.md](av4-instantiation-wrong-winner.md)).
These are two independently-entered/independently-sourced values that
happen to describe the same thing, and a full-corpus comparison
(5,299 AV host nodes, matching each host's winning PBCore item's
`field_duration` against its `node_kaltura.kaltura_duration` by Kaltura
entry id) found:

| | count |
|---|---|
| Both values present | 899 |
| — agree exactly | 531 (59%) |
| — disagree | 368 (41%) |
| `kaltura_duration` only (no usable `field_duration`) | 4,364 |
| `field_duration` only (no usable `kaltura_duration`) | 1 |
| Neither | 35 |

`field_duration` is populated on barely 17% of AV hosts to begin with
(899 + 1 of 5,299). Of the 899 where both exist, most disagreements are a
single second (plausibly a truncation/rounding artifact between two
different measurement paths), but a real minority are large and clearly not
rounding — e.g. (D7 nid, PBCore value, Kaltura value):

- nid 1781: `00:14:53` (893s) vs `1154s` — 261s off
- nid 3551: `00:09:19` (559s) vs `208s` — 351s off
- nid 3555: `00:03:26` (206s) vs `83s` — 123s off
- nid 3598: `00:07:21` (441s) vs `350s` — 91s off
- nid 3544: `00:12:24` (744s) vs `699s` — 45s off

## Why this isn't a D11 bug

D7's own live theme only ever displays `kaltura_duration` — `field_duration`
isn't shown anywhere in the production UI (see
[av15-avinfo-abandoned-fields-review-with-than.md](av15-avinfo-abandoned-fields-review-with-than.md)
for the fuller `avinfo` audit). `kaltura_duration` is also the more
trustworthy of the two by construction: it's Kaltura's own measurement of
the actual transcoded media file, while `field_duration` is a manually (or
semi-manually) cataloged PBCore value that can drift from the real file --
through re-encoding, re-hosting, or simple data entry. D11's new
`field_kaltura_duration` (populated via `drush
av:backfill-kaltura-duration`, `mandala_migrations`) mirrors
`kaltura_duration` for exactly this reason, and AV15's Video/Audio Overview
block displays only that -- matching D7's own behavior, not a compromise.

## What's actually open

Whether the 368 disagreeing (and 1 PBCore-only) `field_duration` values are
worth reconciling into the PBCore record at all, and if so how:

1. Leave `field_duration` as-is (a separate, sometimes-stale catalog field,
   never surfaced in the UI on either D7 or D11) -- lowest effort, matches
   current D7 behavior exactly.
2. Overwrite `field_duration` from `kaltura_duration` wherever they disagree
   -- makes the PBCore record internally consistent with the file, but
   changes cataloged data nobody has reviewed the 368 cases for (some
   disagreements this large could reflect a real cataloging note, e.g. a
   partial/excerpted instantiation, not just error).
3. Leave both as-is for now and only reconcile once real AV cataloging
   staff (or Than, who wrote AV's original PBCore ingestion) can review the
   368 cases -- this is a data-quality call, not a technical one, and is
   Sprint-4/backlog territory either way.

## Recommendation

File alongside the other AV4/AV15 content-model questions already queued
for Than's return (week of 2026-09-22). No action needed before then --
D11's display already does the right thing regardless of how this is
resolved.
