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

## dev-0 confirmed as a complete, durable source (2026-09-23) -- this decides question 2

First checked directly against dev-0 (SSH, read-only `file_system`-service
check, same method as the local audit -- not a raw path guess) for the exact
three fields found missing locally:

| Field | Missing on dev-0 |
|---|---|
| `node.field_transcript` | **0 of 5,379** |
| `node.field_thumbnail_image` | **0 of 2,843** |
| `group.field_featured_image` | **0 of 206** |

**Then ran the full `drush mandala:missing-file-audit` (every field, not
just these three) directly on dev-0:** `No missing files -- every 8,447
file_managed row has a real file on disk.` Zero exceptions, site-wide --
not just the three fields this session happened to find gapped locally.
(dev-0's 8,447 total vs. local's 8,433 -- a few more rows, likely newer
content created directly on dev-0, e.g. the carousel demo block.)

This resolves question 2 below in dev-0's favor over D7 production: it's a
project-owned environment (not being decommissioned), confirmed **fully**
complete, and -- unlike D7's flat/basename-only file root -- files there are
addressable by exact `uri`, so a sync keyed on `uri` doesn't inherit the D7
audit's "root-level only" blind spot (relevant for AV's `transcripts/`-style
subdirectories).

**Checked the reverse direction too** (disk -> DB, which
`mandala:missing-file-audit` never checks -- it only validates DB rows
against disk, not the other way): diffed dev-0's on-disk files (8,459,
excluding generated cache dirs `styles/`, `php/`, `css/`, `js/`) against all
8,447 `public://` `file_managed` rows. Only **12 files on disk have no DB
row** (0.14%, ~4 MB total) -- 4 are Drupal core's own built-in
`media-icons/generic/*.png` defaults (not uploads, expected), the other 8
are small orphaned test/demo images with no functional impact. Both
directions now confirmed clean: dev-0's disk and database agree almost
perfectly, reinforcing it as the right sync source.

## Recommended direction (not yet decided by the team -- for discussion)

**Sync/fetch missing local files from dev-0, not D7 production**, most
simply by extending `mandala:missing-file-audit`'s existing source map
(`MissingFileAuditCommands::D7_SOURCE_BASES`) with dev-0's own public files
URL as an additional, higher-priority source, reusing the same
fetch/verify-byte-count/write-in-place logic already built and proven --
no new SSH/rsync plumbing needed if dev-0 serves these files over plain
HTTPS the same way D7 does (not yet confirmed for every field; spot-check
before building). Keep D7 production as a fallback only for whatever dev-0
itself is ever missing. This directly answers open question 4 below (yes,
extend the existing tool) and narrows question 2 (dev-0 first, D7 as
fallback) -- questions 1 and 3 (full parity vs. narrower scope; on-demand
vs. automatic trigger) are still open for the team to decide.

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
2. ~~What should the canonical source be, if not D7 production?~~ **Answered
   above (2026-09-23): dev-0, confirmed complete for the affected fields.**
3. **On-demand script, or automatic?** A files-sync script mirroring
   `update-db-from-remote.sh`'s pattern (pull, land locally, destructive
   warning) vs. wiring something into `session-start-check.sh` (detect drift,
   like step 3a/3b already do for config/content) vs. into `ddev start`
   itself.
4. ~~Does `mandala:missing-file-audit` become the mechanism, extended?~~
   **Recommended above: yes**, add dev-0 as a source, keep D7 as fallback.

## Not yet done

- Whether to build this at all, and on what trigger (question 1, 3) --
  still an open team decision, not started.
- The ~3,000 files on Xiaoming's DDEV confirmed recoverable from D7
  production have **not** been bulk-restored -- deliberately deferred
  pending this decision, not a forgotten step. With dev-0 confirmed
  complete, restoring from dev-0 instead once a mechanism exists is
  preferable to the D7-based `--fix` used for the carousel's 4 files.
- Not yet confirmed whether dev-0 serves these files over plain HTTPS the
  same way D7 does (assumed by analogy with the home-page/carousel checks
  already run against it) -- verify before implementing the HTTP-fetch
  approach above.
