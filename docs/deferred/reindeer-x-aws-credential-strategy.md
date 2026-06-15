# reindeer_x AWS Credential Strategy (task role + operator assume-role)

**Area:** reindeer_x / infrastructure / IAM
**Raised during:** Spike 8 (Part A — file watcher)
**Jira:** (add when available)
**Priority:** High — blocks deployed use of the Node file watcher
**Owner:** (unassigned — needs Terraform infra access: Yuji / Xiaoming / Dave)

## Context

Spike 8 Part A replaced the legacy `synch`/`synchandler` (clsync + Perl/rclone)
upload pipeline with a native Node module (`sync/fileWatcher.js`) that uploads
Solr docs to the kmassets ingest bucket via the AWS SDK v3. See
[Spike 8](../spikes/spike-08-reindeer-x-consolidation.md) and
[solr-sync-architecture-d11.md](solr-sync-architecture-d11.md).

The watcher creates its S3 client with **no hardcoded credentials**
(`new S3Client({ region })`), so it resolves credentials through the SDK default
provider chain:

```
env vars → shared ini (~/.aws/*) → process → web-identity → ECS task role / EC2 IMDS
```

This means **the application code needs no change** to switch credential sources.
The credential decision is entirely an environment/infrastructure concern. This
note records what must happen on the infra side so the watcher can run in
deployed environments.

## The shadowing gotcha (why this is blocking)

The legacy image **bakes a static credentials file** into the container
(`/home/node/.aws/credentials`, plus `rclone.conf` and `passwd-s3fs`). The shared
credentials file is resolved **earlier** in the SDK chain than the ECS task role,
so as long as that file is present the watcher uses the baked static keys and the
task role is silently ignored.

Therefore the cutover has **two halves that must land together**:

1. **In `uvalib/mandala-reindeer_x` (app repo):** remove the baked
   `.aws/credentials`, `rclone.conf`, and `passwd-s3fs` files and the
   `Dockerfile.reindeer_x` lines that `COPY` them and `apt-get install` `s3fs` /
   `clsync` / `rclone`. With no ini file in the container, the chain falls through
   to the task role.
2. **In the Terraform infra repo (`uvalib/terraform-infrastructure/…`):** define
   the per-environment task role and policy described below.

If only (1) lands, the container has no credentials and uploads fail. If only (2)
lands, the baked file shadows the role and the service is still on static keys.

## Required IAM design (per environment: dev, staging, production)

Each environment needs a reindeer_x **task role** whose **trust policy allows two
principals**:

1. `ecs-tasks.amazonaws.com` — normal ECS task operation.
2. A **scoped operator group / SSO role** — so engineers can assume the role for
   manual runs (see below). Scope this tightly, especially for production; treat
   prod assumption as break-glass (consider a smaller operator list and/or MFA).

**Permissions policy** — least privilege, scoped to that env's inbound bucket:

- `s3:PutObject` on `arn:aws:s3:::mandala-ingest-{env}-inbound/*`
  (covers both the `kmassets-inbound/…` upload prefix and the
  `kmassets-delete/…` tombstone prefix written by `.ids` files)
- add `s3:DeleteObject` only if deletions are later made real rather than
  tombstone markers

This is expected to be narrower than the current static key, which is presumably
broader.

## Manual / operator runs against a real environment

reindeer_x will sometimes be run **by hand** against a real environment — to
diagnose a downstream failure or to test a downstream fix. These runs do **not**
fall back to static keys or a raw personal identity. The operator **assumes the
same task role**, so the manual run has exactly the deployed service's identity
and permissions (no permission drift between manual and ECS execution), with
temporary credentials and CloudTrail attribution.

```bash
# Assume the target environment's reindeer_x task role
eval "$(aws sts assume-role \
  --role-arn arn:aws:iam::115119339709:role/reindeer_x-<env>-task \
  --role-session-name manual-<purpose> \
  --query Credentials --output ... )"   # export as AWS_ACCESS_KEY_ID / SECRET / SESSION_TOKEN

# Target the real environment by configuration only — no code change
export INGEST_BUCKET=mandala-ingest-<env>-inbound
export ENABLE_FILE_WATCHER=true
npm run reindeer_x:dev
```

Because the watcher is fully env-parameterized (`INGEST_BUCKET`, `WATCH_DIRS`,
`INGEST_PREFIX`, `WATCHER_USE_POLLING`), "run as <env>, manually" is pure
configuration; assumed-role env vars sit first in the SDK chain and win.

**Operational caution:** a manual run against a real `…-inbound` bucket drops
files into the live downstream pipeline — intended when testing a downstream fix,
but *not* when only diagnosing. For pure diagnosis, point `INGEST_PREFIX` at the
`…/test/` prefix (or a scratch bucket) to exercise the upload path without
triggering reprocessing.

## Local development / testing (no infra needed)

Local runs defer to the developer's own AWS identity via the same default chain
(env vars, an `~/.aws` profile, or `eval "$(aws configure export-credentials
--format env)"` to bridge an active CLI session — the reindeer_x SDK resolves the
`login_session` profile, whose token can expire independently of the CLI). Spike 8
Part A was demonstrated this way against a throwaway bucket, so no task role is
needed for testing.

## Summary of the hand-off

- **App repo (can be done now):** drop baked credential files + legacy tooling from
  the image so the SDK falls through to the task role.
- **Infra repo (needs Terraform access):** three task roles (dev/staging/prod),
  each trusting `ecs-tasks` **and** a scoped operator group, each granting
  `s3:PutObject` on its env's inbound bucket.
- **No application code change** in either case.
