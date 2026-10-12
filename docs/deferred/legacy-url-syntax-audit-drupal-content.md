# Audit migrated Drupal content for embedded legacy URL syntax, fix in place

**Area:** migration / content model / URL structure / ADR 016
**Raised during:** Session 2026-10-12 (Than, Yuji) — resolves [ADR 016](../adr/016-public-url-structure-single-host.md) open item 3's reverse-lookup question
**Issue:** none
**Priority:** Medium — not urgent while migration is still in progress (the existing client-side shim keeps old links working in the meantime), but should land once migration is complete, before anyone considers retiring that shim

## Context

`MandalaPathDecoder` in `mandala-om`'s `MandalaMarkup.js` does a reverse Solr lookup
(`q: url_html:"{path}"`) for any internal link it can't resolve by a direct
`{content-type}/{nid}` pattern match. This exists specifically to auto-correct
**old D7 URLs embedded directly in Drupal body/description field HTML** —
i.e. stale links authors wrote into node content years ago, not links the
application generates — so they'd still resolve to the right asset client-side
during the migration period.

## Decision (2026-10-12, Than Grove, Yuji Shinozaki)

**This is not a mechanism to carry forward.** Rather than keep correcting old
URL syntax at render time via `url_html` matching, the fix belongs in the
Drupal records themselves: once migration is complete, audit body/description
fields for embedded legacy URL patterns (old D7 hosts, bare `/node/{d7nid}`
links, pre-ADR-016 slug aliases) and rewrite them in place to the canonical
`/{content-type}/{nid}` form ([ADR 016](../adr/016-public-url-structure-single-host.md) item 2).

This means `url_html`'s indexed value does not need to be designed or extended
to support matching arbitrary legacy link forms going forward — that was
always a stopgap for content that hadn't been cleaned up yet, not a permanent
contract.

## What this unblocks

Once this audit/fix lands, `MandalaPathDecoder`'s reverse-lookup path becomes
unnecessary for migrated content (whether to actually remove it from
`mandala-om` is a separate decision, not made here — it may still be useful
for hand-authored external citations).

## Trigger

**Once migration is fully done** (all five collections migrated). Not
actionable before then — content keeps changing under an in-progress
migration, so an audit run now would need repeating.

## Cross-references

- [ADR 016](../adr/016-public-url-structure-single-host.md) — item 3, which this resolves
- `Standalone/mandala-om`'s `kmaps-app/src/views/common/MandalaMarkup.js` — the
  `MandalaPathDecoder` component this note is about
