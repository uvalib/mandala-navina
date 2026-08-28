# The Drupal container image has no `mysql` client — `drush sql:*` silently no-ops; fix belongs in `package/Dockerfile`

**Area:** deployment / packaging / drush / DX
**Raised during:** Session 2026-08-25 (1a.9 acceptance run); documented as its own item 2026-08-28
**Jira:** (add when available)
**Priority:** Medium — not currently blocking, but has already caused a real incident (a silently empty backup) and cost "three detours" in one session

**Status (2026-08-28): both fixes below implemented and verified locally** on branch
`fix/drupal-image-drush-path-and-mysql-client` — deliberately **not merged to `main`**
(a push there triggers `uva-mandala-drupal-codepipeline`, which would deploy). `docker build`
of the branch confirms: bare `drush --version` resolves, `which mysql mysqldump` both resolve
to `/usr/bin/…`. Not yet verified against a real database (no live site in the local build) or
deployed to dev-0/staging. Merge when ready to ship.

## What's wrong

`package/Dockerfile`'s `apt-get install` only pulls `git unzip libfreetype6-dev` (plus
bcmath and phpredis via separate steps) — there is no MySQL/MariaDB client in the image.

`drush sql:*` commands (`sql:dump`, `sql:cli`, …) are thin wrappers around the system
`mysqldump`/`mysql` binaries (`Sql/SqlMysql.php`). With no client present, `Commands/sql/SqlCommands.php`
logs a *warning* from a validate hook and returns false — **drush exits 0 anyway**. Found live
on 2026-08-25: the first version of `scripts/db-checkpoint.sh` used `drush sql:dump`, and it
produced empty `.sql.gz` backup files with no error — discoverable only at restore time. Detail
in `docs/session-logs/2026-08-25-acceptance-run-prepared-and-handoff.md` §2 item 1 and
§"Still unproven".

## Current mitigation (already shipped, stays as-is)

`scripts/db-checkpoint.sh` no longer uses drush for dumps at all — it shells out on the
**host** to a throwaway `mysql:8.0` sidecar container, reading `MYSQL_*` from the app
container's env, and verifies the resulting artifact instead of trusting drush's exit code.
This is also the established pattern for ad hoc human DB access from a workstation (Homebrew's
mysql client can't auth to these RDS instances either). Nothing about that workflow needs to
change.

What's still missing is **in-container** SQL access — `docker exec <container> drush sql:cli`
or any other drush SQL subcommand run from inside the Drupal container itself still silently
fails the same way.

## Proposed fix

Add `default-mysql-client` to the existing `apt-get install` line in `package/Dockerfile`
(Debian base image — `public.ecr.aws/docker/library/drupal:11-php8.3-apache` — so
`default-mysql-client` resolves to the MariaDB-compatible client; that's client tooling only
and doesn't touch ADR 012's server-engine-collation concern, which is about the DB *engine*,
not the client). One-line change.

## Environment scope — no separate decision needed per environment

There is exactly **one** image: `pipeline/buildspec.yml` builds `package/Dockerfile` once and
pushes it to one ECR repo; `deployspec.yml` deploys that same image identically to dev-0 and
staging/dev-1 (both hosts inside the single `staging` terraform environment). So the fix
reaches dev and staging together, automatically, on the next deploy after it merges.

**Production explicitly should get this too** (Yuji, 2026-08-28) — but there is currently no
D11 production pipeline to deploy it through: `terraform-infrastructure/mandala/drupal/production/ansible/`
only has the pre-D11 Aegir-era playbooks (`configure_backend.yml`, `idle_backend.yml`,
`standard_provision.yml`), not `deploy_backend.yml` (see
[d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md), out of scope since
2026-08-11). Since production will build from this same `package/Dockerfile` whenever its
pipeline is stood up, fixing it here now already covers production by construction — no
separate prod-specific step to track, just don't let a future one-off prod image build skip it.

## Related — `drush` itself is very likely NOT a real gap

Raised in the same conversation: `drush` (via Composer, `vendor/bin/drush(.php)`) is already
used successfully everywhere it matters — `deploy_backend.yml` and the dev-0 migration runs
invoke it constantly, always by full path (`{{ drupal_home }}/vendor/bin/drush`). There's no
`/usr/local/bin/drush` symlink, so a bare `drush` in an interactive shell will report "command
not found" — that's a PATH issue, not a missing binary. If `drush` is ever seen missing even by
full path, that's a distinct, real bug (e.g. a failed `composer install`) and should be
investigated on its own, not folded into this note.

## Cross-references

- `docs/session-logs/2026-08-25-acceptance-run-prepared-and-handoff.md` §2, §"Still unproven"
- `scripts/db-checkpoint.sh` — the current host-side mitigation
- [d11-app-has-no-cicd-pipeline.md](d11-app-has-no-cicd-pipeline.md) — why production has no pipeline to carry this fix yet
- `package/Dockerfile`
