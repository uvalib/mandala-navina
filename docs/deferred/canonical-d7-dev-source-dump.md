# Establish a canonical D7 dev source dump on dev-0

**Area:** migration / source data / environments / DX
**Raised during:** Session 2026-08-25 (dev-0 vs DDEV baseline mismatch)
**Jira:** (add when available)
**Priority:** Medium now, **High before cutover rehearsals** — it is what makes counts
comparable between environments

## The problem, measured

dev-0 and local DDEV are running **different D7 source dumps**, and nothing says so anywhere.
Measured 2026-08-25:

| | local DDEV | dev-0 |
|---|---:|---:|
| D7 source nodes | 288,023 | 287,939 |
| `node:shanti_image` | 111,343 | 111,341 |
| `field:field_kmap_terms` | 55,553 | **61,668** |
| groups (collection / subcollection) | 55 / 119 | 55 / **116** |

Seven of the eight committed `EXPECT_LIST` baseline keys differ. **The data is not wrong** —
`61,668` is exactly the figure `scripts/migration-cycle.sh`'s own header records as the
*superseded* 2026-06-11 baseline, which the newer local dump moved to `55,553`. dev-0's source
was loaded 2026-07-17 from production; the committed baseline was calibrated on the 2026-07-07
staging dump.

The immediate consequence is recorded in the
[1a.9 checklist](../planning/1a9-staging-acceptance-checklist.md): running `validate` on dev-0
against the committed baseline produces **seven spurious FAILs**, which reads exactly like a
broken migration.

## Decision (Yuji, 2026-08-25)

**Separate baselines are fine for now.** Re-baseline against dev-0 for the Sprint 1 acceptance
run and commit it as a dev-0-specific baseline.

**Ongoing, dev-0 should hold a canonical D7 dev source that the whole team starts from.**
Everyone's local environment should be reproducible from the same dump, so a count difference
means a real difference rather than an unshared assumption.

## What still needs deciding

**Does the canonical dump change as we approach staging and production?** The trade-off:

- **Frozen** — baselines stay valid, every environment is comparable, and a count regression
  is unambiguous. But it drifts from production reality, and the further it drifts the less a
  green acceptance run says about the real cutover.
- **Refreshed** — keeps fidelity with production, but invalidates every committed count on
  each re-cut, and each refresh is an opportunity for exactly the confusion above.

Suggested middle path, not yet agreed: **freeze per phase**, re-cut once at each phase
boundary, and bake a dated dump identifier into `EXPECT_LIST` so a baseline is never ambiguous
about which source it describes.

## Practical follow-ups

1. **Find or create the canonical dump on dev-0** and record where it lives and how it was
   produced. The D7 source databases are already on the shared RDS as `mandala_d7_images` /
   `mandala_d7_shared` (loaded 2026-07-17, row-count verified).
2. **Give the dump an identifier** — a date, ideally the production dump date rather than the
   load date — and reference it in `EXPECT_LIST` and the runbook.
3. **Document the refresh path.** `scripts/refresh-d7-staging-source.sh` codifies the
   dump/load, but is **UNTESTED** — the one verified run used the earlier manual commands.
4. Decide whether local DDEV should be re-seeded from the canonical dump, or whether local
   simply keeps its own baseline. (Currently the latter, by default rather than by choice.)
