# Spike 6: API Compatibility for React Application
**Status:** Pending
**Lead:** Than Grove (owns React app and D7 API contracts)
**Mode:** Team spike (candidate)
**Date:** —
**Commit:** —

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

*Full spike definition: [docs/planning/spikes-plan.md](../planning/spikes-plan.md#spike-6)*
