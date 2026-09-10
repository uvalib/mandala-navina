# Session Log: AV4's live dev-0 run confirmed complete, re-verified, and the four holding PRs merged

**Date:** 2026-09-10
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** The AV4 migration launched live on dev-0 the previous night
finished cleanly overnight. Re-verified directly against dev-0's own
database — 24/24 checks match the D7 source. All four PRs that had
accumulated around this work (#193–#196) are now merged; `main` is clean
with no open branches or in-flight hazards.

| PR | State | |
|---|---|---|
| [#193](https://github.com/uvalib/mandala-navina/pull/193) | merged (09-09) | AV4 build |
| [#194](https://github.com/uvalib/mandala-navina/pull/194) | **merged today** | dev-0 nightly-shutdown correction + `refresh-d7-staging-source.sh` fixes |
| [#195](https://github.com/uvalib/mandala-navina/pull/195) | **merged today** | 09-09 session log (launch) |
| [#196](https://github.com/uvalib/mandala-navina/pull/196) | **merged today** | this run's final statistics |

---

## 1. Checked in on the overnight run, twice, with a real ETA

Picked up mid-run. At ~56% complete (14/27 stages, 95,726 of 170,934 total
rows), gave a grounded ETA using the dev-0 rates measured so far rather than
guessing — high confidence on the remaining paragraph stages (~25–30 min,
similar shape to what had already run), low confidence on the two node
migrations (no dev-0 node rate existed yet). Projected 1.5–2.75 more hours,
landing inside the original 3.5–5h band from the 09-08 planning session.

## 2. The run finished clean

**2026-09-10T00:57:20Z** — 5h04m49s total, 27/27 stages, **zero**
`FAILED STAGES`. One row failure across the entire run: the already-known
`photo.jpg` 404 on `d7_av_files` (a source-side gap, not a migration defect —
see the AV4 migration notes). Sum of the 27 individual stage durations
matched the wall-clock total exactly, so there was no idle time anywhere in
the run.

**Real dev-0 node rates, finally measured:** `d7_av_audio` ~102 rows/min,
`d7_av_video` ~92 rows/min — about **6× slower** than the DDEV rates measured
two nights ago (620/509 per min). This confirms, with real numbers, the
caution recorded after the DDEV-vs-dev0 comparison mistake: the two
environments really don't transfer, and in this case the direction is
exactly opposite of a naive read of the local numbers. Nodes do more
relational lookup work per row than any paragraph type (13 paragraph
references, 6 KMaps fields, 2 file lookups, a tags lookup), so DDEV's
in-container MySQL — which hides RDS round-trip cost entirely — was always
going to mislead here.

## 3. Re-verified directly against dev-0, not just trusted the clean exit

Ran the same 24 checks `scripts/verify-av-migration.sh` uses locally, against
dev-0's actual database. Hit one more pre-existing, unrelated defect along
the way: **`drush sql:query` fails outright against this RDS instance** with
*"TLS/SSL error: self-signed certificate in certificate chain"* — for any
query, even one against dev-0's own database. `migrate:import` was never
affected because it goes through `\Drupal::database()`, which handles the
same connection differently; verification used `drush php:script` for the
same reason.

**All 24 checks matched the D7 source exactly** — the same clean result the
local DDEV run produced two nights ago, now confirmed for real.

## 4. Merged the four holding PRs

With the migration confirmed done, the hazard that had kept PRs #194–#195
open overnight (a merge triggers a CodePipeline deploy that recreates dev-0's
container, which would have killed the migration) no longer applied. Merged
both, then wrote up this run's final statistics (PR #196) and merged that
too — all before stepping away, so nothing is left half-closed over the
weekend.

Full timing table and the node-rate analysis:
[`docs/planning/av-node-migration-notes.md` §7](../planning/av-node-migration-notes.md).
The dev-0-hazard deferred note is marked resolved but kept as the general
reference for the next long job on dev-0:
[`docs/deferred/av4-dev0-live-migration-run.md`](../deferred/av4-dev0-live-migration-run.md).

## What's left

- **`drush kmassets:index-all && drush kmassets:audit` on dev-0** —
  deliberately not run today (out of time). The migrated AV content exists in
  D11 but isn't in the Solr index yet. Do this first when picking back up —
  before AV8 needs the index current.
- **AV6 (KMaps field wiring), AV7 (Group access mapping), AV8 (Solr/kmassets
  sync)** — all unblocked now that AV4 is fully done and verified.
- Carried forward, unchanged: the `field_pbcore_language` ISO-639 conversion
  (recommended before AV8), the 42 remaining paragraph-ordering ties, and
  AV14's 18-nid cleanup list — all need AV staff input, not more analysis.
