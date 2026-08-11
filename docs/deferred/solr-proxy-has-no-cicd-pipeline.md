# solr-proxy has no CI/CD pipeline

**Area:** deployment / CI-CD / ECR / solr-proxy / ADR 014
**Raised during:** Session 2026-08-11 (CI/CD pipeline inventory)
**Jira:** (add when available)
**Priority:** High — 1b.1 part 4 needs the proxy actually running to validate
ADR 014's hybrid design end-to-end
**Status:** **DECIDED (2026-08-11, Yuji) — solr-proxy needs a full CI/CD pipeline.**
Production explicitly out of scope for now.

## What we found

Auditing all D11-related CI/CD live in the staging AWS account (2026-08-11) found
exactly one working pipeline — `uva-mandala-drupal-codepipeline`, for `drupal/`
only. `solr-proxy/` has **none of it**:

- No ECR repository (`aws ecr describe-repositories` — zero `mandala` repos other
  than `uvalib/mandala-drupal`)
- No CodeBuild project, no CodePipeline entry
- No `buildspec.yml`/`deployspec.yml` in `solr-proxy/` (it has a `Dockerfile` and
  `docker-compose.yml` for local dev only — see `solr-proxy/README.md`)
- No Ansible deploy playbook in `terraform-infrastructure`

Unlike `reindeer_x`, there is also **no legacy hand-built deployment to reconcile
with** — the D11 proxy (forked from `shanti-uva/mandala-solr-proxy` per ADR 014)
has never been run outside local `docker compose up`. The D7 proxy
(`mandala-solr-proxy` container on dev-0) is a different codebase serving the D7
sites unchanged and is explicitly out of scope (see the proxy's own README).

## Why it's ready to build now

Its dependencies are already merged, per 1b.1 parts 1–3 (ADR 014):

1. ✅ The fork itself — proxy code in the monorepo, `$OAUTH_ROOT` pointed at D11,
   `Searcher.php` reads `mandala_solr_fq:{uid}` from Redis instead of querying Solr.
2. ✅ `simple_oauth` installed/configured in D11; OAuth2 authorization-code flow
   verified live (`sub:"2"` = Drupal uid).
3. ✅ `mandala_solr_visibility` module writes/deletes the Redis token on
   login/logout/Group membership change, verified end-to-end.

So the proxy's runtime dependencies exist — what's missing is purely the deployment
mechanism. This is the cleanest of the three gaps found in the 2026-08-11 audit
(compare [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md),
under review, and [s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md](s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md),
deferred).

## What needs to happen

Modelled on `aws_cicd/pipelines/mandala-drupal/` — now the proven, live in-repo
reference (closer and more current than `drupal-dsf`, which mandala-drupal itself
was originally modelled on):

1. `solr-proxy/pipeline/buildspec.yml` / `deployspec.yml` (or a `solr-proxy/`-scoped
   equivalent — decide whether it lives alongside `drupal/pipeline/` or gets its
   own top-level `pipeline/` the way `drupal/` does)
2. ECR repository for the proxy image
3. `aws_cicd/pipelines/mandala-solr-proxy/` (or similar — name it so it won't
   collide with a future production pipeline, per the `var.application`-only
   naming the codepipeline module uses)
4. Ansible deploy playbook + terraform wiring — decide how/where the proxy runs
   relative to the Drupal container (co-located on the same instance vs. its own
   service) and how it fits the existing `index` (8765) ALB target pattern seen
   for the D7 proxy
5. Trigger-path filtering (`trigger_paths`) scoped to `solr-proxy/**` only, same
   pattern as `mandala-drupal`'s filter on `drupal/**`/`package/**`/`pipeline/**`,
   so unrelated monorepo commits don't fire it

**Scope note (2026-08-11, Yuji): production is explicitly out of scope for now.**
Staging/dev only, same as the existing `mandala-drupal` pipeline.

## Cross-references

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the hybrid proxy design this deploys
- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — RESOLVED; the
  reference pipeline to model this on
- `solr-proxy/README.md` — what changed from the D7 proxy, the Redis contract
- `terraform-infrastructure/aws_cicd/pipelines/mandala-drupal/` — the reference shape
