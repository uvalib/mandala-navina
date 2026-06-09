# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

Monorepo for the Mandala Digital Library platform at the University of Virginia Library. This is a Drupal 10 rebuild of the legacy Drupal 7 multi-site installation, consolidating five sites (AV, Images, Sources, Texts, Mandala Home) into a single Drupal instance deployed on AWS via Terraform + Ansible + CodePipeline.

See `/docs/` for architecture and planning documentation.

## Repository Structure

```
mandala/
├── drupal/           # Drupal 10 application
│   ├── web/          # Drupal webroot (Composer-managed)
│   │   ├── modules/custom/   # Custom modules (committed here)
│   │   └── themes/custom/    # Custom themes
│   ├── config/sync/  # Drupal CMI config exports — committed to git
│   └── composer.json
├── solr-proxy/       # Solr authentication proxy service
├── s3-sync/          # S3 file sync utilities
├── package/          # Production Dockerfile
├── pipeline/         # AWS CodeBuild specs (buildspec.yml, deployspec.yml)
└── scripts/          # Local dev helper scripts
```

## Local Development

Uses DDEV. From the repo root:

```bash
ddev start                    # Start environment (first run installs Composer deps)
ddev drush site:install       # Fresh Drupal install
ddev drush config:import      # Import CMI config
ddev drush cache:rebuild      # Clear caches
./scripts/rebuild.sh          # Full local rebuild
```

Site URL: https://mandala.ddev.site

## Common Drush Commands

```bash
ddev drush cr                 # Cache rebuild
ddev drush cim                # Config import
ddev drush cex                # Config export
ddev drush updb               # Run DB updates
ddev drush en <module>        # Enable module
ddev drush pmu <module>       # Uninstall module
```

## Custom Modules

All custom modules live in `drupal/web/modules/custom/`. Key modules:

- `shanti_kmaps_fields` — KMaps term reference field type (D10 port — spike work)
- `shanti_kmaps_admin` — KMaps server configuration
- `solr_proxy` — Solr authentication proxy

## Deployment

Deployment follows the UVA Library standard pattern (same as drupal-dsf, drupal-library):
- `pipeline/buildspec.yml` — Docker image build → ECR push
- `pipeline/deployspec.yml` — Terraform + Ansible deploy
- Terraform in `uvalib/terraform-infrastructure/mandala/drupal/`

## Related Repositories (legacy — being consolidated here)

- `mandala-drupal` — D7 source codebase
- `mandala_drupal_docker` — legacy Aegir/Docker deployment
- `mandala-solr-proxy` — being merged into `solr-proxy/`
- `mandala_s3_synch` — being merged into `s3-sync/`
