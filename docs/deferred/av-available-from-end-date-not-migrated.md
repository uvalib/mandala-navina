# `field_available_from` loses its end date on 9 migrated AV nodes

**Area:** migration / AV / data fidelity
**Raised during:** Sprint 3 AV4 (2026-09-09); moved here from a `config/sync` comment 2026-10-06
**Jira:** (add when available)
**Priority:** Low — 9 rows; needs a content call from Than, not engineering

## What was found

D7's `field_available_from` on AV nodes is a date *range* (`value` / `value2`). D11's field is a
single `datetime`, so the migration carries `value` only. Of the 595 D7 rows, **9 have a `value2`
that differs from `value`**; those nine end dates are not carried. The other 586 are unaffected.

This was a deliberate scope call at AV4 ("widening the D11 field would be an improvement, not a
migration"; see [av-node-migration-notes.md](../planning/av-node-migration-notes.md)). It was
recorded only there and in a comment inside `migrate_plus.migration.d7_av_audio` /
`d7_av_video`. Comments in `config/sync` are being removed (see
[config-export-not-scoped-strips-comments.md](config-export-not-scoped-strips-comments.md)), and a
planning doc is not a place anyone looks for open items — so the loss is filed here.

## Open question (content owner: Than)

Do those nine end dates mean anything to editors or to the public pages?

- **If not:** record the decision here and close the note. Nothing else to do.
- **If so:** widen the D11 field to a date-range type (a config change made in a live Drupal and
  exported, per the practice in CLAUDE.md) and re-run the AV node migration's update path for those
  nodes.

## Notes

- The nine nids are not listed here. They are recoverable from the D7 AV source with
  `field_available_from_value2 IS NOT NULL AND field_available_from_value2 <> field_available_from_value`
  on `field_data_field_available_from`.
- Does D11 render this field anywhere yet? The migration notes say no UI reads the sibling
  `field_rating` yet; check before treating the end date as user-visible.

## Related

- [AV4 node/group/membership/alias migrations](../planning/av-node-migration-notes.md)
- [AV15 abandoned fields review](av15-avinfo-abandoned-fields-review-with-than.md)
