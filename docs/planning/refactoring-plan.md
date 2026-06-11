# Mandala Platform Refactoring Plan

**Project:** Mandala Digital Library — University of Virginia Library  
**Date:** June 2026  
**Status:** Planning

---

## Team

| Name | Organization | Role |
|---|---|---|
| Carla Arton | UVA Library | Project Manager / Coordinator |
| Yuji Shinozaki | UVA Library | Lead Architect & DevOps |
| Xiaoming Wang | UVA Library | Software Engineer & DevOps |
| Than Grove | CSC | Software Engineer; original D7 developer (texts, collections, APIs); React front-end / KMaps React app |
| David Germano | UVA Religious Studies | Director, Mandala Project |
| Andres Montano | Casa Tibet Guatemala | KMaps Engineer (Rails KMaps application) |
| Dave Goldstein | UVA Library | Director, Cloud Infrastructure |

---

## Executive Summary

The Mandala digital humanities platform is undergoing a comprehensive modernization over summer/fall 2026. The current system is built on Drupal 7 (released 2011, end-of-life 2025) and managed through an Aegir hosting control panel — a deployment mechanism that has been superseded by standard cloud infrastructure practices. This refactoring will upgrade Mandala to Drupal 11, consolidate its six separate sites into a single application, and bring its infrastructure in line with the deployment patterns used across the rest of the UVA Library's web properties.

> **Note on Drupal version:** Drupal 11 is the confirmed target. See [ADR 002](../adr/002-drupal-11.md).

---

## Background: Current State

### What Exists Today

Mandala is currently six separate Drupal 7 websites sharing a common codebase:

| Site | URL | Purpose |
|---|---|---|
| Mandala Home | mandala.library.virginia.edu | KMaps explorer and Stand-Alone Projects hub |
| AV | av.mandala.library.virginia.edu | Audio and video (Kaltura) |
| Images | images.mandala.library.virginia.edu | Images (IIIF image server) |
| Sources | sources.mandala.library.virginia.edu | Bibliography and sources (Zotero) |
| Texts | texts.mandala.library.virginia.edu | Scholarly texts |
| Visuals | visuals.mandala.library.virginia.edu | Visualizations (deprecated) |

All sites share a common user database and a taxonomy system called **KMaps** — a centralized gazetteer of subjects, places, and terms maintained in Ruby on Rails applications and indexed in Solr (`kmterms` core). Drupal queries Solr to display KMaps content; it does not own that data.

Each site exposes JSON and AJAX APIs per asset, consumed by an external **React application** and internally across the Mandala platform. These API contracts must be preserved through the migration.

### Current Infrastructure

The application is deployed using **Aegir**, a Drupal-based hosting control panel, running inside a Docker container managed by a custom `docker-compose` configuration. Surrounding services — including Solr search (with two indexes: `kmassets` and `kmterms`) and background content ingestion pipelines — have already been migrated to the modern AWS/Terraform infrastructure used by the rest of the Library. The Drupal application itself has not.

The codebase spans four separate Git repositories:
- **mandala-drupal** — the Drupal application (30 custom modules, 232 contributed modules)
- **mandala_drupal_docker** — the Docker/Aegir deployment wrapper
- **mandala_s3_synch** — S3 file synchronization utilities
- **mandala-solr-proxy** — Solr search proxy service

---

## Why Refactor Now

1. **Drupal 7 is end-of-life.** Community and security support ended in January 2025. Running a public-facing application on an unsupported platform is a security liability.

2. **Aegir is obsolete.** Aegir solved a problem (managing multiple Drupal sites on one server) that Docker and AWS cloud infrastructure now solve more simply and reliably. It adds operational complexity with no remaining benefit.

3. **Infrastructure misalignment.** The Mandala Drupal application is the only UVA Library web property not using the standard EC2 + Docker + Terraform + Ansible + AWS CodePipeline deployment model. This creates a maintenance burden and makes it impossible to apply shared operational practices.

4. **Multi-site complexity without benefit.** The six separate sites share users, content taxonomy, and codebase. The separation creates deployment friction and cross-site complexity (e.g., collections that span sites require special "asset link" placeholder nodes). A single Drupal instance better reflects the application's actual architecture.

---

## Goals

1. **Upgrade to Drupal 11**
2. **Consolidate six sites into one Drupal instance** — eliminating multi-site complexity
3. **Replace Aegir with standard AWS deployment** — EC2 + Docker + Terraform + Ansible + CodePipeline
4. **Consolidate four repositories into one monorepo** — the application, sync utilities, and proxy are one system and should be managed as one
5. **Preserve all existing content and functionality** (excluding the deprecated Visuals site)
6. **Preserve API compatibility** — JSON and AJAX APIs consumed by the React application must continue to function

---

## What Is Not Changing

- **Solr** — The existing Solr infrastructure (master/replica architecture, `kmassets` and `kmterms` indexes, ECS ingest pipelines) is already on the modern platform and will not be refactored as part of this project
- **KMaps external services** — The KMaps taxonomy servers (subjects, places, terms) are Ruby on Rails applications maintained externally; they remain unchanged
- **React application** — The external React app that consumes Mandala's JSON APIs is out of scope; the APIs it depends on must continue to work
- **Content** — All existing content will be migrated to the new system

---

## Content Types

### Shared (all sections)
- **Collection** (`collection`) — Organic Groups-based content grouping
- **Subcollection** (`subcollection`) — One level of nesting; no sub-subcollections
- **Asset Link** (`asset_link`) — Cross-site surrogate node; goes away in single-instance D11
- **Article** (`article`), **Basic Page** (`basic_page`) — Standard Drupal types

### Mandala Home
- **Stand-Alone Project** (`stand_alone_project`) — Cross-site project aggregator

### AV
- **Audio** (`audio`)
- **Video** (`video`)
- **Source** (`source`) — Note: distinct from the Sources site

### Images
- **Shanti Image** (`shanti_image`)
- **Image Descriptions** (`image_descriptions`)
- **Agent** (`image_agent`)
- **External Classification** (`external_classification`)
- **External Classification Scheme** (`external_classification_scheme`)

### Sources
- **Biblio** (`biblio`)
- **Zotero Feed** (`zotero_feed`)

### Texts
- **Book Page** (`book`) — Note: Group and Post types exist but have no instances; ignore for migration

---

## Target Architecture

### Application

A single Drupal 11 site replacing all six current sites. Content that was previously separated by site becomes sections within one application, organized by content type and connected through the KMaps taxonomy system. API endpoints are consolidated onto a single domain with path-based routing preserved.

### Repository Structure

```
mandala/                        ← single monorepo
├── drupal/                     ← Drupal 11 application
├── solr-proxy/                 ← Solr proxy service
├── s3-sync/                    ← S3 sync utilities
└── buildspec.yml               ← AWS CodeBuild specification
```

### Infrastructure

Follows the established UVA Library pattern (same as library.virginia.edu and dsf.library.virginia.edu):

- **EC2 instances** running Docker containers, managed by Ansible
- **EFS** (Elastic File System) for persistent Drupal file storage
- **RDS** or managed MariaDB for the database
- **ALB** (Application Load Balancer) for traffic routing
- **ECR** (Elastic Container Registry) for Docker images
- **Terraform** for all AWS infrastructure provisioning
- **Ansible** for application deployment and configuration
- **AWS CodePipeline** for CI/CD: Build → Migrate → Deploy → Test

---

## Key Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Drupal version | **Drupal 11** | Confirmed — see [ADR 002](../adr/002-drupal-11.md) |
| Collections & access control | **Group module** | Modern D11 entity-based replacement for Organic Groups; recommended for new projects |
| Bibliography (Sources site) | **bibcite** | Only viable D11 replacement for the D7 `biblio` module; uses standard CSL citation styles |
| Authentication | **simplesamlphp_auth 4.1** | D11-compatible; Shibboleth/UVA NetBadge integration preserved |
| Solr integration | **search_api_solr 4.3.x** | Stable D11 support; connects to existing Solr infrastructure in read-only mode |
| Rich text footnotes | **Footnotes 4.x** | CKEditor 5 compatible (CKEditor 5 is required in D11) |
| API compatibility | **Preserved paths or redirects** | React app depends on current URL patterns; strategy TBD pending Spike 6 |

---

## Phased Implementation Plan

### Phase 0 — Repository and Infrastructure Setup
Establish the foundation before any application work begins.
- Create the `mandala` monorepo
- Initialize the Drupal 11 Composer project
- Build the base Dockerfile (PHP 8.3+ + Apache)
- Add `staging` and `develop` Terraform environments to `terraform-infrastructure/mandala/drupal/` (only `production` exists today)
- Configure AWS CodePipeline following the existing UVA Library module pattern
- Set up local development environment (DDEV)

### Phase 1 — Core Drupal and Authentication
A running, authenticated Drupal 11 instance with no content yet.
- Drupal 11 single-site installation
- Config Management (CMI) established from day one
- SimpleSAMLphp / UVA NetBadge authentication
- EFS file storage mount

### Phase 2 — KMaps Integration *(Critical Path)*
KMaps is the taxonomic spine of the entire application. Every content type depends on it. This phase must be complete and validated before content type work begins.
- Port `shanti_kmaps_admin` configuration module to D11
- Port `shanti_kmaps_fields` custom field type to the D11 Field API
- Port `solr_proxy` authentication module to D11
- Configure search_api_solr against the existing `kmassets` and `kmterms` Solr indexes (read-only)
- Validate that existing Solr queries return correct results
- Port `kmaps_views_solr` Views integration handlers

### Phase 3 — Collections Architecture
The Group module structure must be established before content types, as all content participates in collections.
- Group types: `collection`, `subcollection` (one level only — no sub-subcollections)
- Access control model (replacing Organic Groups roles)
- Stand-Alone Project as a collection variant (hub/satellite sync mechanism no longer needed in single instance)
- Asset Link retired — direct entity references replace cross-site surrogates

### Phase 4 — Content Types *(can be parallelized)*
Each section's content model, working from the established KMaps and collections foundation.

| Section | Content Types | Key Work |
|---|---|---|
| **Texts** | Book page | Book module, KMaps fields, CKEditor 5, footnotes, texts splitter |
| **Images** | Shanti Image, Image Descriptions, Agent, External Classification (×2) | IIIF integration, metadata fields |
| **Audio/Video** | Audio, Video, Source | Kaltura integration, transcripts |
| **Sources** | Biblio → bibcite, Zotero Feed | bibcite setup, Zotero API import, KMaps fields |

### Phase 5 — APIs
Reinstate all JSON and AJAX API endpoints consumed by the React application and used internally.
- JSON APIs: `/api/v1/media/node/{nid}.json` (AV), `/api/json/{nid}` (Images), `/sources-api/json/{nid}` (Sources), `/shanti_texts/node_json/{nid}` (Texts)
- AJAX APIs: equivalent embed endpoints per content type
- Validate each endpoint response against D7 output for the same node

### Phase 6 — Search and Browse UI
- Views for each content type
- Faceted KMaps browse interface
- Related assets across sections
- Full-text search

### Phase 7 — Theme
- Single Drupal 11 Bootstrap 5-based theme (replacing six separate D7 themes)
- Section-specific styling within one unified theme
- Custom shanticon font preserved

### Phase 8 — Content Migration
- Migrate module plugins for each content type (D7 source → D11)
- Custom migration plugin for KMaps field data
- Collections and group membership migration
- User account migration (one migration, since users are already shared)
- Biblio → bibcite reference migration

### Phase 9 — Cutover
- Full validation against a production data snapshot in the staging environment
- DNS cutover from Aegir-managed virtual hosts to new ALB routing rules
- API endpoint smoke tests against React application
- Decommission Aegir / docker-compose stack
- Archive legacy repositories

---

## Risks and Critical Path

### Critical Path
**Phase 2 (KMaps) is the highest-risk item and blocks all content work.** The D7 KMaps field type has a custom schema (`raw`, `id`, `header`, `domain`, `path`, `defids` columns) that must be faithfully ported to the D11 Field API. Any incompatibility discovered here needs to be resolved before content type development begins.

**Phase 3 (Collections/Group) blocks Phase 4.** The Group module architecture decisions determine how content is organized across all sections.

### Key Risks

| Risk | Impact | Mitigation |
|---|---|---|
| KMaps field API incompatibility in D11 | High — blocks all content work | Spike 1: prototype field port before committing |
| React app API URL contract broken by consolidation | High — breaks external application | Spike 6: audit API contracts and design URL strategy before cutover |
| Group module API instability | Medium — API marked beta | Spike 3: evaluate against collections requirements early |
| CKEditor 4 → 5 footnotes behavior change | Medium — affects Texts site | Spike 4: validate transformation before migration plugin work |
| bibcite feature gap vs. biblio | Medium — affects Sources site | Spike 5: audit bibcite against current Sources usage |
| Content migration data quality | Medium — D7 data may have inconsistencies | Run migration against staging data early; allow time for cleanup |

### Out of Scope
- Mandala Visuals site (deprecated — not migrated)
- Solr infrastructure refactoring
- KMaps external taxonomy servers (Ruby on Rails applications)
- Changes to the `kmassets` / `kmterms` Solr index schema
- React application changes (API consumers, not producers)

---

## Open Questions for Discussion

1. **API URL strategy:** The six sites currently expose APIs on separate subdomains. In a single D11 instance, what is the URL strategy — preserve old paths via routing rules, redirect, or update the React app to use new URLs? (See Spike 6)

2. **Kaltura contract:** Is the Kaltura video hosting contract (Partner ID: 381832) current and continuing? This affects the AV section scope.

3. **IIIF image server:** Is the existing IIIF server infrastructure staying as-is, or is that also being modernized as part of this effort?

4. **D11 upgrade timing:** Given D11 EOL in December 2026, at what point does the D11 → D11 upgrade begin? Immediately post-launch, or after a stabilization period?

5. **Staging environment:** The current `mandala/drupal/` Terraform only has a `production` environment. `staging` and `develop` environments should be created as part of Phase 0 — confirm with Dave Goldstein.
