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

| Shortcut | Full pipeline name | Source repo | Stages | Trigger path filter |
|---|---|---|---|---|
| `drupal` (default) | `uva-mandala-drupal-codepipeline` | `uvalib/mandala-navina` (this repo) | Source → Build → Deploy | `drupal/**`, `package/**`, `pipeline/**` only |
| `ingest` | `uva-mandala-ingest-production-deploy-codepipeline` | `uvalib/mandala-ingest-production-deploy` (separate repo) | Source → Deploy | none — any push to `main` |
| `solr-proxy` | `uva-mandala-solr-proxy-codepipeline` | `uvalib/mandala-navina` (this repo) | Source → Build (**no Deploy stage**) | `solr-proxy/**` only |

Verify/update these filters with:
```bash
aws codepipeline get-pipeline --name <pipeline-name> --query "pipeline.triggers"
```

**A merge to `main` in this monorepo only auto-deploys if it touches a
path the pipeline's `triggers.gitConfiguration.push[].filePaths` matches.**
A PR that only touches `docs/**`, `scripts/**`, `CLAUDE.md`, etc. merges
cleanly but fires **no** pipeline execution — this is deliberate (added via
terraform-infrastructure commit `8b753bff1`, 2026-07-16, specifically so
docs-only commits wouldn't force an unnecessary container restart during a
long-running migration) and not a bug. `deploy-status.sh` after such a
merge will correctly keep showing the *previous* real execution — that's
expected, not stale output.

`solr-proxy/**` changes only run the **build-only** `solr-proxy` pipeline
(pushes an image; there's no Deploy stage on it) — reaching dev-0 needs the
`drupal` pipeline's Deploy stage to actually run, which a solr-proxy-only
change does **not** trigger (different path filter). If a solr-proxy fix
needs to land on dev-0 by itself, trigger the drupal pipeline's deploy
manually: `aws codepipeline start-pipeline-execution --name
uva-mandala-drupal-codepipeline` — this is the one legitimate case for a
manual trigger (contrast with the *don't* case below).

For pushes that **do** match a pipeline's path filter: merging to `main`
already triggers the deploy via webhook — never also call
`start-pipeline-execution` manually in that case, or you'll create a real
duplicate run (see `feedback-codepipeline-webhook-auto-triggers` memory).

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
| A merge to `main` shows no new execution in `deploy-status.sh` | The merge didn't touch a path the pipeline's trigger filters on (see Pipelines table) | Expected for docs/scripts-only merges to `drupal`/`solr-proxy`. If a deploy is actually needed and no in-scope path changed, trigger by hand (see above) |

## Related

- `scripts/deploy-status.sh` — one-shot snapshot (this doc's main subject)
- `scripts/watch-deploy.sh` — polls a specific execution to a terminal state; see its header comment for the full "poll by execution id, not pipeline status" trap writeup
- `feedback-codepipeline-poll-by-execution-id` memory
- `feedback-codepipeline-webhook-auto-triggers` memory
- [Deployment](../../CLAUDE.md#deployment) section of the root CLAUDE.md
