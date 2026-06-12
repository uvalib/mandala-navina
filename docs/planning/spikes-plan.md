# Mandala Refactoring — Technical Spikes

**Project:** Mandala Digital Library — University of Virginia Library  
**Date:** June 2026  
**Related:** [Refactoring Plan](refactoring-plan.md) · [Critical Path](critical-path.md)

---

## Purpose

Before committing to implementation, six technical theories must be proven. Each represents a risk identified in the critical path assessment that, if wrong, would significantly reshape the implementation plan. Running these as time-boxed spikes in the first three to four weeks surfaces problems at the lowest possible cost.

All spikes except Spike 2 can be completed before any production infrastructure exists. A minimal local D11 instance (DDEV) is sufficient for Spikes 1, 3, 4, and 5. Spike 2 requires access to the existing Solr endpoint. Spike 6 requires coordination with the React app team.

---

## Spike Schedule

```
Week 1–2    Spike 1: KMaps Field Type ───────┐  run in parallel
            Spike 2: Solr Integration ────────┘

Week 2–3    Spike 3: Group / Collections ─────┐
            Spike 4: CKEditor 5 Footnotes ─────┤  run in parallel
            Spike 5: bibcite for Sources ───────┤  (needs D11 from Spike 1)
            Spike 6: API Compatibility ─────────┘

Week 4      Review all results
            Confirm or revise Phases 2–5 of the implementation plan
```

---

## Spike 1 — KMaps Field Type on Drupal 11 ✓ PROVEN

**Status:** Proven — see [docs/spikes/spike-01-kmaps-field.md](../spikes/spike-01-kmaps-field.md)  
**Time-box:** 3–4 days  
**Priority:** Highest — blocks all other work

### Theory
The D7 `shanti_kmaps_fields` custom field type can be ported to the Drupal 11 Field API without loss of functionality or data fidelity.

### Background
KMaps is the taxonomic spine of the entire Mandala application. Every content type on every site uses KMaps term references. The D7 field stores six columns per value (`raw`, `id`, `header`, `domain`, `path`, `defids`), provides an autocomplete widget backed by the live KMaps API, and renders a linked term with popover. If this field cannot be faithfully ported to D11, the entire content architecture must be reconsidered.

### Work
1. Create a minimal D11 custom module `shanti_kmaps_fields`
2. Implement the `FieldType` plugin with the six-column schema
3. Implement the `FieldWidget` plugin with autocomplete against the live KMaps API
4. Implement the `FieldFormatter` plugin for display
5. Create a test content type with one KMaps field instance
6. Save a node with a KMaps value, reload it, and confirm the value round-trips correctly
7. Confirm the autocomplete widget returns correct results from the live KMaps subjects, places, and terms endpoints

### Pass Criteria
- Field type installs cleanly on D11 with no deprecation errors
- A node with a KMaps field value saves and reloads correctly
- Autocomplete returns live suggestions from the KMaps API
- All six column values are preserved through a save/load cycle
- No blocking incompatibility with the D11 Field API

### Fail Criteria and Response
| Finding | Response |
|---|---|
| D11 Field API cannot support multi-column schema | Redesign field storage (e.g., use a single JSON column or a separate entity) |
| KMaps API response format has changed since D7 code was written | Update widget to match current API contract before proceeding |
| `defids` serialized PHP causes problems in D11 | Migrate to JSON storage in the field definition; update migration plan |

### Outputs
- Working D11 `shanti_kmaps_fields` module (proof-of-concept quality)
- Documentation of any API contract changes discovered
- Go/no-go recommendation for Phase 2

---

## Spike 2 — Solr Index Read-Only Integration

**Time-box:** 2 days  
**Priority:** High — blocks Phase 2 Solr work  
**Runs in parallel with:** Spike 1  
**Requires:** UVA network / VPN access to Solr endpoint

### Theory
`search_api_solr` on D11 can query the existing `kmassets` index without modifying the index schema, and results match D7 output for equivalent queries.

### Background
The `kmassets` and `kmterms` Solr indexes are managed outside Drupal by a separate ECS ingest pipeline. The schema is defined in `mandala-scripts/solr-schema/` and must not be changed. The D7 application used the `apachesolr` module; D11 uses `search_api_solr`. These modules use different field naming conventions, which may require a mapping layer.

### Work
1. Review the current `kmassets` schema from `mandala-scripts/solr-schema/`
2. Document all field names in the existing schema
3. Stand up a D11 site with `search_api_solr` installed
4. Configure a Solr server pointing at the existing endpoint
5. Manually map Solr field names to `search_api_solr` field definitions (do not let `search_api_solr` manage the schema)
6. Run a test query and compare result counts and field values against a D7 query for the same search

### Pass Criteria
- `search_api_solr` connects to the existing index without schema modification
- A test query returns results with correct field values
- Field name mapping between `apachesolr` conventions and `search_api_solr` conventions is documented and manageable

### Fail Criteria and Response
| Finding | Response |
|---|---|
| Field naming conventions are incompatible | Build a field name mapping layer; document which fields require aliasing |
| `search_api_solr` attempts to modify the schema on connection | Configure strict read-only mode; investigate `search_api_solr` server settings |
| Query results differ significantly from D7 | Investigate query syntax differences between `apachesolr` and `search_api_solr`; may require query plugin customization |

### Outputs
- Documented field name mapping between existing schema and `search_api_solr`
- Working Solr server configuration (proof-of-concept)
- Go/no-go recommendation for Phase 2 Solr integration

---

## Spike 3 — Group Module Collections Architecture

**Time-box:** 2–3 days  
**Priority:** High — blocks Phase 3 and all of Phase 4  
**Prerequisite:** Working D11 instance from Spike 1

### Theory
The Group module (3.3.5, D11) can model Mandala's collection and subcollection hierarchy — with one level of nesting only — with appropriate access control, replacing the D7 Organic Groups implementation.

### Background
In D7, collections use Organic Groups (`og`). Every content type participates in collections. Access control (public vs. restricted) is managed via `og_access_roles`. Subcollection nesting uses `og_subgroups`, constrained to one level. In a single-instance D11, the cross-site `asset_link` mechanism is no longer needed — but the collections model must support all current access patterns. The Group module's API is currently marked beta; any instability here affects the entire content architecture.

### Work
1. Install the Group module on the D11 test instance
2. Define two group types: `collection` and `subcollection`
3. Register one content type as eligible group content for both group types
4. Create a collection, create a subcollection nested within it
5. Attempt to create a sub-subcollection — confirm this can be prevented
6. Add a test content node to both the collection and the subcollection
7. Configure group roles: anonymous (outsider), authenticated member, administrator
8. Verify a restricted collection hides its content from non-members
9. Verify a public collection is visible to anonymous users
10. Review the Group module issue queue for known breaking changes before the stable 1.0 release

### Pass Criteria
- Collection/subcollection nesting works as expected
- One-level-only constraint is enforceable
- Content can be added to groups and inherits group access rules
- Group roles provide sufficient granularity to model current access patterns
- No critical open issues in the Group module issue queue that would affect the collections model

### Fail Criteria and Response
| Finding | Response |
|---|---|
| Subcollection nesting is broken or incomplete in current release | Evaluate workarounds; consider flat collection model with parent field |
| One-level constraint cannot be enforced by Group module | Implement via custom validation hook |
| Group module access control insufficient for current patterns | Investigate supplementary access control modules (e.g., `group_permissions`) |
| Breaking API changes expected before stable release | Pin to current version and plan for an upgrade sprint post-stable |

### Outputs
- Documented Group module configuration for Mandala collections
- Access control model mapping (D7 OG roles → D11 Group roles)
- Group content type matrix (which content types belong to which group types)
- Go/no-go recommendation for Phase 3

---

## Spike 4 — CKEditor 5 Footnotes and Tibetan Unicode

**Time-box:** 1–2 days  
**Priority:** Medium — blocks Phase 4 Texts work only  
**Runs in parallel with:** Spikes 3, 5, and 6

### Theory
Existing Texts site content — including CKEditor 4 footnote markup and Unicode Tibetan script — can be reliably transformed and migrated to D11 without data loss.

### Background
The Texts site uses a custom footnotes plugin for CKEditor 4 (`shanti_footnotes`). Drupal 11 ships CKEditor 5 as the only supported editor, and the footnote markup format changed between versions. Additionally, many texts contain Unicode Tibetan script — a complex script that must survive the migration pipeline without corruption. Both issues must be verified before writing the texts migration plugin.

### Work
1. Extract a representative sample of texts body content from the D7 database (minimum 20–30 nodes; include Tibetan-language nodes specifically)
2. Document the exact CKEditor 4 footnote markup pattern used by `shanti_footnotes`
3. Install `footnotes 4.x` on the D11 test instance and document the CKEditor 5 markup format it expects
4. Write a transformation function (regex or DOM parser) to convert from CKEditor 4 to CKEditor 5 format
5. Run the transformation against the sample content
6. Verify the output renders correctly in CKEditor 5 with no data loss
7. Check for inconsistencies or edge cases in the sample (malformed markup, nested footnotes, etc.)
8. Insert Tibetan Unicode sample content into the D11 test database and verify it renders correctly
9. Confirm the D11 database is configured as `utf8mb4`

### Pass Criteria
- Footnote markup format for both CKEditor 4 and 5 is fully documented
- A deterministic transformation function handles all patterns found in the sample
- Transformed content renders correctly in CKEditor 5
- Tibetan Unicode content round-trips through the D11 database without corruption
- Edge cases are documented and accounted for

### Fail Criteria and Response
| Finding | Response |
|---|---|
| CKEditor 4 markup is inconsistent across the corpus | Plan a content cleanup pass in D7 before migration; script to normalize footnote markup |
| `footnotes 4.x` uses a fundamentally different storage model | Evaluate alternative footnote modules for D11; assess impact on existing content |
| Nested or complex footnote patterns cannot be transformed deterministically | Manual content review required for affected nodes; scope the cleanup effort |
| Tibetan Unicode corrupted in D11 database | Verify utf8mb4 charset on database and connection; investigate collation settings |

### Outputs
- Documented CKEditor 4 and CKEditor 5 footnote markup formats
- Transformation function (to be incorporated into the migration plugin)
- Inventory of edge cases and any content requiring manual cleanup
- Tibetan Unicode compatibility confirmed
- Go/no-go recommendation for Phase 4a

---

## Spike 5 — bibcite for the Sources Site

**Time-box:** 1–2 days  
**Priority:** Medium — blocks Phase 4 Sources work only  
**Runs in parallel with:** Spikes 3, 4, and 6

### Theory
The `bibcite` (Bibliography & Citation) module on D11 supports all reference types currently in use on the Sources site, handles the `zotero_feed` workflow, and provides a viable migration path from D7 `biblio`.

### Background
The D7 Sources site uses the `biblio` contributed module (no D11 port) plus a `zotero_feed` content type for managing Zotero library connections. The recommended replacement is `bibcite`, which uses CSL citation styles. However, bibcite's feature set and D11 release status need to be verified against current Sources usage, including how it handles Zotero feed management, before committing to it as the migration target.

### Work
1. Confirm a D11-compatible release of `bibcite` exists on drupal.org (3.0 beta expected)
2. Install `bibcite` on the D11 test instance
3. Query the D7 Sources database to inventory all reference types in use and their counts
4. Verify each reference type has a `bibcite` equivalent bundle
5. Identify the three to five most-used citation styles on the Sources site
6. Confirm those styles are available in bibcite's CSL library
7. Investigate how `bibcite` handles Zotero feed management (equivalent to `zotero_feed` content type)
8. Attempt a test import from the Zotero API (verify credentials are current)
9. Compare a sample bibcite citation output against the equivalent D7 biblio output

### Pass Criteria
- `bibcite` has a stable or beta D11 release
- All reference types in current use have `bibcite` equivalents
- Required citation styles are available
- A viable Zotero feed management approach exists in bibcite
- Zotero API import works with current credentials
- Citation output is comparable to current D7 output

### Fail Criteria and Response
| Finding | Response |
|---|---|
| `bibcite` has no D11 release | Evaluate alternatives (custom entity type, Scholarly Communications module) |
| A critical reference type is missing | Assess whether a custom bundle can fill the gap; evaluate effort |
| No equivalent for `zotero_feed` workflow | Design a custom config entity or Feeds-based approach |
| Zotero API credentials are expired | Obtain current credentials from Sources site admin |
| Citation output differs significantly | Identify correct CSL style; document formatting differences for stakeholder review |

### Outputs
- Confirmed D11 compatibility status for `bibcite`
- Inventory of current reference types and their `bibcite` mappings
- Zotero feed management approach documented
- Test Zotero import result
- Go/no-go recommendation for Phase 4d

---

## Spike 6 — API Compatibility for React Application

**Time-box:** 1–2 days  
**Priority:** High — blocks Phase 5 and cutover  
**Runs in parallel with:** Spikes 3, 4, and 5  
**Requires:** Coordination with Than Grove (React app)

### Theory
A clear strategy exists for preserving API compatibility between the current multi-site D7 API endpoints and the consolidated D11 single-instance, without breaking the React application that consumes them.

### Background
Each Mandala site currently exposes JSON APIs (consumed by an external React application) and AJAX APIs (used internally) keyed by node ID. In a single D11 instance, the site-specific subdomains (`av.mandala.`, `images.mandala.`, `sources.mandala.`, `texts.mandala.`) go away. The React application is currently hardcoded to these per-site subdomain URLs. Three options exist — preserving subdomains as aliases, updating the React app, or using redirects — and the choice affects Terraform ALB configuration and whether the React app needs changes.

### Work
1. Contact Than Grove: confirm React app ownership and assess changeability
2. Hit all eight live D7 API endpoints with sample node IDs; document the exact JSON and HTML response structure for each content type:
   - AV: `/api/v1/media/node/{nid}.json` and `/services/node/ajax/{nid}`
   - Images: `/api/json/{nid}` and `/api/ajax/{nid}`
   - Sources: `/sources-api/json/{nid}` and `/sources-api/ajax/{nid}`
   - Texts: `/shanti_texts/node_json/{nid}` and `/shanti_texts/node_embed/{nid}`
3. For each response, document: field names, data types, nested structure, any computed fields
4. Identify which fields come from content vs. KMaps vs. Solr
5. Evaluate the three URL strategy options (see Critical Path doc §6) against React app changeability
6. Agree on a strategy with the React app team

### Pass Criteria
- All eight API response formats are fully documented
- A URL strategy is agreed upon between Drupal and React teams
- The agreed strategy is technically feasible in D11 and in Terraform ALB config
- The D11 API implementation approach is clear (REST resource, JSON:API, or custom controller per endpoint)

### Fail Criteria and Response
| Finding | Response |
|---|---|
| React app cannot be changed (no owner, no source access) | Must use subdomain aliases (Option B) — coordinate with Dave Goldstein on ALB config |
| D7 API response includes fields that are computationally expensive to replicate | Identify which fields can be cached vs. computed on demand; design accordingly |
| Node IDs will change during migration (D7 nid ≠ D11 nid) | Implement a nid mapping table; expose old IDs via a field on D11 nodes; update API to accept either |
| API response structure is inconsistent across nodes of the same type | Document exceptions; handle in D11 controller logic |

### Outputs
- Full documentation of all eight D7 API response formats
- Agreed URL strategy (Option A, B, or C)
- Node ID continuity decision (preserve D7 nids or maintain mapping)
- D11 API implementation approach per endpoint
- Go/no-go recommendation for Phase 5

---

## Spike 7 — Kaltura AV Integration on Drupal 11

**Time-box:** 2–3 days  
**Priority:** High — blocks Phase 4 AV site work  
**Prerequisite:** Working D11 instance from Spike 1  
**Requires:** Kaltura API credentials (from AV site admin); access to D7 AV database for entry ID audit

### Theory
The D7 Kaltura module's two responsibilities — uploading AV content to Kaltura and embedding the Kaltura player in node display — can both be satisfied on D11 using Drupal core Media, a D11-compatible Kaltura contrib module or custom Media Source plugin, and the Kaltura API v3.

### Background
The D7 AV site uses the `kaltura` contributed module to post video/audio content to the Kaltura platform and to embed the Kaltura player in node display using the stored entry ID. Drupal 11 replaces the D7 file and media handling model with the core Media system; a D11 Kaltura integration must cover both the upload/ingest workflow and the player embed. Migration must preserve existing Kaltura entry IDs without re-uploading content.

### Work
1. Survey drupal.org and Kaltura Community for a maintained D11 Kaltura module
2. Prototype Kaltura player embed on a D11 node (oEmbed or custom MediaSource plugin)
3. Prototype the upload/ingest workflow: Drupal → Kaltura API → entry ID stored on Media entity
4. Audit D7 AV database: field storing entry IDs, node count, edge cases
5. Document migration strategy (D7 AV nodes → D11 nodes referencing Kaltura Media entities, no re-upload)
6. Confirm Kaltura partner/credential model for consolidated single-instance D11

### Pass Criteria
- Clear D11 integration path exists for both upload and playback
- Working player embed prototype for a known entry ID
- Upload workflow demonstrated or concretely planned
- Migration strategy defined; no re-upload required
- Kaltura credential model confirmed for single-instance

### Fail Criteria and Response
| Finding | Response |
|---|---|
| No maintained D11 Kaltura module | Build minimal custom Media Source plugin + upload widget using Kaltura API v3 |
| No Kaltura oEmbed endpoint | Use iFrame/JS embed via custom MediaSource plugin |
| Upload workflow requires significant custom work | Scope dedicated upload module sprint; use Kaltura portal as interim workflow |
| Migration requires re-uploading all media | Escalate to David Germano — out of scope without stakeholder decision |
| Kaltura API auth model changed | Obtain current application token credentials from AV site admin |

### Outputs
- Inventory of D11-compatible Kaltura modules and maintenance status
- Documented Kaltura API v3 authentication and embed approach
- Working D11 Kaltura player embed prototype
- Upload workflow prototype or implementation plan
- Migration strategy for existing entry IDs
- Credential/partner model recommendation
- Go/no-go recommendation for Phase 4 AV site work

---

## Spike 8 — reindeer_x Consolidation as Managed Sync Subsystem

**Time-box:** 2–3 days  
**Priority:** High — fixes a known production race condition; independent of D11 Drupal work  
**Lead:** Yuji Shinozaki

### Theory
The `synch`/`synchandler` shell+Perl pipeline (clsync + rclone) can be replaced
with Node.js equivalents inside the existing `reindeer_x` process, and `reindeer_x`
can subscribe to AWS SQS events directly to eliminate the UDP trigger and the
race condition that causes silent stale kmassets writes.

### Background
Currently three separate runtime components handle kmassets sync. The UDP ping
from KMaps Rails fires before the ECS kmterms Solr update completes, causing
`reindeer_x` to read stale data and write stale kmassets entries silently.
This spike consolidates all sync responsibilities into `reindeer_x` and replaces
the UDP trigger with SQS subscription.

See [docs/deferred/solr-sync-architecture-d11.md](../deferred/solr-sync-architecture-d11.md).

### Work

**Part A — Fold synchandler in:** Add `chokidar` + `@aws-sdk/client-s3`;
implement `sync/fileWatcher.js` to watch Drupal solrdocs directories and upload
to S3 using AWS SDK. Retire `synch`, `synchandler`, and `rclone`.

**Part B — SQS trigger:** Add `@aws-sdk/client-sqs`; implement `queue/sqsConsumer.js`
to long-poll a configurable queue, trigger the existing sync path on receipt,
and acknowledge messages on success.

**Part C (stretch) — SNS reporting:** Publish sync completion/failure summaries
to SNS topics for downstream visibility.

### Pass Criteria
- chokidar detects file events and AWS SDK uploads to S3 correctly
- SQS consumer receives a test message and triggers a sync job (visible in Arena UI)
- No shell, Perl, or rclone dependency at runtime

### Fail Criteria and Response
| Finding | Response |
|---|---|
| chokidar misses events in Docker volume mounts | Use `usePolling: true` or switch to HTTP POST inbound from Drupal |
| SQS queue not available for testing | Use LocalStack; defer real integration |
| ECS task can't publish completion event yet | Keep UDP as interim; document gap |

### Outputs
- Updated `kmaps-solr-sync/` with fileWatcher and sqsConsumer modules
- `synch`, `synchandler`, `rclone` removed from Docker image
- Updated `.env.dist` with new env vars
- Go/no-go on retiring the shell/Perl layer

---

## Decision Gate: Week 4 Review

After all six initial spikes are complete, hold a review session with the following agenda:

1. **Spike 1 (KMaps):** Pass → proceed to Phase 2 implementation. Fail → resolve field architecture before proceeding.
2. **Spike 2 (Solr):** Pass → proceed with field mapping documented. Fail → build mapping layer before Phase 2 Solr work.
3. **Spike 3 (Group):** Pass → proceed to Phase 3 design. Fail → assess impact on collections model.
4. **Spike 4 (Footnotes + Tibetan):** Pass → migration plugin can be written. Fail → schedule content cleanup sprint and/or database charset fix.
5. **Spike 5 (bibcite):** Pass → proceed to Phase 4d. Fail → select alternative approach for Sources.
6. **Spike 6 (APIs):** Pass → URL strategy agreed, proceed to Phase 5 design. Fail → escalate React app ownership question before proceeding.
7. **Spike 7 (Kaltura):** Pass → proceed to Phase 4 AV site work. Fail → assess custom module scope and timeline impact.

**If all six initial spikes pass:** The implementation plan and timeline estimates hold. Proceed to Phase 0.  
**If one or two fail:** Resolve the failures in targeted follow-on spikes before proceeding to affected phases. Timeline extends by the resolution time.  
**If three or more fail:** Reassess the overall plan and timeline before committing to implementation.

---

## Spike Environment Setup

All spikes except Spike 2 can be run against the local DDEV environment in this repo:

```bash
ddev start
ddev drush site:install
```

Spike 2 additionally requires network access to the existing Solr endpoint at `mandala-solr-proxy.internal.lib.virginia.edu`. Run Spike 2 from within the UVA network or VPN.

Spike 6 requires access to the live D7 sites and coordination with Than Grove (React app). Both can be done remotely.
