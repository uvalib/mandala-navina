# `deployspec.yml` clones `terraform-infrastructure` in full on every deploy

**Area:** deployment / CI-CD / pipeline performance
**Raised during:** Session 2026-08-26 (watching the dev-0 deploy during the from-scratch rebuild)
**Jira:** (add when available)
**Priority:** Low-Medium — a real cost on every deploy, not a correctness bug
**Status:** DONE — 2026-09-02: group agreed to implement the proposed fix; landed as
`--depth 1 --single-branch` in `pipeline/deployspec.yml:42`. Verified nothing downstream in
`deployspec.yml` reads git history, tags, or other branches (only the current working tree, via
`terraform init`/`apply` and the Ansible playbooks), matching the finding below. #162 and
`dev0-from-scratch-rebuild-runbook.md` steps 7–10 remain separately scheduled.

## What was found

`pipeline/deployspec.yml:42`:

```
git clone https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/uvalib/terraform-infrastructure.git
```

No `--depth`, no `--single-branch`. `terraform-infrastructure` is **11,785 commits / 831 MB
of `.git`**, and the deploy only ever reads the current tree — never the history. Every deploy
pays the cost of cloning the whole thing.

## The fix

```
git clone --depth 1 --single-branch https://${GITHUB_USER}:${GITHUB_TOKEN}@github.com/uvalib/terraform-infrastructure.git
```

Two lines. Should not change behaviour — nothing downstream in `deployspec.yml` reads history,
tags across branches, or anything a shallow clone would break — but verify against the full
deployspec before landing, since this note is a finding, not a reviewed patch.

## Why "not urgent enough to do solo, but worth doing soon"

- Every deploy pays it, including the ones during active development this week.
- It is not blocking anything — the deploy completes, just slower than it needs to.
- `pipeline/**` is a deploy-trigger path, so landing it starts a real deploy. Low risk on its
  own, but bundling it with the next meeting means it's reviewed alongside other pipeline/infra
  decisions rather than as a solo drive-by change.
