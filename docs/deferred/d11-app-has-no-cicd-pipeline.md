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
