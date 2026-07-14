# The rdx ALB target is unhealthy in PRODUCTION (and dev) — port mismatch, unnoticed

**Area:** reindeer_x / rdx / ALB / production defect
**Raised during:** Session 2026-07-14 (1b.1 part 4 — comparing reindeer_x across environments)
**Jira:** (add when available)
**Priority:** High — a live production defect, independent of the D11 rebuild

## Measured, not inferred (2026-07-14)

The `staging` aws-vault profile reaches production as well (Yuji), so both were
checked live:

| | dev (dev-0) | "staging" (dev-1) | production |
|---|---|---|---|
| target group | `alb-mandala-drupal-staging-rdx-0` | **none** | `mandala-drupal-production-rdx-0` |
| **rdx (9001)** | **unhealthy** — `Target.FailedHealthChecks` | n/a | **unhealthy** — `Target.FailedHealthChecks` |
| **index (8765)** | healthy | n/a | healthy |

So `mandala-rdx.internal.lib.virginia.edu` (production) and `mandala-rdx-dev...`
(dev) have **no healthy target behind them**. The `index` target is healthy in both,
so the fault is specific to the reindeer_x-facing target, not the Solr one.

**This is an existing production defect, not D11 cutover work.** It is filed
separately from [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md)
for that reason — that note is about the rebuild; this one is broken right now.

## Why nobody noticed — ANSWERED (Yuji, 2026-07-14)

**rdx exists to pick up changes in KMaps in a timely fashion.** Development on KMaps
data has slowed to the point where **nobody expects updates any more** — so nobody
noticed that updates were not happening. The outage is real but has had no felt
impact, because there has been nothing to propagate.

**Consequence: Yuji is reviewing the need for rdx in general** (2026-07-14). Do not
fix the port, move it, or build it a pipeline until that review lands — all three
respects below may be moot.

> **Important distinction, and a correction.** An earlier framing in
> [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md)
> said reindeer_x is "not droppable". That conflated two separate claims:
>
> - **The kmassets shadow docs must exist** (ADR 006) and Drupal cannot generate them
>   (ADR 013 carves kmterms out; the ~79k/~69k shadow population dwarfs the ~55k terms
>   Drupal references). **This still holds.**
> - **A continuously-running, push-fed sync service must exist.** This does **not**
>   follow. If kmterms is effectively static, the shadow docs already sit in Solr and
>   stay there; the sync has nothing to do.
>
> So retiring rdx / the always-on service is **not** superseding ADR 006. It is the
> **push-vs-pull** question (scope doc §6, Than + Andres), and "no push endpoint;
> re-sync in batch when KMaps actually changes" is a legitimate answer to it. What
> must not happen is losing the *ability* to re-sync when KMaps does change.

## Three respects to address (Yuji, 2026-07-14) — GATED on the review above

Listed as they occurred to Yuji; **not a sequence**. If rdx is retained, note that
(3) likely has to precede (1): choosing a direction for the port fix depends on
config that is currently only on the box.

### 1. Fix the 9000/9001 port mismatch

`mandala/drupal/{staging,production}/variables.tf` both declare:

- `rdx_service_port = 9001` — but reindeer_x **listens on 9000** on the live box
- `index_service_port = 8765` — correct; that target is healthy

Fix in whichever direction is right (change terraform to 9000, or change the service
to 9001) — that depends on §3 below, since the deployed config is currently only on
the box.

### 2. Move rdx to the staging environment

> **CONFIRMED (Yuji, 2026-07-14).** Move rdx off **dev-0** — which scope doc §5.1
> replaces **in place** with D11 — and onto **dev-1**, the *staging* logical
> environment, which today has **no rdx target at all**. `rdx-attach-0` uses
> `target_id = element(aws_instance.backend.*.id, 0)`, i.e. instance 0 only, so
> dev-1 is not wired to rdx in any way.

This matters for cutover: reindeer_x currently lives on the instance being replaced,
hand-built, with nothing to rebuild it from. Moving it to dev-1 takes it out of the
blast radius. It also fits the dev-0=dev / dev-1=staging split (§5.4) — rdx is a
service the staging environment should host, not the dev box.

If the move happens, `rdx-attach-0`'s `element(..., 0)` must change accordingly, and
the CNAME/env naming should be revisited (`mandala-rdx-dev` currently points into the
staging terraform env).

### 3. Make sure it is all on GitHub, in the appropriate repository

Today the deployment exists **only on the box**. `uvalib/mandala-reindeer_x` has the
application code, but nothing that describes how it is deployed:

- no `buildspec.yml` / `deployspec.yml` in the repo
- no ECR repository (zero `mandala` repos exist in the registry)
- no `aws_cicd/pipelines` entry
- no Ansible playbook in terraform-infrastructure
- only `docker-compose.reindeer_x.yml` + `Dockerfile.reindeer_x` + `Dockerfile.redis`,
  built by hand on the host

**Verify the running code matches the repo before anything else.** The scope doc's §6
audit found the legacy stacks are hand-placed `/usr/local` checkouts *with drift* — a
`fail2ban-rework` branch checked out, uncommitted `solr-proxy` compose changes, hand
`.env` secrets. Assume nothing about dev-0's reindeer_x until it is diffed against
`main`. **The port answer for §1 may only exist on the box** — if the running config
says 9000 and the repo says nothing, the repo is what needs correcting.

Appropriate homes: application + build/deploy specs → `uvalib/mandala-reindeer_x`
(ADR 007 — its own repo, its own pipeline, not a stage in `mandala-drupal`'s);
Ansible playbook + ALB/port terraform → `terraform-infrastructure` under `mandala/`.

## Also found: the two envs' terraform has drifted on naming

Will bite anyone scripting across environments — a cross-env lookup by name silently
misses production:

- staging: `name = "alb-${var.application}-${var.environment}-rdx-0"`
- production: `name = "${var.application}-${var.environment}-rdx-0"` — **no `alb-` prefix**

## Cross-references

- [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md) — the rebuild/cutover side
- [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md), [ADR 007](../adr/007-reindeer-x-independent-service.md) — why reindeer_x exists and lives apart
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §5.1 (replace dev-0 in place), §5.4 (dev-0/dev-1 split), §6 (component audit + push-vs-pull)
