# Session Log: 1a.9 acceptance run prepared — and a handoff for the morning run

**Date:** 2026-08-25
**Participants:** Yuji Shinozaki, Claude Code
**Outcome:** Every prerequisite for the Sprint 1 acceptance run is done, deployed and
verified on dev-0. **The run itself was not started** — it is a 16–18 hour job and does not
fit dev-0's 17-hour daily window. §7 is the morning runbook.

> Hand-written rather than machine-generated. The session was long and the value is in a
> handful of findings, which a verbatim transcript would bury.

## 1. What shipped

Twelve PRs merged (#142–#150, #152–#154; #146 was Than's). `main` at `f31026e`, deployed to
dev-0 and verified live.

| | |
|---|---|
| [ADR 016](../adr/016-public-url-structure-single-host.md) | Public URL structure — **Proposed**, 3 open items |
| [ADR 017](../adr/017-legacy-identity-composite-key.md) | Legacy identity composite key — **Proposed**, for tomorrow |
| `d7_images_url_alias` | D7 pathauto paths → `path_alias`; **verified 111,304 / 0 mismatches** in DDEV |
| `d7_images_collection_url_alias` | Collection aliases → Group paths; **174 / 0 mismatches** |
| `field_legacy_site` | `list_string` on node + group, wired into 3 migrations |
| `scripts/db-checkpoint.sh` | Rewritten; `save`/`list` proven on dev-0 |
| `scripts/baselines/dev-0.txt` | Per-environment baseline via `EXPECT_FILE` |

## 2. The recurring lesson, five times over

**Everything that was only checked statically was wrong.** Running things found, in order:

1. `db-checkpoint.sh` used `drush sql:dump`, which **silently produced empty backups**.
   Drush's SQL commands are wrappers; the container has no mysql client, so drush logs a
   *warning* from a validate hook and **exits 0**. A "backup" that is empty, discoverable
   only at restore time.
2. `--no-progress` does not exist in this Drush version — the first full alias run failed
   outright.
3. `db-checkpoint.sh list` reported `(none)` with two checkpoints present: the glob was
   expanded by the caller's shell against a root-owned mode-700 directory.
4. My `EXPECT_LIST` guidance was backwards — I wrote "≥ the node count"; measured, it is
   **below** it (111,304 vs 111,343; 39 nodes have no alias, and there are **no** duplicate
   aliases, contrary to what I had assumed).
5. A YAML quoting bug and a `translatable` default, both caught only by `cim` refusing the
   file.

And one **retraction**: I reported that collection pages were 403 "because no group role
grants `view group`". Both halves were false — the permission is granted and the enforcement
was already built. It came from a bad grep (`view group$` never matches `- 'view group'`).
Detail in [the deferred note](../deferred/d7-alias-preservation-scope-beyond-shanti-image.md).
**Absence of a grep hit is not evidence of absence.**

## 3. Two real defects found

**A duplicate migration on dev-0.** D7 nid `981206` exists twice in D11 (nids 76584, 76585):
111,341 nodes against 111,340 source rows, 111,340 distinct legacy nids. Local DDEV is clean,
so it is dev-0-specific — most likely from the 2026-07-17/18 run that OOM'd and was resumed.

This is *why* the dev-0 baseline is **source-derived**: baselining from current state would
have recorded `111341` and permanently blessed the duplicate as expected. New reconciliation
key `integrity:legacy_nid_dupes` now asserts it, and it **dents ADR 017's premise** — the
composite key assumes `(site, nid)` is unique, and here one source row produced two nodes.

**dev-0 and local DDEV run different D7 dumps.** Seven of eight baseline keys differ and
neither is wrong. See [canonical-d7-dev-source-dump.md](../deferred/canonical-d7-dev-source-dump.md).

## 4. Anonymous group access — a stale-data trap

174 `group_membership` rows with `entity_id = 0` made **anonymous a "member" of every group**
in local DDEV, so the checker took the insider branch and denied everything. Dated
`2026-07-10`, i.e. the pre-fix 1b.2 run. **dev-0 is clean (0 rows), verified.** Cleared
locally; public 200 / private 403 / bogus 404 now all correct.

## 5. Collection pages render only a header

Expected, not broken. The group view display has exactly one component — `label` — and a
Group's canonical page does not list its content. `images-missing-interactive-viewing-surfaces.md`
already owns this. Consequence worth stating: **ADR 016 decision 7 is only half-delivered for
collections** — the URL survives cutover and resolves, but lands on a page with nothing under
the title.

## 6. ⚠ The run does not fit the daily window

Measured dev-0 rates ([source](../deferred/dev-migration-slower-than-ddev-cross-az-latency.md)):

| Migration | Rows | Pace | Time |
|---|---:|---:|---:|
| `d7_images_shanti_image` | 111,340 | ~200/min | **~9.3 h** |
| `d7_images_image_collection_membership` | 111,307 | ~1,120/min | ~1.7 h |
| `d7_images_image_agent` | 111,194 | ~1,120/min | ~1.7 h |
| `d7_images_url_alias` (new) | 111,301 | ~1,100/min est | ~1.7 h |
| `d7_images_image_descriptions` | 55,041 | ~1,250/min | ~0.7 h |
| **Import total** | | | **~15 h** |

Plus a rollback of ~111k nodes and ~166k paragraphs, never timed on dev-0: **16–18 h total.**

**dev/staging instances stop nightly 23:00–06:00** — a full instance stop that kills any
`docker exec`'d process and does not auto-resume. The window is 17 hours. And resuming is
**not** cheap: `prepareRow()` runs on every source row regardless of the migrate map, so a
killed run costs close to a full one again.

**Decision required before starting: suspend the nightly shutdown for one night, or accept
that the run must start at 06:00 sharp and finish inside 17 hours with no hiccups.**
(The 23:00–06:00 window is from memory and was **not** re-verified today.)

## 7. Morning runbook

Preconditions — **all done**, no action needed:

- [x] `main` `f31026e` deployed to dev-0; `MIGRATE_*` in the container, both alias migrations
      registered, `field_legacy_site` present, `config:status` clean.
- [x] Two verified rollback points on dev-0, in `/mnt/data/mandala-drupal-0/checkpoints/`:
      `pre-import-20260825T183428Z.sql.gz` and `post-deploy-20260825T193637Z.sql.gz` (75M each).

Then:

1. **Settle the shutdown question** (§6). Nothing else matters until this is decided.
2. Fresh checkpoint if anything changed overnight:
   `./scripts/db-checkpoint.sh save pre-import` (run **on** dev-0).
3. Run detached, so an SSH drop does not kill it — long-held connections to dev-0 are
   unreliable, and this pattern is the one that has worked:
   ```
   sudo docker exec -d mandala-drupal-0 bash -c \
     'php -d memory_limit=1024M /opt/drupal/app/drupal/vendor/bin/drush.php \
      migrate:import --group=mandala_images > /tmp/import.log 2>&1'
   ```
   The memory limit is **not optional** — 128M has killed a long run twice, and it must
   target `drush.php`, not the `drush` wrapper.
4. Poll with **fresh short SSH connections**, never a held pipe.
5. Validate against the dev-0 baseline:
   `EXPECT_FILE=scripts/baselines/dev-0.txt ./scripts/migration-cycle.sh validate`
6. **kmassets:** the re-import assigns new nids, so every existing `images-11-*` doc is
   orphaned. Needs `kmassets:delete "uid:images-11-*"` → `kmassets:index-all shanti_image`
   → `kmassets:audit --check-stale`. **Not yet in the checklist.**

### Open questions the run will answer

- **Does the duplicate clear?** Rollback deletes everything the migration created, so it
  should. If `integrity:legacy_nid_dupes` still fails after a clean cycle, that is a
  migration defect and the run has found its first real bug.
- The remaining dev-0 baseline keys can then be filled — **reconciled against the D7 source**,
  not copied from the result.

### Still unproven

- **`db-checkpoint.sh restore`** has never been executed. Prove it against a scratch
  database, not the live one.
- Whether the MySQL client should join the Drupal image — its absence cost three detours
  today.
- `AGENTS.md` is untracked and keeps getting swept into commits by `git add -A`.

## 8. For tomorrow's discussion (Yuji, Than, Xiaoming)

- **ADR 017** — the composite key. Note the AV subtlety (audio and video share **one** token,
  because they share one D7 nid sequence) and the dev-0 duplicate that dents its premise.
- **ADR 016** — 3 open items: host-aware redirect mechanism, nid vs **slug** as D11 canonical,
  and `mandala-om` coordination on `url_html` (Than: `MandalaMarkup.js` reverse-looks-up on it).
- **The canonical D7 dev dump** — frozen, or re-cut as staging/production approach?
- Carried over: [uniform endpoint access](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md) (#136), still needs Yuji + Xiaoming.
