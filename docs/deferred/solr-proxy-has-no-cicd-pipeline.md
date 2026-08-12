# solr-proxy has no CI/CD pipeline

**Area:** deployment / CI-CD / ECR / solr-proxy / ADR 014
**Raised during:** Session 2026-08-11 (CI/CD pipeline inventory)
**Jira:** (add when available)
**Priority:** ~~High~~ — **RESOLVED 2026-08-12**
**Status:** ✅ **COMPLETE for the public/anonymous path, which is the 90% case.**
⚠ The **authenticated** path is NOT working end to end — not because of anything
here, but because the kmassets index carries no D11-format uids. See
[kmassets-index-has-no-d11-uids.md](kmassets-index-has-no-d11-uids.md). Production
remains explicitly out of scope; everything below is dev/staging.

## ✅ RESOLVED — 2026-08-12

The whole chain is built and verified working. ADR 014's hybrid design is now
running outside DDEV for the first time.

| Step | Evidence |
|---|---|
| ECR repository | `uvalib/mandala-solr-proxy` — tf-infra `8e9216b93` |
| Pipeline (build-only) | `uva-mandala-solr-proxy-codepipeline` — tf-infra `d3eb4a76d` |
| Ansible playbook | `deploy_solrproxy.yml` — tf-infra `edb80d9d0` |
| Dead deployspec removed | PR #96 |
| Buildspec YAML fix | PR #97 |
| Deploy wired into the app pipeline | PR #99 |
| First green build | `build-20260812132552`, **all 7 smoke tests passed in CodeBuild** |
| Deployed | `mandala-solr-proxy-0` up on 8765; legacy `mandala-solr-proxy` untouched |
| **ALB `idx` target** | **healthy** — first time since 2026-07-15 |

**Proof on dev-0**, not inference: anonymous search through the proxy against the real
index returned **562,952 docs** with
`fq=(visibility_i:1 OR asset_type:(places subjects terms))` injected; the health path
returns HTTP 200 with real `kmassets` core status; Redis `PING: 1` via the `drupalnet`
alias; all 7 env vars correct and `SOLRPROXY_CLIENT_SECRET` present (64 chars).

### ⚠ Scope of that proof — the authenticated path is NOT covered

An earlier revision of this note claimed "proven end to end". That was an
overstatement and is corrected here. What the above establishes is the **anonymous**
path only.

**Proven** (2026-08-12, by injecting a synthetic token and measuring): the proxy reads
`mandala_solr_fq:{uid}` from Redis, injects it verbatim, and Solr honours it — a
session granted one private collection saw **3,112 documents that return 0 anonymously**
(total 562,952 → 566,516). So ADR 014's *enforcement* mechanism genuinely works.

**Not proven, and blocked:**

| Gap | State |
|---|---|
| Drupal *writing* a token on login | Never observed here — Redis db 0 was empty (`DBSIZE 0`) before the synthetic key |
| OAuth2 flow with the real `solrproxy` consumer | Never exercised; needs a browser + NetBadge |
| **D11-format uids in the kmassets index** | **Zero** — blocks the whole authenticated path, see the note below |

That test only worked because the token was deliberately written in **D7** uid format.
A real D11 token would have matched nothing. Also note dev-0 currently has **2 users**
(anonymous + admin) and 22 private groups, so there is nobody who could be a member of
one — the user migration has not been run there.

**The idx target took ~6 minutes to flip after deploy — that is arithmetic, not a
fault.** `interval=120` × `healthy_threshold=3`. Do not go hunting when it reads
unhealthy immediately after a deploy. Note also that ALB health checks are
deliberately absent from the access log (`SetEnvIf User-Agent "^ELB-HealthChecker"
skiplog=1` in the vhost), so "no health checks in the log" is expected, not evidence
of a network problem.

### Three pipeline failures, all understood — the diagnostic path is reusable

1. **`CreatePipeline` → `Project cannot be found`.** The pipeline auto-ran during the
   window when a partial terraform apply had created it but not the CodeBuild project.
   Historical artifact of #2 below.
2. **Partial apply, from an IAM gap.** The `staging` aws-vault profile is the plain
   `ys2n` IAM user, lacking **both** `iam:GetRolePolicy` (known since 2026-07-16) and
   `iam:DeleteRolePolicy` (new). The apply created 21 of 22 resources then 403'd on
   read-back, leaving both inline policies **tainted**; the retry then could not
   replace them. Recovery: confirm with `aws iam list-role-policies` that the policies
   genuinely exist, `terraform untaint` both, then `plan -refresh=false`. **Worth
   raising with Dave** — it makes routine local applies here fail partway.
3. **`DOWNLOAD_SOURCE` → `YAML_FILE_ERROR: Expected Commands[9] to be of string
   type`.** A plain YAML scalar containing `": "` parses as a *mapping*; the inner
   double quotes do not protect it. Fixed in PR #97 by quoting the whole scalar.
   **The local check had only verified the file parsed and counted commands — never
   that each command was a string.** Parsing and being correct are different
   properties; assert the type.

## ⚠ DESIGN CORRECTION (2026-08-11, Yuji) — follow `drupal-netbadge`

> *"The shape should follow the way the drupal-netbadge project is configured. The
> image is deployment-agnostic and gets its configuration from the environment."*

**This supersedes parts of the deployspec merged in PR #92 and of the drafted
Ansible playbook.** Read it before building on either.

The pattern, as verified in `terraform-infrastructure`:

- **`aws_cicd/pipelines/drupal-netbadge` is BUILD-ONLY** — `build_phase = true`,
  `deploy_phase` deliberately commented out. It builds the image, pushes it to ECR
  and writes the SSM tag. It deploys nothing.
- **Each consuming environment deploys that image from its own Ansible.** Mandala
  already does exactly this in `mandala/drupal/<env>/ansible/deploy_netbadge.yml`.
- **All configuration is environment variables**, layered from
  `container_0.env.generated` (terraform) + `container_0.env.managed` (committed,
  non-secret) + `container_0.env.secret` (ccrypt `.cpt`), `combine()`d into the
  container's `env:`, with a `required_env_vars` assertion. **No mounted config
  files.** Precedent already present: `SIMPLESAML_REDIS_HOST: "redis"` /
  `SIMPLESAML_REDIS_PORT: "6379"` in mandala's `container_0.env.managed`.

| Superseded (built earlier 2026-08-11) | Correct, per netbadge |
|---|---|
| separate `solrproxy_creds.php.cpt` | OAuth secret as `SOLRPROXY_CLIENT_SECRET` in the **existing** `container_0.env.secret` |
| playbook bind-mounts `settings/` | no mounts — config via env vars |
| deployspec decrypts a creds file | reuse the `container_0.env.secret` decrypt that already exists |
| build **+ deploy** pipeline | **build-only**; deploy from mandala's own Ansible |
| `creds.php` hardcodes the secret | ✅ **fixed** — reads `getenv()` (see below) |

**`creds.php` converted to `getenv()`.** It had the same `$_ENV`-class problem as
`paths.php` (config baked into a file rather than read from the environment) plus a
hardcoded `clientSecret` placeholder. Now:

- `SOLRPROXY_OAUTH_ROOT`, `SOLRPROXY_CLIENT_ID`, `SOLRPROXY_REDIRECT_URI` →
  `container_0.env.managed`; `SOLRPROXY_CLIENT_SECRET`, `SOLRPROXY_ADMIN_PW` →
  `container_0.env.secret`. `SOLRPROXY_`-prefixed because that env file is **shared
  with the other containers on the host** — the same reason netbadge namespaces
  everything `SIMPLESAML_`.
- **A missing or empty `SOLRPROXY_CLIENT_SECRET` now throws.** Without it the
  authorization-code exchange cannot complete, so every user stays anonymous and the
  proxy quietly serves public-only results while still returning 200s — invisible
  from outside. Same reasoning as `paths.php` throwing on a missing `SOLR_BASEURL`.
- **`$ADMIN_PW` is now actually defined.** It was referenced by `proxysess.php` but
  declared nowhere, so those admin actions were dead (failing closed, which is why
  nobody noticed). Optional — unset leaves them disabled.
- **Consequence: `settings/*.php` now contain no secrets at all**, so they could be
  baked into the image rather than mounted, fully realising the deployment-agnostic
  shape. Not done yet; it is the natural follow-on.

### Rework DONE (2026-08-11)

- **`Dockerfile` bakes `settings/{paths,creds}.php`** from the templates. Now
  mandatory, not cosmetic: `proxy/*.php` require those two paths unconditionally, and
  the deploy-time bind mount was the only thing supplying them. They contain no
  secrets and nothing environment-specific, so baking them is what makes the image
  deployment-agnostic. Local dev still overrides by mounting `./settings`.
- **`deployspec.yml`** — the `solrproxy_creds.php.cpt` decrypt is gone, replaced by
  the **existing shared** `container_0.env.secret` decrypt (the same file
  mandala-drupal's deployspec already decrypts). No solrproxy-specific `.cpt`.
- **`deploy_solrproxy.yml`** — rewritten on `deploy_netbadge.yml`: loads the three
  layered env files, `combine()`s them, asserts `required_env_vars`, and passes the
  result as the container's `env:`. **No volumes.** The settings-mount and
  creds-file-guard tasks are gone.
- **Smoke tests extended** to check the *baked* `paths.php`, that `creds.php`
  resolves from the environment, and that it **refuses to load without a client
  secret** (both halves — resolves when configured, throws when not).

Verified end to end by running it: image builds; settings baked with no placeholder
secret; and with **no mount and env-only configuration** the proxy serves anonymous
search with the correct `fq`, injects a real Redis visibility token for a logged-in
uid, and fails closed to the anonymous filter when Redis is stopped.
`ansible-playbook --syntax-check` passes.

### Pipeline DONE (2026-08-12)

- **ECR repo** `uvalib/mandala-solr-proxy` — terraform-infrastructure `8e9216b93`,
  appended at the END of the count-indexed `repo_names` (plan confirmed
  3 add / 0 change / 0 destroy).
- **Pipeline** `uva-mandala-solr-proxy-codepipeline` — `d3eb4a76d`. **BUILD-ONLY**,
  per drupal-netbadge: Source + Build, no Deploy. `trigger_paths` = `solr-proxy/**`
  alone; `build_buildspec` overridden to `solr-proxy/pipeline/buildspec.yml`.
- **`deploy_solrproxy.yml`** committed — `edb80d9d0`.
- **`solr-proxy/pipeline/deployspec.yml` DELETED** — dead under a build-only
  pipeline. Deployment happens from mandala's own deployspec, which invokes
  `deploy_solrproxy.yml` alongside `deploy_redis` / `deploy_netbadge` /
  `deploy_backend`, exactly as it already deploys the netbadge image.

**Why build-only** (decided 2026-08-12): the `.generated` Ansible inputs are
untracked and rendered by `terraform apply --target=local_file.*` at deploy time, so
a deploy phase here would apply the *same* `mandala/drupal/<env>` state that
mandala-drupal's deploy phase applies. A commit touching both `drupal/**` and
`solr-proxy/**` would race — S3 locking means no corruption, but the loser fails and
looks like real breakage. Accepted consequence: a solr-proxy-only change builds an
image that does not reach the box until mandala's pipeline next deploys.

⚠ **Local applies in `aws_cicd/pipelines/` fail partway.** The `staging` aws-vault
profile is the plain `ys2n` IAM user, lacking **both** `iam:GetRolePolicy` (already
known) and `iam:DeleteRolePolicy` (new). The first apply created 21 of 22 resources
then 403'd on read-back, leaving both inline policies tainted; the retry could not
replace them. Recovery: confirm via `aws iam list-role-policies` that the policies
really exist, `terraform untaint` both, then `plan -refresh=false`. Worth raising
with Dave.

**Config keys: DONE** — terraform-infrastructure `1c6e491c7`. The `solrproxy`
consumer is registered on dev-0 (id 2) with `SOLRPROXY_CLIENT_SECRET` in
`container_0.env.secret` and the non-secret `SOLRPROXY_*` / `SOLR_BASEURL` /
`DEFAULT_RETURL` / `REDIS_HOST` / `REDIS_PORT` in `container_0.env.managed`.

**Deploy wiring: DONE.** `ansible-playbook deploy_solrproxy.yml` runs from the app's
`pipeline/deployspec.yml`, after `deploy_netbadge.yml` and **before**
`deploy_backend.yml` — that one runs `drush updb` + a full `cim`, so anything that can
fail cheaply fails first and leaves Drupal untouched.

Sequenced deliberately: the playbook resolves its tag from
`/containers/uvalib/mandala-solr-proxy/latest` under
`failed_when: latest_tag.stderr != ""`, so wiring it in while ECR was empty would have
failed the *Drupal* deploy. The parameter was seeded by the pipeline's first green
build, `build-20260812132552` (2026-08-12), whose seven smoke tests all passed in
CodeBuild — the first time they ran anywhere but a laptop.

**First-build note:** that pipeline shows two earlier failures, both understood and
fixed. `CreatePipeline` failed with `Project cannot be found` — it auto-ran during the
window when the partial terraform apply had created the pipeline but not yet the
CodeBuild project. The next failed at `DOWNLOAD_SOURCE` with `YAML_FILE_ERROR:
Expected Commands[9] to be of string type` — a plain YAML scalar containing `": "`
parses as a mapping, and the inner double quotes do not protect it (PR #97). **Local
validation had only checked that the file parsed and counted commands, never that each
command was a string** — parsing and being correct are different properties.

~~⚠ One consequence to be aware of: `Searcher.php` requires `creds.php`, which now
throws without `SOLRPROXY_CLIENT_SECRET`.~~ **SUPERSEDED** — `creds.php` now degrades
to public-only rather than throwing (Yuji: unauthenticated public access is the 90%
case and must stay available). Retained below for the reasoning, which still explains
why the misconfiguration is logged on every request. Historical text: a proxy deployed
without that secret
serves **nothing at all**, rather than degrading to public-only results. That is the
deliberate choice — a silent downgrade to public-only is indistinguishable from
working — but it does mean the secret is required even for anonymous search.

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
