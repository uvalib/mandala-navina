# reindeer_x has no ECR repo or pipeline — it is hand-built on dev-0, which D11 replaces

**Area:** reindeer_x / deployment / CI-CD / ECR / cutover
**Raised during:** Session 2026-07-14 (1b.1 part 4 — writing the D11 app pipeline)
**Jira:** (add when available)
**Priority:** High — the current reindeer_x deployment does not survive the dev-0 replacement decided in the part-4 scope doc §5.1

## What we found

`reindeer_x` (ADR 006/007) is **running on `mandala-drupal-dev-0` today** (9000/tcp),
but nothing about that deployment is reproducible:

- **No ECR repository.** `aws ecr describe-repositories` returns **zero** repositories
  matching `mandala` in the staging account — reindeer_x has none, and neither did the
  D11 app until `global/ecs-registry/variables.tf` gained `mandala-drupal`.
- **No pipeline.** `aws_cicd/pipelines/` has no reindeer_x entry.
  `mandala/pipelines/` contains only `mandala-ingest-production-deploy`.
- **No build/deploy specs.** The `uvalib/mandala-reindeer_x` repo has no
  `buildspec.yml` or `deployspec.yml` at all — only `docker-compose.reindeer_x.yml`,
  `Dockerfile.reindeer_x` and `Dockerfile.redis`, building a local image named
  `reindeer_x` (container `reindeer_x`, plus its own redis container `workqueue`).
- **No Ansible deploy playbook** in terraform-infrastructure.

So it is deployed the legacy way: a hand-placed git checkout on the box plus
`docker compose build && up` — the same pattern the scope doc's §6 audit found for the
other legacy components (hand `.env` secrets, uncommitted compose drift).

## Why this is now urgent

Scope doc **§5.1 decided D11 replaces the `mandala-dev` (dev-0) instance IN PLACE.**
reindeer_x lives on that instance, built by hand, with nothing in git or ECR to
rebuild it from. **Replacing dev-0 destroys the only copy of that deployment.** §6
already requires accounting for every unique component before cutover; this is one,
and it currently has no reproducible path back.

## reindeer_x is NOT droppable — do not treat this as a chance to delete it

Checked, because "we're rebuilding anyway" invites the question:

- **ADR 013 explicitly carves kmterms out.** *"kmterms is unchanged. The `kmterms`
  index is owned and maintained by the Rails KMaps application (Andres Montano).
  Drupal queries it for KMaps autocomplete but does not write to it."* So making
  Drupal the source of truth (ADR 013, superseding ADR 004) did **not** absorb
  reindeer_x's premise — Drupal is authoritative for content and access, not taxonomy.
- **`mandala_kmassets_sync` (1a.8) cannot take over.** It writes *Drupal nodes* →
  kmassets. reindeer_x writes *Rails-owned kmterms* → kmassets shadow docs. Different
  source, different population, and Drupal is not downstream of kmterms changes —
  those originate in the Rails app.
- **Scale confirms it.** The shadow population is ~79,174 subjects and ~68,790 places
  — every kmterm. Drupal only knows the ~55,553 terms actually referenced by nodes
  (`field_kmap_terms`). Drupal could not generate the rest even in principle.
- Dropping the shadow pattern would mean **superseding ADR 006** and paying its stated
  costs: a separate query path for taxonomy vs. content, two document schemas in the
  React front-end, and different routing for subject/place/term pages. That is a
  front-end architecture decision (Than + Andres), not a deployment simplification.

## Related: the terraform ALB targets for it are already stale

`mandala/drupal/staging/variables.tf` declares:

- `rdx_service_port = 9001` → `alb-mandala-drupal-staging-rdx-0`, health check `/`
- `index_service_port = 8765` → `alb-mandala-drupal-staging-idx-0`, health check
  `/solr/kmassets/status`

But reindeer_x listens on **9000** on the live box. So the `rdx` target group points at
a port nothing serves. Reconcile at cutover — and settle the **push-vs-pull** question
with Than and Andres at the same time (already flagged in the scope doc §6).

## What needs to happen

Mirroring what was just done for the D11 app (commit `f7ec9604d`):

1. **Add `buildspec.yml` / `deployspec.yml`** to `uvalib/mandala-reindeer_x`.
2. **Append its repo name to `global/ecs-registry/variables.tf`** — **at the END of
   the list**. `registry.tf` uses `count = length(var.repo_names)` with
   `element(var.repo_names, count.index)`, so repositories are keyed by list position;
   inserting mid-list renumbers every later repo and destroys/recreates them.
3. **Create `aws_cicd/pipelines/mandala-reindeer_x/`** from the `drupal-dsf` shape —
   five files, only `application` / `container_image` / `source_repo` and the backend
   state key differ. NB the module derives every name from `var.application` alone, so
   pick a name that will not collide with a future production pipeline.
4. **Write an Ansible deploy playbook** for the container (and decide whether its
   `workqueue` redis stays a private container or joins the D11 Redis work — see
   `redis-enterprise-store-location.md`).
5. **Reconcile the rdx/index ALB ports** against what the service actually serves.

ADR 007 stands: this is reindeer_x's **own** pipeline, not a stage in
`mandala-drupal`'s — the whole point of that ADR is the differing deployment cadence.

## Cross-references

- [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) — the shadow pattern
- [ADR 007](../adr/007-reindeer-x-independent-service.md) — independent deployable
- [ADR 013](../adr/013-drupal-source-of-truth-solr-client-compatibility.md) — the kmterms carve-out
- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — the same gap for the D11 app
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5.1 (replace in place), §6 (component audit)
