# Mandala Home carousel demo left orphaned thumbnail files on dev-0

**Area:** Mandala Home carousel / files / housekeeping
**Raised during:** Session 2026-10-05 (side finding from the file comparison)
**Jira:** (add when available)
**Priority:** Low. **DEFERRED by the group, 2026-10-05, until closer to production deployment time.**
No action is needed before then; revisit it as part of pre-production cleanup.

## What was found

30 `public://kaltura-thumb-*` / `iiif-thumb-*` files exist on dev-0 and nowhere else. All 30
`file_managed` rows were created 2026-09-22 (the carousel build, PR #228) in three batches
about 17 minutes apart: 11 + 11 rows with no usage, then 8 referenced by carousel
paragraphs. The `_0` / `_1` suffixes show repeated runs creating fresh copies of the same
sources. The group's view is that these are demo output and test churn, not content to
preserve; the 8 in use are regenerable from Kaltura / IIIF.

## Possible tidy-up (not started)

Remove the 22 unreferenced rows and files (check their `status` first: if they are marked
permanent, cron will not collect them), and check whether the carousel builder reuses an
existing file by name instead of creating a new one each run. The second point is a guess
from the filenames; the builder code was not read.
