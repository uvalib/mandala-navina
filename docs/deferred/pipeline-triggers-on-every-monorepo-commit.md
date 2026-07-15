# The pipeline will rebuild and redeploy on every commit to `main`, including docs

**Area:** deployment / CI-CD / CodePipeline / monorepo
**Raised during:** Session 2026-07-15 (1b.1 part 4 — reviewing the pipeline before the first apply)
**Jira:** (add when available)
**Priority:** Medium — noise and wasted deploys, not a correctness bug. Deliberately deferred until a green run.

## What we found

`aws_cicd/pipelines/mandala-drupal/codepipeline.tf` does not set `trigger_paths`,
so the module's `dynamic "trigger"` block emits nothing and no path filter is
attached. The source is a `CodeStarSourceConnection` on `uvalib/mandala-navina`
tracking `main`, which means **any push to `main` triggers the full pipeline** —
Source → Build → Deploy — regardless of what changed.

dsf, the pattern we copied, gets away with this because `uvalib/drupal-dsf` is an
app-only repo: every push there is app-relevant. **Mandala is a monorepo**
(ADR 001). `docs/`, `solr-proxy/`, `s3-sync/`, `scripts/`, `mkdocs/` and `.ddev/`
all sit alongside `drupal/`, and this team merges docs PRs constantly — #33, #34,
#35 and #37 were all docs-only. Each of those would rebuild the image and
redeploy dev-0.

The fleet already has the mechanism, used by exactly the pipelines with this
shape (`web-components`, `cs-proxy`, `cdn-reporter`):

```hcl
source_artifact_format = "CODEBUILD_CLONE_REF"
trigger_paths = [
  "apps/collection-space-proxy/**",
]
```

## Why it was deferred, not fixed

Decided 2026-07-15 (Yuji): apply the pipeline unfiltered and add the filter once
we have seen a green run. An unfiltered pipeline is noisy, not wrong, and the
first run is easier to debug without a path filter as an extra variable in it.
This is cheap to add later — it is one argument and does not change any resource
name.

## What needs deciding when we pick this up

A path list, which is not obvious:

- `drupal/**`, `package/**` — clearly yes; they define the image.
- `pipeline/**` — yes; the specs themselves.
- `solr-proxy/**` — **probably not.** It has its own `Dockerfile` and is a
  separate container; it is not built by this pipeline (which builds
  `package/Dockerfile` → `uvalib/mandala-drupal`). But it has no pipeline of its
  own yet either, so excluding it here must not become the reason it never gets
  one. Compare `reindeer-x-has-no-ecr-repo-or-pipeline.md`.
- `s3-sync/**`, `scripts/**`, `docs/**`, `mkdocs/**`, `.ddev/**` — no.

## Related: there is no `.dockerignore`

Worth fixing in the same pass. `package/Dockerfile` does `COPY . /opt/drupal/app`
with **no `.dockerignore` in the repo**, so the Drupal image currently bakes in
`docs/`, `solr-proxy/`, `s3-sync/`, `mkdocs/`, `.ddev/` and anything else at the
repo root — none of which the running app uses.

This interacts with the trigger question: because the whole repo is the build
context, a docs-only commit genuinely does change the image, so filtering the
trigger is the right fix rather than relying on Docker layer caching to make the
rebuild a no-op. A `.dockerignore` would shrink the image and make the build
context honest about what the app actually needs.

Not urgent, and not a secret-exposure issue — the repo is public.
