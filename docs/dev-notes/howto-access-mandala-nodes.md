# How-To: Access the Mandala dev/staging/production EC2 nodes

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-07-18

## Goal

SSH into the Mandala EC2 nodes (dev, staging, production) and run read-only
`docker`/DB inspection — e.g. to check on a running migration, tail a log, or
look at container state.

## Prerequisites

- UVA VPN connected.
- Your own personal SSH key (`~/.ssh/id_rsa`) added as an authorized key on
  the target host under your own computing-id username. (If you've never
  logged into these boxes before and this doesn't work, ask Yuji or Dave —
  key provisioning isn't self-serve.)

## Hosts

All `*.internal.lib.virginia.edu`:

| Environment | Hostname | Notes |
|---|---|---|
| dev | `mandala-drupal-dev-0` | D11 app (`mandala-drupal-0`, `netbadge-0`, `mandala-redis-0` containers); own DB `mandala_drupal_0` on `rds-mysql8-staging` |
| staging | `mandala-drupal-dev-1` | |
| production | `mandala-drupal-0` | Legacy D7 Aegir stack (idle/disabled) — see `docs/deferred/d11-dev-database-bootstrap-and-migration-source.md` for why the real D7 data isn't here, it's on `rds-mysql8-production` |

## Steps

1. SSH in with your **personal key and computing-id username** — not the
   `.pem` files in terraform-infrastructure's `keys/` directories, and not the
   `centos` user. Both are rejected for personal login; only your own key
   under your own username works:
   ```bash
   ssh -i ~/.ssh/id_rsa <your-computing-id>@mandala-drupal-dev-0.internal.lib.virginia.edu
   ```
2. Passwordless `sudo` works once you're in. **Docker requires `sudo`** —
   your user isn't in the `docker` group:
   ```bash
   sudo docker ps
   sudo docker exec <container> <command>
   ```
3. To check on a long-running process (e.g. a migration) inside a container:
   ```bash
   sudo docker exec mandala-drupal-0 sh -c "tail -40 /tmp/some-log-file.log"
   ```

## Verify

`sudo docker ps` should list running containers without a permission error.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `Permission denied (publickey,...)` as `centos` or with the terraform `.pem` | Wrong key/user for personal login | Use `~/.ssh/id_rsa` + your own computing-id username instead |
| `permission denied ... docker.sock` | Your user isn't in the `docker` group | Prefix every docker command with `sudo` |
| A remote command you expected to finish quickly just hangs | These hosts have **no `timeout` binary** | Use `ssh -o ConnectTimeout=N ...` instead of wrapping the remote command in `timeout` |
| A recursive `grep` over the D7 Aegir docroot (`/var/aegir/platforms/mandala-base/docroot`) never returns | It's slow/huge and hangs in practice | Target specific files instead of a recursive search |
| `mysql: command not found` inside the D11 app container | The container has no `mysql` client binary | Use Drupal's own API (`drush php:script`, `drush sql:query` if a client exists elsewhere) or run a `mysql:8.0` Docker container from a machine that can reach the DB host over the VPN |
| Local (non-EC2) `mysql` client gets `Authentication plugin 'mysql_native_password' cannot be loaded` against these RDS instances | Homebrew's `mysql` 9.x dropped that plugin | Run `mysql`/`mysqldump` inside a `mysql:8.0` Docker container instead — it bundles the plugin |

## Related

- `docs/deferred/d11-dev-database-bootstrap-and-migration-source.md` — dev-database bootstrap + D7 migration source, including the RDS reachability matrix (which hosts can reach which DB from where)
- `docs/deferred/migrate-group-import-aborts-on-partial-failure.md` and `migrate-large-migration-oom-and-resume-behavior.md` — checking on / resuming a live migration on dev-0
- `scripts/refresh-d7-staging-source.sh` — codifies dumping a D7 source DB and loading it onto staging RDS (credentials held in shell variables only, never written to disk)
