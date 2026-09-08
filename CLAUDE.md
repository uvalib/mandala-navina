# CLAUDE.md

This file provides guidance to Claude Code when working with this repository.

## Project Overview

Monorepo for the Mandala Digital Library platform at the University of Virginia Library. This is a Drupal 11 rebuild of the legacy Drupal 7 multi-site installation, consolidating five sites (AV, Images, Sources, Texts, Mandala Home) into a single Drupal instance deployed on AWS via Terraform + Ansible + CodePipeline.

## Session startup

At the start of every session, before doing any work:

1. Run `git status` then `git pull --ff-only` (in this repo directory) to make sure local is current — since sessions are driven by different team members on different machines, a stale local copy is a recurring source of working from outdated context. If the pull isn't a fast-forward, stop and surface it rather than resolving it silently.
2. Read these files to orient yourself:
   1. `docs/adr/README.md` — index of all architectural decisions; read any ADR that seems relevant to the task
   2. `docs/spikes/README.md` — spike status; read the doc for any spike being continued or referenced
   3. `docs/deferred/README.md` — known gaps and deferred work
   4. `docs/session-logs/` — scan for the most recent log in particular; it may be an agenda or handoff (e.g. drafted by one driver for another to pick up) with open decisions or context not yet reflected elsewhere
3. **Check the local database against dev-0**, and check that config is in sync. dev-0 is the canonical shared state; a local DB that has quietly drifted from it produces work that passes locally and fails for everyone else. Three checks, cheap enough to run every time:
   1. `ddev drush config:status` — must say *"No differences between DB and sync directory."* Anything else means your local DB is behind (or ahead of) the committed config.
   2. Compare local content/identity counts against dev-0 — nodes by type, `users_field_data`, `groups`, `authmap`, and the `*-group_membership` relationship counts. A local DB with no real users cannot exercise access or permissions meaningfully, and that is easy to miss.
   3. Confirm `drupal/config/sync` is current **on GitHub**, not just locally — it is the shared contract, and it must be committed and pushed, not sitting dirty in someone's working tree.

   If any of these drift, run `./scripts/update-db-from-remote.sh dev` to rebase the local DB onto dev-0 (it dumps, imports, then reasserts committed config on top). **This is destructive** — it replaces the local DB, so take a `ddev snapshot` first if you have local-only test content worth keeping. Skip this whole step only when doing migration *development*, where the local DB is deliberately scratch (see the script's header).

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
- **This repo is public.** Almost everything belongs here anyway — ADRs, spikes, deferred
  notes and session logs are public by design, and over-classifying hides work from the
  team. But material that would hand a stranger a working recipe against a live, unfixed
  system goes in one of two private docs repos instead (`uvalib/mandala-legacy-docs`,
  `uvalib/mandala-navina-docs`; each carries an identical `CONVENTION.md`). See
  [docs/non-public-documentation.md](docs/non-public-documentation.md). When writing here
  about something tracked privately: **say that a problem exists and who to ask, never
  what it is** — and check anything that *references* it, not just the note itself.

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
