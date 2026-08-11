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

## What is and is not droppable — REFINED (Yuji, 2026-07-14)

> **Yuji is reviewing the need for rdx in general** (2026-07-14): rdx exists to pick
> up KMaps changes *in a timely fashion*, but KMaps data development has slowed to the
> point where nobody expects updates — which is why the broken rdx target went
> unnoticed. **Hold the pipeline/ECR work below until that review lands.**
>
> **The distinction that matters** (an earlier version of this note ran the two
> together):
> - **The kmassets shadow docs must exist** — this still holds, see below.
> - **A continuously-running, push-fed sync service must exist** — this does NOT
>   follow. If kmterms is static, the shadow docs already sit in Solr; the sync has
>   nothing to do. Retiring the always-on service is the **push-vs-pull** question
>   (§6, Than + Andres), *not* a supersession of ADR 006. The requirement is to keep
>   the *ability* to re-sync when KMaps changes — not necessarily a live endpoint.

Why the shadow docs themselves cannot simply be dropped, since "we're rebuilding
anyway" invites the question:

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
- Dropping the shadow pattern **entirely** — i.e. removing the docs from kmassets —
  would mean **superseding ADR 006** and paying its stated costs: a separate query
  path for taxonomy vs. content, two document schemas in the React front-end, and
  different routing for subject/place/term pages. That is a front-end architecture
  decision (Than + Andres), not a deployment simplification. **Retiring the always-on
  sync service is a different, much cheaper question** — see the box above.

## Related: the rdx ALB target is DOWN — in production as well as dev

> **Filed separately as [rdx-alb-target-unhealthy-in-production.md](rdx-alb-target-unhealthy-in-production.md)** — it is a live production defect, independent of this rebuild. Summary retained here for context.

Not merely stale. Measured live 2026-07-14 (the staging aws-vault profile reaches
production too):

| | dev (dev-0) | "staging" (dev-1) | production |
|---|---|---|---|
| target group | `alb-mandala-drupal-staging-rdx-0` | — | `mandala-drupal-production-rdx-0` |
| **rdx (9001)** | **unhealthy** — `Target.FailedHealthChecks` | n/a | **unhealthy** — `Target.FailedHealthChecks` |
| **index (8765)** | healthy | n/a | healthy |

Both envs declare `rdx_service_port = 9001`, but reindeer_x listens on **9000** on the
live box. So `mandala-rdx.internal.lib.virginia.edu` (production) and
`mandala-rdx-dev...` (dev) have **no healthy target** — this is an existing production
defect, not cutover cleanup. It has evidently been that way long enough to go
unnoticed, which is itself worth understanding: **who actually consumes rdx over the
ALB?** The `index` target is healthy in both, so the fault is specific to the
reindeer_x-facing target.

**dev-1 has no reindeer_x target at all** — `rdx-attach-0` uses
`element(aws_instance.backend.*.id, 0)`, i.e. instance 0 only. Consistent with dev-1
being the D7 migration source rather than an HA peer.

**The two envs' terraform has also drifted apart on naming**, which will bite anyone
scripting across environments:

- staging: `name = "alb-${var.application}-${var.environment}-rdx-0"`
- production: `name = "${var.application}-${var.environment}-rdx-0"` — **no `alb-` prefix**

Reconcile the port at cutover, and settle the **push-vs-pull** question with Than and
Andres at the same time (already flagged in the scope doc §6).

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
- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — the same gap for the D11 app (RESOLVED 2026-08-11 — see that note; reindeer_x's own gap below is NOT resolved the same way)
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5.1 (replace in place), §6 (component audit)

## Status check — 2026-08-11

The "review the need for rdx in general" gate above was never recorded as closed —
**still open, still gating this note.** Verified live today, both facts unchanged
since 2026-07-14:

- **reindeer_x is not running anywhere.** SSH to dev-0: the container last exited
  2026-07-15T20:05:51Z (`SIGTERM`, `RestartPolicy: no`) — the same session that
  deliberately quiesced the legacy Aegir-adjacent containers for the volume-snapshot
  work, not a crash (job queue was healthy right up to the stop: 72,978 succeeded,
  0 failed). It has not been restarted since — about 4 weeks with no kmterms→kmassets
  sync running anywhere, dev or otherwise.
- **The rdx ALB target is still unhealthy**, dev and production both — same
  9000-vs-9001 port mismatch as 2026-07-14, unchanged.

Triaged 2026-08-11 (Yuji): status is **under review, to be discussed later** —
explicitly not resolving the push-vs-pull question today. This note and
[rdx-alb-target-unhealthy-in-production.md](rdx-alb-target-unhealthy-in-production.md)
both stand as-is until that discussion happens. Also decided the same session:
[s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md](s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md) —
s3-sync's fate is tied to reindeer_x's, so it's deferred alongside this.
