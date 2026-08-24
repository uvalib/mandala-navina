# Spike 5: bibcite for the Sources Site
**Status:** ◐ **Partial — desk research done 2026-08-24; nothing installed or run yet**
**Lead:** Than Grove (reassigned from Xiaoming, 2026-07-22, by mutual agreement)
**Mode:** Individual
**Date:** 2026-08-24 (first working session)
**Commit:** branch `spike/5-bibcite-sources`

**Two of six pass criteria met on evidence; one materially reframed; three not started.**
All findings below are from the D11 package metadata and the production Sources dump
`mandala-prod-sources-db_2026-06-11.sql.gz` (**2026-06-11, ~2.5 months stale**). Nothing has
been installed on D11 and no citation output has been compared.

## Theory
The `bibcite` module on Drupal 11 supports all reference types currently in use on
the Sources site, handles the `zotero_feed` workflow, and provides a viable migration
path from D7 `biblio`.

## Demo
*Nothing runnable yet — no bibcite install exists. The findings below are reproducible from
the dump plus `composer show -a drupal/bibcite`.*

## Findings (2026-08-24)

### 1. bibcite has a stable D11 release — **PASS**

`drupal/bibcite` **3.1.2** is the current stable, and its constraint is
`drupal/core ^10.1 || ^11 || ^12` — D11 is explicitly supported, not merely tolerated. The
3.x line has been stable since 3.0.0 (the 2.x line never left beta). Additional runtime
requirements: `adci/full-name-parser ^0.2.4`, `researchgate/libris ~2.0`.

### 2. Reference-type coverage is 94.2%, and the gap is exactly Mandala's custom types — **PASS with a named gap**

**bibcite is biblio's successor and ships biblio's own type list, near-verbatim.** 36 of
bibcite's 37 shipped `reference_type` configs correspond by name to D7 biblio's built-ins, so
the mapping is 1:1 rather than a CSL re-modelling exercise. This was the main risk in the
theory and it does not materialise.

Against the 25,629 `biblio` rows in production:

| | Types | Rows | Share |
|---|---|---|---|
| 1:1 name match in bibcite | 26 | 24,149 | **94.2%** |
| No bibcite equivalent | 10 | 1,480 | 5.8% |

**Every gap is a Mandala-defined custom type** (`biblio_types.tid >= 1000`), plus three rows
carrying junk type ids (`0`, `1`, `200` — one row each, almost certainly data errors):

| Custom type | Rows | Plausible bibcite target |
|---|---|---|
| Review | 599 | needs a bundle, or `miscellaneous` |
| Dictionary | 375 | needs a bundle (CSL has `entry-dictionary`; bibcite does not ship it) |
| Book (single author) | 247 | collapses into `book` |
| **Block Print** | 140 | **no equivalent anywhere** — Tibetan xylograph; domain-specific |
| Obituary | 63 | needs a bundle, or `newspaper_article` |
| Book (multiple authors) | 45 | collapses into `book` |
| Multi-Chapter Volume | 8 | collapses into `book` |

Two of these are cheap: `Book (single author)` + `Book (multiple authors)` +
`Multi-Chapter Volume` (300 rows) are **authorship distinctions D7 encoded as separate types**,
which bibcite expresses through contributor roles instead — they should collapse into `book`
rather than become bundles. That leaves four genuine candidates for custom bundles, of which
**`Block Print` is the only one with no analogue in any citation vocabulary** and is exactly
the kind of thing the fail-criteria row "a critical reference type is missing" anticipated.

Ten bibcite types have no D7 usage at all (`bill`, `chart`, `government_report`, `hearing`,
`legal_ruling`, `miscellaneous_section`, `patent`, `statute`, `unpublished`, `web_service`) —
harmless, but worth not exposing in the editorial UI.

### 3. The Zotero workflow is live but far smaller than the pass criterion implies — **reframed**

Both `biblio_zotero` and `sources_zotero_importer` are **enabled** in production. But the
`zotero_feed` content type the criterion is built around has exactly **one node**, and
`biblio_zotero_collections` has **one row**.

The *imported data* is substantial — ~12,817 `field_zotero_tags` rows, ~6,212
`field_zotero_collections`, ~1,015 `field_zotero_attachment` — so Zotero clearly seeded a large
share of the corpus historically. What is small is the **ongoing feed configuration**.

That materially changes the criterion "a viable Zotero feed management approach exists in
bibcite": migrating *one* feed definition is a very different problem from migrating a feed
subsystem. **Whether that single feed is still actively syncing has not been checked** and is
the next thing to establish — if it is dormant, this criterion may reduce to preserving the
imported field data, with no live-feed requirement at all.

## What this does NOT establish

- **Citation styles** (criterion 3) — not investigated. Note [Spike 6](spike-06-api-compatibility.md)
  found `sources-api/ajax/{nid}/{type}` renders citations with the biblio style taken from
  `arg(4)`, so the set of styles actually reachable is broader than the site's default and needs
  enumerating from that route as well as from config.
- **Zotero API credentials** (criterion 5) — not tested, and deliberately not extracted here.
- **Citation output comparison** (criterion 6) — needs bibcite installed and a D7 baseline;
  nothing has been rendered.
- **Whether the type mapping survives contact with the data.** Name-level 1:1 correspondence
  is not field-level compatibility. bibcite's per-type field sets have not been compared against
  D7's `biblio_field_type_data`, and the [endpoint-field-inventories-are-lower-bounds](../deferred/endpoint-field-inventories-are-lower-bounds.md)
  caution applies with equal force here.
- **Staleness.** All counts come from a 2026-06-11 dump. Directionally reliable, not current.

## Deferred notes
*To be completed when spike is run.*

---

## Reference: Pass Criteria
- `bibcite` has a stable or beta D11 release
- All reference types in current use have `bibcite` equivalents
- Required citation styles are available
- A viable Zotero feed management approach exists in bibcite
- Zotero API import works with current credentials
- Citation output is comparable to current D7 output

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| `bibcite` has no D11 release | Evaluate alternatives (custom entity type, Scholarly Communications module) |
| A critical reference type is missing | Assess whether a custom bundle can fill the gap |
| No equivalent for `zotero_feed` workflow | Design a custom config entity or Feeds-based approach |
| Zotero API credentials are expired | Obtain current credentials from Sources site admin |
| Citation output differs significantly | Identify correct CSL style; document differences for stakeholder review |

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-5)*
