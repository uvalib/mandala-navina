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
