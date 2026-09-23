# No mechanism populates local `sites/default/files` binaries -- needs a team decision

**Area:** local dev environment / DX / infrastructure
**Raised during:** Session 2026-09-23 (diagnosing 4 blank Mandala Home carousel
slides on Xiaoming's DDEV; see
[collection-featured-images-missing-on-production.md](collection-featured-images-missing-on-production.md)
for the incident this generalizes from)
**Jira:** (add when available)
**Priority:** Medium-High -- cosmetic today (default-thumbnail/blank-image
fallbacks mean nothing is functionally broken), but confirmed to independently
affect every developer's local environment, and gets worse as more
file-referencing features (like the carousel) get built.

## The gap

Nothing in this project's local-dev tooling ever bulk-populates
`sites/default/files`. The two mechanisms that keep a local DDEV "in sync" --
`git pull` + `config:import` (code/config) and `update-db-from-remote.sh`
(content, via a dev-0 DB dump) -- only ever touch the database and config.
Neither one fetches a single file binary. `sites/default/files/` is pure
local, per-machine state: never in git, never in `config/sync`, and (checked
2026-09-23) there is no script in `scripts/` that syncs it from anywhere.

Consequence: a `file_managed` DB row can exist (correctly) on every
environment via the DB sync, while the actual bytes at that row's `uri` only
ever exist on whichever single machine happened to fetch them by hand.

## Confirmed independently on two developers' machines

- **Yuji's DDEV, 2026-09-21:** `drush mandala:missing-file-audit` found 126 of
  210 `group.field_featured_image` references missing on disk; fixed via the
  command's `--fix` (re-fetches from D7 production, writes to the existing
  `uri`/`fid`). That fix is real and still holds -- **on his machine only.**
- **Xiaoming's DDEV, 2026-09-23:** the same audit, run there for the first
  time, found **8,417 of 8,433 managed files (99.8%) missing sitewide** (not
  scoped to one field -- the command discovers every real file/image field
  generically). Of those, 3,004 are still fetchable from D7 production, 5,413
  are confirmed gone there too. Found via 4 blank carousel thumbnails; those 4
  fixed directly, the other ~3,000 deliberately left alone pending this note.

Neither machine's result was a regression of the other's fix -- they are two
independent, never-connected local states. **The working assumption going
forward should be that any team member's DDEV has this gap until someone
actually runs the audit there**, not that it's specific to one machine.

## What exists today (reactive, not a fix)

`drush mandala:missing-file-audit` (`mandala_migrations` module,
`MissingFileAuditCommands.php`), built 2026-09-21:
- Reports every managed file missing from disk and what references it.
- `--check-d7-source`: tries each missing file's basename against a hardcoded
  map of exactly **two** of the five legacy D7 sites' production file roots
  (Images, AV -- Sources/Texts/Mandala Home are unmapped).
- `--fix`: re-fetches and restores confirmed-recoverable files in place.
- Root-level only by design (see its class docblock) -- a file that lived in
  a D7 subdirectory (e.g. AV's `transcripts/`) won't be found even if it still
  exists.
- Someone has to remember to run it, on each machine, after the fact. It is
  not wired into `ddev start`, `session-start-check.sh`, or anything else
  that runs automatically.
- Its own source (live D7 production) is itself degrading: 5,413 of
  Xiaoming's 8,417 missing files were already confirmed gone there too on
  2026-09-23 -- whatever the long-term source is, it can't be D7 production
  alone indefinitely.

## Precise breakdown (2026-09-23, Xiaoming's DDEV) -- it's exactly 3 fields, not "everything"

A DB-only pass (no network calls, so fast and safe to re-run any time) shows
the 8,413-8,417 missing figure is not open-ended -- it resolves almost
entirely to three specific fields, each essentially 100% missing on this
machine:

| Field | Missing / total distinct files | Where it's used |
|---|---|---|
| `node.field_transcript` | **5,379 / 5,379 (100%)** | AV transcript files |
| `node.field_thumbnail_image` | **2,839 / 2,843 (99.9%)** | Video/audio node thumbnails -- likely visible on regular AV listing/detail pages, not just the carousel; worth a live spot-check |
| `group.field_featured_image` | **206 / 206 (100%)** | Collection/subcollection hero images -- the original 2026-09-03/2026-09-21 finding, now also back to fully missing on this machine |

(5,379 + 2,839 + 206 = 8,424, plus a handful of orphaned rows accounts for
the full total.) This materially changes the scope of question 1 below: a
fix doesn't need to solve "sync arbitrary file fields," just these three,
and `field_thumbnail_image` in particular is likely a live, user-visible gap
beyond the carousel that's worth confirming directly.

## Open questions for the team (not decided, not started)

1. **Does local dev need full file-binary parity at all?** Per
   [images-field-image-binary-migration.md](images-field-image-binary-migration.md),
   only 0.5% of `shanti_image` nodes even carry a local `field_image` by
   design -- display is IIIF-driven, not local-file-driven, for the vast
   majority of Images content. The real functional need may be much narrower
   than "sync everything" -- e.g., only the fields actual features render
   locally (like the carousel's slide images), not the full historical
   corpus. Scoping this down could make the fix far cheaper than it looks
   from the raw 99.8% number.
2. **What should the canonical source be, if not D7 production?** Candidates,
   none evaluated yet: dev-0's own `sites/default/files` (already the shared
   team resource for DB content, but this session never checked whether it
   itself is fully populated); a dedicated S3 bucket; a files tarball
   refreshed alongside `canonical-d7-dev-source-dump.md`'s existing DB
   refresh-and-alert design.
3. **On-demand script, or automatic?** A files-sync script mirroring
   `update-db-from-remote.sh`'s pattern (pull, land locally, destructive
   warning) vs. wiring something into `session-start-check.sh` (detect drift,
   like step 3a/3b already do for config/content) vs. into `ddev start`
   itself.
4. **Does `mandala:missing-file-audit` become the mechanism, extended?** It
   already does the hard part (generic field discovery, safe restore-in-place
   with byte-count verification) -- it may just need a better/broader source
   map and a trigger, rather than a wholly new tool.

## Not yet done

- No design chosen among the above.
- The ~3,000 files on Xiaoming's DDEV confirmed recoverable from D7
  production have **not** been bulk-restored -- deliberately deferred
  pending this decision, not a forgotten step.
- Yuji's and dev-0's own file-binary completeness have not been
  independently re-verified as part of this finding.
