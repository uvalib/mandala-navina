# No repeatable way to deliver app code/config to dev-0 — decide: manual rebuild/redeploy vs CI pipeline

**Area:** deployment / CI-CD / dev-0 / ECR
**Raised during:** Session 2026-07-21 (attempting to deliver the merged user-migration config to dev-0)
**Jira:** (add when available)
**Priority:** High — blocks the user-migration deploy and every future code/config change to dev-0
**Owners:** Yuji / Dave (they own ECR, terraform, and the ansible/deploy estate)
**Status:** **DECISION OPEN — deferred to Yuji/Dave; do not treat either option as chosen**

## What we found

There is **no repeatable mechanism to get application code or config onto dev-0.**

- dev-0 (`mandala-drupal-0`) runs a **hand-built image**; `package/Dockerfile:34`
  (`COPY . /opt/drupal/app`) bakes the whole repo — including `drupal/config/sync`,
  the CMI baseline — into the image at build time. The deploy then runs
  `updb` + `cim` from that baked baseline.
- There is **no ECR repository and no CodePipeline** (see
  [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md)). The
  `pipeline/buildspec.yml` / `deployspec.yml` are written but have never run.
- Therefore **merging to `main` does not deploy anything.** The images that dev-0
  has run were produced by hand ("today's build") — undocumented, tribal knowledge.

## Trigger

On 2026-07-21, PR #45 (the D7 user-migration config) merged to `main`, but the
config physically **cannot reach dev-0** without a rebuild — so the dev-0 user
migration is blocked on this, independent of the separately-tracked
[role-permission-wipe bug](d7-user-role-migration-wipes-committed-role-permissions.md).

## The open decision

Pick and implement a delivery mechanism. The two candidates (not necessarily
mutually exclusive — a documented manual runbook could be an interim while the
pipeline is built, but the choice and sequencing are **open**, pending Yuji/Dave):

### Option A — a documented, repeatable manual rebuild + redeploy runbook
Turn the current tribal "today's build" into a written procedure. Derived from
`buildspec.yml` / `deployspec.yml`:
1. `docker build -f package/Dockerfile -t uvalib/mandala-drupal:latest --build-arg BUILD_TAG=$V .`
2. push to ECR (**first-build gaps:** the ECR repo `uvalib/mandala-drupal` does
   **not exist** — `aws ecr create-repository` first; the SSM tag param
   `/containers/uvalib/mandala-drupal/latest` is **unseeded** — `put-parameter`).
3. `terraform apply --target=local_file.{inventory,tfvars,environment}` then
   `ansible-playbook deploy_backend.yml` (which runs the snapshot-guarded `cim`).
   For a config-only change, `deploy_backend.yml` alone suffices (redis/netbadge
   already up).
- **Pro:** fastest to unblock; low new infra. **Con:** manual, error-prone,
  doesn't survive as a deliverable; leaves the pipeline gap open.

### Option B — build the real CI pipeline
Create `aws_cicd/pipelines/mandala-drupal/` (ECR repo + CodeBuild for
`buildspec.yml` + CodePipeline for `deployspec.yml`), modelled on
`aws_cicd/pipelines/drupal-dsf/`. This is the deliverable already described in
[d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md).
- **Pro:** every `main` merge auto-deploys; the real fix. **Con:** substantial;
  delays the immediate user-migration deploy.

## Impact until decided

Every config/code change to dev-0 (user migration now, and everything after) is
gated on this. Note the same gap independently affects reindeer_x
([reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md)),
so whichever way this goes, consider solving both consistently.

## Cross-references

- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — the pipeline-infrastructure gap (Option B is its deliverable)
- [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md) — same gap for reindeer_x
- [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md) — how dev-0 was stood up
- `package/Dockerfile`, `pipeline/buildspec.yml`, `pipeline/deployspec.yml`

## RESOLVED — 2026-08-11

**Option B was chosen and built** — see the matching update in
[d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md). Verified live
2026-08-11: the pipeline exists, auto-deploys on every relevant `main` merge, and
has been doing so since 2026-07-15. This note's "no repeatable way to deliver code"
premise no longer holds for the Drupal app. Left as-is otherwise for history —
Option A (manual runbook) was never needed.
