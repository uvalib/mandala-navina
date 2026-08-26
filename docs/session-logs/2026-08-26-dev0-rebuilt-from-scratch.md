# Session Log: dev-0 rebuilt from scratch; the migration is running

**Date:** 2026-08-26
**Participants:** Yuji Shinozaki, Claude Code
**Outcome:** The group chose a **from-scratch rebuild** over `migrate:rollback` → `import`.
dev-0 was reset and reinstalled, users migrated, and the ~15 h images migration is **running
detached**. Six PRs merged (#157–#160 plus #151, #158). Runbook:
[dev0-from-scratch-rebuild-runbook.md](../planning/dev0-from-scratch-rebuild-runbook.md).

> Hand-written. The value here is a sequence of things that were wrong until they were run.

## 1. The decision: from scratch, not rollback

`migrate:rollback` does not reset `AUTO_INCREMENT`, so every cycle assigns higher nids.
Three consequences settled it:

1. **Deterministic nids.** ADR 016's redirects and the kmassets `images-11-{nid}` uids are
   both keyed on D11 nids, so under rollback→import **every rebuild silently invalidates
   them**. From an empty database, nid *N* maps to the same D7 row every time.
2. It clears the dev-0 duplicate (D7 nid `981206` → nids 76584/76585) by construction.
3. It rehearses cutover, which will be a fresh build, never a rollback.

**A resume is not the cheap recovery it looks like.** `prepareRow()` runs on every source row
regardless of the migrate map, so a restart at hour 14 costs another ~15 h, not one. And
whether a resume *cleanly skips* was never verified — the deferred note flagged exactly that,
and the duplicate came from precisely such a resumed run. That is an argument for letting this
run finish uninterrupted.

## 2. Pre-flight: almost nothing needed saving

| Item | Finding |
|---|---|
| Config drift | **No differences** |
| Nodes / groups without `field_legacy_nid` | **0 / 0** |
| Users without a `migrate_map_d7_users` row | **0** of 1,543 |
| `files/`, `keys/` | bind-mounted, survive |

An earlier query of mine labelled 156 users "hand-created" — **wrong**; they are migrated
users who had no D7 `authmap` row.

Exactly two things a fresh install could not rebuild: the **`solrproxy` OAuth2 consumer**
(hashed secret) and the **uid-600 test IdP authmap link**.

**`hash_salt` was a non-issue**, settled by reading the code rather than guessing:
`settings.php:359` reads it from `getenv('DRUPAL_HASH_SALT')` and never writes it to the file,
so `site:install` cannot change it. Better still, the secret is injected into the container by
the deploy — so the consumer was recreated by reading `$SOLRPROXY_CLIENT_SECRET` from the
container's own environment. **No secret was decrypted, copied or typed.**

## 3. The runbook was wrong in three places

Written carefully, reviewed, and still wrong — each found only by running it:

1. **`site:install` does not drop tables.** It refused with Drupal's "already installed"
   page. An explicit drop step was needed. Nothing was damaged.
2. **`--existing-config` is impossible here.** The `standard` profile implements
   `hook_install()`, which Drupal forbids installing from config. Fell back to: normal
   install → pin the committed site UUID → `cim`.
3. **The drop needs `-i`.** `docker run` without it never received the heredoc, so the drop
   ran against no input and reported success while all 286 tables survived. Caught only
   because the step asserts "tables after drop must be 0".

Three further obstacles in the same stretch:

- **D11 core bug**: `Call to undefined function node_access_needs_rebuild()` during `cim`
  when modules install. Worked around by re-running.
- **Shortcut entities blocked `cim`.** `drush entity:delete shortcut` silently did nothing;
  the entity API worked.
- **The 128 MB limit killed `cim`** — its *third* victim after `migrate:import` and
  `kmassets:index-all`. Strengthens the case for raising the CLI limit in the image.

## 4. Two real bugs found in our own code

**`field_legacy_site` was missing from both display configs** ([#159](https://github.com/uvalib/mandala-navina/pull/159)).
Adding a field makes Drupal auto-add it to the `hidden` list of the node view and form
displays in *active* config, but [#152] never updated `config/sync` to match — so every
environment drifts the moment the field installs. **`deploy_backend.yml` fails the build on
drift, so the next deploy would have failed regardless of this rebuild.**

**`db-checkpoint.sh restore` would have dropped only *some* tables**
([#160](https://github.com/uvalib/mandala-navina/pull/160)). The drop builds
`DROP TABLE IF EXISTS <list>` with `GROUP_CONCAT`, whose `group_concat_max_len` defaults to
**1024 bytes**; this schema's ~286-table list is ~8 KB and was silently truncated. **The
"restore is proven" test could not have caught it** — the scratch database was empty, so the
drop ran with nothing to concatenate. It now raises the limit and asserts the schema is
actually empty before loading.

## 5. `restore` was proven first — and DDEV would not have done it

Before any of this, `restore` was exercised against a **scratch schema on the real RDS**
(`mandala_restore_test`, permitted by `GRANT ALL ON mandala%.*`): 286/286 tables,
111,341/111,341 nodes, 166,395/166,395 paragraphs, 171/171 groups — exact.

DDEV was considered and rejected: it runs as **root on a local MySQL**, so it would have
proven the drop/load logic while skipping the thing most likely to fail — whether the *app
user* can drop and reload on RDS. A `TARGET_DB` override was added so the safety net can be
tested without risking the live database.

## 6. The long-clone problem, quantified

`pipeline/deployspec.yml:42` clones `terraform-infrastructure` with **no `--depth` and no
`--single-branch`** — a repo of **11,785 commits / 831 MB of `.git`** — on *every* deploy,
when the deploy only reads the current tree. `--depth 1 --single-branch` should cut it
dramatically.

**Deliberately not fixed yet:** `pipeline/**` is a trigger path, so merging it starts a deploy,
and the container restart would kill the running migration.

## 7. Current state — migration RUNNING

dev-0 is a clean D11 install: correct site UUID (`dfc3f060-…`), unchanged `hash_salt`, ADR 015
roles, 54 `shanti_image` fields, `config:status` clean after the #159 deploy.

- **Users migrated:** 1,542 created / 0 failed; authmap 1,384 / 0 failed. NB 1,384 against
  1,385 before the rebuild — the difference is exactly the hand-made uid-600 link (step 7).
- **`solrproxy` consumer recreated**, matching the pre-reset shape (`auto_auth=on`, redirect
  = the ALB `idx` CNAME's `/auth`).
- **Images migration launched detached** with the 1024 MB limit against `drush.php`.
  ~15 h; `d7_images_shanti_image` alone is ~9.3 h at ~200 rows/min.

### ⚠ Merge freeze while it runs

Nothing touching **`drupal/**`, `package/**` or `pipeline/**`** may be merged to `main` until
the run completes — each is a pipeline trigger path, and the deploy restarts the container,
which kills the migration. `scripts/**` and `docs/**` are safe.

### Remaining steps

7. Re-link the uid-600 test identity (not a migration; identity is in `mandala-navina-docs`).
8. `EXPECT_FILE=scripts/baselines/dev-0.txt ./scripts/migration-cycle.sh validate` —
   **`integrity:legacy_nid_dupes` must be 0.** If the duplicate survives a from-scratch
   rebuild it is a **migration defect**, not a July artefact. That is the single most
   valuable thing this run can tell us.
9. kmassets: `delete "uid:images-11-*"` → `index-all` → `audit --check-stale`.
10. URL smoke tests: public 200 / private 403 / bogus 404.

Then: fill the remaining dev-0 baseline keys (reconciled against the D7 source, not copied
from the result), and land the `--depth 1` clone fix.

## 8. The lesson, again

Every single defect in this session — three runbook errors, two code bugs, three environment
obstacles — was invisible to review and static checking, and appeared the moment something
was actually executed. The pattern held even for a runbook written *specifically* to be
careful, and for a restore path I had reported as "proven" the same morning.
