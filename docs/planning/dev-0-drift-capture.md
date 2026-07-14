# dev-0 drift capture — what must come off the box before D11 replaces it

**Raised:** 2026-07-14 (Yuji). Operationalises §6 of
[the part-4 scope doc](1b1-part4-d11-backend-deploy-scope.md): *"every unique component
of the old dev instance must be accounted for before cutover (carry over / replace /
consciously drop)"*.

> **Why this exists.** §5.1 decided D11 **replaces `mandala-drupal-dev-0` in place**.
> The box's config is spread across three sources — terraform, hand-drift on the live
> instance, and the legacy git repos — and **only source 1 has been audited**. The
> box-vs-repo diff is the part nobody has done, and it is where the risk is.
>
> **The EBS is `BackupPolicy=none`** (`/mnt/docker`, ~70 G of 100 G). There is no
> snapshot under any of this today.

## What is NOT at risk (established 2026-07-13/14 — don't re-litigate)

- **D7 site data is not on the box.** It lives on the shared `rds-mysql8-staging`
  (confirmed via `drush sql-connect`, *not* `docker ps` — which is what made this easy
  to get wrong). Replacing the instance does not touch it.
- **The local `mariadb` container is Aegir's control DB** — retired, irrelevant (Yuji).
- **The `*_dev` databases are throwaway dev data**, not the migration source.
- **The migration source is `dev-1`**, which is not being replaced.

## Phase 0 — snapshot first (do this before anything else)

The EBS volume is unbacked. A snapshot turns every item below from *lost* into
*recoverable*, and costs one command. Do it before the inventory, not after — the
inventory is read-only but the cutover is not.

## Phase 1 — inventory (read-only SSH)

For each hand-placed checkout, capture: remote, branch, **unpushed commits**,
uncommitted diff, untracked files.

```bash
for d in /usr/local/dockerfiles \
         /usr/local/mandala-solr-proxy \
         /usr/local/kmaps-solr-sync-out \
         /usr/local/mandala_drupal_docker; do
  echo "=== $d"
  git -C "$d" remote -v
  git -C "$d" branch --show-current
  git -C "$d" log --oneline @{u}..HEAD     # unpushed — the irreplaceable part
  git -C "$d" status --porcelain           # uncommitted + untracked
  git -C "$d" diff                         # the actual edits
done
```

Also capture the **real runtime environment** of each container — the `.env` files on
disk may not match what is actually running:

```bash
docker inspect <container> --format '{{json .Config.Env}}'
```

> **⚠ These outputs contain secrets** (`MYSQL_LOCAL_ROOT_PW`, `SOLR_BASEURL`, …).
> Do not paste them into git, chat, or a ticket. Secrets go into
> `container_0.env.secret` and through `crypt-key.ksh` like everything else — see the
> part-4 scope doc's secrets flow.

## Phase 2 — triage: carry / replace / consciously drop

| Item | Where it lives now | Disposition | Notes |
|---|---|---|---|
| **`fail2ban-rework` branch** (`shanti-uva/mandala_drupal_docker`) | `/usr/local/dockerfiles`, **not `main`**; possibly unpushed | **CARRY — highest priority** | **D11's IaC already depends on it.** See below. |
| untracked `docker-compose-dev.yml` | `/usr/local/dockerfiles` | triage | Aegir-era; likely drop, but diff first |
| `volumes/aegir-sites-logs/` | `/usr/local/dockerfiles` | **dir already in IaC**; contents transient | `configure_backend.yml` creates `aegir_sites_logs_dir` |
| **solr-proxy uncommitted `docker-compose.yml` edit** | `/usr/local/mandala-solr-proxy` (on `main`) | **CARRY or consciously drop** | Diff against the monorepo's `solr-proxy/docker-compose.yml` — the 1b.1 part-1 fork may already supersede it |
| **solr-proxy `.env`**: `SOLR_BASEURL`, `DEFAULT_RETURL` | box only, no IaC | **CARRY → secrets flow** | D11's solr-proxy still needs these |
| **reindeer_x config**: `PORT=9000`, `KMAPS_SYNC_CLASS=staging`, `REDIS_URL=redis://workqueue`, `NODE_ENV=development` | `/usr/local/kmaps-solr-sync-out` | **CAPTURE regardless; carry is GATED** | See below |
| Aegir `.env`: `HTTP_PORT`/`SSH_PORT`/`FQDN`/`MYSQL_LOCAL_ROOT_PW`/`COOKIE_DOMAIN` | box only | **consciously DROP** | Dies with Aegir. Record the decision so it isn't re-discovered as a gap. |
| second, idle `mandala_drupal_docker` checkout | `/usr/local/` | DROP | idle duplicate |
| docker network drift (two solr-proxy networks) | box | DROP | dies with the box |
| `var-aegir` + mariadb volumes | `/mnt/docker` | DROP | Aegir control DB, retired |
| `workqueue` redis-data | `/mnt/docker` | verify empty, then DROP | a work queue; KMaps is static so it should be idle |

### The `fail2ban-rework` branch is load-bearing — not legacy cruft

`mandala/drupal/staging/ansible/configure_backend.yml` is **already committed** and
already builds the host half of this design: `mandala-banlist-reload.sh` +
`mandala-banlist-reload.timer`, batching reloads to avoid a storm during a scraper
burst. Its own comment names the files it expects:

> *"See mandala_drupal_docker's fail2ban `action.d/apache-deny*.conf` and
> `build/files/etc/apache2/conf-available/scraper-blocklist.conf`."*

Those live on the **`fail2ban-rework`** branch. If dev-0 is replaced and that branch
was never pushed, **D11's provisioning references configuration that exists nowhere.**
Verify `git log @{u}..HEAD` on that checkout first — everything else follows from
whether it is pushed.

**A gap this exposes, worth deciding separately:** the design has fail2ban running
*inside the app container*, writing `/etc/apache2/banned-ips.conf`, with the host only
batching reloads (iptables can't block by real client IP — the ALB terminates every
connection). **D11's `package/Dockerfile` installs no fail2ban at all** (mentions: 0).
So the host half is in IaC while the container half has no D11 equivalent. Either the
D11 image gains fail2ban, or the banlist machinery in `configure_backend.yml` is dead
code on the new box.

### reindeer_x — capture now, decide later

Carrying reindeer_x is **gated on Yuji's review of whether rdx is needed at all**
(see deferred [`rdx-alb-target-unhealthy-in-production.md`](../deferred/rdx-alb-target-unhealthy-in-production.md)).
But **capture its config regardless**: `PORT=9000` is the evidence for the
9000-vs-9001 ALB mismatch, and that evidence currently exists only on the box. If rdx
is retired, the capture is what justifies deleting the target groups; if it is kept,
it is what the pipeline needs. Either way, losing it is strictly worse.

## Phase 3 — land each item in its home

- **fail2ban work** → push the branch to `shanti-uva/mandala_drupal_docker`; then
  decide its D11 home (the container-half question above).
- **solr-proxy compose edit** → the monorepo's `solr-proxy/` (ADR 014 fork), or a
  recorded decision to drop it.
- **solr-proxy env values** → `terraform-infrastructure` `container_0.env.managed` /
  `.secret` (+ `crypt-key.ksh`).
- **reindeer_x config** → `uvalib/mandala-reindeer_x` + an Ansible playbook (ADR 007:
  its own repo, its own pipeline) — gated on the rdx review.
- **Aegir items** → a recorded "consciously dropped" line, not silence.

## Phase 4 — done means reproducible

The accounting is complete when **a box built purely from IaC behaves the same**.
Concretely: nothing in Phase 2 is still marked "carry" and unlanded, and no
`.env`/config value exists only on dev-0. §6's three sources — terraform, live box,
legacy repos — must all reconcile; today only terraform has been audited.

## Cross-references

- [1b1-part4-d11-backend-deploy-scope.md](1b1-part4-d11-backend-deploy-scope.md) §5.1 (replace in place), §6 (audit + three sources)
- [rdx-alb-target-unhealthy-in-production.md](../deferred/rdx-alb-target-unhealthy-in-production.md) — the rdx review this is partly gated on
- [reindeer-x-has-no-ecr-repo-or-pipeline.md](../deferred/reindeer-x-has-no-ecr-repo-or-pipeline.md)
