# AV: `field_pbcore_language` stores raw language names, not ISO 639 codes — may affect Solr tokenization

**Area:** migration / AV / content model / search quality
**Raised during:** Session 2026-09-09 (building AV4), recommendation never acted on
**Jira:** (add when available)
**Priority:** Medium — a real, currently-live gap is plausible, not just a
someday task; needs confirmation, not more analysis

## What happened

While building AV4, converting `field_pbcore_language` (the PBCore
title/description sub-field recording what language the **text** is in —
see `docs/planning/av-node-migration-notes.md` §8 and the sibling notes
below for the full und/en storage-layer context this is separate from) to
ISO 639 codes was recommended **before AV8** (kmassets/Solr sync), so
Tibetan/Chinese AV content would route into Solr's existing `*_bo`/`*_tibt`
schema fields the same way other Tibetan-script content already does
(confirmed elsewhere in this project — `*_tibt` is keyword, `*_bo` is ICU;
see the Tibetan tokenization note).

**AV8 shipped without it.** Checked live on 2026-09-18: D11's
`field_pbcore_language` stores raw D7 strings verbatim — "English", "Tibetan",
"Chinese", "Dzongkha", "Russian", "Estonian", "German", "Arabic", "Japanese",
"French" — not ISO codes. `mandala_kmassets_sync`'s source has no reference
to `field_pbcore_language` at all (confirmed via a full grep of
`drupal/web/modules/custom/mandala_kmassets_sync/src/`).

Real corpus volume, from the original 2026-09-08 measurement: English 8,315 ·
Dzongkha 2,121 · Tibetan 2,108 · Chinese 1,541 · Russian 23. Tibetan and
Chinese together are ~3,600 items — not a rounding error.

## Why this might be live, not hypothetical

If AV's kmassets doc contributor never routes text through a
language-specific Solr field based on `field_pbcore_language`, Tibetan/Chinese
AV titles and descriptions may be getting the **default** analyzer chain
instead of the ICU/keyword handling built for Tibetan-script content
elsewhere in the index — a real search-quality regression for exactly the
content this project's own Tibetan-tokenization work was built to serve.
Unconfirmed either way: it's equally possible AV's searchable text fields
were never meant to be per-language-routed the way KMaps *terms* are (a
different mechanism, already handled), making this recommendation moot for
AV specifically. That's the open question.

## Recommendation

1. Confirm with whoever owns `mandala_kmassets_sync`'s Solr schema (and
   Than, for what D7's own AV search actually did with this field) whether
   AV title/description text is expected to route through
   `field_pbcore_language` at all.
2. If yes: convert the field to ISO 639 codes (or add a lookup table) and
   wire it into the kmassets doc contributor before the next reindex.
3. If no: close this out explicitly — don't let it keep drifting as an
   open recommendation nobody revisits.

## Related

`docs/planning/av-node-migration-notes.md` §8 (where this was first
flagged, alongside the 42 ordering ties and `field_tags` questions — see
[av4-paragraph-ordering-ties.md](av4-paragraph-ordering-ties.md) and
[av2-field-tags-old-label-inconsistency.md](av2-field-tags-old-label-inconsistency.md));
the project's Tibetan tokenization decisions (`*_bo`/`*_tibt` schema).
