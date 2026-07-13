# Session Log: Sprint 01 1B1 Part4 D11 Deploy Scope

**Date:** 2026-07-13  
**Participants:** Yuji Shinozaki, Claude Code  
**Outcome:** Scoped the D11 backend deploy for 1b.1 part 4 and verified it against the live dev box (read-only SSH). See [`docs/planning/1b1-part4-d11-backend-deploy-scope.md`](../planning/1b1-part4-d11-backend-deploy-scope.md); new deferred notes [`production-migration-planning.md`](../deferred/production-migration-planning.md) and [`saml-sp-cert-expired-renewal.md`](../deferred/saml-sp-cert-expired-renewal.md); stored the SAML SP keypair (committed to terraform-infrastructure).

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-07-13 18:07

We are continuing work from this morning

---

## Claude — 2026-07-13 18:08

Let me orient myself on where we left off this morning.

---

## Claude — 2026-07-13 18:08

I'm oriented — we're picking up **1b.1 part 4** (SAML/NetBadge), where the app-repo half is merged (PR #32) and three things remain, in order:

1. Delete the 5 obsolete `public-0-auth-*` ALB rules in `terraform-infrastructure`
2. Build the SimpleSAMLphp env-var/Ansible config (clone from dsf.library)
3. Outside-DDEV validation on the **dev** instance

Let me check what's actually available locally before proposing where to start.

---

## Claude — 2026-07-13 18:08

Both repos are present locally: `terraform-infrastructure` and the `drupal-netbadge` reference. Let me check the terraform repo's state and glance at the two files the pickup plan centers on, so I'm grounded before we start.

---

## Claude — 2026-07-13 18:08

State matches the memory exactly. Terraform repo is on `master` (clean, aside from unrelated `library.virginia.edu` untracked files from someone else). The 5 `public-0-auth-*` rules are confirmed at lines 317–457 — all targeting `authproxy_target_arn` with `/Shibboleth.sso/*` paths, exactly the mod_shib vestige the plan says to delete.

Here's the ordered remaining work for part 4, and one thing worth noting: the plan explicitly says to **fold the ALB rule-deletion (task 1) into the SimpleSAMLphp env-var/Ansible pass (task 2)** — they touch the same terraform env and want a single `terraform plan`. So tasks 1+2 are really one build session, and task 3 (validation) needs the dev environment actually deployed first.

Let me confirm where you want to focus this afternoon before I start editing anything.

---

## Claude — 2026-07-13 18:13

Building tasks 1+2 together in the terraform repo. First I need to study the dsf reference thoroughly (the template) and mandala's current terraform/ansible structure. Let me create a task list and explore both.

---

## Claude — 2026-07-13 18:13

Let me read the dsf staging reference files (staging is the right template since mandala's "dev" lives inside the staging configs) and understand mandala's current deploy wiring.

---

## Claude — 2026-07-13 18:13

Now let me understand the container architecture and env-var wiring. The `deploy_netbadge.yml` runs a **separate** `netbadge-0` container (uvalib/drupal-netbadge, port 8081) — I need to understand how that relates to the Drupal container and where the SIMPLESAML_* env vars and Redis session store are set.

---

## Claude — 2026-07-13 18:14

Now the architecture is clear: `drupal-netbadge` is a **standalone SimpleSAMLphp SP container** (`netbadge-0`, port 8081, aliased `sp` on `drupalnet`), configured entirely by `SIMPLESAML_*` env vars — including `SIMPLESAML_STORE_TYPE: redis`, `SIMPLESAML_REDIS_DATABASE: 3`, `SIMPLESAML_REDIS_PREFIX: SIMPLESAML_DSF:`. The Drupal container reverse-proxies `/simplesaml/*` to it internally (why DSF needs no ALB SAML rules).

Now the critical mandala-specific piece: how the staging configs express the "dev" env, and mandala's own Redis usage (to pick a non-colliding DB/prefix). Let me examine mandala's staging ansible.

---

## Claude — 2026-07-13 18:14

mandala's staging uses the **older** deploy pattern (single `container.env`, `configure_backend.yml`) — not dsf's newer split-env (`container_0.env.generated/.managed/.secret` + `deploy_backend_0.yml`). And there are two tfvars: `staging-0` and `staging-1`. Let me read the current mandala deploy files to understand what I'm adapting into.

---

## Claude — 2026-07-13 18:14

Key finding: mandala's staging instances are named `staging-0/-1` but their **host_name is `mandala-drupal-dev-0/-1.internal.lib.virginia.edu`** — this confirms the memory's "dev = a 2nd env inside the staging configs." The `container.env` is empty, so env vars are threaded elsewhere. Let me read mandala's actual deploy playbook (`configure_backend.yml`) — the analog to dsf's `deploy_backend_0.yml`.

---

## Claude — 2026-07-13 18:16

This is a significant finding I need to surface before writing files. Let me confirm one more thing — whether a D11-style deploy exists on any branch or elsewhere.

---

## Claude — 2026-07-13 18:17

You're right — `configure_backend.yml` is just host provisioning (Docker install, EBS mount, fail2ban, cache-warm timers); there's **no `deploy_backend.yml` in mandala/drupal at all**. That confirms the bigger picture I was converging on, and it changes the shape of today's task-2 work. Let me lay out what I found:

**mandala's terraform env is still the legacy D7 Aegir stack, not a D11 dsf-style deploy:**
- `mandala/drupal/{staging,production}` provisions an Aegir host (`dockerfiles-hostmaster-1`, aegir uid, the 5 D7 CNAMEs: av/aegir/images/texts/sources/visuals). Aegir's hostmaster pulls the sites itself — hence no app-deploy playbook.
- There is **no** `deploy_backend_0.yml`, no `container_0.env.{generated,managed,secret}`, no `deploy_netbadge.yml` for mandala drupal. The only `deploy_backend.yml` files are Solr's. No D11 deploy on any branch either (only `mandala-fail2ban-rework`).

**Why this matters for task 2:** dsf's netbadge/SimpleSAMLphp config doesn't stand alone — it attaches to dsf's single-container D11 deploy. `deploy_backend_0.yml` mounts the SP config *into* the `drupal-0` (`uvalib/drupal-dsf`) container, and `deploy_netbadge.yml` + `container_0.env.*` ride alongside it. mandala has none of that scaffolding yet. So "clone dsf's netbadge config into mandala's staging configs" has no D11 backend deploy to land against — the whole dsf-style D11 AWS deploy for mandala is still greenfield.

The morning plan (mine) implicitly assumed a dsf-shaped mandala env already existed. It doesn't. Task 1 (delete the 5 dead `/Shibboleth.sso/*` → authproxy rules) is still independently actionable against the D7 **production** env. But task 2 forks. How do you want to handle it?

---

## Claude — 2026-07-13 18:18

Good pointer — so dsf/library carry **both** `configure_backend.yml` (host provisioning) *and* `deploy_backend_0.yml` (app-container deploy); they coexist. mandala has only the former (Aegir did app deployment itself). So scoping the D11 deploy means modeling mandala's missing `deploy_backend_0.yml` + container-env split + netbadge on the dsf/library pair. Let me read the dsf deploy_backend_0.yml in full and compare configure_backend across dsf and mandala.

---

## Claude — 2026-07-13 18:19

Reaffirmed — proceeding to scope the D11 backend deploy. I now have dsf's full model. The critical architectural fact: **in the dsf pattern the SimpleSAMLphp SP runs *inside* the Drupal container** (`drupal-0`), with config/metadata/cert/drupal-config mounted in and `drush cim --partial` loading the SAML Drupal config after boot. The separate `netbadge-0` is the test-IdP sidecar. So mandala's missing piece is a whole D11 app-deploy, with the SP embedded.

To ground the scope in mandala's actual D11 image/pipeline, let me check the app repo's `package/` and `pipeline/`.

---

## Claude — 2026-07-13 18:19

Now the gap is concrete: `pipeline/deployspec.yml` already calls `ansible-playbook ansible/deploy_backend.yml` — **but that playbook doesn't exist** in mandala's terraform ansible dir. That's the missing D11 app-deploy. Let me read the pipeline specs in full and the existing deferred note so I build on it rather than duplicate.

---

## Claude — 2026-07-13 18:20

The pipeline confirms the full gap: `buildspec` builds `uvalib/mandala-drupal`, and `deployspec` does `terraform apply` in `mandala/drupal/$ENVIRONMENT` then `ansible-playbook ansible/deploy_backend.yml` — but that terraform env is D7-Aegir and `deploy_backend.yml` doesn't exist. One more thing to verify for the scope: whether the D11 image itself sets up SimpleSAMLphp (dsf's image bakes it in). Let me read the rest of the Dockerfile.

---

## Claude — 2026-07-13 18:20

Confirmed a second gap: mandala's `package/Dockerfile` builds a **stock** Drupal 11 image — it does *not* install SimpleSAMLphp or wire Apache for `/simplesaml/` (dsf's `uvalib/drupal-dsf` image bakes that in). So the SP-in-container model needs image work too, not just ansible. I now have everything to write the scope. Let me set up tasks and write the scoping doc.

---

## Claude — 2026-07-13 18:21

Now let me write the scoping doc.

---

## Claude — 2026-07-13 18:22

Now add it to the planning `.pages` nav:

---

## Claude — 2026-07-13 18:22

Scope is captured in `docs/planning/1b1-part4-d11-backend-deploy-scope.md` (added to the nav). Here's where this leaves us.

## What the scoping turned up

The morning plan assumed part 4 was "clone dsf's netbadge config into mandala's existing D11 env + delete some ALB rules." **That D11 env doesn't exist.** `terraform-infrastructure/mandala/drupal/*` is still the **legacy D7 Aegir** stack, and the whole dsf-style D11 backend deploy is greenfield. The SimpleSAMLphp SP is one component *of* that deploy — it can't be built in isolation.

Concretely, two gaps beyond what the plan named:

1. **No app-deploy at all.** `pipeline/deployspec.yml` already calls `ansible/deploy_backend.yml`, but that file doesn't exist — `configure_backend.yml` only provisions the Aegir host. There's no container-env split, no SP assets, no SAML keys.
2. **The image itself lacks SimpleSAMLphp.** `package/Dockerfile` is stock Drupal 11. dsf's model runs the **SP inside the Drupal container** (config mounted into `/var/simplesamlphp/`, `drush cim --partial` post-boot) — so this needs image work too, not just Ansible.

The doc has the full component inventory (exists/missing table), the dsf target architecture, a 7-step work breakdown that folds in the netbadge assets **and** the ALB rule deletion, and §5 the open decisions.

## The real blockers (§5) — need you + Dave

These gate the terraform half and aren't mine to default:
- **Host strategy:** D7 is still live on `mandala-drupal-dev-{0,1}`. Does D11 repurpose those hosts (cut D7 over) or stand up new ones alongside?
- **RDS:** new D11 MySQL 8.4 DB on the shared staging instance, or dedicated?
- **Redis:** same cluster as ADR 014's `mandala_solr_fq` (separate DB/prefix, dsf-style) or dedicated?

## Recommended next step

Settle host/RDS/Redis with Dave before writing terraform. Meanwhile the **image work + Ansible app-deploy + SP assets** (§4 items 1–5) are all env-value-driven and *don't* depend on the host decision — I can draft those in parallel now. Two things worth noting: **ALB rule deletion (task 1) is still independently doable today** on D7 production, and it stays gated on `terraform plan` for listener-rule priorities.

Want me to start drafting the parallel-able pieces (I'd begin with `deploy_backend.yml` adapted from dsf's, then the container-env split and SP assets), or would you rather lock the §5 decisions first? I have not touched the terraform repo yet — it's still clean.

---

## User — 2026-07-13 18:23

What image is the drupal-dsf and drupal-library built from?  How do they get the SimpleSAMLPHP installation?

---

## Claude — 2026-07-13 18:24

Good question — the answer corrects part of my scoping note. Neither dsf nor library installs SimpleSAMLphp as a standalone app. Let me confirm the exact mechanism (how `/simplesaml/` gets served and where the library comes from) before I state it.

---

## Claude — 2026-07-13 18:25

That's the key detail I got slightly wrong in the scope doc — here's the precise answer.

## Base image

All three build from the **official Docker Hub Drupal image** (same family):

| Repo | Base image |
|---|---|
| `drupal-dsf` | `public.ecr.aws/docker/library/drupal:10.6.7` |
| `drupal-library` | `public.ecr.aws/docker/library/drupal:10.6.10` |
| `mandala-navina` | `public.ecr.aws/docker/library/drupal:11-php8.3-apache` |

## How they get SimpleSAMLphp — two pieces, and neither is a standalone install in the app image

**1. The library → via Composer.** None of the Dockerfiles install SimpleSAMLphp by hand. It comes in as a dependency of `drupal/simplesamlphp_auth: ^4.1` (in all three composer.json files, mandala included), pulled into `vendor/` by `composer install` via `simplesamlphp/composer-module-installer`. This is the **in-process** copy the `simplesamlphp_auth` module uses to validate the SAML session (the drupal-library Dockerfile comment "SimpleSAMLphp runs in-process here" refers to this). Config dir is externalized: `SetEnv SIMPLESAMLPHP_CONFIG_DIR /var/simplesamlphp/config`.

**2. The `/simplesaml/*` web endpoints → reverse-proxied to a separate `sp` container.** This is the part I mis-stated. Both dsf and library ship an Apache vhost that does:

```apache
ProxyPass        "/simplesaml/"  "http://sp:80/simplesaml/"
ProxyPassReverse "/simplesaml/"  "http://sp:80/simplesaml/"
SetEnv SIMPLESAMLPHP_CONFIG_DIR /var/simplesamlphp/config
```

`sp` is the `netbadge-0` container (`uvalib/drupal-netbadge`, alias `sp` on `drupalnet`) deployed by `deploy_netbadge.yml`. So that container is **not just a dev test-IdP — it serves the SP's `/simplesaml/*` web UI in every environment.** The two containers act as one SP because they **share the mounted config and the Redis session store** (`SIMPLESAML_STORE_TYPE=redis`) — a session created through `sp`'s `/simplesaml/` login is visible in-process to the Drupal container's `simplesamlphp_auth`. That shared Redis store is exactly why ADR 014's DB/prefix separation matters.

## What this corrects in the mandala scope

The mandala image gap is **smaller** than the scope doc claimed — `composer install` already vendors the library (mandala has `simplesamlphp_auth` in composer). What's actually missing in `package/Dockerfile` is just the **Apache vhost ProxyPass** `/simplesaml/ → http://sp:80/` + the `SIMPLESAMLPHP_CONFIG_DIR` SetEnv. And notably mandala's Dockerfile **already** runs `a2enmod proxy proxy_http headers` — they set up for exactly this and just haven't added the vhost yet. The `netbadge`/`sp` container is therefore **required in all envs**, not a dev-only test-IdP.

Let me fix the two places in the scope doc that state this imprecisely.

---

## Claude — 2026-07-13 18:26

That resolves the biggest open decision (§5.1): **replace the mandala-dev instance with the D11 instance — no D7 instance needed — but account for every unique component of the old dev instance.** Let me update the doc to reflect the decision and the corrected image work, then do the actual component audit.

---

## Claude — 2026-07-13 18:27

Now the core of the new instruction: audit the old Aegir dev (staging) env for **every unique component**, so nothing is lost at cutover. Let me inventory the staging terraform + ansible systematically.

---

## Claude — 2026-07-13 18:27

Rich findings already — the old dev env has mandala-specific **rdx (re-index, port 9001)** and **index (port 8765)** services with their own ALB routing, a special SG, and an IP allowlist. Let me read the remaining unique pieces (the ALB-routing variants, instance/EBS definition, ansible wiring).

---

## Claude — 2026-07-13 18:28

The old dev env has three notable mandala-unique surfaces: an **rdx (re-index) service on :9001** (IP-allowlisted to 3 Linode IPs, special SG), an **index service on :8765** (health-checked at `/solr/kmassets/status`), and **legacy `*-dev.shanti.virginia.edu` redirects**. Let me finish with the instance/storage definition and provisioning.

---

## Claude — 2026-07-13 18:29

Complete inventory in hand. There's also a **persistent EBS volume** (`/dev/xvdf`, 100 GB, notably `BackupPolicy=none`) and the recently-ported **fail2ban + cache-warm scraper mitigation**. Let me add the component audit (§6) to the doc — a disposition table for every unique component at D11 cutover.

---

## Claude — 2026-07-13 18:29

Important caveat — so the old dev box has three sources of truth, not one: **(1) terraform** (what I just audited), **(2) hand-installed drift on the instance** (not in any IaC), and **(3) a git repo somewhere** (likely the legacy `mandala_drupal_docker`). The terraform audit alone won't catch everything. Let me record the §6 audit with that framing, then hunt down the git repo.

---

## Claude — 2026-07-13 18:30

Now let me hunt down that git repo — likely the legacy Aegir/Docker deployment that holds the hand-installed pieces.

---

## Claude — 2026-07-13 18:31

Found the legacy repos at `/Users/ys2n/Code/mandala-legacy/`. The "git repo someplace" is almost certainly **`mandala_drupal_docker`** (the legacy Aegir/Docker deploy). Let me confirm what it holds and pin down what the rdx/index services actually are.

---

## Claude — 2026-07-13 18:31

Concrete identifications now:
- **The git repo** = `github.com/shanti-uva/mandala_drupal_docker` (legacy Aegir/Docker deploy: docker-compose, build/, scripts/, volumes/), plus siblings `mandala-solr-proxy`, `mandala_s3_synch`, `kmaps_engine`.
- **index service :8765** = the **mandala-solr-proxy** container (`8765:80`, health `/solr/kmassets/status`) → superseded by D11's monorepo `solr-proxy/` (ADR 014).
- **rdx service :9001** isn't a compose port in those repos — it lives in the Aegir/docker stack (KMaps re-index webhook, gated to the Linode IPs); pin down from `mandala_drupal_docker`'s compose or the live box.

Let me fold these into the doc.

---

## Claude — 2026-07-13 18:33

API Error: Connection closed mid-response. The response above may be incomplete.

---

## User — 2026-07-13 18:40

could you re-assess the last question

---

## Claude — 2026-07-13 18:40

Let me actually re-assess the rdx `:9001` question properly instead of leaving it as "DECIDE" — I'll dig into what actually serves that port rather than guessing.

---

## Claude — 2026-07-13 18:41

The grep hit large JSON data files. Let me target config/compose files only.

---

## Claude — 2026-07-13 18:42

Let me pin down the one remaining lead on `:9001` — the prod compose only maps Aegir hostmaster+database, so `:9001` isn't containerized there (consistent with "hand-installed"). Let me check the KMaps engine / D7 Apache for a `:9001` listener before re-assessing.

---

## Claude — 2026-07-13 18:43

Good — that settles it. The re-index logic lives in the Rails **`kmaps_engine`** (`FlareUtils.reindex_all`, feature sweepers), and crucially **`:9001` is defined in none of the git repos' compose/apache configs** — confirming your point that it's **hand-installed on the box**. Here's my re-assessment of the rdx `:9001` disposition (the last audit question).

## Re-assessment: rdx `:9001`

**What it is.** An inbound **re-index webhook**, IP-gated to 3 external KMaps source IPs (Linode). Its job: let external KMaps servers trigger a re-index of mandala's Solr when their terms/features change. The re-indexing engine itself is the Rails `kmaps_engine` (Puma :3000); `:9001` is the front door that external sources hit.

**Where it's defined — the important correction.** Not in `mandala_drupal_docker`, `mandala-solr-proxy`, or `kmaps_engine` (checked compose + apache + config). So it is **hand-installed on the live dev box** and exists in *no* IaC or repo. This is exactly the drift you flagged — and it means my earlier "pin down from compose *or* the live box" was too optimistic: **it can only be recovered from the running instance + team memory (Than), not from git.**

**D11 disposition — architecturally superseded, but with a live dependency to clear.** In D11 the KMaps-term → Solr flow is owned by `reindeer_x` (ADR 006/007, independent service) plus the direct-to-master `mandala_kmassets_sync` (1a.8). So the *function* of `:9001` is replaced. But two things must happen before it can be dropped:
1. **Recover the actual `:9001` service off the live box** (what listens, what it does with a request) — it's not in git.
2. **Confirm the external flow with Than/Andres**: do those 3 KMaps sources still *push* re-index events in the D11 model, or does `reindeer_x` replace that with a pull/independent sync? If push is gone, the IP owners must be told the endpoint is decommissioned.

Net: **not a straight "carry over," and not safe to silently drop** — it's a supersede-and-decommission that's gated on a live-box recovery + a Than/Andres confirmation, precisely because it's hand-installed.

Let me capture that in the doc.

---

## Claude — 2026-07-13 18:43

Done — the doc's rdx `:9001` row now reflects the re-assessment: **supersede-and-decommission**, gated on a live-box recovery + a Than/Andres confirmation, explicitly flagged as hand-installed (in no repo).

Where the scope doc (`docs/planning/1b1-part4-d11-backend-deploy-scope.md`) stands now:
- **§5.1 host strategy** — decided (replace mandala-dev in place).
- **§6 audit** — three sources of truth called out (terraform / hand-installed drift / the `shanti-uva/mandala_drupal_docker` repo); disposition table for every unique component, with `:8765`=solr-proxy (REPLACE), `:9001`=rdx (SUPERSEDE+decommission), Shanti redirects (CARRY), 5 D7 CNAMEs (COLLAPSE), EBS + fail2ban/cache-warm accounted for.
- **Still open:** §5.2/5.3 (RDS + Redis, need Dave); the two live-box actions (recover hand-installed drift; identify what serves `:9001`).

The single biggest residual risk is the hand-installed drift — and by definition it's only knowable from the running `mandala-drupal-dev-{0,1}` box, not from anything I can read here. If you want, next I can either (a) draft a concrete diff/recovery checklist to run against the live instance over SSH, or (b) start drafting the parallel-able D11 pieces (the `deploy_backend.yml` + the `/simplesaml/` vhost for `package/Dockerfile`), which don't depend on the RDS/Redis decisions. Which way do you want to go?

---

## User — 2026-07-13 18:46

You can have access to the dev box from this session.  mandala-drupal-dev-0.internal.lib.virginia.edu using my ssh credentials

---

## Claude — 2026-07-13 18:46

I'll access the box **read-only** — inspection only, no writes, no service restarts, no config changes on a live instance. Let me test connectivity first.

---

## Claude — 2026-07-13 18:46

Connected as `ys2n` to staging-0 (AL2023). Now read-only inspection — first, what's listening (especially `:9001` and `:8765`), running containers, and the persistent volume.

---

## Claude — 2026-07-13 18:47

This is a goldmine and it **revises the `:9001` re-assessment significantly**. Key finds:

- **`reindeer_x` is already running on this box** — `9000/tcp` + `9001/**udp**`. So `:9001` is now reindeer_x (UDP), not the old HTTP re-index webhook. The D11-era KMaps sync service (ADR 006/007) is *already here*, under docker network `kmapssolrsync_default` (its former name).
- The D7 app uses a **local `mariadb` container**, not shared RDS (relevant to §5.2).
- Persistent EBS = `/mnt/docker` (the Docker data-root, 70G/100G used) — so "persistent data" is the docker overlay2 + volumes.
- Network drift: **two** solr-proxy networks (`mandala-solr-proxy_default` + `mandalasolrproxy_default`).

Let me pin down what reindeer_x's ports do, and where the compose stacks + systemd/cron are defined on disk.

---

## Claude — 2026-07-13 18:48

Now the picture is concrete. Three compose stacks live as **git checkouts in `/usr/local/`** with hand-created `.env` files, started manually (only the two mandala timers are systemd-managed). And **reindeer_x's real HTTP port is 9000**, not 9001 — so the terraform `rdx :9001` target is stale relative to what's actually running. Let me locate the reindeer_x stack dir and capture the hand-config `.env` keys (values redacted) — that's the drift that must be ported.

---

## Claude — 2026-07-13 18:48

Complete picture now. The "hand-installed + git repo" mix is fully characterized. Let me record the live-box audit in the doc, then summarize.

**What's actually on dev-0:**
- Three legacy stacks are **git checkouts hand-placed in `/usr/local/`**, started manually (no systemd except the two mandala timers):
  - `/usr/local/dockerfiles` → `shanti-uva/mandala_drupal_docker` on branch **`fail2ban-rework`** (+ untracked `docker-compose-dev.yml`) — the **active** Aegir D7 stack
  - `/usr/local/mandala-solr-proxy` → `main`, **uncommitted edit** to `docker-compose.yml`
  - `/usr/local/mandala_drupal_docker` → a second, idle checkout of the same repo
  - `/usr/local/kmaps-solr-sync-out` → the reindeer_x/`kmapssolrsync` stack
- **Hand-created `.env` secrets** (in no IaC): Aegir `MYSQL_LOCAL_ROOT_PW/FQDN/COOKIE_DOMAIN/HTTP_PORT/SSH_PORT`; solr-proxy `SOLR_BASEURL/DEFAULT_RETURL`.
- **reindeer_x is the live D11 replacement**, already here (`kmapssolrsync` project, `KMAPS_SYNC_CLASS=staging`, app on **9000/tcp**, redis `workqueue`). The terraform `rdx :9001` HTTP target is stale vs. this.

---

## Claude — 2026-07-13 18:49

Now update the disposition rows and the RDS decision to reflect the live findings.

---

## Claude — 2026-07-13 18:49

Now update the RDS decision (§5.2) with the local-mariadb finding, and add a `workqueue`/reindeer-redis note.

---

## Claude — 2026-07-13 18:51

Done — the live-box audit is captured in the scope doc (§6). Here's what the inspection changed.

## What dev-0 actually is

It's a **hybrid box** — the legacy D7 Aegir stack *and* the D11-era sync service run side by side, all as **hand-placed git checkouts in `/usr/local/`, launched manually** (nothing but the two `mandala-*` timers is systemd-managed):

| Stack | Ports | Where | State |
|---|---|---|---|
| Aegir D7 (`hostmaster` + `mariadb`) | 8080, 8222, 3306 | `/usr/local/dockerfiles` | `mandala_drupal_docker` on branch **`fail2ban-rework`** + untracked dev compose |
| `mandala-solr-proxy` | 8765 | `/usr/local/mandala-solr-proxy` | `main`, **uncommitted** compose edit |
| `reindeer_x` + `workqueue` redis | 9000/tcp, 9001/udp, 6379 | `/usr/local/kmaps-solr-sync-out` | live, `KMAPS_SYNC_CLASS=staging` |

## The `:9001` question — resolved, and my earlier re-assessment revised again

`:9001` isn't a stale hand-installed HTTP webhook to decommission. It's **`reindeer_x`** (UDP; its real app port is **9000/tcp**) — i.e., **the D11 replacement is already deployed on this box**. The terraform `rdx:9001`/HTTP target + `mandala-rdx-dev` CNAME are *stale relative to what's running*. So the disposition flips from "supersede + decommission a mystery service" to **"reconcile the ALB targets against the reindeer_x that's already here"** (ADR 006/007). The one thing still needing Than/Andres: whether the 3 external Linode IPs still *push* or reindeer_x now pulls.

## Other findings that move earlier decisions

- **D7 uses a local `mariadb` container, not RDS** → §5.2: D11's DB is genuinely net-new, no existing mandala RDS to reuse.
- **Redis now has three consumers** (§5.3): ADR 014 visibility token, SimpleSAMLphp sessions, *and* reindeer_x's `workqueue`.
- **Persistent data = `/mnt/docker`** (the Docker data-root, 70 G used), incl. the `var-aegir` volume + mariadb data; still `BackupPolicy=none`.
- **Hand-drift to port** is now enumerated: the `.env` secrets (MySQL root pw, `SOLR_BASEURL`, `DEFAULT_RETURL`, `FQDN`, `COOKIE_DOMAIN`), the feature-branch checkout, and the uncommitted solr-proxy compose edit.

The drift turned out **modest and recoverable** — not the scary unknown it could have been. Nothing on the box was modified; all read-only.

Want me to next (a) diff the `/usr/local` checkouts against their upstreams to confirm exactly what's un-pushed (so nothing's lost), or (b) start drafting the parallel-able D11 deploy pieces now that the DB/Redis picture is clearer?

---

## User — 2026-07-13 18:57

please reassess the database situation by checking each drupal host via drush sql-connect.   we.g. drush @mandala-dev.internal.lib.virginia.edu sql-connect

---

## Claude — 2026-07-13 18:57

Good idea — `sql-connect` shows the real DB endpoint each site actually uses, which settles the DB question definitively. These are Aegir site aliases, so drush lives in the `dockerfiles-hostmaster-1` container as the `aegir` user. Let me find the aliases first. I'll **redact passwords** in anything I capture.

---

## Claude — 2026-07-13 18:57

Interesting — there's a `@server_rdsstandardstaginginternallibvirginiaedu` alias (an RDS server) alongside `@server_database` (local mariadb). So the DB situation may be mixed. Let me run `sql-connect` on each site alias, capturing host/db/user and **redacting passwords**.

---

## Claude — 2026-07-13 18:58

That corrects my earlier `docker ps`-based assumption — and it's exactly why you had me verify. The reality:

- **All the D7 content sites are on the shared RDS `rds-mysql8-staging`** (MySQL 8), each its own database (`mandala_dev`, `mandala_av_dev`, `mandala_images_dev`, `mandala_sources_dev`, `mandala_texts_dev`, `mandala_visuals_dev`), common user `mandala_sites_dev`.
- **Only Aegir's own control-panel DB** (`@mandala-aegir-dev` → `mandalaaegirde_2`) is on the **local `database` mariadb** container.

So "D7 uses a local mariadb" was wrong — the local container is just Aegir bookkeeping; real site data is on the same shared RDS dsf uses. Let me enumerate the full DB inventory on that RDS and check the short/non-dev aliases to be thorough.

---

## Claude — 2026-07-13 18:59

Definitive now. `SHOW DATABASES` on `rds-mysql8-staging` confirms the full mandala inventory — including **`mandala_shared_dev`**, the cross-site shared user DB (the D7 kludge, confirmed live). Let me correct the doc; the earlier "local mariadb" line was misleading.

---

## Claude — 2026-07-13 18:59

Now update §5.2 with the DB inventory and the corrected decision, and add the live-box audit note about the DB verification.

---

## Claude — 2026-07-13 18:59

Let me correct the earlier bullet in the live-box audit that stated the DB conclusion from `docker ps` alone.

---

## Claude — 2026-07-13 18:59

Reassessment complete — and it flips the DB conclusion. Verified via `drush sql-connect` on every site alias:

## The real DB situation

**D7 site data lives on the shared RDS, not locally.** Every content site connects to `rds-mysql8-staging.internal.lib.virginia.edu` (MySQL 8), user `mandala_sites_dev`:

| Alias | Database | Host |
|---|---|---|
| `@mandala-dev` | `mandala_dev` | rds-mysql8-staging |
| `@mandala-av-dev` | `mandala_av_dev` | rds-mysql8-staging |
| `@mandala-images-dev` | `mandala_images_dev` | rds-mysql8-staging |
| `@mandala-sources-dev` | `mandala_sources_dev` | rds-mysql8-staging |
| `@mandala-texts-dev` | `mandala_texts_dev` | rds-mysql8-staging |
| `@mandala-visuals-dev` | `mandala_visuals_dev` | rds-mysql8-staging |
| `@mandala-aegir-dev` | `mandalaaegirde_2` | **`database`** (local mariadb) |

The local `mariadb` container I flagged from `docker ps` is **only Aegir's own control-panel DB** — the actual Drupal data was on RDS all along. Good call having me check; `docker ps` was misleading.

`SHOW DATABASES` on that RDS confirms the full inventory, including **`mandala_shared_dev`** — the cross-site shared user DB (the D7 table-prefix kludge), now confirmed live rather than just documented.

## What this changes

- **§5.2 flips from "net-new RDS" to "reuse the shared instance."** mandala already lives on `rds-mysql8-staging` — the *same* RDS dsf uses, and MySQL 8 (matches ADR 012's 8.4 target). D11 just needs a database on it: either a fresh `mandala_d11_dev` or migrate into/reuse `mandala_images_dev` (the Images pilot).
- **These per-site DBs are the live migration source** — directly relevant to the `staging-migration-execution-prerequisites` deferred note; the source is already on the RDS, not a dump to load.
- **`mandala_shared_dev` is the user-migration source** — ties to the still-blocked 1b.2 user↔collection memberships ([[project-d7-shared-user-database]]).

Doc (§5.2, §6, live-box audit) updated to reflect all of this. Want me to enumerate the row counts / last-updated of `mandala_images_dev` (to gauge freshness as a migration source vs. the June/July dumps you've been using), or move on to drafting the parallel-able D11 deploy pieces?

---

## User — 2026-07-13 19:03

In case it makes a difference we no longer care about the aegir database, we haven't used aegir in many years.  We also don't care about the specific mandalal-drupal-dev-0 databases, they are just development instances that also reside on mandala-drupal-dev-1 (the staging instance).  The migration work will focus on staging as the D7 source.

---

## Claude — 2026-07-13 19:04

That's a useful correction to my mental model — thank you. Three things land:
1. **Aegir DB = don't care** (dead for years) → drop it from scope entirely.
2. **dev-0's specific `*_dev` databases = don't care** — they're just dev data.
3. **The two nodes are two *logical environments*, not an HA pair**: `dev-0` = development, `dev-1` = **staging** (the D7 migration source). That resolves the §5.4 "single vs HA pair" question I'd raised.

Let me fix the doc so it doesn't over-index on the throwaway details, and record the durable facts (RDS reuse; dev-0/dev-1 split; migration source = staging).

---

## Claude — 2026-07-13 19:05

The DB question is now settled and the doc reflects your clarifications. Net picture:

- **D11 reuses the shared `rds-mysql8-staging`** (MySQL 8, matches ADR 012) — no new RDS, no DB migration needed for the *deploy* (just a database on it). Resolves §5.2.
- **Aegir DB and the `dev-0` `*_dev` databases are out of scope** — dead/throwaway.
- **`dev-0` = dev (what D11 replaces), `dev-1` = staging (the D7 migration source, stays put).** They're two logical envs, not an HA pair — resolves §5.4.
- **Migration is a separate track** focused on staging (`dev-1`), not part of this D11-deploy scope.

That clears two of the six open decisions. What's left genuinely open:
- **§5.3 Redis topology** — three consumers (ADR 014 token, SimpleSAMLphp sessions, reindeer_x workqueue); needs Dave.
- **§5.5 dev SP entityID/base URL** — Dave / ITS NetBadge.
- **§5.6 ALB target/health-check** for the D11 container port.

And the parallel-able build work (§4) — the `deploy_backend.yml`, the `/simplesaml/` vhost for `package/Dockerfile`, and the SP assets — is unblocked and doesn't depend on any of those.

Given how much this session has firmed up, do you want me to (a) start drafting those parallel-able D11 pieces, or (b) pause here — the scope doc is in solid shape as the team artifact and this is a natural handoff point? I'd lean (b) unless you want to keep building, since the remaining terraform decisions want Dave in the loop.

---

## User — 2026-07-13 19:08

And an overall strategy note.  I am assuming that any migrations we do now are NOT the final migrations.  The staging database lags behind production by several months, but there likely is very little that is significant about the shape of the data, that isn't in the staging database.  So, working with the staging database now, is sufficient to prove the migration and development.  That said we have to plan a production migration in the future.

---

## Claude — 2026-07-13 19:08

That's an important strategy assumption to capture durably — it governs how we treat staging-as-source. Let me record it in two places: a strategy note in the scope doc, and a dedicated deferred note for the future production migration (the actionable "must plan later" item), so it isn't lost.

---

## Claude — 2026-07-13 19:08

Now the deferred note. Let me check the README/.pages format first.

---

## Claude — 2026-07-13 19:09

Captured in both places:

- **Scope doc §5.2** — a "Migration strategy" callout: migrations now aren't final; staging's data *shape* ≈ production so staging is sufficient to prove migration + development now; production migration is future work.
- **New deferred note** `docs/deferred/production-migration-planning.md` (added to `.pages`) — the actionable future item, with the planning checklist for a real D7-prod → D11-prod cutover: fresh prod extract, prod recalibration of baselines, cutover/downtime/rollback, the `mandala_shared_*` user migration as a hard prerequisite, KMaps/Solr prod write path, and a freeze-vs-delta decision.

That way the "staging is good enough for now" license and the "but plan prod later" obligation are both durable and linked to the existing `staging-migration-execution-prerequisites` and `d7-shared-user-database` notes.

This feels like a natural stopping point — the scope doc (`docs/planning/1b1-part4-d11-backend-deploy-scope.md`) is now a solid team artifact: the D7-Aegir-vs-D11 gap, the corrected SP architecture, the full live-box audit, and the DB/host/migration facts all verified against the running box rather than assumed. The remaining open items (§5.3 Redis, §5.5 SP entityID, §5.6 ALB) want Dave/ITS, and the §4 build pieces are unblocked whenever you want to start them.

Want me to refresh the `project-mandala-state` memory to reflect this session before we wrap, or leave it here?

---

## User — 2026-07-13 19:12

Let's discuss what we need from Dave (some of that we already have).

---

## Claude — 2026-07-13 19:13

Good — let me frame it around what's actually **Dave's domain** (Director, Cloud Infrastructure: AWS resource provisioning + the terraform-infrastructure repo + NetBadge/ITS liaison), and triage each item into *already have* vs *still need*.

## What we already have (don't need to ask Dave)

- **RDS identity & connection** — `rds-mysql8-staging` (MySQL 8), user `mandala_sites_dev`, mandala already lives there (same RDS as dsf). Verified via `sql-connect`.
- **Host strategy** — decided: replace `dev-0`; `dev-1` stays as D7 staging source.
- **reindeer_x** — already deployed; independent service (ADR 007), not Dave-gated.
- **The SP config values** — entityID pattern, UVA IdP metadata (`urn:mace:incommon:virginia.edu`), attribute OIDs (uid/computing-id, mail) — we have these from the D7 config ([[reference-d7-shibboleth-sp-config]]) and the dsf reference.
- **The terraform edits themselves** — we have the code (delete 5 auth rules, add D11 target); we just can't *apply*.

## What we still genuinely need from Dave

1. **Redis cluster for D11** — the real gap. Three logical consumers (ADR 014 `mandala_solr_fq` token, SimpleSAMLphp sessions, Drupal cache). dsf shares `ha-redis-staging`. Does mandala get its own ElastiCache, or share dsf's cluster with separate DB indices/prefixes? Is one provisioned? *(reindeer_x's `workqueue` is just a local container — separate concern.)*

2. **A D11 database + user on `rds-mysql8-staging`** — we know the instance; we need either a `mandala_d11` DB + grant provisioned, or confirmation the team self-serves that. Small but blocking.

3. **NetBadge SP registration (Dave ↔ ITS)** — the part we *partly* have. We need Dave to confirm: is the **existing dev/staging SP** entityID + ACS reusable for the D11 instance, is **attribute release** (computing-id → uid, mail) already in place on the IdP side, and do we **reuse the existing SAML signing cert or generate + register a new one** with ITS? This is the one with real external lead time.

4. **Terraform/ALB apply + review** — go-ahead and the apply path to edit the shared mandala terraform (delete the 5 dead auth rules — needs a `plan` for listener priorities — plus the D11 target/health-check and DNS). The `authproxy` component stays (Solr uses it).

So of the six original open decisions, Dave really only owns **Redis (#1)**, **RDS provisioning (#2)**, **NetBadge/ITS (#3)**, and **terraform apply (#4)** — and #3 is half-done.

Two things worth deciding before we take this to Dave:
- On **#3**, I can recover the *exact* live SP entityID + signing cert from the D7 config on the box (or the `mandala-drupal` repo's `simplesamlphp/` dir) — that would let us hand Dave "reuse this exact SP" vs. "register new," sharpening the ask. Want me to pull that?
- On **#1**, do you already know whether mandala is meant to have its own Redis or share `ha-redis-staging`? If you know, that closes it without Dave.

Which of these do you want to dig into?

---

## User — 2026-07-13 19:19

Pull the live SP entityID and cert from the box.  THe question is where to store it.  I think we should follow the pattern we have been using for lirary and dsf: What we have done in the past is encrypt them using the crypt-key.ksh scripts.

---

## Claude — 2026-07-13 19:20

On it. Two parts: (1) locate the `crypt-key` tooling and the dsf/library key-storage pattern so I know the exact target format, and (2) recover the live SP config + cert from the box (I'll capture the entityID and the **public** cert, and *locate* the private key without printing it — the private key only ever gets handled encrypted).

---

## Claude — 2026-07-13 19:20

The pattern is now clear: `<env>/keys/<project>-saml-<env>.crt` (public cert, committed plaintext) + `<project>-saml-<env>.pem.cpt` (private key, encrypted via `crypt-key.ksh`, committed; the raw `.pem` stays gitignored). For mandala that's `mandala/drupal/staging/keys/mandala-drupal-saml-staging.{crt,pem.cpt}`. Let me read the rest of `crypt-key.ksh` (the secret/passphrase mechanism) and locate the live SP config + cert on the box.

---

## Claude — 2026-07-13 19:21

Found the live D7 SP config at `/var/aegir/platforms/mandala-base-dev/simplesamlphp/`. And `crypt-key.ksh` = `ccrypt -e` with the passphrase from `get-secret.ksh <name>` (there's a `decrypt-key.ksh` counterpart). Now let me pull the SP entityID + the **public** cert, and *locate* the private key without printing it. I'll grep `config.php` for only non-secret keys (avoiding `secretsalt`/`auth.adminpassword`).

---

## Claude — 2026-07-13 19:21

Understood — I'll **pause before any encryption/storage step** and let you walk me through the fine details. Here's what I recovered from the live SP config so far, which is useful regardless:

## Live D7 SP config (`/var/aegir/platforms/mandala-base-dev/simplesamlphp/`)

`default-sp`:
- **`entityID` => `null`** — this is the notable detail. The SP doesn't hard-code an entityID; SimpleSAMLphp auto-generates it from the metadata URL. The commented reference shows what that resolves to: `https://mandala-dev.internal.lib.virginia.edu/simplesaml/module.php/saml/sp/metadata.php/default-sp`.
- `idp` => `urn:mace:incommon:virginia.edu` (UVA NetBadge — matches what we have).
- `AssertionConsumerService` => `https://mandala-dev.internal.lib.virginia.edu/simplesaml/module.php/saml/sp/saml2-acs.php/default-sp`
- `privatekey` => `saml.pem`, `certificate` => `saml.crt` (in `certdir => cert/`).
- `baseurlpath` => `https://<host>/simplesaml/`; technical contact = you (ys2n@virginia.edu).

**Why the `entityID => null` matters for the Dave/ITS question:** the SP's identity is tied to the **host** (`mandala-dev.internal.lib.virginia.edu`). If the D11 instance keeps that hostname, the derived entityID + ACS stay identical — so the existing ITS registration should still match, i.e. "reuse," not "register new." If the host changes, the entityID changes and ITS needs re-registration. That's the crisp thing to confirm with Dave.

I have **not** touched the private key (`saml.pem`) — locating it is as far as I went, and I'll leave pulling/encrypting it until you've explained the crypt-key details.

Go ahead — what are the fine details I need to know?

---

## User — 2026-07-13 19:22

Do you understand how crypt-key.ksh (and decrypt-key.ksh) work?

---

## Claude — 2026-07-13 19:22

Let me read the actual scripts rather than assert from memory — `decrypt-key.ksh` and the `get-secret*` helpers.

---

## Claude — 2026-07-13 19:23

Yes. Here's my understanding — tell me where the fine details differ:

## The mechanism

**Passphrase source.** Both scripts get their key from **AWS Secrets Manager**, not a local passphrase: `get-secret.ksh <secret-name>` → `aws secretsmanager get-secret-value --secret-id <secret-name>` → `.SecretString`. So encrypt and decrypt are symmetric and share one named secret.

**`crypt-key.ksh <key> <secret-name>`:**
1. Refuses if `<key>.cpt` already exists (no clobber).
2. Pulls the passphrase from Secrets Manager.
3. `echo $SECRET | ccrypt -e <key> -k -` — encrypts with the passphrase on stdin. Since `ccrypt -e` works in place, this **consumes the plaintext `<key>` and leaves only `<key>.cpt`**.

**`decrypt-key.ksh <key>.cpt <secret-name>`:**
1. No-op if the plaintext already exists.
2. Pulls the same passphrase.
3. **Copies the `.cpt` aside first** (`$$.save`) because `ccrypt -d` removes its input, decrypts, `chmod 600` + `touch` the result, then **restores the `.cpt`** — so you end with *both* the plaintext and the `.cpt`.

**What gets committed:** only `<project>-saml-<env>.crt` (public cert, plaintext) and `<project>-saml-<env>.pem.cpt` (encrypted key). The raw `.pem` is gitignored/transient — regenerated on demand via `decrypt-key.ksh`. At deploy time the playbooks reference `global/playbooks/check_private_keys.yml` (the "Check that the private keys exist" step) to materialize the `.pem` before Ansible copies it into the container.

**`get-secret-file.ksh`** is the newer alternative — its header literally says "AWS Secrets Manager replacement for decrypt-key.ksh": instead of an encrypted file in git + a passphrase, it stores the whole secret in Secrets Manager and writes it straight to a file.

## Where I expect the fine details to be

- **The exact `<secret-name>`** holding the ccrypt passphrase — org knowledge I don't have. Is it one shared secret across library/dsf/mandala, or a per-project one I'd need created?
- **Do we follow the older ccrypt `.cpt` pattern (to match library/dsf) or the newer `get-secret-file.ksh` Secrets-Manager pattern** for mandala?
- **AWS creds/permissions** — I'd need Secrets Manager read (and the AWS profile) for the encrypt step to even run.
- **The `ccrypt -e` consumes-the-plaintext behavior** and how you want the raw `saml.pem` handled once pulled off the box.

What are the details you wanted to flag?

---

## User — 2026-07-13 19:31

The secret-name is the exact detail I was thinking of.   Normally the secret name follows the convention of using the terraform-infrastucture relative path to the file to be decrypted as the secret-name.   We end up reusing the secret-name for an existing key instead of generating a new one for the saml keys.   So for example we use the secret-name  "library.virginia.edu/staging/keys/library-drupal-staging.pem" for decrypting library.virginia.edu/staging/keys/library-drupal-saml-staging.pem.cpt

---

## Claude — 2026-07-13 19:32

That's the key detail — thank you. So the SAML `.pem.cpt` **reuses the existing SSH host key's secret-name** rather than getting its own Secrets Manager entry. Mapping that convention to mandala:

- **Existing SSH key (already has a secret):** `mandala/drupal/staging/keys/mandala-drupal-staging.pem` — and I confirmed `mandala-drupal-staging.pem.cpt` is present in that keys dir, so that Secrets Manager entry already exists.
- **Secret-name to reuse (for encrypt *and* deploy-time decrypt):** `mandala/drupal/staging/keys/mandala-drupal-staging.pem`
- **New SAML files to create:**
  - `mandala/drupal/staging/keys/mandala-drupal-saml-staging.crt` — the public cert (`saml.crt` from the box), committed plaintext.
  - `mandala/drupal/staging/keys/mandala-drupal-saml-staging.pem.cpt` — `saml.pem` from the box, encrypted with `crypt-key.ksh <path-to-saml.pem> mandala/drupal/staging/keys/mandala-drupal-staging.pem`.

So the encrypt command would be, from the terraform repo root:
```
scripts/crypt-key.ksh mandala/drupal/staging/keys/mandala-drupal-saml-staging.pem \
                      mandala/drupal/staging/keys/mandala-drupal-staging.pem
```
(after placing the raw `saml.pem` at that `.pem` path), and the paired deploy-time decrypt uses the **same** second argument.

This also lines up with dsf's `deploy_backend_0.yml`, which reads `keys/{SIMPLESAML_PROJECT}-{SIMPLESAML_ENV}.pem` → so `SIMPLESAML_PROJECT=mandala-drupal-saml`, `SIMPLESAML_ENV=staging` gives exactly `mandala-drupal-saml-staging.pem`.

A couple of things before I'd actually run it (still holding):
1. **AWS creds** — `crypt-key.ksh` needs Secrets Manager read for that secret. Do you want me to run the encryption from this session (do I have an AWS profile with access here), or is that a step you run?
2. **Reuse vs. regenerate the keypair** — we're reusing the *existing* dev SP keypair (`saml.pem`/`saml.crt`) pulled from the box, not generating a fresh one. Correct? (Consistent with keeping the host-derived entityID stable so ITS registration still matches.)

Do I have the secret-name convention right, and are there other fine details?

---

## User — 2026-07-13 19:37

Yes let's just reuse the pre-existing saml keys pair.  That is what is used in the existing shibboleth registration.  The crypt of course will be freshly generated.   And yes my current AWS keys are sufficient to do the encryption and decryption (but of course test before removing the saml.crt and saml.pem.   Typical I wil use aws-vault to exchange my aws tokens.  (see my shell's aliases to see what I use).

---

## Claude — 2026-07-13 19:38

Got it — reuse the existing keypair (matches the live Shibboleth registration), fresh `.cpt`, and **verify the encrypt→decrypt round-trip before deleting any plaintext**. Let me find your aws-vault alias so I use the right profile.

---

## Claude — 2026-07-13 19:38

aws-vault is present with a `staging` profile. Let me find your specific alias (the grep patterns may have missed it) — checking for how you wrap `aws-vault exec`.

---

## Claude — 2026-07-13 19:39

Found them:
```
tf='aws-vault exec staging -- terraform'
ap='aws-vault exec staging -- ansible-playbook'
aws='aws-vault exec staging -- aws'
```
So the wrapper is `aws-vault exec staging -- <cmd>`. Since `crypt-key.ksh` calls `aws` internally, I'd run the whole script under `aws-vault exec staging --`.

Let me do the **non-secret prep** first (no AWS needed): check the keys dir + `.gitignore` (so the raw `.pem` can't be accidentally committed), pull the **public** `saml.crt` to view it, and confirm the private key exists — I'll handle the private key without ever printing it.

---

## Claude — 2026-07-13 19:40

`.gitignore` covers it well — `*/*/*/keys/*.pem` matches `mandala/drupal/staging/keys/*.pem`, so the raw `.pem` can't be accidentally committed, while `.pem.cpt` and `.crt` remain committable. The existing keypair (`saml.crt` 1424 B, `saml.pem` 1704 B, from Jun 2021) is there.

Let me pull both to the target paths — cert viewable, **key written straight to file (never printed)** + backed up for the round-trip test — and verify the key matches the cert (modulus hashes only, no secrets).

---

## Claude — 2026-07-13 19:41

Prep is done and the pair verifies — but I hit something you need to see first.

## ✅ Placed & verified
- `mandala-drupal-saml-staging.crt` (public, committable) and `mandala-drupal-saml-staging.pem` (raw key, **git-ignored**, backed up for the round-trip test) are in place.
- **Key matches cert** — modulus MD5 identical (`1a3bef1d…`), no secrets exposed.
- `.gitignore` confirmed: raw `.pem` ignored, `.crt` + (future) `.pem.cpt` committable.

## ⚠️ The cert is expired
```
subject/issuer = CN=Raf, O=SHANTI … emailAddress=rca2t@virginia.edu   (self-signed)
notBefore = Jun 30 2016
notAfter  = Jun 30 2026   ← expired 13 days ago (today is Jul 13 2026)
```

This is the existing SP keypair — self-signed by SHANTI (Raf / rca2t), 10-year validity that lapsed **June 30, 2026**. Two implications:

1. **For matching the current Shibboleth registration**, reuse is still "correct" — SAML SP signing certs are trust-by-metadata, and many IdPs (incl. Shibboleth) don't hard-enforce `notAfter` on SP certs, which is likely why the live SP still functions. So storing this pair now reproduces today's behavior.
2. **But it's genuinely expired**, so this keypair is on borrowed time regardless of D11 — it'll want regeneration + re-registration with ITS (via Dave) as a near-term item, independent of this migration.

I've **paused before the encryption step** because this is a fork:

- **(A) Encrypt & store the existing (expired) pair now** — matches the current registration, unblocks the D11 dev bring-up, and we file cert-renewal as a follow-up with Dave/ITS. (Your stated intent — just confirming now that you know it's expired.)
- **(B) Regenerate a fresh keypair now** + register with ITS before storing — cleaner, but adds an ITS round-trip to the critical path and diverges from "reuse what's registered."

My read: **(A)** to keep moving, with a deferred note for the cert renewal. Want me to proceed with the `aws-vault exec staging -- scripts/crypt-key.ksh …` encryption on the existing pair (then round-trip test before removing the plaintext), or regenerate?

One heads-up on execution: `crypt-key.ksh` calls `aws secretsmanager` internally, so I'd run it under `aws-vault exec staging --` — which may pop a Touch ID / keychain prompt for you to approve. Good to run it from here, or would you rather run that one command via `!`?

---

## User — 2026-07-13 19:42

Proceed with (A), encrypt the existing pair, and let's plan how to renew the keys next.

---

## Claude — 2026-07-13 19:43

Proceeding with (A). Running the encryption under `aws-vault exec staging` — approve the Touch ID / keychain prompt if it pops.

---

## Claude — 2026-07-13 19:44

Encryption succeeded — `.pem.cpt` created, raw `.pem` consumed. Now the round-trip test: decrypt and compare against the backup of the original.

---

## Claude — 2026-07-13 19:44

Round-trip **verified** — decrypted key is byte-identical to the original and still matches the cert. `.cpt` restored intact. Now clean up the plaintext (the raw `.pem` is transient/gitignored; only `.crt` + `.pem.cpt` rest in git) and confirm what would commit.

---

## Claude — 2026-07-13 19:45

Done — the SAML keypair is stored and verified. Final resting state (both untracked, ready to commit when you want):
```
mandala/drupal/staging/keys/mandala-drupal-saml-staging.crt       (public cert, plaintext)
mandala/drupal/staging/keys/mandala-drupal-saml-staging.pem.cpt   (private key, ccrypt-encrypted, reusing the mandala-drupal-staging.pem secret)
```
Plaintext key + backup shredded; round-trip proved the `.cpt` decrypts byte-identically. I've **not committed** — that's yours to do when ready.

Now, renewal planning. Let me check the `gen-key.ksh` helper so the plan is concrete about how the fleet generates keys.

---

## Claude — 2026-07-13 19:45

Key reference points for renewal:
- `gen-key.ksh` is **ssh-keygen (RSA 4096)** — that's for *SSH* keys, **not** SAML X.509 certs, so it's the wrong tool here.
- dsf/library SAML certs are **openssl self-signed X.509**, and notably were **freshly regenerated in March 2026** with 10-year validity: `O=University of Virginia, OU=Library IT, CN={Project}-drupal-simplesamlphp`.
- There's a fleet runbook: `dh.library.virginia.edu/SIMPLESAMLPHP_SECURITY.md`. Let me read it — it likely has the exact generation + rollover procedure.

---

## Claude — 2026-07-13 19:46

That doc covers the salt/admin-password env vars, not the signing cert — so there's no separate fleet runbook for the X.509 cert; it's just an openssl self-signed cert (as dsf/library's are). I have enough to plan the renewal.

Here's the plan, and I'll capture it as a deferred note since it's a coordinated future change gated on ITS.

## Key renewal plan

**Context:** the stored cert is self-signed (`CN=Raf`, SHANTI, 2016→2026), **expired 2026-06-30**. It still works because Shibboleth trusts SP certs by metadata, not PKI validity — which is why we could store it to match the live registration and keep D11 moving. Renewal is a **separate, coordinated change**, not on the D11 bring-up critical path.

**Steps:**
1. **Generate** a fresh self-signed X.509 keypair with `openssl` (not `gen-key.ksh` — that's ssh-keygen). Match the fleet convention dsf/library just used (Mar 2026, 10-yr): `C=US, ST=Virginia, L=Charlottesville, O=University of Virginia, OU=Library IT, CN=Mandala-drupal-simplesamlphp`, RSA-3072+.
2. **Store** via the exact pattern we just proved: `crypt-key.ksh <new .pem> mandala/drupal/staging/keys/mandala-drupal-staging.pem` → commit new `.crt` + `.pem.cpt`.
3. **Register the new cert with ITS NetBadge via Dave** — the gating step with lead time (NetBadge encrypts assertions to the SP cert, so the IdP must trust the new one before cutover).
4. **Rollover without an outage** — preferred: publish SP metadata carrying *both* old + new certs (SimpleSAMLphp supports multiple signing/encryption keys), have ITS ingest it, deploy the new key, then retire the old; fallback: a coordinated maintenance window.
5. **Both environments** — staging (dev) and production (`mandala-drupal-saml-production`); **check prod's actual current cert first**, it may differ from this dev pair.
6. **Validate** — SP metadata endpoint, full login, confirm assertion decryption with the new key.

Let me write the deferred note.

---

## Claude — 2026-07-13 19:47

Both done. Where things stand:

## SAML keypair — stored & verified (option A)
In `terraform-infrastructure` (untracked, **not committed** — yours to commit):
```
mandala/drupal/staging/keys/mandala-drupal-saml-staging.crt      (public)
mandala/drupal/staging/keys/mandala-drupal-saml-staging.pem.cpt  (ccrypt, reusing the mandala-drupal-staging.pem secret)
```
Round-trip proved the `.cpt` decrypts byte-identically; key↔cert modulus matches; plaintext + backup shredded. This reuses the exact pair in the live NetBadge registration, so the D11 dev SP will authenticate as-is.

## Renewal — planned & captured
New deferred note `docs/deferred/saml-sp-cert-expired-renewal.md` (in `.pages`): openssl self-signed X.509 to the fleet convention (`CN=Mandala-drupal-simplesamlphp`, O=UVA, OU=Library IT, 10-yr) → store via the same `crypt-key.ksh` pattern → **register with ITS via Dave** (the gating step; assertions are encrypted to the SP cert) → dual-cert rollover to avoid an auth outage → do both envs, checking prod's actual cert first.

## Two things for you
1. **Commit** the two key files in `terraform-infrastructure` when ready (I've only staged them on disk).
2. The renewal's **ITS re-registration is a Dave item** — folds into the Dave list alongside Redis, RDS DB provisioning, and the ALB apply.

Natural stopping point. Whenever you're ready, remaining threads are: the Dave conversation (Redis / RDS DB / NetBadge cert re-reg / terraform apply), and the parallel-able build work (`deploy_backend.yml`, the `/simplesaml/` vhost, SP assets). Want me to refresh the `project-mandala-state` memory to capture this session before we wrap?

---

## User — 2026-07-13 19:53

I committed and pushed the mandala certs (the old ones).

---

## Claude — 2026-07-13 19:53

Nice — so the encrypted keypair (`mandala-drupal-saml-staging.crt` + `.pem.cpt`) is now in `terraform-infrastructure`. Let me mark that in the scope doc so the artifact state is accurate, tied to the renewal note.

---

## Claude — 2026-07-13 19:55

Let me refresh the state memory, then give you the to-do list.

---

## Claude — 2026-07-13 19:55

State memory refreshed. Here's the wrap.

## Session wrap
Scoped the D11 backend deploy for 1b.1 part 4 end-to-end and **verified it against the live dev box** rather than assumptions. Big reframe: the mandala terraform is still legacy **D7 Aegir** — the dsf-style D11 deploy is greenfield, and the SP is one piece of it. Landed: `docs/planning/1b1-part4-d11-backend-deploy-scope.md`, two deferred notes (`production-migration-planning.md`, `saml-sp-cert-expired-renewal.md`), and the **committed, verified SAML keypair**.

## To-do list

### 🔴 Long-lead — kick off first (external people)
1. **Dave — Redis topology.** Own ElastiCache for mandala D11 vs. share dsf's `ha-redis-staging`? Three consumers need non-colliding DB/prefix: ADR 014 `mandala_solr_fq`, SimpleSAMLphp sessions, reindeer_x `workqueue`.
2. **Dave — RDS database.** Provision a D11 DB + user/grant on `rds-mysql8-staging` (or confirm we self-serve). *(No new RDS needed — reuse confirmed.)*
3. **Dave → ITS — NetBadge cert re-registration.** For the SP cert renewal (it's expired). Also confirm the existing dev SP entityID/ACS is reusable for the D11 host. → `saml-sp-cert-expired-renewal.md`.
4. **Dave — terraform apply path.** Go-ahead + who runs `plan`/`apply` on the shared mandala terraform (the `authproxy` component stays — Solr uses it).
5. **Than + Andres — reindeer_x push-vs-pull.** Do the 3 external KMaps IPs still *push* re-index under D11, or does reindeer_x pull? Settles the stale `rdx:9001`/`index:8765` ALB reconcile.

### 🟡 Quick decisions (yours, no external dep)
6. **D11 database name** — fresh `mandala_d11` vs. reuse an existing DB on the RDS.
7. **Cutover component sign-off** — confirm the §6 audit dispositions (Shanti redirects CARRY, 5 D7 CNAMEs COLLAPSE, EBS files to migrate, fail2ban/cache-warm CARRY).

### 🟢 Build work — draftable anytime (no host decision needed)
8. `deploy_backend.yml` — adapt from dsf's `deploy_backend_0.yml` (`drupal_home=/opt/drupal/app/drupal`, `uvalib/mandala-drupal`, SP mounts, post-boot `drush cim --partial`).
9. `/simplesaml/` **ProxyPass vhost** in `package/Dockerfile` (lib already vendored; `a2enmod` already done).
10. SP assets — `authsources.php`, `saml20-idp-remote.php` (verbatim), the container-env 3-file split with SIMPLESAML_* + separate Redis DB/prefix.
11. `deploy_netbadge.yml` `sp` sidecar (all envs; `example-userpass` only on dev).
12. **Delete the 5 dead `public-0-auth-*` ALB rules** (production; `terraform plan` first for listener priorities).

### ⚠️ Unrelated but High
13. `group-relationship-delete-broken-no-data-field` — breaks merged 1b.2 membership removal; needs a design choice.

Items **1–5** are the ones worth starting before next session. When we reconvene, I can take **8–12** (the build work) since none of it blocks on the Dave/Than answers.

---

## User — 2026-07-13 19:57

record this session and check-in anything that needs to be checked in.

---

## Claude — 2026-07-13 19:57

On it — I'll run the session-log script and check in the docs. First, let me see the current repo state and how the session-log script works.

---

## Claude — 2026-07-13 19:58

The script needs the current session's JSONL. Let me locate it (most recently modified in the project dir).

---
