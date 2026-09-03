# 15 collection/subcollection featured images 404 on production — source files missing or misnamed

**Area:** migration / Images content
**Raised during:** Session 2026-09-03 (backfilling `field_featured_image` onto D11
Group entities — see
[docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md),
Workstream B5, and
[migrate-entity-group-update-mode-nulls-uid.md](migrate-entity-group-update-mode-nulls-uid.md)
for the migration this surfaced during)
**Priority:** Low — cosmetic (affects the "All Collections" card grid's thumbnail and a
collection's own page image), not a functional/data-integrity blocker. The new
`shanti_collections_view` module already falls back to a generic default thumbnail for
any collection with no resolvable featured image, so nothing is broken or missing
visually — these 15 just show the default instead of their real photo.

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
