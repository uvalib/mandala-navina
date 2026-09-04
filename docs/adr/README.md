# Architecture Decision Records

Significant architectural decisions for the Mandala Drupal 11 rebuild are recorded here.

Each ADR is numbered sequentially and immutable once accepted — superseded decisions
get a new ADR that references the old one, rather than editing in place.

| # | Title | Status |
|---|-------|--------|
| [001](001-monorepo.md) | Consolidate all components into a single monorepo | Accepted |
| [002](002-drupal-11.md) | Target Drupal 11 (not Drupal 10) | Accepted |
| [003](003-terraform-ansible-deployment.md) | Replace Aegir/docker-compose with Terraform + Ansible + AWS CodePipeline | Accepted |
| [004](004-solr-source-of-truth.md) | Treat existing Solr infrastructure as source of truth; defer Solr refactor | Accepted |
| [005](005-single-site.md) | Redesign as Drupal single-site rather than multisite | Accepted |
| [006](006-kmterms-in-kmassets-shadow-pattern.md) | Maintain kmterms-in-kmassets shadow entries for subjects, places, and terms | Accepted |
| [007](007-reindeer-x-independent-service.md) | reindeer_x lives as an independent deployable service, not in the monorepo | Accepted |
| [008](008-mvp-migrate-not-improve.md) | MVP scope is migrate, not improve | Accepted |
| [009](009-migration-sequencing-strategy.md) | Migration sequencing: Images pilot → mob → parallel tracks → AV last | Accepted (Phase 3 ordering superseded by 018) |
| [010](010-adr-008-scope-clarification.md) | Clarify scope of "migrate, not improve": user-facing features, not internal architecture (refines ADR 008) | Accepted |
| [011](011-group-collections-inheritance.md) | Group collections inheritance: entity-reference nesting + custom hooks (Option D); lands in Sprint 1 Step 1b | Accepted |
| [012](012-ddev-production-db-engine.md) | Local DDEV runs the production DB engine (MySQL 8.4), not DDEV's default MariaDB, for migration/collation fidelity | Accepted |
| [013](013-drupal-source-of-truth-solr-client-compatibility.md) | Drupal is the source of truth; Solr/client compatibility is an essential active requirement (supersedes ADR 004) | Accepted |
| [014](014-hybrid-solr-proxy-design.md) | Hybrid Solr proxy: Drupal writes Redis visibility tokens; lightweight standalone proxy reads them; D11 proxy forked into monorepo | Accepted |
| [015](015-editorial-access-model-global-content-editor.md) | D11 editorial access model: global non-admin `content_editor` (shanti_editor equivalent), assigned only to former shanti_editors; per-group editors deferred to Group roles | Accepted |
| [016](016-public-url-structure-single-host.md) | Public URL structure: one host, asset-type-namespaced paths (`/images/{nid}`, `/audio/{nid}`, `/video/{nid}`), flat `/api/json/{nid}`; legacy URLs redirected via `field_legacy_nid` | **Proposed** |
| [017](017-legacy-identity-composite-key.md) | Legacy identity is a composite key: `field_legacy_site` + `field_legacy_nid`; discriminator is the D7 **site** (nid space), not the asset type; kmassets `service` vocabulary | Accepted |
| [018](018-av-track-starts-in-parallel-not-strictly-last.md) | AV migration track starts now, in parallel with Texts/Sources, driven by Than's 2-week absence and Yuji's AV fit — not a revision of AV's risk profile (supersedes ADR 009's Phase 3 ordering only) | Accepted |
