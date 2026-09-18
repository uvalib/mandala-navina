# AV15: `avduration`/`avuploader`/`avrating` — likely abandoned fields, needs Than's read

**Area:** migration / AV / content model
**Raised during:** Session 2026-09-17/18, auditing D7's `avinfo` block for AV15 parity
**Jira:** (add when available)
**Priority:** Low — nothing user-facing is blocked; these three UI slots are
either empty or unimplementable as-is on every real example checked, so
AV15 already degrades gracefully without them. The value here is a content-
model question for Than, not urgent implementation work.

## What was found

D7's `avinfo` block (`av.mandala.library.virginia.edu`, the real production
theme) has 4 rows: `avdate`, `avduration`, `avuploader`, `avrating`. AV15
implements `avdate` (the node's own creation timestamp) and deliberately
left the other three out, each for a different, checked (not assumed)
reason:

- **`avduration`** — confirmed live that this is NOT sourced from any
  migrated field. Found a real node (D7/D11 nid 1749, "Tsegol the Mountain
  Deity") where the migrated `field_duration` value is `00:03:49`, but the
  live page displays "3 min **50** sec" — a one-second mismatch that can
  only mean the theme reads the actual Kaltura media length at runtime, not
  the stored PBCore value.
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

**`avduration` in particular looks like a real content-model gap, not just
a UI nuance.** PBCore *does* have a duration concept, and D7's own data has
it: `field_duration` is a real, migrated `av_pbcore_instantiation` sub-field
with 1,403 populated values corpus-wide (not sparse). The live theme
ignoring that in favor of a client-side Kaltura lookup could be:

- a deliberate choice (duration drifts from the cataloged value as media
  gets re-encoded/re-hosted, so "ask the actual file" is more trustworthy
  than a stale catalog entry), **or**
- an artifact of the theme evolving after the PBCore data model was set up,
  never wired back together — i.e. genuinely abandoned, matching this
  note's title.

Than was the original D7 developer for AV's content types and APIs and
would know which. If it's the latter, AV15 (or a later Sprint 4 pass) should
probably show `field_duration` as a real static value instead of leaving
duration out entirely — cheaper and more reliable than adding a live
Kaltura API dependency to a static metadata panel just to match D7 exactly.

`avuploader`/`avrating` are lower-stakes but the same question applies:
confirm whether they're genuinely dead UI (safe to leave out permanently)
or gated/underused features worth a real look.

## Recommendation

Ask Than, when he's back (week of 2026-09-22):
1. Is `avduration`'s live-Kaltura-only behavior deliberate, or did it drift
   from `field_duration` at some point? Should AV15 show the PBCore value?
2. What (if anything) populates `avuploader` — a field this audit didn't
   find, or a permissions-gated view this audit didn't check?
3. Is `avrating` a live feature anywhere in the D7 site, or already
   effectively retired given the 2-node data footprint?

Not blocking any other AV work.
