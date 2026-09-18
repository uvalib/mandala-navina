# AV15: `avuploader`/`avrating` — likely abandoned fields, needs Than's read

**Area:** migration / AV / content model
**Raised during:** Session 2026-09-17/18, auditing D7's `avinfo` block for AV15 parity
**Jira:** (add when available)
**Priority:** Low — nothing user-facing is blocked; these two UI slots are
either empty or unimplementable as-is on every real example checked, so
AV15 already degrades gracefully without them. The value here is a content-
model question for Than, not urgent implementation work.

## `avduration` — RESOLVED 2026-09-18, now implemented

Originally filed here alongside `avuploader`/`avrating` as a third
"likely abandoned" field, on the strength of one real node (D7/D11 nid
1749) where the migrated PBCore `field_duration` (`00:03:49`) was one
second off the live page's displayed "3 min 50 sec" -- taken at the time as
evidence the theme reads the Kaltura entry live, out of scope for a static
panel.

That was wrong in a useful way, caught when asked directly to wire the row
up rather than leave it out. D7's `.avduration` is not a live API read --
it's `node_kaltura.kaltura_duration`, D7's own local cache of the Kaltura
entry's length (an `int`, seconds), joined via the node's `field_video`/
`field_audio` entry id. Confirmed exactly on a second node (D7 nid 33126:
`kaltura_duration = 194` -> "3 min 14 sec", matching the live page exactly)
-- so it's a perfectly migratable, static value, just not the PBCore one.

Implemented: new `field_kaltura_duration` (node-level, video+audio),
backfilled from D7's `node_kaltura` via the already-migrated Kaltura entry
id (`drush av:backfill-kaltura-duration`, `mandala_migrations`, no
migrate-map lookup needed -- D11 already has the entry id on the node
itself). Wired into AV15's Video/Audio Overview block
(`shanticon-hourglass`, matching D7's icon/wording).

A full-corpus check of the *other* duration value (PBCore's own
`field_duration`) found it disagrees with `kaltura_duration` on 41% of the
hosts that have both -- a real, separate data-cleanup question, not a D11
defect (D7's own UI never shows `field_duration` either). Filed on its own:
[av15-pbcore-duration-vs-kaltura-duration.md](av15-pbcore-duration-vs-kaltura-duration.md).

## What was found (avuploader/avrating, still open)

D7's `avinfo` block (`av.mandala.library.virginia.edu`, the real production
theme) has 4 rows: `avdate`, `avduration`, `avuploader`, `avrating`. AV15
implements `avdate` and (as of 2026-09-18) `avduration`; `avuploader`/
`avrating` remain deliberately left out, each for a checked (not assumed)
reason:

- **`avuploader`** (icon `title="Uploader"`) — checked 3 real nodes,
  including ones with otherwise rich metadata: empty every time. No D7 field
  at the node level is named anything uploader/registrar-like, and
  `field_workflow`'s ~28 sub-fields (checked exhaustively) don't have one
  either. Only checked anonymous view — unconfirmed whether this populates
  for a logged-in editor/admin account.
- **`avrating`** — a `fivestar` widget. Already established elsewhere this
  session that only 2 nodes in the entire ~11,583-node AV corpus have any
  `field_rating` data at all.

## Why this is worth Than's time, not just leaving alone

`avuploader`/`avrating` are low-stakes but worth confirming: are they
genuinely dead UI (safe to leave out permanently), or gated/underused
features worth a real look? Than was the original D7 developer for AV's
content types and APIs and would know.

## Recommendation

Ask Than, when he's back (week of 2026-09-22):
1. What (if anything) populates `avuploader` — a field this audit didn't
   find, or a permissions-gated view this audit didn't check?
2. Is `avrating` a live feature anywhere in the D7 site, or already
   effectively retired given the 2-node data footprint?

Also worth Than's read while he's looking at this area: the separate
`field_duration`-vs-`kaltura_duration` disagreement filed in
[av15-pbcore-duration-vs-kaltura-duration.md](av15-pbcore-duration-vs-kaltura-duration.md).

Not blocking any other AV work.
