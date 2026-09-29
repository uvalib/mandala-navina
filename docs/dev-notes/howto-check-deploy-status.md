# How-To: Check or watch a Mandala CodePipeline deploy

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-09-29

## Goal

Answer "what's the current deploy status" (a one-shot snapshot) or babysit a
just-triggered deploy through to done, without re-deriving the AWS CLI calls
by hand each session.

## Prerequisites

- AWS credentials reachable from the shell — either already present (an
  `aws-vault exec`'d shell, SSO session) or `aws-vault` installed with a
  `staging` profile configured. Both scripts below try plain `aws` first and
  fall back to `aws-vault exec staging --` automatically.
- Note: the `staging` aws-vault profile also reaches **production** — see
  `reference-terraform-local-invocation` memory.

## Pipelines

| Shortcut | Full pipeline name | Stages |
|---|---|---|
| `drupal` (default) | `uva-mandala-drupal-codepipeline` | Source → Build → Deploy |
| `ingest` | `uva-mandala-ingest-production-deploy-codepipeline` | Source → Deploy |
| `solr-proxy` | `uva-mandala-solr-proxy-codepipeline` | Source → Build |

All three trigger on a GitHub webhook push to `main` on the corresponding
repo (`mandala-navina` for `drupal`; each has its own repo). **Merging to
`main` already triggers the deploy** — never also call
`start-pipeline-execution` manually, or you'll create a real duplicate run
(see `feedback-codepipeline-webhook-auto-triggers` memory).

## Steps

**One-shot status check** — "is it done, and did it work":
```bash
./scripts/deploy-status.sh              # drupal pipeline (default)
./scripts/deploy-status.sh ingest
./scripts/deploy-status.sh solr-proxy
./scripts/deploy-status.sh <full-pipeline-name>   # any other pipeline in the account
```
Prints the newest execution's id, trigger, source commit/PR, and per-stage
status, then exits. Exit code: `0` Succeeded, `1` InProgress, `2`
Failed/Stopped/Superseded or not found.

**Watch a deploy through to completion** (e.g. right after merging a PR):
```bash
./scripts/watch-deploy.sh                        # auto-detects the newest execution
./scripts/watch-deploy.sh <execution-id>          # watch a specific one
./scripts/watch-deploy.sh <execution-id> <pipeline-name>   # non-default pipeline
```
Polls every 15s (`POLL_SECONDS` env var to change) and prints stage
transitions as they happen. Exit code: `0` Succeeded, `1` otherwise.

## Verify

`deploy-status.sh`'s `Stages:` block should show every stage for the
pipeline (see table above) as `Succeeded` once a deploy completes.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| A pipeline's own `latestExecution.status` looks "Succeeded" right after a fresh merge | It lags behind which execution is actually current — that's the *previous* run's stale status | Both scripts here already filter/query by the newest execution's own id, not the pipeline's aggregate status. Don't query `get-pipeline-state` unfiltered by hand. |
| You're about to call `start-pipeline-execution` after merging | The GitHub webhook already triggered a run | Don't — check with `deploy-status.sh` first; find the webhook-triggered execution instead |
| `deploy-status.sh`/`watch-deploy.sh` hang or error on `aws sts get-caller-identity` | No credentials in the shell and `aws-vault` isn't installed/configured | Install `aws-vault` and configure a `staging` profile, or run from a shell that already has credentials |

## Related

- `scripts/deploy-status.sh` — one-shot snapshot (this doc's main subject)
- `scripts/watch-deploy.sh` — polls a specific execution to a terminal state; see its header comment for the full "poll by execution id, not pipeline status" trap writeup
- `feedback-codepipeline-poll-by-execution-id` memory
- `feedback-codepipeline-webhook-auto-triggers` memory
- [Deployment](../../CLAUDE.md#deployment) section of the root CLAUDE.md
