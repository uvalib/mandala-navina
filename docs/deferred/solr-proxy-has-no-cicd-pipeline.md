# solr-proxy has no CI/CD pipeline

**Area:** deployment / CI-CD / ECR / solr-proxy / ADR 014
**Raised during:** Session 2026-08-11 (CI/CD pipeline inventory)
**Jira:** (add when available)
**Priority:** High — 1b.1 part 4 needs the proxy actually running to validate
ADR 014's hybrid design end-to-end
**Status:** **DECIDED (2026-08-11, Yuji) — solr-proxy needs a full CI/CD pipeline.**
Production explicitly out of scope for now.

## What we found

Auditing all D11-related CI/CD live in the staging AWS account (2026-08-11) found
exactly one working pipeline — `uva-mandala-drupal-codepipeline`, for `drupal/`
only. `solr-proxy/` has **none of it**:

- No ECR repository (`aws ecr describe-repositories` — zero `mandala` repos other
  than `uvalib/mandala-drupal`)
- No CodeBuild project, no CodePipeline entry
- No `buildspec.yml`/`deployspec.yml` in `solr-proxy/` (it has a `Dockerfile` and
  `docker-compose.yml` for local dev only — see `solr-proxy/README.md`)
- No Ansible deploy playbook in `terraform-infrastructure`

Unlike `reindeer_x`, there is also **no legacy hand-built deployment to reconcile
with** — the D11 proxy (forked from `shanti-uva/mandala-solr-proxy` per ADR 014)
has never been run outside local `docker compose up`. The D7 proxy
(`mandala-solr-proxy` container on dev-0) is a different codebase serving the D7
sites unchanged and is explicitly out of scope (see the proxy's own README).

## Why it's ready to build now

Its dependencies are already merged, per 1b.1 parts 1–3 (ADR 014):

1. ✅ The fork itself — proxy code in the monorepo, `$OAUTH_ROOT` pointed at D11,
   `Searcher.php` reads `mandala_solr_fq:{uid}` from Redis instead of querying Solr.
2. ✅ `simple_oauth` installed/configured in D11; OAuth2 authorization-code flow
   verified live (`sub:"2"` = Drupal uid).
3. ✅ `mandala_solr_visibility` module writes/deletes the Redis token on
   login/logout/Group membership change, verified end-to-end.

So the proxy's runtime dependencies exist — what's missing is purely the deployment
mechanism. This is the cleanest of the three gaps found in the 2026-08-11 audit
(compare [reindeer-x-has-no-ecr-repo-or-pipeline.md](reindeer-x-has-no-ecr-repo-or-pipeline.md),
under review, and [s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md](s3-sync-pipeline-deferred-pending-reindeer-x-consolidation.md),
deferred).

## What needs to happen

Modelled on `aws_cicd/pipelines/mandala-drupal/` — now the proven, live in-repo
reference (closer and more current than `drupal-dsf`, which mandala-drupal itself
was originally modelled on):

1. ✅ **DRAFTED 2026-08-11** — `solr-proxy/pipeline/buildspec.yml` +
   `deployspec.yml`. Location resolved: the codepipeline module's
   `build_buildspec` / `deploy_buildspec` are configurable variables (defaulting
   to `pipeline/buildspec.yml`), so a second pipeline in this monorepo points at
   `solr-proxy/pipeline/…` without colliding with the D11 app's root `pipeline/`.
   **The deployspec is not runnable until items 3–4 below exist** — it is marked
   as such in its own header
2. ECR repository for the proxy image
3. `aws_cicd/pipelines/mandala-solr-proxy/` (or similar — name it so it won't
   collide with a future production pipeline, per the `var.application`-only
   naming the codepipeline module uses)
4. Ansible deploy playbook + terraform wiring — decide how/where the proxy runs
   relative to the Drupal container (co-located on the same instance vs. its own
   service) and how it fits the existing `index` (8765) ALB target pattern seen
   for the D7 proxy. **The drafted deployspec assumes `deploy_solrproxy.yml` at
   `mandala/drupal/<env>/ansible/`** and deliberately does NOT re-run
   `deploy_redis.yml` (that belongs to the app's pipeline; the proxy only *reads*
   the ADR 014 tokens Drupal writes). It also expects an encrypted
   `solrproxy_creds.php.cpt` there for the OAuth2 client secret — `paths.php`
   needs no encryption, holding no secrets, so the playbook should render it from
   the committed template
5. Trigger-path filtering (`trigger_paths`) scoped to `solr-proxy/**` only, same
   pattern as `mandala-drupal`'s filter on `drupal/**`/`package/**`/`pipeline/**`,
   so unrelated monorepo commits don't fire it

**Scope note (2026-08-11, Yuji): production is explicitly out of scope for now.**
Staging/dev only, same as the existing `mandala-drupal` pipeline.

## Prerequisite DONE — reproducible builds (2026-08-11)

Fixed before writing the pipeline, because an auto-deploying pipeline on top of a
non-reproducible build is worse than no pipeline: a green build today and a broken
one tomorrow would have byte-identical source.

`solr-proxy/proxy/composer.json` required `league/oauth2-client: "dev-master"` under
`minimum-stability: dev`, and the fork had **dropped the D7 repo's `composer.lock`** —
so `composer install` silently degraded to a fresh resolve on every build. The
Dockerfile's `composer.lock*` glob made the lock optional, which is how its absence
went unnoticed.

Measured, not assumed: resolving the old `composer.json` against PHP 7.4 today
produced **Guzzle 8.2.x-dev**, while the D7 lock (still on the box, still what
production runs) pins the **Guzzle 7** line. Unpinned builds were drifting across a
major version.

Fix: pinned `league/oauth2-client: ^2.8`, dropped `minimum-stability: dev`, added
`config.platform.php = 7.4.33` so the lock resolves for the runtime regardless of the
developer's local PHP, and **committed `composer.lock`** (10 packages, all stable —
`league/oauth2-client` 2.9.0, Guzzle 7.15.3; no security advisories). Dockerfile now
requires the lock rather than globbing it. Verified: lock-driven `composer install`
succeeds against the PHP 7.4 platform, and all five `league/oauth2-client` methods
`auth.php` calls (`getAuthorizationUrl`, `getState`, `getAccessToken`,
`getResourceOwner`, `getDefaultScopes`) are public on `GenericProvider` at 2.9.0.

**Image build verified 2026-08-11** — `docker build --platform linux/amd64` (matching
CodeBuild's architecture, not the arm64 laptop) succeeds end to end. `composer install`
reports *"Installing dependencies from lock file"* — the lock is honoured, not
re-resolved. Runtime checks on the built image: `redis`/`json`/`mbstring` all loaded,
`GenericProvider` + `AccessToken` resolve through the locked autoloader at 2.9.0, and
all six Apache modules the vhost needs (`rewrite`, `proxy`, `proxy_http`,
`proxy_balancer`, `proxy_connect`, `remoteip`) are present.

**Deploy-relevant finding from that verification: `SOLR_BASEURL` is a hard
container-start requirement, not merely app config.** `files/apache2/proxy-conf/kmterms-proxy.conf`
interpolates it into a `ProxyPass` directive, so with the variable unset Apache fails
config parse (`AH00526 ... ProxyPass URL must be absolute!`) and **the container exits
immediately** — before any PHP runs, so `check.php`'s own env validation never fires.
With `SOLR_BASEURL` + `DEFAULT_RETURL` set, `apache2ctl configtest` returns `Syntax OK`
and the container stays up. The Ansible playbook must therefore guarantee both are
present at container start; a missing value is a crash-loop, not a degraded service.

**Two follow-ups deliberately not taken:**
- **A stale-lock guard is still missing.** `composer install` only *warns* when the
  lock does not match `composer.json`; `composer validate --strict` catches it
  (exit 2) but also fails on the missing `license` field. Declaring a license for
  this code is a project decision, not a build-fix — decide the license, then add
  `validate --strict` to the pipeline.
- **PHP 7.4 is EOL** (Nov 2022) and constrains every dependency pin here. Out of
  scope for this fix; worth its own decision before the proxy carries production
  traffic on D11.

## Playbook draft — 2026-08-11, UNCOMMITTED in terraform-infrastructure

`deploy_solrproxy.yml` has been drafted but **deliberately not committed**, pending
review in a later session. It is untracked in the terraform-infrastructure working
copy — note that repo takes commits straight to `master` with no PR mechanism, so
committing it *is* publishing it, which is why it is being held:

```
terraform-infrastructure/mandala/drupal/staging/ansible/deploy_solrproxy.yml
terraform-infrastructure/mandala/drupal/staging/ansible/files/var/solr-proxy/paths.php
```

⚠ **This is per-machine state on the current driver's laptop.** Another driver
picking this up will not see it. Either commit it or re-draft from this note.

Validated as far as is possible without running it: `ansible-playbook --syntax-check`
passes (exit 0, matching `deploy_backend.yml`), 19 tasks parse, and the deployed
`paths.php` passes `php -l` inside the built image.

Modelled on `deploy_backend.yml`. Deliberate differences, all load-bearing:

- **No SimpleSAMLphp anything** — the proxy is an OAuth2 client, not a SAML SP.
- **No drush** — plain PHP/Apache, not Drupal.
- **Container named `mandala-solr-proxy-0`, not `mandala-solr-proxy`** — the
  unsuffixed name is the *legacy D7 proxy*, a different codebase. Reusing it would
  make the playbook silently replace a live service.
- **Missing credentials are a hard failure, not a warning.** `deploy_backend.yml`
  only warns on a missing SAML key (the trap its own deployspec calls out). Here a
  missing `creds.php` would let the proxy come up and serve *only public results to
  logged-in users* — invisible from outside — so the playbook refuses to deploy.
- **Does not stop the legacy container** even though both bind 8765. It is stopped
  on dev-0 today so there is no conflict; in production it is live. Which proxy
  owns the port is a decision, not something a playbook should force.
- Ends by probing the exact ALB health-check path (`/solr/kmassets/status`) from
  inside the container, warning rather than failing — Solr being briefly
  unreachable shouldn't fail a deploy; the ALB is the authority on target health.

**Still to be created before it can run:** `solrproxy_creds.php.cpt` (the encrypted
OAuth2 client secret) in the same ansible directory, plus items 2–3 above (ECR repo,
pipeline entry).

## Cross-references

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the hybrid proxy design this deploys
- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — RESOLVED; the
  reference pipeline to model this on
- `solr-proxy/README.md` — what changed from the D7 proxy, the Redis contract
- `terraform-infrastructure/aws_cicd/pipelines/mandala-drupal/` — the reference shape
