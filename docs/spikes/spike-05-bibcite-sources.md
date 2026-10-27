# Spike 5: bibcite for the Sources Site
**Status:** ◐ **Partial — desk research done 2026-08-24; nothing installed or run yet**
**Lead:** Than Grove (reassigned from Xiaoming, 2026-07-22, by mutual agreement)
**Mode:** Individual
**Date:** 2026-08-24 (first working session)
**Commit:** branch `spike/5-bibcite-sources`

**Updated 2026-10-27 (bibcite 3.1.2 installed locally in DDEV only; nothing committed to composer or config).**
Criteria 1, 2 met; 3 met for CSE (both prepared styles import and render); 4 reduced and 5 dropped
pending one staff confirmation (finding 3); 6 sampled on one node (finding 6), not yet broad.
Counts come from the production Sources dump `mandala-prod-sources-db_2026-06-11.sql.gz`
(**2026-06-11, ~4.5 months stale**).

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

Three of these are not real gaps: `Book (single author)`, `Book (multiple authors)` and
`Multi-Chapter Volume` (300 rows) are **authorship distinctions D7 encoded as separate types**,
which bibcite expresses through contributor roles instead — they collapse into `book`.

### 2a. The four remaining types are all wanted, and all are config-only to add — **the fail criterion does not fire**

**Than, 2026-08-24: all four are to be kept as real types**, with these meanings:

| Type | Rows | What it is |
|---|---|---|
| Review | 599 | **book reviews** |
| Dictionary | 375 | dictionaries |
| Block Print | 140 | a **wood-block print (xylograph) of a Tibetan text** |
| Obituary | 63 | obituaries |

Two findings make this cheap rather than risky:

**A bibcite reference type is pure configuration.** Each is a single
`bibcite_entity.bibcite_reference_type.*.yml` selecting from a shared field pool, with
per-type `required` flags and `label` overrides. No code, no new fields, no schema change.
The shipped `manuscript` type is a working precedent for exactly this kind of domain
relabelling — it renames the shared fields to `Library/Archive`, `Folio Number`,
`Volume/Storage Container`, `Abbreviation`. **`Block Print` should be modelled on it.**

**Citation rendering is not lost either.** bibcite maps each reference type to a CSL type
through a configurable mapping (`bibcite_entity`'s `CslMappingForm`), and the selectable
CSL vocabulary is the full CSL type list — which contains precise targets for all four:

| Custom type | CSL target | Quality |
|---|---|---|
| Review | `review-book` | exact — CSL has a dedicated book-review type |
| Dictionary | `entry-dictionary` | exact |
| Obituary | `article-newspaper` — **taken, see below; needs an unused target** | not usable as written |
| Block Print | `manuscript` — **taken, see below; needs an unused target** | not usable as written |

So the fail-criteria row *"a critical reference type is missing → assess whether a custom
bundle can fill the gap"* resolves affirmatively: **a custom bundle can fill all four gaps,
config-only, with correct CSL rendering.** `Block Print` is the only one where the CSL target
is an approximation rather than a match — acceptable, since CSL has no xylograph concept and
`manuscript` carries the right physical-artifact semantics.

**Verified on a real install (2026-10-27, bibcite 3.1.2 in DDEV).** A custom reference type
is accepted: `CslMappingForm` takes its CSL side from a fixed list (which does contain
`review-book` and `entry-dictionary`) and builds the reference-type dropdown from entity
storage, so config-only types appear in it. Creating a `review` type and mapping
`review-book` to it rendered it as `review-book`.

**But the mapping is one-to-one, and two of the targets above are already taken.** The
mapping config (`bibcite_entity.mapping.csl`, `types:`) is keyed by CSL type with one
reference type per key, and the normalizer inverts it with `array_flip`. The shipped mapping
already sends `article-newspaper` to `newspaper_article` and `manuscript` to `manuscript`.
Mapping Obituary onto `article-newspaper` was tested: `obituary` rendered correctly, but
`newspaper_article` lost its mapping and silently fell back to the default type. So
**Obituary and Block Print need unused CSL targets** (candidates not used by any shipped
type: `article`, `entry`, `pamphlet`, `graphic`, `post`; choice is open and belongs with the
criterion 6 output comparison). Review and Dictionary are unaffected, since `review-book` and
`entry-dictionary` have no existing mapping.

Ten bibcite types have no D7 usage at all (`bill`, `chart`, `government_report`, `hearing`,
`legal_ruling`, `miscellaneous_section`, `patent`, `statute`, `unpublished`, `web_service`) —
harmless, but worth not exposing in the editorial UI.

### 3. The Zotero workflow is dormant in the dump — **criterion 4 reduced, criterion 5 dropped (pending one confirmation)**

Both `biblio_zotero` and `sources_zotero_importer` are **enabled** in production, but the
`zotero_feed` content type the criterion is built around has exactly **one node**, and
`biblio_zotero_collections` has **one row**.

The *imported data* is substantial — ~12,817 `field_zotero_tags` rows, ~6,212
`field_zotero_collections`, ~1,015 `field_zotero_attachment` — so Zotero seeded a share of the
corpus historically. What is small is the **ongoing feed configuration**.

**Dormancy check (2026-10-23, against the 2026-06-11 dump; timestamps and structure only, no
credential fields read):**

- **The feed node** (nid 20812, "Zotero Feed Importer", published) was created 2014-04-28 and
  last changed 2015-12-01. It has **no rows in any `field_data_*` table**, so its connection
  settings are not stored on the node and are not visible in the dump. The only `variable` rows
  mentioning Zotero are per-content-type settings (pathauto, node options, kmaps field
  settings) — no feed schedule or credential variable. The `queue` table has no Zotero entries.
- **The imported records:** 1,920 nodes carry Zotero tag/URL fields, **all created 2014-05-12 to
  2014-09-05** — one import burst, nothing created through Zotero since. Their `changed` dates
  cluster in 2015 (890), 2016 (54), 2020 (975) and 2023 (1); the 2020 batch looks like a bulk
  edit rather than a sync, but that was not verified.
- **The wider corpus** is not Zotero-sourced: most of the 25.6k `biblio` nodes were created
  2019-2022 (2019: 6,608; 2020: 10,440; 2022: 5,222), the latest on 2025-12-19. Sources is still
  growing, but not through this feed.
- **`watchdog`** holds only 20,193 `php`, 6 `cron` and 1 `actions` rows — no Zotero-typed entries,
  so no log evidence of a recent sync.

**Consequence.** Criterion 4 ("a viable Zotero feed management approach exists in bibcite")
reduces to **preserving the imported Zotero fields** (tags, collections, attachments, URLs) on
the migrated nodes; no live feed management is needed. Criterion 5 (Zotero API credentials)
almost certainly falls away, since nothing has pulled from Zotero since 2014 as far as the dump
shows.

**Still open before criterion 5 is formally dropped:**
- The dump is ~4.5 months old. One question to Sources staff ("does anyone still sync from
  Zotero?") settles it.
- The feed's actual settings live somewhere not visible in the dump (module config or the feed
  node's revisions). That is the one place left to look, and it likely holds credentials, so it
  was deliberately not read.

### 4. Sources' configured style is `cse`, which bibcite does **not** ship — **gap, solvable**

Production D7 has `biblio_style = "cse"` (Council of Science Editors), with
`biblio_user_style = "system"` — so every user gets the site style; there is no per-user
override to migrate.

bibcite ships five CSL styles: **APA, Chicago author-date, AMA, MLA, MLA 8th**. **CSE is not
among them.**

This is a gap but not a blocker: CSE exists in the Zotero/CSL style repository, and bibcite
supports uploading arbitrary CSL style files (`CslStyleFileForm`). The work is to source the
correct CSE CSL file and import it — plus confirm it renders acceptably, which is criterion 6.

Two things widen this question:

- **The style is per-request on one route.** [Spike 6](spike-06-api-compatibility.md) found
  `sources-api/ajax/{nid}/{type}` renders citations with the biblio style taken from `arg(4)`,
  so styles beyond the site default are reachable by URL. **Which styles are actually requested
  in practice is unknown** and would need either log analysis or a survey of the React client's
  call sites. If clients request styles bibcite lacks, each needs importing too.
- **`cse` is a D7 biblio style name, not a CSL style id.** D7 biblio had its own PHP style
  plugins; bibcite is CSL-only. So this is not a rename — it is a substitution of one rendering
  engine's "CSE" for another's, and the output will not be byte-identical. That makes criterion
  6 (output comparability) the place where this actually gets settled.

### 5. Field sets: nothing is lost in storage; the default edit forms hide populated D7 fields — **PASS with named config work** (2026-10-27)

Method: loaded the 2026-06-11 dump into a scratch database, counted per D7 type which `biblio`
columns are non-empty, and compared against the matching bibcite type on the local install.
(A first pass compared against the type config's `fields:` list and found "no gaps" — vacuous,
because every bibcite type lists the same 49 fields. Per-type visibility actually lives in each
bundle's **form display** (`content` vs `hidden`); the comparison below uses that.)

- **Storage:** every bibcite type carries the same 49-field pool, and every D7 data column has a
  `bibcite_*` counterpart (only the derived `sort_title`, `full_text`, `coins`, `md5`, `formats`
  do not). Nothing is unmappable.
- **Editing:** bibcite's default form displays hide fields D7 populates. By volume: `citekey`
  (every row; bibcite normally generates it from `citekey_pattern`, so migrate or regenerate is a
  decision), `lang` (5,305 on journal articles), `remote_db_provider` (5,867), `notes` (2,729),
  `date` (3,627 on books), `access_date`, and `type_of_work`, `publisher`, `place_published` on
  several types. The data would store, but editors could not see or change it. Fixing this is
  form-display config per type, no code.
- **Custom types** must enable what their D7 rows populate. Review: `other_number`,
  `secondary_title`, `type_of_work`, `date`, `isbn`, `issue`, `volume`, `pages`, `publisher`.
  Dictionary: `custom3` (166 rows) plus the usual. Block Print: `custom1`–`custom4`,
  `custom6`, `number_of_volumes` (105), `short_title`, `section`. Obituary: the Review set.
- **Not checked:** how field *values* map into CSL (`bibcite_entity.mapping.csl` `fields:`),
  which is part of criterion 6.

### 6. CSE output renders and is comparable to D7, on a sample of one — **criterion 3 met for CSE; criterion 6 started** (2026-10-27)

Imported both prepared CSE styles into the local install and rendered nid 25581 (a journal
article) from its D7 row via `bibcite.citation_styler`:

| Source | Output |
|---|---|
| D7 `cse` baseline | `[Anonymous]. 2017. A Theoretically and Ethically Grounded Approach to Mindfulness Practices in the Primary Grades.. Childhood Education. :100-108.` |
| bibcite, CSE 9th ed. | `Moreno AJ. 2017. A Theoretically and Ethically Grounded Approach to Mindfulness Practices in the Primary Grades. Childhood Education. 100–108` |
| bibcite, CSE 8th ed. | `Moreno AJ. 2017. A Theoretically and Ethically Grounded Approach to Mindfulness Practices in the Primary Grades. Childhood Education.:100–108.` |

- **bibcite is the better output, not just a different one.** The D7 baseline prints
  `[Anonymous]` although the node has an author (`Moreno, A. J.`, primary, in the dump), doubles
  the period after the title and leads the pages with a stray colon. A migration that "matches D7"
  byte for byte would reproduce those defects.
- **The 8th-edition style is the closest match to D7's shape** (`Journal.:pages.`); the 9th
  drops the trailing period. Which edition Sources wants is a stakeholder call.
- **Differences to expect everywhere:** en dash in page ranges where D7 has a hyphen, and author
  initials without periods (`AJ`, correct CSE).
- **Limits:** one node, one type, one style family. Not yet checked: books, chapters, the four
  custom types, multi-author lists, and the other seven styles reachable through
  `sources-api/ajax/{nid}/cite/{style}` (apa, chicago, mla, ama, harvard, vancouver, ieee).

## Handoff for the Sources migration (Xiaoming)

The spike installed bibcite locally in DDEV only; **no composer or config change is committed**,
because a spike should not change the production image. When the Sources migration starts:

1. `composer require drupal/bibcite:^3.1` (3.1.2 tested; pulls in `drupal/token`,
   `seboettg/citeproc-php` and the CSL styles/locales packages), then enable `bibcite`,
   `bibcite_entity`, `bibcite_import`, `bibcite_export`. Export the resulting config from a live
   Drupal; never hand-edit it.
2. Create the four custom reference types (Review, Dictionary, Obituary, Block Print) as config.
   Model Block Print on the shipped `manuscript` type.
3. Map each to its **own** CSL type: the CSL mapping is one-to-one (see finding 2a), so Review
   gets `review-book` and Dictionary `entry-dictionary`; Obituary and Block Print need unused
   targets, to be chosen in the criterion 6 output comparison.
4. Import the prepared CSE styles from `docs/spikes/assets/spike-05-bibcite/`.

## What this does NOT establish

- **The seven non-CSE styles** reachable through the citation route (finding 6) — not rendered.
- **Zotero API credentials** (criterion 5) — not tested, and deliberately not extracted here.
- **Broad citation output comparison** (criterion 6) — one node sampled (finding 6); books,
  chapters, multi-author lists and the four custom types are not.
- **How D7 values map into CSL fields** (`bibcite_entity.mapping.csl` `fields:`) beyond the
  fields exercised in finding 6, and which CSL targets the custom types should use (finding 2a).
- **Whether the migrated Zotero fields survive** — criterion 4 is reduced to preserving them but
  that has not been exercised.
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
