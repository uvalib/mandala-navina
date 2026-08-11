# The D11 app has no CI/CD pipeline — buildspec/deployspec are orphaned

**Area:** deployment / CI-CD / ECR / CodePipeline
**Raised during:** Session 2026-07-14 (1b.1 part 4 — building the app image for the first time)
**Jira:** (add when available)
**Priority:** High — blocks 1b.1 part 4 validation (§4 item 7); `deploy_backend.yml` has no image to pull

## What we found

`pipeline/buildspec.yml` and `pipeline/deployspec.yml` exist in this repo and look
finished, but **nothing invokes them and they have never run**. Verified against the
staging account (2026-07-14):

- `aws ecr describe-images --repository-name uvalib/mandala-drupal` →
  **`RepositoryNotFoundException`**. The ECR repository does not exist.
- `aws ssm get-parameter --name /mandala/drupal/build_tag` → **`ParameterNotFound`**.
- `terraform-infrastructure/aws_cicd/pipelines/` has a **`drupal-dsf`** entry; there
  is **no `mandala-drupal` equivalent**. `mandala/pipelines/` contains only
  `mandala-ingest-production-deploy`.

So the D11 app image has never been built or pushed, and there is no CodeBuild /
CodePipeline project that would do it.

This directly contradicts the framing in
`docs/planning/1b1-part4-d11-backend-deploy-scope.md` §1 ("The D11 CI/CD pipeline is
already written and points at this terraform env"). The *specs* are written; the
*infrastructure* does not exist.

## Why it matters

`deploy_backend.yml` runs `docker_container: pull: true` against
`{{ globalvars.registry_name }}/uvalib/mandala-drupal:{{ tag }}`. With no repository
and no image, part-4 validation (§4 item 7) cannot run — the deploy fails at the
pull, regardless of everything else being ready.

## Consequence already found

Because the build had never run, `composer install` had been failing silently since
SAML was added: `simplesamlphp/xml-common` requires `ext-bcmath`, which the base
image does not ship. Fixed by installing bcmath in `package/Dockerfile` — but the
point stands that a never-executed pipeline hides real breakage. **The image now
builds and has been verified locally** (149 packages; `apache2ctl configtest` Syntax
OK; drush 13.7.3.0 at the path the playbook invokes; CMI baseline baked in).

## What needs to happen

Either:

1. **Write the pipeline properly** — `aws_cicd/pipelines/mandala-drupal/` modelled on
   `aws_cicd/pipelines/drupal-dsf/`: the ECR repository, the CodeBuild project for
   `buildspec.yml`, and the CodePipeline that runs `deployspec.yml`. This is the real
   deliverable.
2. **Or bootstrap by hand to unblock validation sooner** — create the ECR repo, build
   and push the image from a workstation, seed `/mandala/drupal/build_tag`, then run
   the playbooks manually. Gets item 7 moving but leaves the deliverable outstanding.

Note also that `buildspec.yml` publishes the build tag to `/mandala/drupal/build_tag`
while the house convention (dsf, drupal-library) is
`/containers/<image>/latest`. `deploy_backend.yml` currently follows the app repo;
reconcile the two deliberately when the pipeline is written.

## 2026-07-21 update

This gap became load-bearing again: PR #45 (D7 user-migration config) merged to
`main` but **cannot reach dev-0** without a rebuild, so the user-migration deploy
is blocked on it. The broader "how do we deliver code/config to dev-0 at all"
question — with the manual-rebuild vs pipeline choice left **open** for Yuji/Dave
— is now tracked in
[dev-0-code-config-delivery-rebuild-or-pipeline.md](dev-0-code-config-delivery-rebuild-or-pipeline.md).
Option 1 below (write the pipeline) is that note's Option B. No decision recorded.

## Cross-references

- [dev-0-code-config-delivery-rebuild-or-pipeline.md](dev-0-code-config-delivery-rebuild-or-pipeline.md) — the open delivery-mechanism decision this blocks
- `docs/planning/1b1-part4-d11-backend-deploy-scope.md` §1, §4
- `pipeline/buildspec.yml`, `pipeline/deployspec.yml`
- `terraform-infrastructure/aws_cicd/pipelines/drupal-dsf/` — the reference shape

## RESOLVED — 2026-08-11

**This gap was already closed the day after it was filed and this note was never
updated.** `terraform-infrastructure/aws_cicd/pipelines/mandala-drupal/` was added
2026-07-14 (`e7bf08615`), fixed up through 2026-07-16, and the full cycle went GREEN
2026-07-15 (see `docs/session-logs/2026-07-15-sprint-01-1b1-part4-first-green-pipeline.md`
and the project-mandala-state memory's "pipeline is GREEN" block). This note and
today's 2026-08-11 session-log agenda both still cited it as open — verified live
today and it is not:

- ECR repo `uvalib/mandala-drupal` — exists, created 2026-07-15
- CodePipeline `uva-mandala-drupal-codepipeline` — exists, Source→Build→Deploy,
  webhook-triggered on `drupal/**`/`package/**`/`pipeline/**` pushes to `main`
- 10 most recent executions checked, mostly Succeeded, most recently 2026-08-07
  (matches `main`'s HEAD at the time)
- SSM `/containers/uvalib/mandala-drupal/latest` — live, `build-20260807170435`

So the "write it properly vs. bootstrap by hand" choice this note posed is moot —
Option 1 was already done. **What's real and still open:** the pipeline only
deploys to staging/dev (`ENVIRONMENT: staging` hardcoded in `deployspec.yml`); there
is no production pipeline app and `terraform-infrastructure/mandala/drupal/production/ansible/`
still only has the pre-D11 Aegir-era playbooks, not `deploy_backend.yml`/etc. That's
explicitly **out of scope for now** (2026-08-11, Yuji) — not tracked as a gap here.

Also resolves [dev-0-code-config-delivery-rebuild-or-pipeline.md](dev-0-code-config-delivery-rebuild-or-pipeline.md)
the same way — Option B there is this same pipeline, already built and running.
