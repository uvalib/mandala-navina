# AV4: 84 hosts have genuine content differences between D7's `und`/`en` layers — never reviewed

**Area:** migration / AV / content model / data fidelity
**Raised during:** Session 2026-09-09 (building AV4), never revisited since
**Jira:** (add when available)
**Priority:** Low — out of scope for D11 implementation (the migration
already picks `en` as authoritative, per design, and that choice is sound);
this is a data-quality question about the D7 source, not a D11 defect

## What happened

While confirming AV4's `und`/`en` storage-layer handling (see the project's
language-layer background: `und` is an artifact of an earlier conversion to
language-specific storage, `en` is the authoritative later layer), the
investigation specifically checked whether `und` ever *duplicates* `en`
**content** for the same item, as opposed to just linking it at a different
delta. It confirmed the common case is clean: every redundancy found was the
same field_collection item linked twice, not conflicting content.

**But 84 hosts were found with genuine content differences between the two
layers** — cases where `und` and `en` don't just disagree on position, they
disagree on the actual field values. These were flagged for AV staff review
at the time and never followed up on; no list of which 84 hosts, or what the
differences actually are, was preserved anywhere durable (only the count,
in a session-log narrative).

## Why this matters

The migration's `en`-preferred rule means these 84 hosts' D11 content
reflects whichever value happened to be in the `en` layer — which is the
right *default* per the established authority ordering, but for these
specific 84 hosts nobody has confirmed that `en`'s value is actually the
*correct* one rather than, say, a stale or incomplete edit. This is squarely
a D7 content-quality question (was `en` written correctly for these 84
items?), not something D11's migration logic can resolve on its own.

## Recommendation

Re-derive the list of 84 affected hosts (the original query isn't preserved
in the repo, only the resulting count) and hand it to AV staff / Than for a
content review: for each, confirm whether the migrated (`en`) value is the
one that should have won, or whether the D7 source itself has a data-entry
problem worth correcting before or after cutover.

## Related

`docs/planning/av-node-migration-notes.md` §8;
[av4-paragraph-ordering-ties.md](av4-paragraph-ordering-ties.md) (the
sibling ordering-only question from the same investigation);
[av4-field-pbcore-language-iso639-conversion.md](av4-field-pbcore-language-iso639-conversion.md),
[av2-field-tags-old-label-inconsistency.md](av2-field-tags-old-label-inconsistency.md).
