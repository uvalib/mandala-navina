# Sources: build the record display from the D7 pages and display settings, across several subtypes

**Category:** migration-sources
**Raised during:** Spike 5 (session 2026-10-30)
**Issue:** none
**Priority:** High — start trigger: whoever builds the Sources record page (the Sources migration/app)
**Owner:** unassigned (Than and Xiaoming expected; either's Claude should act on this)

## Instruction to Claude (read this when the Sources record page or app is being built)

Than's requirement: a migrated Sources record must show **all the fields the old Sources record
showed, in roughly the same way** (Spike 5, finding 8). Do **not** build the display from
bibcite's defaults, from one example record, or from memory. When this work starts, before
designing the display:

1. **Look at several D7 record pages of different subtypes** (journal article, book, book
   chapter, thesis, web page, and the four custom types Review, Dictionary, Obituary, Block Print
   at least), either by viewing `https://sources.mandala.library.virginia.edu/node/{nid}`
   directly or by **asking the person building it to paste the rendered page** (the legacy site
   may be behind a WAF that blocks curl; use a browser, or ask).
2. **Read the old site's display settings** (the `field_bundle_settings` / display configuration
   for the `biblio` node type in the D7 database or Drupal 7 admin, "Manage display") to see
   exactly which fields appear, in what order, with which labels and groups. The 2026-06-11
   Sources dump is at `~/Sandbox/Mandala/data/mandala-prod-sources-db_2026-06-11.sql.gz`.
3. Compare field by field and record gaps. Subtypes show different field sets, so one subtype
   is not enough.

## Reference example (D7 node 21045, journal article, pasted by Than 2026-10-30)

Title; journal (Complementary Health Practice Review); author with role; Format: Journal
Article; Publication Year; Pages; **Sources ID** (the legacy nid); **Visibility** (Private);
Zotero Collections; collapsible **Abstract**; **URL**; then **Zotero Collections** and
**Subjects** (KMaps) as linked lists. Details and the gap list against bibcite's default page:
[Spike 5, finding 8](../spikes/spike-05-bibcite-sources.md).

## Why this is deferred

It belongs to the Sources build, not to Spike 5, which only needs to show the model is viable.
Than does not want it done now; this note exists so the instruction survives into the build
session regardless of who drives it.
