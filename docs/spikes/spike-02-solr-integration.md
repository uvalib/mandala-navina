# Spike 2: Solr Index Read-Only Integration
**Status:** Proven
**Lead:** Yuji Shinozaki
**Date:** 2026-06-11
**Commit:** TBD

## Theory
`search_api_solr` on Drupal 11 can query the existing `kmassets` index without
modifying the index schema, and results match D7 output for equivalent queries.

## Demo

`search_api_solr` 4.3.10 connects to the live `kmassets` index via the existing
Solr proxy and returns results:

```
Query: "Buddhism" (D11, after fixes) → subjects-2610, subjects-1205, subjects-882 (top 3)
Query: "Buddhism" (D7-equivalent raw query) → subjects-2610, subjects-1205, subjects-882 (top 3)
Direct Solr (*:*) → 147,778 documents via proxy (anonymous), 557,483 total
```

A working comparison page at `/spike/solr-comparison` has two sections. **The `spike_solr_demo` module is disabled as of 2026-08-13** (see the correction note at the end); the code is retained — re-enable with `drush en spike_solr_demo` to view the page.


**Section 1 — Result order:** Corrected D11 (D7-equivalent raw Solarium query, green
header) on the left vs naive D11 (search_api standard stack) on the right. Row color:
green = same result, amber = reordered, red = different document.

**Section 2 — Field fidelity:** For each corrected D11 result, an independent raw Solr
fetch by `uid` verifies that all field values are identical. Since both come from the
same Solr index via Solarium, all fields show ✓ match — confirming the corrected
approach has perfect field fidelity with no transformation layer.

## Findings

### 1. Connection — PASS
`search_api_solr` connects cleanly to the proxy. The correct Solarium endpoint
configuration is:
- `scheme: https`
- `host: mandala-index-dev.internal.lib.virginia.edu`  ← **corrected 2026-08-13**; see
  the correction note at the end
- `port: 443`
- `path: /`  ← critical: NOT `/solr/`
- `core: kmassets`
- `skip_schema_check: true`

Setting `path: /solr/` causes Solarium to double the path to `/solr/solr/kmassets/`
because Solarium's `Endpoint` class appends its own `context` value (`solr`) between
the path and core. The correct path is `/`.

### 2. Schema modification — PASS (no modification attempted)
With `skip_schema_check: true` and `read_only: true` on the index, `search_api_solr`
makes no attempt to modify the remote schema. The `solr_document` datasource is
specifically designed for querying externally-managed indexes.

### 3. Field naming — PASS (no mapping layer needed)
The `kmassets` index uses plain human-readable field names (`title`, `service`,
`asset_type`, `kmapid`, `url_html`, etc.) — not `search_api_solr`'s internal
prefixed naming (`ss_`, `its_`, `tm_`). With the `solr_document` datasource,
`search_api_solr` queries fields by their exact names as configured in the index
`field_settings`. No mapping layer is required.

### 4. The proxy is a visibility filter — KEY FINDING (hostname corrected 2026-08-13)
The Mandala Solr proxy is not a simple pass-through. It intercepts every query and
injects a visibility filter:

**Anonymous:** `fq=(visibility_i:1 OR asset_type:(places subjects terms))`
**Authenticated:** adds user's private collections to the filter

This filter is real and was re-verified on 2026-08-13: against production kmassets,
`visibility_i:1 OR asset_type:(places subjects terms)` returns **549,312** documents,
which is exactly what the filtered proxy endpoint (`mandala-index`) returns to an
anonymous caller. The semantics recorded by this spike are correct.

**The endpoint attribution was not.** The hostname originally recorded here — and used in
the server connector — is not the filtered proxy. The connector has been repointed
(see the correction note at the end). The routing detail behind that is tracked outside
this repo; **ask Yuji before changing any production Solr endpoint.**

The original figure above ("via proxy (anonymous): 147,778") matches neither endpoint
today and is closest to `visibility_i:1` alone (150,013) less the demo's
`-asset_type:(picture document video)` exclusion — i.e. it appears to have measured a
query rather than the proxy's anonymous behaviour. Left unexplained rather than
retro-fitted.

The proxy authenticates users via Mandala Drupal OAuth2 (`sid` session cookie).
D11 will need to pass authenticated sessions to the proxy to serve private records
to logged-in users — the same mechanism the React app uses.

### 5. Solr version
The live index runs Solr 7.7.3. `search_api_solr` 4.3.10 supports Solr 7.x.

The proxy blocks `/admin/*` endpoints by design. Setting `solr_version: '7'` in the
server connector config prevents `search_api_solr` from calling `/admin/info/system`
to detect the version (which would timeout and cause a 5-second delay on every query).

### 6. Index schema — 192 fields, dynamic field conventions
The schema uses suffix-based dynamic field types:
- `*_s` — single-value string (e.g. `visibility_s`, `title_sort_s`)
- `*_ss` — multi-value string (e.g. `associated_subjects_ss`)
- `*_i` — integer (e.g. `visibility_i`, `collection_nid_path_is`)
- `*_is` — multi-value integer
- `*_idfacet` — custom facet type for KMaps term references
- `*_txt` — text (analyzed)
- `*_bo`, `*_tibt` — Tibetan-script fields
- `url_*` — URL fields (stored as `text_general`)

Key content fields: `id`, `uid`, `title`, `service`, `asset_type`, `asset_subtype`,
`url_html`, `url_json`, `url_thumb`, `kmapid`, `collection_title`, `collection_nid`,
`visibility_i`, `visibility_s`, `node_created`, `node_changed`, `description`,
`node_lang`, `names_txt`, `caption`, `summary`.

### 7. kmassets is flat — no subdocument concern for asset search
`kmassets` documents are flat. The `block_type` / `block_child_type` nested document
pattern does NOT exist in kmassets — querying `block_type` returns "undefined field".
`search_api_solr`'s standard stack handles flat documents correctly.

The nested document (block-join) pattern exists in the **`kmterms`** index, which is
separate (see finding 9 below).

### 8. Field fidelity — PASS with one systematic difference
For every field where both D11 and raw Solr return values, they match — with one
documented exception:

**Date fields** (`node_created`, `node_changed`): `search_api_solr` normalizes date
fields to Unix timestamps (e.g. `1667330940`). Raw Solr returns ISO-8601
(e.g. `2022-11-01T20:09:00Z`). These represent the same instant; the difference is
purely representational.

All other mapped fields (`uid`, `title`, `service`, `asset_type`, `asset_subtype`,
`url_html`, `url_json`, `url_thumb`, `visibility_i`, `visibility_s`, `collection_title`,
`collection_nid`, `node_lang`, `node_user`, `description`, `kmapid`) pass through
unchanged.

`kmapid` is a multivalued field; `search_api_solr` returns all values correctly.

### 9. kmassets title/names_txt are STRING fields — CRITICAL FOR SEARCH ⚠

**This is the most important finding for future search implementation.**

The `title` and `names_txt` fields in kmassets use Solr's `string` field type (no
tokenization / no text analysis). The values are stored as-is. This means:

- `title:Buddhism` → matches only documents whose title value **is exactly** "Buddhism"
- `title:"Buddhism and Science"` → matches (exact phrase)
- `title:Buddhism*` → matches "Buddhism and Science", "Buddhism and Psychology", etc.
  (prefix wildcard works on string fields)

D7's `jquery.kmapsSolr.js` was specifically engineered for this. Its query includes
`title:${starts}^80` (where `starts = "Buddhism*"`) precisely to catch subjects and
terms whose title begins with the search term. This is not an optimization — it is
required for correct results.

**D11's `search_api_solr` generates term queries** (`title:(+"Buddhism")^21`) which are
tokenized-text queries. Applied to a string field, they only match exact full-value
documents. "Buddhism and Science" is silently excluded.

**Confirmed by testing:**
```
title:Buddhism          → 0 results for subjects-5955 ("Buddhism and Science")
title:"Buddhism and Science" → 2 results ✓
title:Buddhism*         → 2 results ✓
```

The correct D7-equivalent query for a keyword `q`:
```
title:{xact}^100 title:{slashy}^100 names_txt:{xact}^90
title:{starts}^80 names_txt:{starts}^70 title:{q}^10
caption:{q} summary:{q} names_txt:{q}
```
where `xact = q`, `starts = q + "*"`, `slashy = q + "/"`.

### 10. Language filter must be disabled — CRITICAL FOR SEARCH ⚠

**Setting `language_field: node_lang` in the datasource config silently eliminates all
subjects, terms, and places from search results.**

When `language_field` is set, `search_api_solr` automatically adds:
```
fq=node_lang:("en" "und")
```
KMaps subjects, terms, and places in `kmassets` do not have a `node_lang` field.
This filter therefore removes the entire KMaps taxonomy layer (subjects-*, terms-*,
places-*) from every query — silently, with no error.

**Fix:** Set `language_field: ''` in `datasource_settings.solr_document` of the index
config. Do not set this field for any `kmassets`-connected index.

Verified: removing this filter immediately brought subjects to the top of "Buddhism"
results (`subjects-2610 | Buddhism` at #1, matching D7).

### 11. Result ordering after fixes
After removing the language filter and mapping the correct search fields (`names_txt`,
`caption`, `summary`) with proportional boosts:

**Top 3 match D7 exactly** for "Buddhism":
```
#1 subjects-2610 | Buddhism       ✓ same
#2 subjects-1205 | Buddhism       ✓ same
#3 subjects-882  | Buddhism       ✓ same
```

**#4+ diverge** because D11's term query misses "Buddhism and Science" subjects (finding 9).
D7 shows `subjects-5955 / subjects-948` ("Buddhism and Science") at #4–5.
D11 shows `sources-125390` ("Buddhism and western psychology") at #4.

To fully match D7's ordering, D11 must use the D7-equivalent raw Solr query (finding 9)
rather than search_api's standard query building.

### 12. kmterms index: nested documents (block-join)
The `kmterms` index is a separate Solr core used for KMaps term/place/subject hierarchy
browsing. It uses Solr's block-join nested document pattern:

| block_type | Count |
|---|---|
| `parent` | 472,478 |
| `child` | 3,989,633 |

Child document types (`block_child_type`): `related_names`, `related_terms`,
`related_definitions`, `terms_recording`, `related_places`, `related_subjects`,
`feature_types`, `places_shape`, `places_altitude`.

D7's `kmaps_explorer` queries this index with block-join syntax:
`{!child of=block_type:parent}`, `{!parent which=block_type:parent}`.

`search_api_solr`'s standard query interface cannot issue block-join queries. KMaps
tree navigation in D11 will require either raw Solarium queries or the existing
React KMaps app (which already handles this natively).

## What this does NOT establish

- **Result ordering fully matching D7** — D11 top 3 match but #4+ diverge due to the
  string field issue (finding 9). Full equivalence requires using the D7-equivalent
  query structure rather than search_api's standard query building.
- How D11 will pass authenticated user sessions to the proxy (the OAuth2 `sid`
  mechanism). This is the primary deferred item.
- Whether `search_api_solr` Views integration works correctly with `solr_document`
  datasource (not tested in this spike).
- Performance characteristics under production query load.
- KMaps tree navigation (`kmterms` block-join queries) — requires raw Solarium or
  the React KMaps app, not search_api.

## Deferred notes

**Auth integration with proxy (blocking for private content)**
The proxy's visibility filtering is correct behavior, but D11 needs a strategy for
passing authenticated user identity to the proxy for users who should see private
records. Options:
1. D11 acts as an OAuth client to itself (same as the React app does) — complex.
2. D11 backend queries Solr directly (bypassing the proxy) using internal network
   credentials, then enforces visibility in Drupal access control — cleaner.
3. The proxy is extended to accept Drupal session tokens directly.

This is deferred to Phase 2 design. Public-content queries (anonymous) work today.

**Search query strategy (follow-on decision)**
The spike proves connection and field fidelity. The question of whether to use
`search_api_solr`'s standard query building (with custom field configuration) or
to bypass it with raw Solarium queries (replicating D7's exact query structure)
is a design decision for the search implementation phase. The D7-equivalent query
approach is already implemented in the spike comparison page as a reference.

---

## Reference: Pass Criteria
- ✓ `search_api_solr` connects to the existing index without schema modification
- ✓ A test query returns results with correct field values
- ✓ Field name mapping between `apachesolr` conventions and `search_api_solr`
  conventions is documented and manageable (no mapping layer needed for `solr_document`)

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| Field naming conventions are incompatible | **Did not occur.** `solr_document` datasource uses exact field names. |
| `search_api_solr` attempts to modify the schema on connection | **Did not occur** with `skip_schema_check: true` and `read_only: true`. |
| Query results differ significantly from D7 | **Partial.** Top 3 match for "Buddhism"; #4+ diverge due to string field type mismatch (finding 9). Full equivalence requires the D7-equivalent raw query approach. |

## Do not repeat these mistakes

1. **Never set `language_field`** on a `solr_document` index connected to `kmassets`
   (or any cross-asset-type index). It silently drops all KMaps taxonomy documents.

2. **Never treat `title` or `names_txt` as analyzed text fields** in `kmassets`. They
   are Solr `string` fields. Search them with prefix wildcards (`title:Buddhism*`),
   not term queries (`title:Buddhism`). The distinction is invisible until you notice
   that "Buddhism and Science" doesn't match "Buddhism".

3. **Set `solr_version: '7'`** in the server connector config. The proxy blocks
   `/admin/info/system`; without a hardcoded version, `search_api_solr` attempts
   that endpoint and times out on every query.

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-2)*

---

## Correction note — 2026-08-13

Found while auditing which Solr indexes are actually in use across dev / staging /
production, and then asking what consumes this spike's `search_api` index.

**1. The connector was pointed at the wrong endpoint.** Finding 4 above attributed the
visibility filter to a hostname that does not serve it. The connector has been repointed
to `mandala-index-dev.internal.lib.virginia.edu` — the dev proxy, which reads the staging
replica and applies the documented filter — in `config/sync`, in this module's
`config/optional/`, and directly on dev-0. The reason the previous endpoint was wrong is
tracked outside this repo; ask Yuji.

**2. This spike's demo module was the D11 kmassets index's only consumer**, and its
route (`_permission: 'access content'`) was reachable anonymously. `spike_solr_demo` is
now disabled. The `search_api.server.kmassets` / `search_api.index.kmassets` config
survives the uninstall — neither depends on this module — and remains as the D11 read
connection to kmassets.

**2b. Expected side-effect: Drupal will report the server as "unavailable".** The
proxy blocks `/admin/system` (404) while permitting `/admin/ping` and `/select` (both
200). `search_api_solr`'s `isAvailable()` reaches for core info and therefore fails, so
`/admin/config/search/search-api` shows the server as unavailable. **Queries work
regardless** — verified through the full D11 stack on dev-0:

```
$server->getBackend()->getSolrConnector()->execute(*:*)  →  numFound = 562,952
```

which matches `mandala-index-dev` anonymously (562,952), i.e. the filtered staging count.
This was masked before the repoint because the old endpoint permitted `/admin/*`. Do not
"fix" it by repointing back.

**3. What this does not change.** Findings 2, 3, 5–9 stand as written; they concern the
index schema, field naming, Solr version handling, flat-document structure, field
fidelity and the `string`-vs-`text` query issue, none of which depend on which endpoint
served the query. **Spike 2 remains Proven.**

**4. One consequence worth carrying forward.** The connector host is environment-specific
configuration living in a shared `config/sync`. Correct for dev, wrong for anywhere else.
Tracked in
[`spike-solr-demo-enabled-with-anonymous-route.md`](../deferred/spike-solr-demo-enabled-with-anonymous-route.md).
