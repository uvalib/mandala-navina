# Collection/subcollection featured images — 126 of 210 missing on DDEV only, FIXED, dev-0 was never affected

**Area:** migration / Images content / local dev environment
**Raised during:** Session 2026-09-03 (backfilling `field_featured_image` onto D11
Group entities — see
[docs/sprints/sprint-02-theme-images-ui-and-endpoint-access.md](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md),
Workstream B5, and
[migrate-entity-group-update-mode-nulls-uid.md](migrate-entity-group-update-mode-nulls-uid.md)
for the migration this surfaced during); **corrected, then fixed, 2026-09-21** via a
proper full-corpus audit (`drush mandala:missing-file-audit`, new command in
`mandala_migrations`), built after a manual demo-content check hit one missing file.
**Priority:** Low — cosmetic (affects the "All Collections" card grid's thumbnail and a
collection's own page image), was never a functional/data-integrity blocker, and turned
out to be **DDEV-local only, now fixed** (see below). `shanti_collections_view` already
falls back to a generic default thumbnail for any collection with no resolvable
featured image, so nothing was ever broken or missing visually on a real environment —
affected collections just showed the default instead of their real photo, on DDEV only.

## 2026-09-23 update: back to 99.8% missing on DDEV (8,417 of 8,433) — the 2026-09-21 fix did not stick, and scope is much larger than `group.field_featured_image`

Found while diagnosing blank thumbnails in the Mandala Home carousel (4 of 12
audio-slide images blank, links working). `drush mandala:missing-file-audit
--check-d7-source` (full corpus, not scoped to one field) reported **8,417 of
8,433 managed files (99.8%) missing from disk**, not the 0 the 2026-09-21 fix
left it at (8,425 total then; +8 today from freshly seeded carousel
thumbnails matches exactly). Of the missing files: 3,004 still fetchable from
a known D7 source (recoverable), 5,413 confirmed gone at the source too.

This is the same class of problem as the 126/210 `group.field_featured_image`
gap below, but the true scope is sitewide across every real file/image field
(the command discovers fields generically via `field_storage_config`, not
just the one field class it was originally built for) — **126/210 was an
undercount of a much bigger, still-unexplained gap**, not the whole story.

**Only the carousel's 4 blank slides were fixed today** (`ugyen.png`,
`Kelzang Dolma_2.png`, `65249.jpg`, `Choden_5.png` — all 4 confirmed
recoverable from the AV production root, restored via the same
fetch-and-write-in-place approach as the command's `--fix`, spot-verified
live on `mandala.ddev.site`). **The other ~3,000 recoverable files were
deliberately NOT restored** — that's a much bigger action (real network
fetches, tens of minutes) than what the carousel needed, and directly
overlaps the open question below. Deferred to this afternoon's meeting with
Than (2026-09-23) rather than acted on solo.

**New data point for the "why DDEV specifically" question below:** whatever
restored 0-missing on 2026-09-21 did not persist — DDEV was not rebuilt
between sessions (git log shows no full-DB rebase this week), so either the
2026-09-21 fix's restored bytes were written somewhere that didn't survive
(e.g. a container recreate, since `ddev-mandala-web`'s writable layer is
known to not survive a stop/start per
[[project-ddev-local-env-gotchas]]'s stale-Apache-PID note), or something
else quietly reset the files directory since then. Worth raising directly
with Than, not just the original "custom files" lead.

## 2026-09-21 update: 126 missing on DDEV, D7 source intact, dev-0 was fine all along

Running the new `drush mandala:missing-file-audit --check-d7-source` on DDEV found
**126 of 210 `group.field_featured_image` references (60%) point to a file missing from
disk**. That's a large jump from the 16 (15 Images + 1 AV) known as of 2026-09-03 —
but see the correction below on what that scope actually turned out to mean.

**The good news, checked directly, not assumed:** all 126 were still live and fetchable
at their known D7 production source root (`https://images.mandala.library.virginia.edu/
sites/mandala-images.lib.virginia.edu/files/{filename}`). Spot-verified one directly: a
live `HEAD` request returned a real `200`, `content-type: image/png`, and a
`content-length` that matched D11's own stored `filesize` for that fid exactly.

**Fixed on DDEV 2026-09-21** — `drush mandala:missing-file-audit --fix` re-fetched all
126 from their confirmed-live D7 source and wrote them to the existing `uri`/`fid` in
place (no entity reference changed). Verified: the audit re-run reported 0 missing of
8,425; spot-checked one restored file's bytes matched the D7 source's `content-length`
exactly; confirmed live in a browser that a restored collection page now shows its real
photo instead of the fallback.

**Correction, same day, before running the equivalent fix on dev-0:** this was
originally reported as "confirmed missing on dev-0 too, not a local artifact" — that
was **wrong**, caused by checking dev-0's filesystem at the wrong path
(`/var/www/html/sites/default/files/`, a generic Docker-Drupal guess, never verified
against this project's actual container). The real docroot on
`mandala-drupal-dev-0.internal.lib.virginia.edu`'s `mandala-drupal-0` container is
`/opt/drupal/app/drupal/web/sites/default/files/`. Re-checked properly (via Drupal's own
`file_system` service, which resolves the real path, not a raw `ls` guess): **`drush
mandala:missing-file-audit` on dev-0 reports 0 missing files, corpus-wide, before any
fix was ever run there.** dev-0 was never affected. This was a DDEV-local sync/bootstrap
gap the whole time, not a cross-environment or production-adjacent issue -- lesson
recorded in [[feedback-negative-grep-is-not-proof-of-absence]]-adjacent territory: a
raw filesystem check against an unverified guessed path is not proof of absence either.

**Still open, now more narrowly scoped:** *why* did DDEV's local files specifically miss
these 126 (mostly clustered late in the original 2026-09-03 migration's fid range, with
one exception — fid 161 is missing despite sitting between two survivors, 121 and 176,
breaking a simple "copy stopped partway through" theory) while dev-0's copy of the same
migration output was always complete? Checked and ruled out any *Drupal-level* edit as
the difference -- every group referencing one of these 135 files (missing on DDEV or
not) has the identical `changed` timestamp as the original 2026-09-03 migration/backfill
run; nothing was touched afterward through Drupal on either environment. **Than has said
(recalled during this session, not yet independently confirmed) that these particular
collection-featured-images were "custom files"** -- given dev-0 was fine, this now reads
as a plausible lead specifically about *how DDEV's local files got bootstrapped*: if
DDEV's local environment setup pulls files via some mechanism that specifically excludes
non-standard/hand-uploaded ("custom") files while dev-0's storage never went through
that same bootstrap step, that would explain the DDEV-only gap precisely. **FOR THAN
(back 2026-09-24):** worth asking directly what "custom files" meant, and separately
worth checking `scripts/refresh-d7-staging-source.sh` / whatever populated DDEV's local
`sites/default/files/` for anything that would selectively skip this set.

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
