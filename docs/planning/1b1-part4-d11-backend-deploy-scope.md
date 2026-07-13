# 1b.1 part 4 — D11 backend deploy scope (SimpleSAMLphp / NetBadge)

**Raised:** 2026-07-13 (afternoon, Yuji). Continues the 1b.1 part 4 work whose
app-repo half merged in PR #32. This doc records a **scoping finding** that
reshapes the remaining part-4 terraform/Ansible work.

> **TL;DR** — The morning plan assumed a dsf-shaped D11 deploy already existed
> in `terraform-infrastructure/mandala/drupal/*` and that part 4 was just
> *cloning the netbadge/SimpleSAMLphp config into it* (+ one ALB cleanup). It
> does not exist. That terraform env still describes the **legacy D7 Aegir**
> stack. The whole dsf-style D11 AWS backend deploy is **greenfield**, and the
> SimpleSAMLphp SP is one component *of* it — it cannot be built in isolation.
> This doc scopes the D11 backend deploy end-to-end and folds netbadge + the ALB
> cleanup into it.

See also: deferred note [`saml-alb-routing-assumes-mod-shib.md`](../deferred/saml-alb-routing-assumes-mod-shib.md)
(the ALB half + the user-provisioning test matrix), ADR 014 (Redis visibility
token), the reference deploy pattern (`dsf.library.virginia.edu/staging/ansible/`).

## 1. The gap (what's actually there vs. what the pipeline expects)

The D11 CI/CD pipeline is already written and points at this terraform env:

- `pipeline/buildspec.yml` → builds `uvalib/mandala-drupal` from `package/Dockerfile`,
  pushes to ECR, records the tag in SSM `/mandala/drupal/build_tag`.
- `pipeline/deployspec.yml` → `cd terraform-infrastructure/mandala/drupal/$ENVIRONMENT`
  → `terraform apply` → `ansible-playbook ansible/deploy_backend.yml -e deploy_tag=$DEPLOY_TAG`.

But `terraform-infrastructure/mandala/drupal/{staging,production}` is the **D7
Aegir** environment:

- `ansible/configure_backend.yml` only *provisions the host* (Docker, EBS mount,
  fail2ban ban-list reload, cache-warm timers). It deploys **no app container** —
  Aegir's `dockerfiles-hostmaster-1` pulls the D7 sites itself.
- There is **no `deploy_backend.yml`** (the deployspec calls a file that does not
  exist), **no** `container_0.env.{generated,managed,secret}` split, **no**
  SimpleSAMLphp Ansible assets, **no** SAML keys.
- `production/alb-routing.tf` still carries 5 dead `mod_shib` rules
  (`public-0-auth-0..4` → `authproxy_target_arn`, `/Shibboleth.sso/*`).
- The staging/dev hosts are named `mandala-drupal-dev-{0,1}.internal.lib.virginia.edu`
  (staging env == the "dev" instance, per Yuji) but run the Aegir stack.

**Second gap — the app image (smaller than first thought).** The SimpleSAMLphp
*library* is **already vendored** by `composer install` — it's a dependency of
`drupal/simplesamlphp_auth ^4.1` (in mandala's composer.json), installed via
`simplesamlphp/composer-module-installer`. Confirmed identical to dsf/library,
which are **also** built from the stock official Drupal image
(`public.ecr.aws/docker/library/drupal:10.6.7` / `:10.6.10`) and likewise install
no standalone SP. So no "bake the SP into the image" work is needed.

What `package/Dockerfile` is **actually missing** is the Apache vhost that
dsf/library ship (`package/data/…/000-default.conf`):

```apache
ProxyPass        "/simplesaml/"  "http://sp:80/simplesaml/"
ProxyPassReverse "/simplesaml/"  "http://sp:80/simplesaml/"
SetEnv SIMPLESAMLPHP_CONFIG_DIR /var/simplesamlphp/config
```

mandala's Dockerfile already runs `a2enmod proxy proxy_http headers` — it was set
up for exactly this and just never got the vhost. **Correction to the "SP runs
inside the Drupal container" framing:** the SP *library* runs in-process in Drupal
(for `simplesamlphp_auth` session validation), but the `/simplesaml/*` **web
endpoints are served by the separate `sp`/`netbadge-0` container**
(`uvalib/drupal-netbadge`) and reverse-proxied to it. The two act as one SP because
they share the mounted `/var/simplesamlphp/config` **and** the Redis session store.
Consequence: the `netbadge`/`sp` container is **required in every environment**, not
a dev-only test IdP (enabling `example-userpass` is the only dev-specific part).

## 2. Target architecture (the dsf model)

Single Drupal container, SP embedded — verified from `dsf.library.virginia.edu`:

- Two containers on the `drupalnet` network: `mandala-drupal-0` (`uvalib/mandala-drupal`,
  `8080:80` → ALB target group) **and** `netbadge-0` (`uvalib/drupal-netbadge`,
  alias `sp`, `8081`) which serves the `/simplesaml/*` web endpoints.
- **The SP is split across both containers, unified by shared config + Redis
  session store.** The Drupal container's Apache reverse-proxies `/simplesaml/` →
  `http://sp:80/simplesaml/`; `simplesamlphp_auth` (with the composer-vendored
  library) validates the session in-process via `SIMPLESAMLPHP_CONFIG_DIR`.
  `deploy_backend.yml` mounts from the host into the Drupal container:
  - `…/simplesamlphp/config`   → `/var/simplesamlphp/config`   (`authsources.php`, `config.php`)
  - `…/simplesamlphp/metadata` → `/var/simplesamlphp/metadata` (`saml20-idp-remote.php` — UVA IdP)
  - `…/simplesamlphp/cert`     → `/var/simplesamlphp/cert`     (SAML `.pem`/`.crt`)
  - `…/simplesamlphp/drupal-config` → `/var/simplesamlphp/drupal-config` (`simplesamlphp_auth.settings.yml`)
- Post-boot: `drush cim -y --partial --source=/var/simplesamlphp/drupal-config`
  loads the SAML Drupal config; `apache2ctl configtest` + reload.
- `/simplesaml/*` and `/saml_login` are served as ordinary app paths on the normal
  Drupal target — **no** dedicated ALB SAML rules (dsf has zero). This is why the 5
  `public-0-auth-*` rules are dead weight and get deleted.
- `deploy_netbadge.yml` deploys that `sp`/`netbadge-0` container. It is **required
  in every environment** (it serves `/simplesaml/*`), not a dev-only add-on. Its
  only dev-specific aspect is enabling `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true`
  (`example-userpass` test IdP) so validation needn't hit real UVA NetBadge.
- Config is env-driven via a 3-file split loaded by the playbook:
  `container_0.env.generated` (terraform-templated, e.g. `MYSQL_HOST`) +
  `.managed` (hand-managed non-secrets: `SIMPLESAML_*`, Redis) +
  `.secret` (`.cpt`-encrypted: salts, admin pw).

## 3. Component inventory — exists / missing

| Component | dsf reference | mandala state | Action |
|---|---|---|---|
| SP library in app image | via composer (`simplesamlphp_auth`) | **already vendored** by `composer install` | none — already present |
| Apache vhost `ProxyPass /simplesaml/ → sp:80` | `000-default.conf` | **missing** (`a2enmod` done, no vhost) | **Add** the vhost to `package/Dockerfile` |
| `sp`/`netbadge-0` container (serves `/simplesaml/*`) | `uvalib/drupal-netbadge` | **missing** | **Deploy** via `deploy_netbadge.yml` — all envs |
| `ansible/deploy_backend.yml` (app deploy) | `deploy_backend_0.yml` | **missing** (deployspec calls it) | **Create** from dsf, adapt `drupal_home=/opt/drupal/app/drupal`, image/container names |
| `ansible/configure_backend.yml` (host provision) | present (both) | present but **Aegir-flavored** | **Adapt**: drop Aegir/hostmaster bits; keep EBS/docker/fail2ban/cache-warm as applicable |
| `container_0.env.{generated,managed,secret}` | present | **missing** (only empty `container.env`) | **Create** the 3-file split |
| `files/var/simplesamlphp/config/authsources.php` | present (env-driven) | **missing** | **Clone** verbatim (already env-driven) |
| `files/var/simplesamlphp/config/config.php` | (in image) | — | Confirm where store/Redis config is set |
| `files/var/simplesamlphp/metadata/saml20-idp-remote.php` | UVA IdP | **missing** | **Clone verbatim** (UVA `urn:mace:incommon:virginia.edu`) |
| `files/var/simplesamlphp/drupal-config/simplesamlphp_auth.settings.yml` | dsf mapping | app-repo CMI has **mandala's** version | **Reuse** the committed mandala CMI (uid→username, `register_users:false` baseline) |
| SAML keys `keys/mandala-drupal-saml-staging.{crt,pem.cpt}` | encrypted `.pem.cpt` + `.crt` | ✅ **DONE (2026-07-13, committed)** — reused the live SP pair off `dev-0`, `crypt-key.ksh` reusing the `mandala-drupal-staging.pem` secret | Cert is **expired** (renew later — deferred `saml-sp-cert-expired-renewal.md`) |
| `deploy_netbadge.yml` test-IdP sidecar | present | **missing** | **Clone** (dev only; `SIMPLESAML_ENABLE_EXAMPLE_AUTH=true`) |
| Redis DB/prefix separation | `db 3`, `SIMPLESAML_DSF:` | — | **Pick** distinct `SIMPLESAML_REDIS_DATABASE` + `SIMPLESAML_MANDALA:` prefix (≠ ADR 014 `mandala_solr_fq:{uid}`) |
| ALB: delete 5 `public-0-auth-*` rules | n/a (dsf has none) | present in **production** only | **Delete** (task 1); `terraform plan` first |
| Terraform host/target/RDS/Redis for D11 | dsf single-container | Aegir hosts + D7 DB | **Decide** (see §5) — biggest open item |

## 4. Work breakdown (folds in netbadge + ALB cleanup)

1. **App image** — add the Apache vhost (`ProxyPass /simplesaml/ → http://sp:80/`,
   `ProxyPassReverse`, `SetEnv SIMPLESAMLPHP_CONFIG_DIR /var/simplesamlphp/config`)
   to `package/Dockerfile`, mirroring dsf/library's `000-default.conf`. The SP
   library is already vendored by composer; `a2enmod proxy proxy_http headers` is
   already done — this is just the vhost.
2. **Ansible app deploy** — create `mandala/drupal/<env>/ansible/deploy_backend.yml`
   from dsf's `deploy_backend_0.yml`; adapt paths (`drupal_home=/opt/drupal/app/drupal`),
   `image_name=uvalib/mandala-drupal`, `container_name=mandala-drupal-0`, ports,
   the SP mounts, and the post-boot `drush cim --partial`.
3. **Container env split** — `container_0.env.generated` (terraform template:
   `MYSQL_HOST`, DB creds ref), `.managed` (`SIMPLESAML_*`, Redis db/prefix,
   base URL/entityID for the dev SP), `.secret(.cpt)` (`SIMPLESAML_SECRET_SALT`,
   `SIMPLESAML_ADMIN_PASSWORD`, `HASH_SALT`).
4. **SP assets** — clone `authsources.php`, `saml20-idp-remote.php` verbatim;
   drop in the app-repo `simplesamlphp_auth.settings.yml`. ✅ SAML `keys/` done
   (committed 2026-07-13).
5. **Test-IdP sidecar** — `deploy_netbadge.yml` for dev (example-userpass), so
   validation (part-4 §"outside-DDEV") can run without real NetBadge.
6. **ALB cleanup (task 1)** — delete the 5 `public-0-auth-*` rules in
   `production/alb-routing.tf`; `terraform plan` to confirm listener-rule
   priorities; `authproxy` component stays (Solr proxies use it).
7. **Validation** — the part-4 §"outside-DDEV" run + the 5-row provisioning matrix
   from the deferred note (NetBadge/test-IdP → session → `/oauth/authorize`
   no-reprompt → token `sub`=uid → proxy Redis read; `register_users` false/true).

## 5. Open decisions (need Yuji + Dave Goldstein) — the real blockers

These gate the terraform half and are **not** ours to default:

1. **Host strategy — DECIDED (2026-07-13, Yuji): replace in place.** The D11
   instance **replaces** the `mandala-dev` (staging-env) instance; there is **no D7
   dev instance** to keep. We edit the existing `mandala/drupal/staging` terraform
   env into the D11 shape (not a new env). **Requirement: audit the old Aegir dev
   env for every *unique* component and account for each before cutover** — see §6.
2. **RDS — reuse the shared instance (verified via `drush sql-connect`).** The
   durable fact: mandala already lives on **`rds-mysql8-staging.internal.lib.virginia.edu`**
   (MySQL 8, the same RDS dsf uses; matches ADR 012), user `mandala_sites_dev`.
   **No new RDS needed** — D11 just needs a database on it. The specific `*_dev`
   databases are throwaway development data (not the migration source; Yuji) — no
   need to reuse a particular one; a fresh consolidated D11 DB is fine. **Migration
   D7 source = the *staging* instance (`dev-1`), handled by the separate migration
   track** (deferred `staging-migration-execution-prerequisites.md`); the shared
   user DB for that track is `mandala_shared_*` (see
   [[project-d7-shared-user-database]]). The Aegir control DB (local `mariadb`
   container) is **irrelevant** — Aegir is retired.
   > **Migration strategy (Yuji, 2026-07-13):** any migration we run *now* is **not
   > the final migration**. Staging lags production by several months, but the
   > *shape* of the data is essentially the same — little that's structurally
   > significant is absent from staging. So **working against staging now is
   > sufficient to prove the migration and drive D11 development.** A separate
   > **production migration must be planned as future work** (D7 prod → D11 prod
   > cutover) — see deferred `production-migration-planning.md`.

3. **Redis — now THREE consumers, confirm topology.** (a) ADR 014
   `mandala_solr_fq:{uid}` visibility token; (b) SimpleSAMLphp session store
   (`SIMPLESAML_STORE_TYPE=redis`); (c) **reindeer_x's `workqueue`** (live on the
   box as its own redis container). Decide per-consumer: shared cluster with
   separate DB/prefix each (dsf shares `ha-redis-staging`) vs. dedicated. At minimum
   SimpleSAMLphp must not collide with ADR 014's keyspace.
4. **~~Single node vs. HA pair~~ — RESOLVED (Yuji): the two nodes are two
   *logical environments*, not an HA pair.** `dev-0` = development, `dev-1` =
   **staging** (the D7 migration source). So "replace mandala-dev with D11" = replace
   **`dev-0`**; `dev-1` stays D7 as the migration source until that track completes.
   The terraform "staging" env's `instance_count=2` is really dev + staging, not a
   redundant pair — the D11 rework must keep that dual-env split (or split them into
   proper separate envs).
5. **Dev SP entityID / base URL.** D7 `default-sp` lived on
   `mandala-dev.internal.lib.virginia.edu` (existing dev SP available). Reuse that
   entityID/ACS for the D11 dev SP, or register a new one? (Dave / ITS NetBadge.)
6. **ALB target port.** Repurposing target-0 to the D11 container's `8080` — confirm
   the target group / health check path is right for D11.

**Recommended next step:** settle §5.2–5.3 with Dave (RDS/Redis) before
writing terraform; the Ansible + SP assets (§4 items 2–5) and the image work
(§4.1) can be drafted in parallel since they're env-value-driven and don't depend
on those. §5.1 (host strategy) is decided — replace in place (see §6).

## 6. Old dev-instance component audit (DECIDED: D11 replaces mandala-dev)

**Decision (2026-07-13, Yuji):** the D11 instance replaces the `mandala-dev`
instance in place — no D7 dev instance is retained. **Every *unique* component of
the old dev instance must be accounted for before cutover** (carry over / replace /
consciously drop).

### Three sources of truth — the terraform audit is not enough

The old dev box's configuration is spread across (Yuji, 2026-07-13):

1. **Terraform** (`mandala/drupal/staging/*`) — audited below. Captures infra:
   instance, DNS, ALB routing, SGs, EBS, IAM.
2. **Hand-installed drift on the live instance** — packages/config/scripts applied
   directly, *not* in any IaC. **Only discoverable by inspecting the running box**
   (`mandala-drupal-dev-{0,1}.internal.lib.virginia.edu`) — needs SSH + a diff
   against what terraform/ansible/the git repo would produce.
3. **A git repo** — the legacy Aegir/Docker deploy:
   **`github.com/shanti-uva/mandala_drupal_docker`** (docker-compose, `build/`,
   `scripts/`, `volumes/`, `env.dist`), plus siblings `mandala-solr-proxy`,
   `mandala_s3_synch`, `kmaps_engine` (all under `mandala-legacy/` locally). Must be
   diffed against the live box to separate repo-tracked config from hand drift.

Accounting is complete only when all three are reconciled. Items 2–3 are the risk;
item 1 is the easy part.

### Live-box audit — `mandala-drupal-dev-0` (2026-07-13, read-only SSH)

Inspected the running instance directly (`ip-10-130-109-110`, AL2023). The box is a
**hybrid**: the legacy D7 Aegir stack *and* the new D11-era sync service run side by
side, all as **hand-placed git checkouts under `/usr/local/`, started manually**
(no systemd management except the two `mandala-*` timers already in
`configure_backend.yml`).

**Running containers (`docker ps`):**

| Container | Image | Ports | Compose project / dir |
|---|---|---|---|
| `dockerfiles-hostmaster-1` | `mandala/mandala_drupal_docker` | `8080→80`, `8222→22` | `dockerfiles` — `/usr/local/dockerfiles` |
| `dockerfiles-database-1` | `mariadb` | `3306` (internal) | `dockerfiles` (same) |
| `mandala-solr-proxy` | `mandala-solr-proxy-php-proxy` | `8765→80` | `mandala-solr-proxy` — `/usr/local/mandala-solr-proxy` |
| `reindeer_x` | `reindeer_x` | `9000/tcp`, `9001/udp` | `kmapssolrsync` — `/usr/local/kmaps-solr-sync-out` |
| `workqueue` | `redis` | `6379` (internal) | `kmapssolrsync` (same) |

**Findings that change the audit:**

- **`:9001` is reindeer_x, and it's UDP.** reindeer_x's *app* port is **`9000/tcp`**
  (`PORT=9000`, `npm run reindeer_x:dev`, `NODE_ENV=development`,
  `KMAPS_SYNC_CLASS=staging`, `REDIS_URL=redis://workqueue`). The terraform
  `rdx_service_port=9001`/HTTP target + `mandala-rdx-dev` CNAME + IP-allowlist SG are
  **stale** relative to what actually runs — the D11 replacement (`reindeer_x`,
  ADR 006/007) is *already deployed here*, not a future item. Reconcile the ALB
  `rdx`/`index` targets against reindeer_x's real `9000/tcp` (+ `9001/udp`) at cutover.
- **DB (corrected via `drush sql-connect`, not `docker ps`):** the D7 **site** data
  is on the shared **`rds-mysql8-staging`** (MySQL 8), user `mandala_sites_dev` —
  the local `mariadb` container is **only Aegir's control DB, which is retired and
  irrelevant** (Yuji). → §5.2: D11 reuses this RDS, no new instance. The `dev-0`
  `*_dev` databases seen here are **throwaway dev data, not the migration source**.
- **`dev-0` = development, `dev-1` = staging (Yuji).** The two nodes in the terraform
  "staging" env are two *logical environments*, not an HA pair. This box (`dev-0`)
  is the one D11 replaces; `dev-1` (staging) is the D7 **migration source**. → §5.4.
- **Persistent EBS = `/mnt/docker`** (the Docker data-root; `/dev/nvme1n1`, 70 G/100 G
  used). "Persistent data" = docker overlay2 + volumes (`var-aegir`, mariadb data).
  Still `BackupPolicy=none`.
- **Hand-drift specifics to port/reconcile:**
  - `/usr/local/dockerfiles` = `shanti-uva/mandala_drupal_docker` on branch
    **`fail2ban-rework`** (not `main`) + untracked `docker-compose-dev.yml` +
    `volumes/aegir-sites-logs/`.
  - `/usr/local/mandala-solr-proxy` = `main` with an **uncommitted** edit to
    `docker-compose.yml`.
  - `/usr/local/mandala_drupal_docker` = a **second, idle** checkout of the same repo.
  - Hand-created **`.env` secrets** (in no IaC): Aegir
    `HTTP_PORT/SSH_PORT/FQDN/MYSQL_LOCAL_ROOT_PW/COOKIE_DOMAIN`; solr-proxy
    `SOLR_BASEURL/DEFAULT_RETURL`. These must be recreated as D11 env/secret values.
  - Docker-network drift: **two** solr-proxy networks
    (`mandala-solr-proxy_default` + `mandalasolrproxy_default`).
- **No custom systemd/cron for the stacks** — they were `docker compose up`'d by
  hand, so nothing but `restart:always` brings them back after a reboot. The only
  managed units are `mandala-banlist-reload.timer` + `mandala-cache-warm.timer`
  (already in IaC).

### Terraform-captured components + D11 disposition

| Component | What it is | D11 disposition |
|---|---|---|
| **rdx/index CNAMEs `:9001`/`:8765`** | `mandala-rdx-dev` (`:9001`/HTTP, IP-allowlisted to 3 Linode IPs via SG `tcp-9001-in-from-public-sn`) + `mandala-index-dev` (`:8765`). **Live-box reality (see audit above):** `:8765` = `mandala-solr-proxy` container; the real KMaps-sync service is **`reindeer_x`** on **`9000/tcp`** (+ `9001/udp`), already running. The terraform `rdx:9001`/HTTP target is **stale** vs. what runs. | **RECONCILE, not build-from-scratch — `reindeer_x` is already the D11 replacement and already deployed here** (ADR 006/007). At cutover: re-point/retire the `rdx`/`index` ALB targets against reindeer_x's actual `9000/tcp` + the solr-proxy; keep the IP-allowlist for the 3 external KMaps sources if they still push; confirm push-vs-pull with Than/Andres. |
| **Legacy Shanti redirects** | `{mandala,audio-video,images,sources,texts,visuals}-dev.shanti.virginia.edu` → internal CNAMEs (301). | **CARRY** (backward-compat for old Shanti URLs) — but repoint at the single consolidated D11 site. |
| **5 D7 site CNAMEs** | `internal_cnames` (av/images/texts/sources/visuals/mandala/aegir) → LB, one per legacy multisite. | **COLLAPSE** — D11 is one site; decide each name → single D11 app or retire. |
| **`reindeer_x` + `workqueue` (redis) stack** | Live D11-era KMaps sync (`kmapssolrsync` project, `/usr/local/kmaps-solr-sync-out`), reindeer_x on `9000/tcp`+`9001/udp`, own redis `workqueue`. | **CARRY as its own service** (ADR 007 = independent repo `uvalib/mandala-reindeer_x`). Not part of the Drupal container; deploy alongside. Reconcile its redis vs. ADR 014's + SimpleSAMLphp's. |
| **Site databases on shared RDS** | **Corrected via `drush sql-connect` per site:** the D7 *site* data is on **`rds-mysql8-staging.internal.lib.virginia.edu`** (MySQL 8, the same RDS dsf uses), user `mandala_sites_dev`, one DB per site. The local `mariadb` container holds **only Aegir's own control DB** (`@mandala-aegir-dev` → `mandalaaegirde_2`). | Informs §5.2 — **D11 needs no new RDS**; reuse the shared instance. |
| **Persistent EBS = `/mnt/docker`** | 100 GB gp3 (`/dev/nvme1n1`, 70 G used), the Docker **data-root** — holds `var-aegir` volume + mariadb data + images. **`BackupPolicy=none`**. | **INSPECT + migrate** the relevant volumes (uploaded files) to the D11 `sites/default/files` bind mount. Flag the no-backup risk before touching. |
| **fail2ban ban-list reload + cache-warm timers** | mandala-specific scraper mitigation in `configure_backend.yml` (recently ported, `mandala-fail2ban-rework`). | **CARRY** to the D11 host/app — actively maintained, mandala-unique. |
| Instance / IAM profile / keypair / CloudWatch / SGs | AL2023 t3a.medium ×2, root 100 GB, CloudWatch agent role, ssh SGs. | **RECREATE** in D11 shape (standard). |

**Next audit actions:** (a) ✅ live-box drift enumerated (see audit above);
(b) recover the hand-created `.env` values (MySQL root pw, `SOLR_BASEURL`,
`DEFAULT_RETURL`, `FQDN`, `COOKIE_DOMAIN`) into D11 env/secret management; (c) copy
off the checked-in edits before cutover — the `fail2ban-rework` branch state of
`/usr/local/dockerfiles` + the uncommitted `mandala-solr-proxy/docker-compose.yml`
edit (confirm both are captured upstream or port them); (d) confirm reindeer_x
push-vs-pull with Than/Andres to settle the `rdx`/`index` ALB target reconcile;
(e) identify which `/mnt/docker` volumes hold user-uploaded files to migrate.
