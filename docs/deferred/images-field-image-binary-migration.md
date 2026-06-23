# Original `field_image` file binaries are not migrated (display is IIIF)

**Area:** migration / Images / files
**Raised during:** Sprint 1 (1a.7 — pattern-setting image migration)
**Jira:** (add when available)
**Priority:** Low

## Context

The D11 `shanti_image` display path renders from the existing IIIF server via
`field_iiif_id` (the `i3fid`), proven in [1a.5](../sprints/sprint-01-images-implementation.md).
The original uploaded file (`field_image`) is therefore not on the critical path
for display.

Production-data check (2026-06-11 dump): of 111,340 images, **only 545 have a
`field_image` file** (`field_data_field_image.field_image_fid > 0`); the other
99.5% have no local file at all and rely entirely on IIIF. The ~36 imageless
metadata records (no `i3fid` and no `field_image`) are covered separately by the
`field_iiif_id`-optional decision.

## Decision (1a.7)

The 1a.7 migration **does not migrate the `field_image` file binaries.** It carries
`field_iiif_id` / `field_iiif_mms_id` / dimensions forward from the `shanti_images`
sidecar, which is what drives display. `field_image` is left empty on migrated
nodes.

This is consistent with [ADR 004](../adr/004-solr-source-of-truth.md) (IIIF server
stays as-is) and the audit's IIIF-as-wiring stance.

## Follow-up (post-MVP, if ever needed)

If those 545 local originals are deemed worth preserving in D11, add a `d7_file` +
`field_image` migration step (standard Migrate file handling) sourced via
`field_data_field_image`. Low priority — they are a 0.5% tail and not part of the
user-facing display path.
