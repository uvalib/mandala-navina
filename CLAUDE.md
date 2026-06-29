# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

Monorepo for the Mandala Digital Library platform at the University of Virginia Library. This is a Drupal 11 rebuild of the legacy Drupal 7 multi-site installation, consolidating five sites (AV, Images, Sources, Texts, Mandala Home) into a single Drupal instance deployed on AWS via Terraform + Ansible + CodePipeline.

## Session startup

At the start of every session, read these files to orient yourself before doing any work:

1. `docs/adr/README.md` — index of all architectural decisions; read any ADR that seems relevant to the task
2. `docs/spikes/README.md` — spike status; read the doc for any spike being continued or referenced
3. `docs/deferred/README.md` — known gaps and deferred work

This ensures all team members' Claude instances start from the same shared context regardless of who drove the previous session.

## Team workflow

Development is driven collaboratively — team members take turns leading sessions. Key practices:

- **One repo, one session.** Always open Claude Code from this directory. Never work on Mandala from a legacy repo directory.
- **Session end ritual.** Before closing a significant session:
  1. Flush any decisions to `docs/adr/`, findings to `docs/spikes/`, and deferred notes to `docs/deferred/`.
  2. Update the corresponding `.pages` file for every directory you added a doc to (`docs/adr/.pages`, `docs/spikes/.pages`, `docs/deferred/.pages`). New files are invisible in mkdocs until listed there. `docs/session-logs/.pages` uses `...` and self-updates.
  3. Run `scripts/save-session-log.py` for long planning or spike sessions.
  4. Refresh your local Claude memory so the next session doesn't start stale: update `project-mandala-state` (sprint/spike/ADR status, dates) and add or revise topic memories for anything decided this session, marking superseded framings as superseded. Note: memory is **per-machine and per-driver** — each lead refreshes their own; the committed `docs/` tree remains the team source of truth, and memory only mirrors it.
- **ADRs are immutable.** Once accepted, don't edit an ADR — write a new one that supersedes it.
- **Spikes over engineering.** Prove unknowns with the lightest possible demo before building production code.

## Team

- **Yuji Shinozaki** — Lead Architect & DevOps (UVA Library)
- **Xiaoming Wang** — Software Engineer & DevOps (UVA Library)
- **Carla Arton** — Project Manager / Coordinator (UVA Library)
- **Dave Goldstein** — Director, Cloud Infrastructure (UVA Library)
- **David Germano** — Director, Mandala Project (UVA Religious Studies)
- **Than Grove** — Software Engineer (CSC); original D7 developer on texts, collections, and APIs; React front-end / KMaps React app
- **Andres Montano** — Rails KMaps application (Casa Tibet Guatemala)

See `/docs/` for architecture and planning documentation.

## Related Services (independent repos)

- [`uvalib/mandala-reindeer_x`](https://github.com/uvalib/mandala-reindeer_x) — kmterms→kmassets sync service (Node.js, `reindeer_x` container); formerly `shanti-uva/kmaps-solr-sync`. Maintains the 1:1 shadow kmasset entries for `subjects`, `places`, and `terms` asset types so that KMaps taxonomy terms are discoverable as first-class assets in a single Solr index. See [ADR 006](docs/adr/006-kmterms-in-kmassets-shadow-pattern.md) and [ADR 007](docs/adr/007-reindeer-x-independent-service.md).

## Repository Structure

```
mandala/
├── drupal/           # Drupal 11 application
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

- `shanti_kmaps_fields` — KMaps term reference field type (Spike 1 — proven on D11)
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
- `shanti-uva/kmaps-solr-sync` — transferred to `uvalib/mandala-reindeer_x` (independent repo, not in monorepo)
