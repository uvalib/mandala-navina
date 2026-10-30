# Spike 5: bibcite for the Sources Site
**Status:** ◐ **Partial — desk research done 2026-08-24; nothing installed or run yet**
**Lead:** Than Grove (reassigned from Xiaoming, 2026-07-22, by mutual agreement)
**Mode:** Individual
**Date:** 2026-08-24 (first working session)
**Commit:** branch `spike/5-bibcite-sources`

**Updated 2026-10-27 (bibcite 3.1.2 installed locally in DDEV only; nothing committed to composer or config).**
Criteria 1, 2 met; 3 met for CSE (both prepared styles import and render); 4 met as a design with a
working throwaway prototype (finding 7: the API pull is custom, bibcite supplies none); 5 partly
(public-library API access needs no credentials and works; a keyed private library is untested);
6 sampled on one node plus a 40-item Zotero sample (findings 6, 7), not yet broad.
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

### 3. The Zotero feed has been dormant since 2014 in the dump — **but the capability is wanted; see finding 7**

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

**Consequence (revised 2026-10-27).** This section first read the dormant feed as "criterion 4
reduces to preserving imported fields, criterion 5 drops". Than clarified the requirement: **the
capability itself is wanted**, as in D7: a privileged user imports a Zotero bibliography and each
entry becomes a record. Dormancy only says nobody has *used* it since 2014. Criteria 4 and 5 are
live again and are addressed in finding 7.

**Still open:**
- Whether anyone on the Sources side uses Zotero today (ask Sources staff).
- The feed's actual library and settings live in D7's per-feed Feeds config, not in the dump,
  and likely include a key. Deliberately not read.

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
  (every row; bibcite normally generates it from `citekey_pattern`; **decided 2026-10-30 (Than):
  migrate the D7 citekeys**. All 25,627 D7 rows have one and all are distinct in the 2026-06-11
  dump, so none need repair. Still to verify in the build: that bibcite does not overwrite a
  supplied citekey on save), `lang` (5,305 on journal articles), `remote_db_provider` (5,867), `notes` (2,729),
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
  drops the trailing period. **Decided 2026-10-30 (Than): Sources uses the 9th edition** (the
  prepared `cse_name_year` style; `cse_name_year_8th_edition` is then not needed). It differs from
  D7's output by the trailing period, so the migrated citations will not match D7 byte for byte.
- **Differences to expect everywhere:** en dash in page ranges where D7 has a hyphen, and author
  initials without periods (`AJ`, correct CSE).
- **Limits:** one node, one type, one style family. Not yet checked: books, chapters, the four
  custom types, multi-author lists, and the other seven styles reachable through
  `sources-api/ajax/{nid}/cite/{style}` (apa, chicago, mla, ama, harvard, vancouver, ieee).

### 7. Zotero import: admin-only API pull, each entry a `bibcite_reference` — **design proven by a throwaway prototype** (2026-10-27)

**Requirement (Than):** a way for privileged users, **admins only to start**, to import a Zotero
bibliography into Sources, converting each entry into a record. Chosen record model: **bibcite's
own** (`bibcite_reference` entities, one bundle per reference type), not nodes.

**What D7 did.** A Feeds importer attached to a `zotero_feed` node type: an editor created a feed
node holding the library (user or group), numeric id, optional collection key and an API key
(stored per feed in the database), and the feed re-imported on create and every 30 minutes. It
read Zotero's old v2 Atom API, mapped item type to biblio type (`ZoteroBiblioMap.inc`;
unsupported Zotero types went to Miscellaneous), creators to contributors (editor/translator keep
their role, an institution becomes a corporate contributor), tags and collections to two taxonomy
vocabularies, links to an attachment field, and skipped notes and attachments. It matched
existing records on the Zotero item key and updated them; nothing was deleted. Who was allowed to
run it was not recoverable from the code (only generic node-type and Feeds permissions).

**What bibcite gives.** `bibcite_import` is **file upload only** (BibTeX, RIS, EndNote, MARC); no
Zotero API pull, no re-sync, no tags, collections or attachments. So the pull is custom either
way. bibcite contributes the type list, contributors, keywords and CSL rendering.

**Prototype** (`docs/spikes/assets/spike-05-bibcite/zotero-prototype/`; throwaway, local DDEV only,
not a module): a Zotero API v3 client, a pure-PHP item mapper and a Drush writer.
- **Zotero API v3 works for a public library with no credentials**, returns JSON, pages through a
  `Link: rel="next"` header, exposes `Total-Results` and a per-library `version`, and supports a
  `since` parameter, so an import can be incremental where D7 re-fetched everything every run. The
  `itemType` filter accepts only **one** negation (`-attachment`); standalone notes are skipped in
  the mapper.
- **Mapper:** on a public library of 164 top-level items, 163 mapped (1 standalone note skipped).
  Item types seen: book, journalArticle, webpage, newspaperArticle, film, conferencePaper,
  artwork, manuscript, plus two that D7's table also did not support (`blogPost`,
  `encyclopediaArticle`), which fall back to `miscellaneous`. Extending the type table is cheap.
- **Writer:** upsert keyed on the Zotero item key. Run on 40 items: 40 created; an immediate
  re-run 40 unchanged; after editing one record locally, a re-run updated exactly that one and the
  next run showed 40 unchanged. The check compares stored values, so it can fail (it did detect the
  edit). Zotero **tags map naturally onto bibcite keywords**.
- **Rendering:** the imported references render through the CSE style, e.g.
  `Mann T. 2008. The Peloponnesian War and the future of reference, cataloging, and scholarship in research libraries. Journal of Library Metadata. 8(1):53–100.`
  The prototype's test references, contributors, keywords and styles were deleted afterwards.

**Gaps the prototype exposed**
- **Dedup key needs a dedicated field.** The prototype reused `bibcite_remote_db_name`, which D7
  populated on real rows, to avoid a config change. Production needs its own field for the Zotero
  item key (and the library), and should also keep the item `version` for `since` pulls.
  **Decided 2026-10-30 (Than): add a dedicated field for the Zotero item key** (library id and item
  `version` still to be settled in the build; `bibcite_remote_db_name` stays free for D7's own use).
- **Zotero collections have no stock home.** D7 kept them as a vocabulary; bibcite has nothing
  equivalent (tags map to keywords). **Decided 2026-10-30 (Than): keep Zotero Collections in the new
  Sources.** So the build needs a taxonomy vocabulary (D7's `collections` has 280 terms) and a
  term-reference field on every reference bundle, filled by the migration (2,196 D7 nodes carry
  one or more) and by the Zotero import, and shown on the record page and as a facet or listing.
  Other D7 Zotero fields in the 2026-06-11 dump: `field_zotero_tags` on 1,920 nodes (maps to
  bibcite keywords), `field_zotero_attachment` on 809, `field_zotero_files` on 7,
  `field_zotero_attachment_links` on 12; `field_zotero_canonical_url` and `field_zotero_fetch_url`
  are on none. Whether the attachment fields are still wanted is not decided.
- **Observed, not isolated:** rendering emitted `field "original-date" has invalid values
  (month=1, day=1)` for some items. It did not reproduce on a journal article with or without the
  prototype's raw `bibcite_date`; D7 mapped Zotero's free-text date to year only. Check in the
  broader render pass before storing raw dates.

**Production design (for Xiaoming, not built):** an admin-only form behind its own permission
(widen to editors later with one permission); the API key held outside `config/sync` and outside
any node (Key module or environment); run as a batch or queue; idempotent upsert on the item key;
incremental via `since`. **Decided 2026-10-30 (Than): on-demand only**, no D7-style 30-minute
re-sync, so no scheduled job or cron dependency is needed.

**Consequences of using `bibcite_reference` instead of nodes** (stated from reading, **not
verified**; they affect the whole Sources migration, not only Zotero): there is no nid, so old
`sources-api/ajax/{nid}/...` routes resolve via a legacy-nid field (ADR 017); D7 collections and
access were OG on nodes, and Group needs a relationship plugin for `bibcite_reference` (it ships
node support), else access falls back to bibcite permissions; the KMaps field should attach to
reference bundles but is untested; `mandala_node_api`, the kmassets sync hooks and
`mandala_solr_visibility` assume nodes and need reference-aware versions; editing, display and
views come from bibcite's admin pages. **Xiaoming and Yuji should be told of this model choice.**

### 8. Legacy-key redirect and `/sources/{id}` paths work on `bibcite_reference`; the display requirement (2026-10-30)

**Throwaway prototype, local DDEV only** (`docs/spikes/assets/spike-05-bibcite/legacy-redirect-prototype/`):
- `bibcite_reference` is fieldable per bundle, so `field_legacy_site` + `field_legacy_nid` (ADR 017)
  can be added to every reference type.
- `mandala_legacy_redirect` needed one change to serve references: add `bibcite_reference` to the
  entity types its lookup tries (`['node', 'group']` today). With that, a legacy-host
  `/node/{d7nid}` resolved by `(sources, nid)` returned a 301 to `/sources/{id}`. Tested with three
  real D7 nids from the 2026-06-11 dump (21043, 21045, 22011): each landed on the record carrying
  that nid. A made-up nid, the raw D11 id and the wrong site all returned 404, and an anonymous
  request without view permission also returned 404 (no leak that the record exists).
- A path alias `/sources/{id}` on each reference works; production would generate it with pathauto
  or in the migration. The id is the new entity id, never the D7 nid.
- The canonical page is bibcite's bare default display, visible only with the "view
  bibcite_reference" permission (anonymous gets 403 by default).

**Requirement (Than, 2026-10-30): a migrated record must show all the fields the old Sources
record showed, in roughly the same way.** Example, D7 node 21045 (`/node/21045` on the legacy
host) shows, in order: title; the journal ("Complementary Health Practice Review"); author with
role; Format; Publication Year; Pages; **Sources ID** (the legacy nid); **Visibility** (Private);
**Zotero Collections**; a collapsible **Abstract**; **URL**; then **Zotero Collections** and
**Subjects** (KMaps) as linked lists. bibcite's default display shows only its own bibliographic
fields, so a production build needs a dedicated display (view mode or template) that adds:
Format label, Sources ID, Visibility (from Group/collection access, see the open Group-plugin
question), Zotero Collections (no stock home; see finding 7), a collapsible Abstract, URL,
Subjects (KMaps field, untested on a reference bundle). This is the node-vs-entity cost the
choice has to carry: everything node-based that Sources pages got for free is built for the
reference type instead. The field-by-field comparison in the broader render pass (criterion 6)
should use this page as the reference.

### 9. Entity-model proof of concept: access, listings, Solr and display all work, with named code changes (2026-10-30)

**Throwaway, local DDEV only** (`docs/spikes/assets/spike-05-bibcite/entity-model-poc/`). Each part
below was run, and the access test was shown able to fail: with the new hook disabled, anonymous
could view the private reference (it then returned `YES (leak)`); with it on, it was denied.

**Access (single record): works.** Group 3.3.5 takes a custom relation plugin for
`bibcite_reference` (an attribute class modelled on gnode's `GroupNode`, no bundle deriver) and a
relationship type on the `collection` group type. Installing it adds a new group permission,
`view group_bibcite_reference entity`; until a collection role holds it, **Group itself forbids
every grouped reference to everyone** (the first symptom was a public reference denied to
anonymous). With the permission granted to the collection anonymous/outsider/member roles and a
`hook_entity_access()` that resolves through `CollectionVisibility` (it already accepts any
`EntityInterface`), the matrix was correct for anonymous, an authenticated outsider, a member of the
private collection and admin, across a private, a public, a UVA and an ungrouped reference.

**Access (listings): needed its own port.** An `EntityQuery` with `accessCheck(TRUE)` returned
all four references, private and UVA included, until a `bibcite_reference_access` query alter was
added. It is `NodeQueryAlter` with three swaps (base table, plugin id, no per-record override
field). After it, listings matched single-record access for all four viewers.

**Solr: reader side needs no change, writer side needs generalizing.** `KmassetDocBuilder::build()`
is typed `NodeInterface` and fails on a reference with a `TypeError`; the contributors, the
direct sink and the insert/update/delete hooks are node-typed too. A PoC document for a reference
(`sources-11-{id}`, `service: sources`, visibility and `collection_uid_s` from `CollectionVisibility`,
`uid_legacy_s` from the legacy key) was evaluated against `VisibilityTokenBuilder`'s real filter for
anonymous, outsider and member: Solr-side and Drupal-side agreed in all 12 cases. The builder only
built; nothing was posted (the effective `solr_master_url` is empty in DDEV). Not covered: owner
fields (a reference has no owner), kmapid expansion from the KMaps field, and the bulk
`kmassets:index-all` path. In the scratch run `collection_uid_s` read `images-11-{gid}` because the
scratch groups had no `field_legacy_site`; writer and reader share that fallback, so they agreed.

**Display: every D7 element has a source on a reference.** The canonical route renders the
**`table` view mode**, not `default`; a display alter must cover it. With the `shanti_kmaps_fields_default`
field and a term-reference field for Zotero Collections attached to the reference bundles, and node
21045's real values on a reference, the page rendered, in order: title, journal, author with role,
Format, Publication Year, Pages, Sources ID (legacy nid), Visibility (derived from the collection),
Zotero Collections, collapsible Abstract, URL, then the Zotero Collections and Subjects lists.
**The KMaps field type attaches to a reference bundle and stores a value** (its `isEmpty()` needs
the `raw` column, three `|`-separated parts). Not done: real theming, the abstract in full, the
Zotero attachment fields, and the other subtypes (the build must check several, see the deferred
note).

**What production would have to change** (the cost of the entity model, measured):
1. `mandala_group_inheritance`: the access hook's entity-type guard and the bundle discovery are
   node-only; generalize both. Generalize `NodeQueryAlter` or add the reference twin.
2. `mandala_kmassets_sync`: builder, contributors, sink and hooks from `NodeInterface` to
   `EntityInterface`, plus a `sources` entry in the bundles config.
3. Config: the relationship type for `collection` and `subcollection`, and the new group
   permission on each role that holds `view group_node:* entity`. Exported from a live Drupal.
4. A reference-aware display (view mode or template) and the reference-type fields above.
5. Not needed: the Solr reader (`VisibilityTokenBuilder`, the proxy), which keys on
   `visibility_i` and `collection_uid_s` only.
6. Open: references have no per-record visibility override (`field_group_content_access` exists
   only on AV nodes); D7 Sources showed `Visibility` from its group, which this matches.

## Handoff for the Sources migration (Xiaoming)

**Before building the record display, read
[sources-record-display-parity-check-d7-subtypes](../deferred/sources-record-display-parity-check-d7-subtypes.md):
look at several D7 record pages of different subtypes (or have them pasted in) and at the old
site's display settings; do not design the display from bibcite's defaults or one example.**

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
- **A keyed (private) Zotero library** (criterion 5) — only a public library was fetched; the
  credential path is untested and no real key was read or used.
- **Broad citation output comparison** (criterion 6) — one node sampled (finding 6); books,
  chapters, multi-author lists and the four custom types are not.
- **How D7 values map into CSL fields** (`bibcite_entity.mapping.csl` `fields:`) beyond the
  fields exercised in finding 6, and which CSL targets the custom types should use (finding 2a).
- **Migrating the existing 2014 Zotero data** (tags, collections, attachments on ~1.9k nodes) —
  the prototype imports new Zotero entries, not the D7 history.
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
