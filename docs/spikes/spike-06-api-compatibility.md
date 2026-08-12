# Spike 6: API Compatibility for React Application
**Status:** ◐ In progress — pre-spike findings 2026-07-30 (client architecture + live WAF/proxy incident); **D7 node-JSON endpoint audit 2026-08-07** (all four per-site detail endpoints located + response shapes documented against live D7 source); **URL strategy DECIDED 2026-08-12** — Option A (generalize the same-origin proxy). Open: D11 node-JSON endpoint implementation, client-side generalization to all sites, and the proxy's SSRF hardening.
**Lead:** Than Grove (owns React app and D7 API contracts)
**Mode:** Team spike (candidate)
**Date:** —
**Branch:** `spike/6-api-compatibility` (superseded — pre-findings + audit now on `main`)

## Theory
A clear strategy exists for preserving API compatibility between the current
multi-site D7 API endpoints and the consolidated D11 single-instance, without
breaking the React application that consumes them.

## Demo
*Full end-to-end demo (D11 endpoint prototype) to be completed. So far the spike has
produced two audits against real source: the client-side fetch architecture (pre-spike
findings, 2026-07-30, against `mandala-om`) and the D7 server-side node-JSON endpoints
(2026-08-07, against `Site/mandala-drupal`), documented below.*

## Findings

### D7 per-site node-JSON endpoint audit (2026-08-07, against live D7 source `Site/mandala-drupal`)

Located and read all four per-site individual-asset detail endpoints (the ones the React
client reaches via each Solr record's `url_json` field). This resolves Pass Criterion #1
for the JSON endpoints. **Key takeaway: the four endpoints are not uniform** — three
return the augmented raw node (JSONP-capable), one returns a bespoke flat "doc" shape (no
JSONP), and one embeds rendered HTML. A single generic D11 controller will not reproduce
them; each needs its own response shaping.

| Site | Public path | Module / file | Callback | Response shape | JSONP |
|---|---|---|---|---|---|
| **AV** | `/api/v1/media/node/{nid}.json` | `mediabase/mb_solr/mb_solr.module` | `mb_solr_get_solrdoc()` | **Bespoke flat `doc`** (Solr-style: `service`, `asset_type="audio-video"`, `id`, `uid`, `collection`, `duration_s`, `url_*`, `caption`, `kmapid`…) — **not** the node | **No** (plain `drupal_json_output`) |
| **Images** | `/api/json/{nid}` | `shanti_images.module` | `shanti_images_node_json()` | Augmented **raw node** (entity-refs expanded in place); `?extend=true` gives a reshaped flat variant w/ IIIF url + dims | **Yes** (`?callback=`) |
| **Sources** | `/sources-api/json/{nid}` | `shanti_biblio_modules/sources_misc/sources_misc.module` | `sources_misc_node_json()` | Augmented **raw node** (+`description` from body, collection/subcollection relations); sends `Access-Control-Allow-Origin: *` | **Yes** (`?callback=`) |
| **Texts** | `/shanti_texts/node_json/{nid}` | `shanti_texts.module` | `shanti_texts_node_json()` | Augmented **raw node** **+ embedded rendered HTML** (`full_markup`, `toc_links`, `bibl_summary`, `views_links` via `views_embed_view()`) + book `toc`/`parent`/`children` | **Yes** (`?callback=` **or** `?json_wrf=`) |

**Load-bearing gotchas for D11:**

1. **AV's public path has no Drupal route.** `/api/v1/media/node/{nid}.json` is a
   **server/proxy-level rewrite** to the internal `services/solrdoc/%` route
   (`mb_solr_get_solrdoc`) — verified: the string `api/v1/media/node/` appears in the D7
   codebase *only* where the endpoint self-documents its own URL (`mb_solr.module:885`),
   never as a `hook_menu` key. **D11 must recreate this path mapping explicitly** (route
   alias or rewrite); it will not fall out of a straight module port.
2. **AV is Solr-derived, not node-derived.** Its `doc` shape mirrors the kmassets Solr
   record, which ties the AV endpoint to the kmassets write path (1a.8 / reindeer_x),
   consistent with the client already reading `url_json` from Solr.
3. **Texts bakes rendered HTML into JSON** via four `views_embed_view()` panes — so the
   D11 equivalent depends on those Views (or replacements) existing and rendering, not
   just on node data. This overlaps the Texts book-display model (see
   [Spike 4b](spike-04b-ckeditor5-footnotes.md)).
4. **JSONP is per-endpoint inconsistent** — Images/Sources use `?callback=`, Texts adds
   `?json_wrf=`, AV has none. The client's JSONP dependency (pre-findings) is only
   satisfiable on 3 of the 4 today; the D11 reachability decision (proxy-everything vs.
   CORS) should standardize this rather than replicate the inconsistency.
5. **Private-content gating is shared** — all four call `shanti_general_api_check($node)`
   before emitting. D11 must enforce the equivalent access check (ties to the ADR 015 /
   Group access model) in whatever replaces these endpoints, or private assets leak via
   the API.

### How the endpoint URL is configured & discovered — `url_json` is per-content-type config, not a hardcoded route (2026-08-07)

This closes the loop on the pre-finding that *"the node-JSON URL is data carried in the Solr
`url_json` field, not hardcoded in the client."* **Where that data comes from, in D7:**

- The **`shanti_kmaps_fields`** module adds, to **each content type's** edit form (e.g.
  `…/admin/structure/types/manage/shanti_image`), a block of asset settings:
  **`asset_type`, HTML Path, AJAX Path, JSON Path, Thumbnail Path** — each a **URL template
  with a `__NID__` placeholder** (e.g. JSON Path = `api/json/__NID__`; HTML Path defaults to
  `node/__NID__`). Stored as D7 vars `shanti_kmaps_fields_url_json__{type}` etc.
  (`shanti_kmaps_fields.module:867–895`).
- When the module builds a node's Solr doc, it substitutes `__NID__`→nid, wraps in an absolute
  `url()`, and writes `url_html` / `url_ajax` / `url_json` / `url_thumb` into the kmassets
  record (`…module:1192–1196`). The React client then reads `url_json` off the Solr record and
  fetches it. **So the discoverable API path is per-content-type configuration**, decoupled by
  design from the endpoint that serves it — the settings form itself notes the paths *"may not
  exist, so they may need to be created … by Services, Views, or a module"* (which is why the
  endpoint implementations audited above live in separate modules per site).
- **AV is the exception**: it uses `mb_solr` (not `shanti_kmaps_fields`) to build its doc, so
  its `url_json` (`/api/v1/media/node/__NID__.json`) is set there, not via content-type config.

**D11 state — the mechanism was relocated & redesigned, NOT dropped** (verified across all D11
custom modules; it is **not** in the pared-down D11 `shanti_kmaps_fields`, which is field-type
only):

- It now lives in **`mandala_kmassets_sync`** (the 1a.8 kmassets write path). Per-**bundle** CMI
  config (`mandala_kmassets_sync.settings.yml`) keyed by node bundle instead of per-install D7
  vars (single-site, ADR 005), tokens `__BASE_URL__` + `__NID__`, built by `KmassetDocBuilder`.
- The `shanti_image` bundle default is already `url_json: '__BASE_URL__/api/json/__NID__'` — but
  the config's own comments flag that the **D11 single-site path scheme is a *deferred
  decision*** and these templates merely *"preserve the D7 path shape"* as placeholders. **That
  deferred decision is precisely the URL strategy this spike owes.**
- Only `shanti_image` is configured so far (images is the only migrated site) — AV/Texts/Sources
  bundles are not yet defined.

**⚠️ Concrete gap this surfaces:** the **producer** side exists (D11 writes a `url_json` into
kmassets), but the **server** side does **not** — a route check across all D11 custom modules
found **no controller serving `/api/json/__NID__`** (or any node-JSON path). So today D11 would
publish a `url_json` into Solr that resolves to nothing. Building that D11 endpoint (to return
the response shapes documented in the audit above) is core Spike-6 implementation work.

### What the current React client actually consumes — scoping the real D11 API surface (2026-08-07)

Audited `mandala-om` (`release/v1.1.0-rc`) for which endpoints/fields it truly uses, to size
the D11 work against reality rather than the full historical endpoint matrix. **The
Pass-Criteria "8 endpoint" table is both too big and too small:** the browse-by-KMap Drupal
endpoints appear unused, while a current-user endpoint the matrix omits *is* used.

**In scope — the client consumes these (must exist in D11):**

| Solr field / endpoint | How the client uses it | D11 status |
|---|---|---|
| **`url_json`** | Core node-detail fetch (`useMandala.js`, JSONP `callback`; `assetapi.js` uses `json_wrf`). | Written by `mandala_kmassets_sync`; **serving endpoint missing** (above) |
| **`url_html`** | Full-page links (`FeatureCard`, `TextsViewer`, `SourcesViewer`, legacy `searchui`) **and a reverse Solr lookup** — `MandalaMarkup.js` queries `q: url_html:"…"` to find an asset by its page URL. | D11 writes it (`__BASE_URL__/image/__NID__`); pages must resolve **and** match the value the client searches on |
| **`url_thumb`** | Image thumbnails + client-side size derivation (`searchapi.js`, legacy image/collection views). | Handled — `mandala_kmassets_sync` `ImageFieldContributor` builds IIIF thumb URLs |
| **`url_ajax` → embed** | **Texts only** (`legacy/texts.js`): rewrites `node_ajax`→`node_embed`, `?callback=pfunc`, to pull an embeddable HTML fragment. | D11 has no embed endpoint; Texts-phase work |
| **`/general/api/user/current`** | `LoginLink.js` — current-user / auth status. **Not in the endpoint matrix.** | Not yet in D11; ties to SAML/OAuth (Spike 10) |

**Out of scope — appears NOT consumed by the React client:**

- The **browse-by-KMap Drupal endpoints** (`/services/subject/{id}`, `/general/api/*images/{id}`,
  etc. from the Spike 2 pre-findings) — no client references found. Consistent with Spike 2's
  finding that browse/search is done **directly against Solr**, not via Drupal endpoints. So
  these likely **do not need reproducing in D11** for the React app (confirm before deleting the
  requirement — other consumers, e.g. the WordPress plugin, are unaudited).
- The generic **AJAX endpoints** for non-Texts sites (`/api/ajax`, `/services/node/ajax`) — only
  the Texts embed path showed up in the client.

**Net:** the D11 API surface the React client actually needs is **`url_json` (all sites) +
`url_html` page resolution + `url_thumb` (done) + a Texts embed endpoint + `/…/user/current`** —
materially smaller and differently-shaped than the historical 8-endpoint matrix. This should
refocus the remaining spike work and the D11 implementation estimate.

### Client-side architecture + live WAF incident (2026-07-30)
See the **Pre-spike findings (2026-07-30)** section below — how `mandala-om` fetches
(Solr record → `url_json` → node JSON, all JSONP across 6 subdomains), and the confirmed
Sources WAF-503 incident + its same-origin `/proxy/json` mitigation.

## URL-strategy DECISION (2026-08-12, Than): Option A — generalize the same-origin proxy

**Decided.** The React app is embedded via WordPress on third-party hosting (`thlib.org` on
hosting.com today, potentially other WordPress sites in the future) that Mandala/D11 does not
control and never will. That rules out **Option C (same-origin serving)** outright, not just
deprioritizes it — there is no single origin to serve the app *and* the API from, because the
set of embedding sites isn't fixed or Mandala-owned. It also weakens **Option D** (ALB-aliased
subdomains) for the same reason the doc already flagged: it doesn't defeat a browser-targeted
WAF rule. That leaves **Option A, generalized to every app**, as the strategy — not a cutover
stopgap with a later migration to C.

This is the spike's headline deliverable. The findings below reframe the original Option A/B/C
into a sharper question. Two facts drove it:

- **`url_json` is a lever D11 already controls** — `mandala_kmassets_sync` writes the client's
  fetch URL per bundle (`__BASE_URL__/api/json/__NID__` today, a *placeholder* by the config's
  own admission). Changing the scheme + re-indexing changes what the client hits **without a
  client redeploy**.
- **The real obstacle is not CORS, it's the WAF** — the Sources incident was a browser
  cross-origin block (503 on JSONP), not a plain CORS-header problem. Any option that keeps a
  **browser cross-origin call** is exposed to the same D11 AWS WAF rule; options that make the
  call **same-origin** or **server-to-server** sidestep it.

| Option | Cross-origin call from browser? | Client change | WAF exposure | Notes |
|---|---|---|---|---|
| **A. Generalize `/proxy/json`** (same-origin WP proxy → D11 server-side) | **No** (same-origin to WP; proxy fetches server-side) | Small — extend the proven Sources pattern to all apps | **Avoided** | Already working for Sources; adds a proxy hop + couples to WordPress; owner/host TBD |
| **B. Native CORS on D11** + client JSONP→`fetch` | **Yes** | Larger — touches aging client (React 16) | **Exposed** — WAF must allow-list the browser cross-origin call (the exact thing that broke Sources) | Cleanest standards-wise; risk concentrated in WAF policy + client rewrite |
| **C. Same-origin serving** (React app served from the D11 origin / ALB path) | **No** (no cross-origin at all) | Large — changes the WordPress-embed model | **Avoided** | Architecturally cleanest end state; biggest structural change; may not fit `wp-kmaps` embedding |
| **D. ALB-aliased subdomains** (keep per-app hosts → single D11) | **Yes** (still cross-origin from the WP-embed origin) | None (if kmassets writes subdomain URLs) | **Exposed** — does not by itself defeat a browser-targeted WAF rule | Preserves D7 shape / zero client change, but doesn't solve the actual blocker |

**Decision: Option A, generalized to all apps, no Option C migration planned.**

- Generalize the same-origin `/proxy/json` proxy to Images/AV/Texts/Visuals (today it's
  Sources-only in the client). Already proven in production for Sources, needs no D11 CORS/WAF
  allow-listing, and the server side is already generic (see Implementation reality below) — the
  remaining work is client-side.
- **Option C is off the table, not deferred.** It required the React app and the D11 API to
  share an origin. The app is embedded on arbitrary third-party WordPress installs Mandala
  doesn't control — there is no single origin to converge on. This also means there's no
  "eliminate the proxy hop later" follow-up: the proxy *is* the permanent architecture.
- **Option D rejected** — preserves the D7 URL shape but doesn't defeat a browser-targeted WAF
  rule, and is now moot since A is already proven and generalizing it is strictly less work.
- Option B (native CORS) is superseded by A for the same reason C is off the table: CORS only
  helps if the WAF allows the browser-side cross-origin call, and A already sidesteps needing
  that permission at all.

## Implementation reality (2026-08-12): the proxy is a separate plugin, and it's currently an open proxy

Locating the actual `/proxy/json` implementation behind the proven Sources fix took three
lookups — it is **not** in `wp-kmaps` (the app-embedding plugin) and **not** in `mandala-kadence`
(the display theme). It lives in its own repo: **[`shanti-uva/mandala-wp-proxy`](https://github.com/shanti-uva/mandala-wp-proxy)**,
a small standalone WordPress plugin (`mandala-proxy.php`, one file) that predates this spike.
It registers four proxy routes via `add_rewrite_rule`: `/proxy/wfs` (Geoserver), `/proxy/ttt`,
`/proxy/solr` (hardcoded to `texts.thdl.org`), and the general-purpose `/proxy/json` used by the
Sources fix.

**Good news for generalizing Option A:** the `json_proxy` handler is already fully generic —
`$base_url = $params['url']; wp_remote_get($base_url);` — it isn't hardcoded to Sources or any
single host. What's hardcoded today is only the **client** (`useMandala.js` in `mandala-om`
gates the proxy path on `query.includes('sources.mandala.library.virginia.edu')`). Generalizing
to all four remaining sites is a client-side change, not a server-side one.

**⚠️ Blocking security finding: `json_proxy` is currently an open proxy (SSRF risk).** It takes
any `url` param, fetches it server-side with **no host allowlist**, and serves the response with
`Access-Control-Allow-Origin: *`. That's a narrow, low-traffic stopgap today; making it the
sanctioned, generalized architecture for every embedding site and every app raises its exposure
significantly. **Must add a host allowlist (restrict `url` to `*.mandala.library.virginia.edu`)
before generalizing client usage.** Tracked as a deferred item — see
[mandala-wp-proxy-json-proxy-open-ssrf.md](../deferred/mandala-wp-proxy-json-proxy-open-ssrf.md).

**Merge-vs-separate decision (2026-08-12, Than): keep `mandala-wp-proxy` as its own plugin.**
Considered folding it into `wp-kmaps` for discoverability (three lookups to find it is itself a
signal), but rejected: (1) it isn't Mandala-specific — it already proxies Geoserver/WFS and a
THDL Solr endpoint unrelated to KMaps, so merging would misscope a general-purpose CORS proxy
into an app-embedding plugin; (2) once hardened with an allowlist it's a security-sensitive
component that benefits from its own release/review cycle, independent of `wp-kmaps`'s UI churn.
Instead: declare the dependency explicitly via a WordPress `Requires Plugins` header on
`wp-kmaps` (so sites can't activate it without the proxy present) plus README documentation —
fixes the actual problem (undiscoverable dependency) without the coupling cost of a merge.
Tracked: [wp-kmaps-mandala-proxy-dependency.md](../deferred/wp-kmaps-mandala-proxy-dependency.md).

## What this does NOT establish
- **Whether the browse-by-KMap and generic AJAX endpoints have any remaining consumer.** The
  React client does **not** use them (scoping audit above), but the **WordPress `wp-kmaps`
  plugin and any server-side consumers are unaudited** — confirm before dropping them from the
  D11 requirement. Their exact D7 response shapes were not documented (deprioritized as likely
  out of scope for the React app).
- **The Texts embed endpoint** (`node_embed`, reached via `url_ajax`) and the
  **`/general/api/user/current`** endpoint are identified as in-scope but **not yet audited**
  for response shape / D11 approach.
- **URL strategy is decided (2026-08-12): Option A, generalized.** What remains is
  implementation: generalize the client's Sources-only proxy gate to all sites, harden
  `mandala-wp-proxy`'s open `json_proxy` route with a host allowlist, and wire the
  `wp-kmaps`↔`mandala-wp-proxy` dependency declaration.
- **No D11 endpoint prototype exists yet** — and a route check confirms D11 currently serves
  **no** `/api/json/__NID__` (or equivalent) node-JSON endpoint at all, even though
  `mandala_kmassets_sync` already publishes that URL into `url_json`. Building it (to return
  the audited response shapes, esp. AV's Solr-derived `doc` and Texts' embedded Views HTML) is
  the central open implementation task; feasibility is argued from source, not yet run.
- **The D11 single-site URL path scheme is still formally deferred** — `mandala_kmassets_sync`'s
  `url_json` template is a D7-shape placeholder by its own admission. Choosing the real scheme
  (and reconciling it with the client's JSONP/CORS/WAF needs from the pre-findings) is this
  spike's headline deliverable, still open.
- Whether node IDs are preserved across migration (a Fail-Criteria risk) — assumed via
  `field_legacy_nid`, not yet confirmed against the client's `url_json` values end-to-end.

## Deferred notes
- [mandala-wp-proxy-json-proxy-open-ssrf.md](../deferred/mandala-wp-proxy-json-proxy-open-ssrf.md) —
  the open-proxy/SSRF finding in `json_proxy`, blocking generalized rollout
- [wp-kmaps-mandala-proxy-dependency.md](../deferred/wp-kmaps-mandala-proxy-dependency.md) —
  the plugin-dependency declaration needed since the two plugins stay separate repos
- Still to file: a note on the AV `/api/v1/media/node` server-rewrite requirement for the D11
  Terraform/ALB config, and the client-side change to generalize the proxy gate beyond Sources

---

## Reference: Pass Criteria
- All eight D7 API response formats are fully documented
- A URL strategy is agreed upon between Drupal and React teams (Option A/B/C)
- The agreed strategy is technically feasible in D11 and in Terraform ALB config
- The D11 API implementation approach is clear per endpoint

## Reference: API Endpoints to Document
| Site | JSON API | AJAX API |
|------|----------|----------|
| AV | `/api/v1/media/node/{nid}.json` | `/services/node/ajax/{nid}` |
| Images | `/api/json/{nid}` | `/api/ajax/{nid}` |
| Sources | `/sources-api/json/{nid}` | `/sources-api/ajax/{nid}` |
| Texts | `/shanti_texts/node_json/{nid}` | `/shanti_texts/node_embed/{nid}` |

## Reference: URL Strategy Options
- **Option A:** Single domain, same paths — React app updated to use new domain
- **Option B:** Old subdomains kept as ALB aliases to single D11 instance — no React changes
- **Option C:** 301 redirects from old subdomain paths — may break React depending on redirect handling

## Reference: Fail Criteria
| Finding | Response |
|---|---|
| React app cannot be changed | Must use Option B — coordinate with Dave Goldstein on ALB config |
| Expensive computed fields in D7 response | Design caching strategy before implementing |
| Node IDs change during migration | Implement nid mapping table; update API to accept old or new nid |
| API response structure inconsistent across nodes | Document exceptions; handle in D11 controller logic |

## Pre-spike findings from Spike 2 (Solr integration)

Spike 2 examined D7's search and API layer in detail. Key findings that bear directly on this spike:

### D7 has no Drupal-level free-text search endpoint

Text search in D7 is entirely **client-side**: the browser (or React app) calls the
Solr proxy directly using a weighted multi-field query built in `jquery.kmapsSolr.js`.
There is no Drupal controller or REST endpoint that accepts a keyword and returns ranked
asset results. D11's corrected Solarium query (the D7-equivalent weighted query with
prefix wildcards) produces results that match D7's text search output exactly, because
they are the same query against the same index.

This means there is no text-search API endpoint to replicate in D11 — the React app
queries Solr directly and will continue to do so.

### D7's Drupal-level API is browse-by-KMap, not text search

The actual Drupal API endpoints in D7 are KMap-term-scoped browse endpoints:

| Module | Endpoint | Returns |
|--------|----------|---------|
| `mb_services` | `/services/subject/{kmap_id}` | A/V assets tagged with that subject |
| `mb_services` | `/services/place/{kmap_id}` | A/V assets tagged with that place |
| `shanti_general` | `/general/api/subjectsimages/{kmap_id}` | Images for a KMap subject |
| `shanti_general` | `/general/api/placesimages/{kmap_id}` | Images for a KMap place |
| `shanti_general` | `/general/api/termsimages/{kmap_id}` | Images for a KMap term |

These issue a Solr query of the form `fq=kmapid:{domain}-{id}` against the kmassets
index, filtered by `asset_type`. They return paginated JSON.

**Implication for Spike 6:** D11 needs equivalent endpoints. The Solr query is
straightforward (already proven in Spike 2). The open question is URL strategy — whether
D11 keeps the same paths, redirects, or updates the React app to use new paths.

### The per-site node API endpoints (the table above in Pass Criteria) are separate

The per-site endpoints (`/api/v1/media/node/{nid}.json`, `/api/json/{nid}`, etc.) are
individual-asset detail endpoints — not search or browse. These are distinct from the
browse-by-KMap endpoints and likely handled by different D7 modules (shivanode,
shivadata, etc.). Spike 6 should audit those separately.

### Working reference implementation

The comparison page at `/spike/solr-comparison` on the D11 dev site demonstrates
corrected D11 text search (D7-equivalent weighted query via raw Solarium) matching D7
results. The controller at
`drupal/web/modules/custom/spike_solr_demo/src/Controller/SpikeComparisonController.php`
is a working reference for raw Solarium queries and native Solr field access, reusable
for building the browse-by-KMap endpoints.

---

## Pre-spike findings (2026-07-30): client API architecture + the WAF/proxy problem

Reviewed the React client (`mandala-om`, branch `release/v1.1.0-rc`) to map how it
actually consumes the mandala APIs, prompted by a **live production incident
(2026-07-29)** that already exercises the exact compatibility risk this spike exists
to address.

### How the React client fetches asset data (two steps)

Every asset-detail view does two calls:

1. **Solr** (`useKmap` / `useAsset`, `kmaps-app/src/hooks/`) — query the kmassets
   index (via the solr-proxy) for the asset's record.
2. **Node JSON** (`useMandala`, `kmaps-app/src/hooks/useMandala.js`) — read the
   **`url_json` field stored on that Solr record** and fetch it for the full Drupal
   node JSON. (AV special-case: append `p` so `.json` → `.jsonp`.)

**Load-bearing fact: the node-JSON endpoint URL is not hardcoded in the client — it
is data, carried per-record in the Solr `url_json` field.** The URL the browser hits
is therefore controlled by *what the kmassets sync writes into `url_json`*, which
couples this spike to the kmassets write path (Sprint 1a.8 / reindeer_x,
[ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) /
[ADR 007](../adr/007-reindeer-x-independent-service.md)), not just to ALB routing.

### Everything is JSONP, across six subdomains

The node-JSON fetch uses **JSONP** — a cross-origin `<script>` injection with a
callback param (`callback` in `useMandala`; `json_wrf` / `json.wrf` in
`kmaps-app/src/logic/assetapi.js`) — purely to dodge CORS, because the app (embedded
by the `wp-kmaps` WordPress plugin at `…/mandala`) fetches from **six distinct D7
subdomains** (`REACT_APP_DRUPAL_*` env vars):

| App | Production host |
|---|---|
| Home / places / subjects / terms | `mandala.library.virginia.edu` |
| AV | `av.mandala.library.virginia.edu` |
| Images | `images.mandala.library.virginia.edu` |
| Sources | `sources.mandala.library.virginia.edu` |
| Texts | `texts.mandala.library.virginia.edu` |
| Visuals | `visuals.mandala.library.virginia.edu` |

The D11 consolidation collapses all of these into one instance — so the client's env
hosts *and* every Solr `url_json` value need a coherent story at cutover.

### CONFIRMED incident (mandala-om commit `6a2ef22b`, 2026-07-29): WAF 503 on browser JSONP

`sources.mandala.library.virginia.edu` returns **HTTP 503 to cross-origin browser
JSONP requests** — an edge/WAF block that **`curl` and the WordPress server itself do
NOT hit**. Effect: Sources detail pages rendered the title (from Solr) but a **blank
body** (the `url_json` fetch was 503'd). The block is browser-cross-origin-specific —
i.e. keyed on `Origin` / `Referer` / `Sec-Fetch-*` / bot heuristics, exactly the class
of rule the new D11 AWS WAF will also enforce.

### The mitigation (commits `6a2ef22b`, `27a21c63`): same-origin server-side proxy

Fixed by routing Sources body fetches through a **WordPress server-side proxy**:
`{REACT_APP_WP_PROXY}/json/?url=<encoded target>` via a plain `axios.get` (not JSONP),
so the browser makes a **same-origin** request and the proxy performs the cross-origin
fetch server-side (not subject to the browser-targeted WAF rule). Verified:
`#/sources/127668` body fetch → `/proxy/json/?url=…/sources-api/json/127668` → 200.
Related config: `REACT_APP_JSON_PROXY=/proxy/json?url=` (same-origin relative rule),
and local dev `kmaps-app/src/setupProxy.js` proxies `/proxy/*` to DDEV WordPress.
**Currently scoped to the Sources host only** — images / AV / texts / visuals still use
direct JSONP and are one WAF-config change away from the same 503.

### Implications for this spike (new / updated criteria)

1. **The WAF/JSONP failure is live, not hypothetical.** The Fail-Criteria rows "React
   app cannot be changed" and (implicitly) a strict edge/WAF are already triggered in
   production for Sources; the D11 AWS WAF makes every app a candidate post-consolidation.
2. **Evaluate generalizing the same-origin proxy to all asset JSON** as the primary
   URL strategy — one same-origin `/proxy/json?url=<D11 endpoint>` call sidesteps CORS +
   JSONP + WAF in one move and is already proven for Sources. This is more concrete than
   the abstract Option A/B/C above and reframes the choice as **proxy-everything vs.
   move the client to native `fetch` + CORS**.
3. **WAF must explicitly allow the server-to-server fetch path** — the fix works
   precisely because server-side requests bypass the browser rule. Add this to the
   "feasible in D11 + Terraform ALB/WAF config" pass criterion.
4. **`url_json` is a second lever.** D11 controls the client's API URL by what the
   kmassets sync writes into `url_json`, so the cutover strategy is a joint decision
   with the kmassets write path — and can avoid a client redeploy if the sync writes
   D11 URLs.
5. **Sources is the canary** (broke first, patched first) and is *also* the
   [Spike 5](spike-05-bibcite-sources.md) bibcite target — coordinate the two.
6. **The client is aging** (React 16, `react-scripts` 3.4.3, Node 14 in CI, a
   Dependabot backlog per `mandala-om` README). Any data-layer change (proxy-everything
   or JSONP→CORS) lands in old code — a cost input for the Phase 3 cutover.
### Key decision this spike must make: is `/proxy/json` the final solution?

The Sources fix works, but it was a **targeted stopgap**, not a chosen architecture.
This spike must **explicitly decide whether the `/proxy/json` same-origin proxy is the
final D11 answer, or whether a better solution exists** — and record the rationale.
Candidate alternatives to weigh against "generalize `/proxy/json` to all apps":

- **Native CORS on D11** — D11 sets `Access-Control-Allow-Origin` for the app origin(s)
  and the client moves from JSONP to plain `fetch`; no proxy tier, but touches the aging
  client and depends on the WAF allowing the browser cross-origin call.
- **Same-origin serving** — if the React app ends up served from the same origin as the
  D11 API (or an ALB path on it), there is no cross-origin call at all (no proxy, no CORS).
- **ALB-aliased subdomains** (the doc's Option B) — keep per-app hostnames as ALB
  aliases to the single D11 instance; may not by itself defeat a browser-cross-origin
  WAF rule.
- **A dedicated proxy service** (vs. the WordPress plugin) — if a proxy tier is chosen,
  decide where it lives (WordPress plugin, a small standalone service, or the D11
  app itself) and how it is deployed/owned on AWS.

Deliverable: a recommended API-reachability architecture with the WAF, CORS, ALB, and
`url_json` implications spelled out — not just "the Sources proxy works."

**Source refs** (`mandala-om`, branch `release/v1.1.0-rc`):
`kmaps-app/src/hooks/useMandala.js`, `kmaps-app/src/logic/assetapi.js`,
`kmaps-app/README.md` (architecture); commits `6a2ef22b` (Sources 503 → `/proxy/json`),
`27a21c63` (`REACT_APP_WP_PROXY` env split from the geoserver var).

---

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-6)*
