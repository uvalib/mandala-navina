# kmassets:audit Hardening Follow-ups
**Area:** solr / kmassets / audit / DX
**Raised during:** Session 2026-07-07 (Sprint 1 1a.9, PR #19)
**Jira:** (add when available)
**Priority:** Low–Medium — non-blocking polish on the working `kmassets:audit` command. **Item 3 (new 2026-09-24) is Medium-High: the analysis-and-cleanup that had to be done by hand for the DDEV-pollution incident should be a real, tested drush command.**

Three follow-ups (item 3 added 2026-09-24) flagged during the 1a.9 `kmassets:audit` build. The command works and is
verified end-to-end; these are hardening items, not correctness bugs.

## 1. Confirmation guard for large `--fix` runs

`kmassets:audit --fix` reindexes **every** missing doc. Against a freshly-migrated staging
index that is the whole bundle — potentially ~111k writes to the **shared** staging Solr
master. That is legitimate as the deliberate bulk-index, but there is currently no guard
against running it unintentionally. Add a confirmation prompt (or a `--yes` / dry-run
`--report-only` split, or a threshold above which it refuses without `--force`) so a large
`--fix` is always deliberate.

## 2. Pass A performance — avoid full entity loads

The missing/stale pass (`KmassetAuditor`, Drupal→Solr) loads **full node entities** in
batches just to read `id` + `changed` — ~1.5 min for 111k on DDEV. A targeted query for
`nid` + `changed` (skipping full entity hydration) would cut that dramatically. Worth doing
if the audit is run frequently or against larger asset types than Images.

## 3. A drush command for the analysis + cleanup done by hand on 2026-09-24

Cleaning the shared master after the DDEV write incident
([solr-cross-environment-write-targets.md](solr-cross-environment-write-targets.md)) took a
series of ad-hoc scripts: dump the real node ids from dev-0, page every `audio-video-11-*` doc
out of Solr, diff the two, profile *where the bad docs came from*, back them up, delete, then
re-verify. It should be a supported, tested command (working name
`kmassets:audit --analyze` / `kmassets:reconcile`), so the next incident is minutes not hours and
is done the same way every time. What the by-hand run needed that `kmassets:audit` does not do:

1. **Both directions, by uid namespace.** Compare all Solr docs matching the service's uid
   pattern (`<service>-11-*`) against published nodes: orphans (doc, no node), missing (node, no
   doc). `kmassets:audit` already does this; the command should also report **expected counts**
   (nodes vs docs) so a size mismatch is obvious.
2. **Wrong-content docs.** A doc with a *valid* uid but content belonging to another node
   (27 -> **45** such docs on 2026-09-24; `kmassets:audit` reports "in sync" for them). Detect by
   comparing title, `node_changed` (doc newer/older than the node), and `collection_uid_s`
   against the node, not just existence. `--check-stale` only compares `node_changed` lag.
3. **Provenance profile.** Group the suspect docs by write-time burst, `node_changed` value
   (identical to the second = one drush process, since Drupal stamps `changed` with request
   time), and uid range vs the environment's max nid (a clean +N offset = a different
   environment's ids). This is what pinned the cause and proved nothing legitimate was in the
   delete set.
4. **Safe by default.** Dry-run unless told otherwise; a **JSON backup of every doc it will
   delete or overwrite** written before any change (the 2026-09-24 backup was 8.7 MB for 4,194
   docs and is the only rollback); a hard cap / confirmation above N deletions (see item 1
   above); explicit flags for delete-orphans vs reindex-mismatched.
5. **Master AND reader.** Verify against both hosts, aware that the reader is a **public-only**
   view (private docs are absent from it by design), so "on master, not on reader" must be
   judged by `visibility_s`, not counted as a gap
   ([kmassets-audit-checks-master-not-search-reader.md](kmassets-audit-checks-master-not-search-reader.md)).
6. **Post-run verification built in:** re-diff, assert 0 orphans / 0 missing / no doc carrying
   the bad `node_changed`, and print before/after counts.
7. **Per-service and sibling-safe** (audio/video share `audio-video`; see PR #199), and it must
   refuse to run when `solr_master_url` is unset (the DDEV guardrail) rather than fail midway.

The 2026-09-24 manual procedure is the reference implementation (steps and results are in the
write-targets note). Not started; no owner assigned.

## Context

Both live in `drupal/web/modules/custom/mandala_kmassets_sync/` (the `KmassetAuditor`
service + `kmassets:audit` Drush command). See
[kmassets uid identity](kmassets-uid-identity-across-migration.md) for the surrounding
identity/audit design.
