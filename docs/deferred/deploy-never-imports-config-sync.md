# The dev deploy never imports config/sync — merging to main does not change the site

**Area:** deployment / Ansible / CMI / dev environment
**Raised during:** Session 2026-08-12 (running the user migration on dev-0)
**Jira:** (add when available)
**Priority:** **High — every `config/sync` change merged to `main` since this deploy
path was built has silently failed to reach dev-0.**

**Status: PARTIALLY RESOLVED 2026-08-17** — `deploy_backend.yml` now runs a full
`drush updb` + `drush cim` on every deploy (not just the partial SimpleSAMLphp
import), and a post-cim `drush config:status --format=json` check fails the build
if any drift remains (`terraform-infrastructure` commit `5904a3684`). This closes
items 1 and 4 of "What needs to happen" below. **Still open:** item 2 (RDS
snapshot gating before the full `cim`/`updb` runs — Decision B's other half,
never built) and item 3 (this was checked live 2026-08-17 and found already
clean — `drush config:status` on dev-0 reports no drift, so the ADR 015 items
must have been hand-applied at some point after 2026-08-12; not investigated
further since it's now moot given the build-failing check above).

## What we found

`terraform-infrastructure/mandala/drupal/staging/ansible/deploy_backend.yml` runs
exactly one `cim`:

```yaml
- name: load simplesaml drupal configs
  command: docker exec {{ container_name }} .../drush cim -y --partial --source=/var/simplesamlphp/drupal-config
```

That imports **only** the SimpleSAMLphp config directory. There is **no full `cim`**
and **no `updb`** anywhere in the playbook.

So the deploy ships new *code* (the image bakes the repo, including
`drupal/config/sync`) but never *imports* it. The files sit inside the container,
unread. Merging to `main` updates the image and changes nothing about the running
site's configuration.

## This contradicts a decision that was made and never implemented

Decision B of 2026-07-16 (`d11-dev-database-bootstrap-and-migration-source.md`) was
explicitly: *dev's deploy runs `updb` + FULL `cim`* — a deliberate divergence from
dsf's partial-only approach — **gated on a fresh RDS snapshot** of `mandala_drupal_0`.
The decision was recorded; the playbook was never changed to match. Worth checking
whether the snapshot gating was also never built, since the two were meant to ship
together.

## Measured impact (dev-0, 2026-08-12)

`drush config:status` showed **9 items** adrift, none of which had ever been applied:

| Config | State |
|---|---|
| `migrate_plus.migration.d7_users` | Only in sync dir |
| `migrate_plus.migration.d7_user_authmap` | Only in sync dir |
| `migrate_plus.migration_group.mandala_users` | Only in sync dir |
| `group.role.collection-content_editor_insider` | Only in sync dir |
| `group.role.collection-content_editor_outsider` | Only in sync dir |
| `group.role.subcollection-content_editor_insider` | Only in sync dir |
| `group.role.subcollection-content_editor_outsider` | Only in sync dir |
| `user.role.content_editor` | Different |
| `core.extension` | Different |

Two whole workstreams were affected without anyone noticing:

- **The user migration** (PRs #45/#66/#73, merged and independently verified on a
  scrubbed DB 2026-07-24) was never *registered* on dev-0 — `migrate:status` simply
  did not list `d7_users` or `d7_user_authmap`. It looked like the work had not been
  done; in fact it had, and the config had never been imported.
- **ADR 015's editorial access model** — `content_editor` plus its four Group roles —
  is likewise still unimported. **The access model everyone believes is deployed on
  dev-0 is not.**

`core.extension` being `Different` also means the *enabled module set* diverges from
the committed one.

## Why it went unnoticed

Nothing fails. The deploy is green, the site runs, the image genuinely contains the
new code. Only a `config:status` — which nobody runs after a routine deploy — reveals
the drift. It is the same shape as several other findings this week: a green pipeline
that conceals an inert result.

It also explains an old confusion: the 2026-07-21 note that PR #45 "merged to main but
cannot reach dev-0 without a rebuild". A rebuild was the wrong fix — the image was
never the problem. The *import* was missing.

## What needs to happen

1. **Add a full `cim` (and decide on `updb`) to `deploy_backend.yml`**, per the
   2026-07-16 decision. Note this is a genuine divergence from dsf, which is
   partial-only by design — so it is a decision to re-affirm, not just a bug to fix.
2. **Restore the snapshot gating** that decision specified, or consciously drop it.
   An unattended full `cim` against a database holding a two-day migration deserves a
   restore point.
3. **Import the outstanding ADR 015 config on dev-0** — currently 6 items adrift after
   the targeted migration import below. Until then, any testing of the editorial
   access model on dev-0 is testing something that is not there.
4. Consider a post-deploy `config:status` assertion so drift fails loudly rather than
   silently.

## Interim action taken 2026-08-12

To unblock the user migration only, the three migration configs were imported
**targeted** rather than via a full `cim`:

```
drush cim -y --partial --source=<dir containing only those 3 files>
```

Deliberately narrow: all three were *"Only in sync dir"*, so the import purely **added**
config and could not overwrite anything — rollback would be `config:delete` on three
names. The ADR 015 items were left alone rather than swept in as a side effect of a
migration task. Safety nets: RDS snapshot `mandala-preusermigration-20260812-1151` and
a pre-change `config:export` at `/tmp/config-pre` in the container.

**6 config items remain adrift** and still need a decision.

## Cross-references

- `terraform-infrastructure/mandala/drupal/staging/ansible/deploy_backend.yml`
- [d11-dev-database-bootstrap-and-migration-source.md](d11-dev-database-bootstrap-and-migration-source.md) — decision B (2026-07-16)
- [adr-015-unanswered-questions-at-merge.md](adr-015-unanswered-questions-at-merge.md) — the access model that is not actually deployed
- [dev-0-code-config-delivery-rebuild-or-pipeline.md](dev-0-code-config-delivery-rebuild-or-pipeline.md) — the 2026-07-21 "cannot reach dev-0" confusion this explains
