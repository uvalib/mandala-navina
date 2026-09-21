# Collection/subcollection featured images — 126 of 210 missing locally/on dev-0, FIXED on DDEV, root cause still open

**Area:** migration / Images content / infrastructure
**Raised during:** Session 2026-09-03 (backfilling `field_featured_image` onto D11
Group entities — see
[docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md),
Workstream B5, and
[migrate-entity-group-update-mode-nulls-uid.md](migrate-entity-group-update-mode-nulls-uid.md)
for the migration this surfaced during); **corrected and dramatically expanded, then
fixed on DDEV, 2026-09-21** via a proper full-corpus audit
(`drush mandala:missing-file-audit`, new command in `mandala_migrations`), built after a
manual demo-content check hit one missing file.
**Priority:** Low — cosmetic (affects the "All Collections" card grid's thumbnail and a
collection's own page image), not a functional/data-integrity blocker, and **not data
loss** (see below). `shanti_collections_view` already falls back to a generic default
thumbnail for any collection with no resolvable featured image, so nothing is broken or
missing visually — affected collections just show the default instead of their real
photo. **DDEV fixed; dev-0 still affected (fix not yet run there); root cause of the
original loss still open** — see below.

## 2026-09-21 update: the real scope is far bigger than "15," and it's fully recoverable

Running the new `drush mandala:missing-file-audit --check-d7-source` found **126 of 210
`group.field_featured_image` references (60%) point to a file missing from disk** —
confirmed on both DDEV and dev-0, so not a local-environment-only sync gap. That's a
large jump from the 16 (15 Images + 1 AV) known as of 2026-09-03, meaning most of the
"135 of 150 succeeded" files from the original migration have since gone missing too,
not just the original 15 failures.

**The good news, checked directly, not assumed:** all 126 are still live and fetchable
at their known D7 production source root (`https://images.mandala.library.virginia.edu/
sites/mandala-images.lib.virginia.edu/files/{filename}` for the Images-sourced ones —
every one of the 126 turned out to be Images-sourced, none AV). Spot-verified one
directly: a live `HEAD` request returns a real `200`, `content-type: image/png`, and a
`content-length` that matches D11's own stored `filesize` for that fid exactly —
genuinely the same, unchanged file, not a coincidence. **This is not data loss** — the
source is intact; D11's local file storage (in whichever environment(s) this affects)
just never received (or later lost) the copied bytes, even though the `file_managed`
metadata row and the group's field reference are both correct. Root cause of *why* the
binaries are missing (a `file_copy` failure that didn't surface as a migration error?
something later removed from storage post-migration?) is not yet investigated.

**Fixed 2026-09-21** — `drush mandala:missing-file-audit --fix` (new command,
`mandala_migrations`) re-fetched all 126 from their confirmed-live D7 source and wrote
them to the existing `uri`/`fid` in place (no entity reference changed). Verified: the
audit re-run reports 0 missing of 8,425; spot-checked one restored file's bytes match
the D7 source's `content-length` exactly; confirmed live in a browser that a restored
collection page now shows its real photo instead of the fallback. **Run so far on DDEV
only** — the same 126 are confirmed missing on dev-0 too; re-running there is a
deliberate follow-up, not done automatically, since it writes to shared file storage.

**⚠ FOR THAN (back 2026-09-24) — root cause discussion, not just a review.** Root cause
still not confirmed, but a real lead exists: ruled out any *Drupal-level*
edit as the cause -- every group referencing one of these 135 files (missing or
surviving) has the identical `changed` timestamp as the original 2026-09-03
migration/backfill run (~23s after the files' own `created` timestamp); nothing was
touched afterward through Drupal. Whatever happened left no trace in Drupal's own data,
pointing at either the original migration's own file-copy step silently failing for
these specific items, or a later filesystem-level event (a partial rsync, an incomplete
environment/files-directory restore) that wouldn't touch any Drupal timestamp either
way. **Than has said (recalled during this session, not yet independently confirmed)
that these particular collection-featured-images were "custom files"** -- consistent
with `field_general_featured_image` generally being a hand-curated hero image per
collection rather than reused member content, but unconfirmed whether that also
explains *why* a majority specifically failed to survive locally while ~7% (9 of 135)
did. Worth confirming directly with Than (back 2026-09-24) whether "custom" here means
something more specific -- e.g. uploaded to D7 through a non-standard path that a normal
backup/sync might not have captured -- since that would be a concrete, checkable root
cause rather than a guess.

## Original 2026-09-03 finding (superseded above, kept for the specific 15 hosts already investigated)

## What happened

The new `d7_images_collection_featured_image` file migration (150 D7
`field_general_featured_image` references, scoped via a custom source plugin —
see the migration doc above) fetches each file's bytes over plain HTTP from
production's public files path
(`https://images.mandala.library.virginia.edu/sites/mandala-images.lib.virginia.edu/files/{filename}`).
135 of 150 succeeded; **15 failed with a live 404** on that URL — confirmed via direct
`curl` after the migration run, not just the migration's own error log, so these are
real, current, reproducible 404s on production right now, not a migration bug or a
transient network blip.

## The 15

| Collection/Subcollection | Type | D7 `file_managed.filename` | Note |
|---|---|---|---|
| Resist | subcollection | `IMG_0571.JPG` | |
| Standalone Image Collection Sample | collection | `5981596891_f4a1601dc5_o.jpg` | looks like test/scratch data |
| Maria Varela Photographs | subcollection | `Maria Varela Self-Portrait` | **no file extension** |
| Orchestration II: Beethoven | collection | `Beethoven manuscript.png` | |
| Shang Chuan Dao Villages | subcollection | `Shang Chuan Dao Villages Agriculture` | **no file extension** |
| Theatre (Shuison) | collection | `Theatre (Shuison)` | **no file extension** |
| Zangkar Collection | collection | `9-30.JPG` | |
| Ganlho Dzoge | subcollection | `Ganlho Dzoge` | **no file extension** |
| Ganlho Tsos Nabuk | subcollection | `Ganlho Tsos Nabuk` | **no file extension** |
| Dzala | subcollection | `shanti-image-53396-Dzala.jpg` | |
| All New Test Collection | collection | `staunton drawaing` | **no file extension**; looks like test/scratch data |
| 2004 Provisional | subcollection | `Kham Monastery.png` | |
| Toni Huber Collection | collection | `Toni Huber Collection.png` | |
| Test subcollection-AM 03-13-23 | subcollection | `Reurink JRO_6247 Trugo Gon Manasarovar.jpg` | looks like test/scratch data |
| Mysql Update Test | subcollection | `afternoon-tea_web.jpg` | looks like test/scratch data |

6 of the 15 have a `filename` value in D7's `file_managed` table with **no file
extension at all** — a real D7 data-quality gap predating this migration. A missing
extension makes a 404 almost certain regardless of whether the underlying file still
exists, since the constructed URL can't match a real path; the true file may still be
on production under a slightly different (correctly-extensioned) filename, which isn't
recoverable from the D7 metadata alone. Confirmed via direct `curl` that all 6 of these
also currently 404, same as the other 9.

4 of the 15 (`Standalone Image Collection Sample`, `All New Test Collection`, `Test
subcollection-AM 03-13-23`, `Mysql Update Test`) look like test/scratch collections by
name, not real production content — may not need a real image at all.

## Possible next steps (not decided, not started)

- For the ~6 real (non-test-looking) collections with a proper extension (`Resist`,
  `Orchestration II: Beethoven`, `Zangkar Collection`, `Dzala`, `2004 Provisional`,
  `Toni Huber Collection`) — someone with direct filesystem/DB access to production
  could check whether the file genuinely no longer exists, or whether `file_managed`'s
  `uri`/`filename` is simply stale (e.g. the file was renamed/moved after the D7 row was
  last updated).
- For the 6 extension-less filenames — same access would be needed to find the real
  on-disk filename, if the file still exists at all.
- The 4 test/scratch-looking collections likely don't need any resolution — worth a
  quick human confirmation they're genuinely test data before writing them off.
- No action needed for D11/D111 functionality either way — the default-thumbnail
  fallback already covers this gracefully.

## AV shows the same pattern — 1 file, 2026-09-09 (Sprint 3 AV4)

The AV file migration (`d7_av_files`, 8,292 files) hit exactly one 404 on the live
D7 AV site, and it is the same shape as the 15 above:

| fid | uri | referenced by |
|---|---|---|
| 9788 | `public://photo.jpg` | AV collection nid **4686** — "Landscape" |

`file_managed` has the row; the binary is gone from the server. Consequence is
identical and equally cosmetic: that one AV collection falls back to the default
thumbnail. Every other AV file transferred, verified byte-for-byte including
non-ASCII names.

Worth noting the ratio: Images lost 15, AV loses 1 out of 8,292. Whatever caused
this is not AV-specific and not getting worse.
