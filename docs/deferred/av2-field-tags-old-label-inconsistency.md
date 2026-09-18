# AV2: `field_tags` is labeled "Tags Old" and hidden on `video`, but live "Tags" on `audio`

**Area:** migration / AV / content model
**Raised during:** Session 2026-09-08/09 (building AV2/AV4), never revisited
**Jira:** (add when available)
**Priority:** Low — migrates as-is, nothing broken; a naming/intent question
for AV staff, not an implementation gap

## What happened

D7's `field_tags` field is configured inconsistently across AV's two
bundles: on `video` it's labeled "Tags Old" and hidden from display; on
`audio` it's live, labeled plainly "Tags." Per AV2's decision (two D11
bundles, one shared field definition, migrated faithfully rather than
"improved" per ADR 008/010), this inconsistency migrates as-is — D11's
`audio`/`video` both carry the field with whatever data D7 has, following
D7's own display config per bundle.

Nobody has asked what this actually means: is "Tags Old" on `video`
genuinely retired/superseded content (in which case hiding it in D11 too
might be the right call), or is it a labeling accident that left real,
useful tag data hidden on video nodes while the equivalent field stayed
visible on audio?

## Recommendation

Ask AV staff / Than what "Tags Old" actually refers to on the `video`
bundle — retired taxonomy, a renamed/replaced field, or just an
inconsistently-maintained label — and whether D11's `video` display should
follow D7's hidden treatment (as it does now) or align with `audio`'s
visible one.

## Related

`docs/planning/av-node-migration-notes.md` §8;
[av4-paragraph-ordering-ties.md](av4-paragraph-ordering-ties.md),
[av4-84-und-en-content-differences.md](av4-84-und-en-content-differences.md),
[av4-field-pbcore-language-iso639-conversion.md](av4-field-pbcore-language-iso639-conversion.md),
raised in the same sessions.
