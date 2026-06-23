# `field_summary` was undersized (255); widened to 1024

**Area:** migration / Images / paragraphs / content model
**Raised during:** Sprint 1 (1a.7 — pattern-setting image migration)
**Jira:** (add when available)
**Priority:** Low

## What we found

The `image_descriptions` paragraph's `field_summary` was built as `string`
(`max_length: 255`). In the production dump, D7 `field_summary` values reach
**750 characters** — 3 of 55,041 referenced descriptions exceed 255. During the
1a.7 full run those 3 rows failed with `SQLSTATE[22001] … Data too long for
column 'field_summary_value'` and were skipped (status IGNORED), so 55,038
description paragraphs landed instead of 55,041.

## Fix (committed in 1a.7)

`field.storage.paragraph.field_summary.yml` `max_length` raised **255 → 1024**.

Drupal refuses a varchar-length change on a field that already has data, so this
**cannot hot-apply** to a populated dev DB (`drush cim` errors there). It applies
cleanly on a **fresh install** — the field is created at 1024 with no data — which
is exactly how the staging/acceptance run (1a.9) and `scripts/rebuild.sh` build the
site. No teammate doing a fresh rebuild is affected.

## Follow-up

- On the staging run, confirm all **55,041** description paragraphs import (the 3
  formerly-truncated summaries included) with 0 ignored.
- If a populated environment ever needs the widening in place, do it via a
  `hook_update_N` (ALTER the `paragraph__field_summary` + revision columns and
  update the stored field schema), not config import.
