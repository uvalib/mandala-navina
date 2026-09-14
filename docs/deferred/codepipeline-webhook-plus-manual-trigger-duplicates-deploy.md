# Merging to `main` auto-triggers a deploy via webhook — a manual `start-pipeline-execution` right after creates a real duplicate

**Area:** deployment / CI/CD
**Raised during:** Session 2026-09-14, deploying the AV7 group-permission fix (PR #201)
**Jira:** (add when available)
**Priority:** Low — self-correcting once known, but wastes a dev-0 container restart each time it's hit

## What happened

Merged PR #201 to `main`, then immediately ran `aws codepipeline start-pipeline-execution` to deploy it — the same manual-trigger step used throughout earlier sessions this sprint. Checking pipeline state shortly after showed **two executions for the identical commit**, queued back to back: `2be6e5a0` (auto-triggered) and `acaa020a` (the manual one), the second stacked directly behind the first.

`aws codepipeline get-pipeline-execution` on the earlier one confirmed it: `"trigger": {"triggerType": "WebhookV2", ...}` — `uva-mandala-drupal-codepipeline` has a GitHub webhook that fires on every push to `main`, and the merge itself already started a deploy before the manual command even ran. The two executions carry the same `revisionId`, so nothing was gained by the manual trigger — only a second, fully redundant Build→Deploy cycle, each of which restarts dev-0's container.

## Recovering from a duplicate

Stopping the redundant execution needs care:

- **Use a graceful stop, not `--abandon`.** `aws codepipeline stop-pipeline-execution ... ` (no `--abandon` flag) tells CodePipeline to let the *currently running* action finish naturally and simply not queue anything after it — it does **not** kill an in-progress CodeBuild job (Terraform/Ansible apply) mid-run. `--abandon` would, and could leave infrastructure half-applied.
- **CodePipeline's own stage status can lag reality.** After a graceful stop, `get-pipeline-state` kept reporting the Deploy stage as `"Stopping"` for the *entire remaining duration* of the underlying CodeBuild job — including well after that job had actually finished. Don't trust this label for "is it safe to proceed yet." Poll the real CodeBuild build directly instead: `aws codebuild batch-get-builds --ids <externalExecutionId from get-pipeline-state>` and watch `buildStatus`/`currentPhase` — that reflects ground truth.
- **A newer execution can supersede an older one mid-stage.** Observed directly: the manual execution's Build finished *while* the auto-triggered one's Deploy was still running, and CodePipeline let the newer execution's Deploy jump ahead and become the stage's `latestExecution` — meaning a poller watching for the *older* execution's id in a stage will never see it again once this happens (it's not stuck, it's just no longer the one that matters). Watch whichever execution is actually the stage's current `latestExecution`, not a fixed id chosen up front.

## Recommendation

**Before manually calling `start-pipeline-execution` right after a merge, check whether the webhook already covers it** — `aws codepipeline get-pipeline-state` and look at the Source stage's `latestExecution.status`; if it's already `InProgress`/recently `Succeeded` for the commit you just pushed, the webhook has it and a manual trigger will only create a duplicate. Reserve the manual trigger for cases where the webhook genuinely hasn't fired (e.g. after fixing a webhook/connection issue, or triggering a re-deploy of an already-deployed commit with no new push).

## Related

- Session log: `docs/session-logs/2026-09-14-*` (AV6/AV10 close-out)
- [[pipeline-triggers-on-every-monorepo-commit.md]] — a related but distinct over-triggering concern (every commit anywhere in the monorepo, not specifically the duplicate-execution shape here)
