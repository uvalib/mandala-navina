# Spike 6: API Compatibility for React Application
**Status:** Pending — **pre-spike findings recorded 2026-07-30** (client API architecture + a live WAF/proxy incident; see section below)
**Lead:** Than Grove (owns React app and D7 API contracts)
**Mode:** Team spike (candidate)
**Date:** —
**Branch:** `spike/6-api-compatibility`

## Theory
A clear strategy exists for preserving API compatibility between the current
multi-site D7 API endpoints and the consolidated D11 single-instance, without
breaking the React application that consumes them.

## Demo
*To be completed when spike is run.*

## Findings
*To be completed when spike is run.*

## What this does NOT establish
*To be completed when spike is run.*

## Deferred notes
*To be completed when spike is run.*

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
