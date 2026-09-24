# Cross-environment Solr writes: D7 staging → production, production Visuals → staging, and (new) D11 DDEV → shared staging master

**Area:** solr / D7 legacy / environment isolation / production risk
**Raised during:** Session 2026-08-13 (Solr index inventory across dev / staging / production)
**Jira:** (add when available)
**Priority:** **HIGH (new item, 2026-09-24): D11 DDEV writes to the shared staging Solr master by default and had already polluted it — see "D11: DDEV writes..." below. The guardrail is merged (PR #250) and the pollution is CLEANED UP (2026-09-24); a local Solr container, the ADR/CLAUDE.md/start-check follow-ups and a reusable cleanup command remain.** The original D7 items: **Medium — the two staging→production write paths are FIXED (2026-09-02, group
decision).** `mandala-sources-staging`'s `solr` search_api server is disabled
(`search-api-server-disable`, verified via `search-api-server-list`). `mandala-av-staging`'s
`mandala_library_rw` apachesolr environment is repointed from the production Solr master to an
inert local placeholder (`solr-set-env-url`, verified in `apachesolr_environment`). Neither can
write to production anymore. **Production Visuals → staging remains open, assigned to Yuji** —
see below.

## D11: DDEV writes to the shared staging Solr master by default (found 2026-09-24) — HIGH, fix prioritized

**Principle (raised in the 2026-09-24 session, Yuji present -- confirm agreement):** a DDEV
environment must **not write to any shared Solr index by default**; it should have its own
local Solr index. Reaching a shared endpoint from DDEV should be a deliberate opt-in.

**What is true today**
- DDEV has **no Solr service** (`.ddev/` has Redis only) and imports the committed `config/sync`
  unchanged. `mandala_kmassets_sync.settings` sets `solr_master_url` to the shared
  `mandala-solr-master-staging-private` master, with no DDEV override in `settings.php` /
  `settings.ddev.php`. `mandala_kmassets_sync_node_insert/update` write **inline on every node
  save**, so any node save on a DDEV writes to the shared master under **that DDEV's own node
  ids**.
- `search_api.server.kmassets` reads from the shared `mandala-index-dev`. Read-only, but a DDEV's
  search shows shared-index content.
- The drush tools can also **delete** from the shared master from a DDEV (`kmassets:delete`,
  `kmassets:audit --fix`) -- the same isolation gap, in the destructive direction.
- Redis (visibility tokens) is already DDEV-local; only Solr is shared.

**What it already did (read-only investigation, 2026-09-24)**
- On 2026-09-18, **4,194 AV docs** were written in one burst (15:50-17:15Z) with `node_changed`
  all exactly `15:53:57Z` -- a single drush process (Drupal stamps `changed` with request time)
  saving ~4,194 nodes over about an hour, in an environment whose AV nids were dev-0's **+4,187**
  (uid max 127,092 = dev-0's max 122,905 + 4,187; the id offset documented in
  [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md)).
  A separate 10,341-doc burst that day (18:00-20:10Z, dev-0's real ids) is dev-0's own
  `av:backfill-kaltura-duration` run and is legitimate.
- Result on the shared master: **4,149 orphaned docs** (`kmassets:audit audio|video`, report-only,
  run on dev-0) -- uids like `audio-video-11-123558` for nodes that do not exist on dev-0 -- plus
  **45 docs with a valid dev-0 uid but the wrong content** (first reported as 27 from a too-narrow uid cutoff; corrected the same day) (e.g. uid 116968 is titled as a
  different recording than the real node; the orphan audit does not catch these). The master
  holds 27,273 AV docs against 11,583 AV nodes (legacy D7 docs account for part of the rest).
- **Access impact:** the reader is a public-only view (0 private docs). It still serves **382
  public orphan docs** inside 7 of the 15 AV collections whose access was just repaired (see
  [subcollection-access-overwritten-by-inheritance-hook.md](subcollection-access-overwritten-by-inheritance-hook.md)):
  titles/metadata searchable as public for private/UVA-only collections (links 404, the nodes
  do not exist on dev-0).
- **Which machine wrote it is not proven.** The 2026-09-18 sessions were git-authored by Yuji and
  the 2026-09-22 log found "DDEV" ids at dev-0 + 4,187, which points at his DDEV, but that is
  inference. Ask him.

**Cleanup -- DONE 2026-09-24 (approved by Xiaoming; run against the shared master via dev-0 as `xw5d`)**
1. Independent orphan list: all 15,731 `audio-video-11-*` docs paged from the master and diffed
   against dev-0's 11,582 published AV nids -> **4,149 orphans, 0 missing** (matched the audit
   exactly). Every orphan: uid 122,924-127,092 (above dev-0's max real nid, 122,923), written
   16:15-16:45Z on 2026-09-18, all `node_changed` = `15:53:57Z` (3,314 public / 835 private; 474
   inside the 15 repaired AV collections). Nothing legitimate in the delete set.
2. **Backup before any change:** JSON of every orphan and wrong-content doc (8.7 MB) at
   `~/Desktop/mandala/solr_cleanup_backup_2026-09-24.json` on Xiaoming's machine. It is the only
   rollback (re-POST the docs); keep it until this is closed.
3. `kmassets:audit audio --fix` on dev-0: **4,149 deleted, 0 reindexed.**
4. The **45** wrong-content docs (valid uid, `node_changed` = `15:53:57Z`) were re-indexed from
   their real nodes via `indexNode()`: 45 ok, 0 errors (e.g. uid 116968 now titled correctly).
5. **Verified afterwards:** master has 11,582 `audio-video-11-*` docs = the 11,582 real nodes, 0
   orphans, 0 missing, 0 docs left with the bad `node_changed`. Reader: 0 orphans (8,547 docs;
   the difference is its public-only view), and 0 docs in the 15 repaired collections, while the
   master holds their 1,143 private docs -- the 382 public orphans that had leaked are gone.

**Still to do:** a reusable, tested drush command for this analysis and cleanup -- see item 3 in
[kmassets-audit-hardening.md](kmassets-audit-hardening.md) -- **assigned to Yuji (2026-09-24)**.
The hand-run steps above are the reference for it.

**Fix plan (proposed, not started)**
1. **Guardrail, small PR, first -- IMPLEMENTED in [PR #250](https://github.com/uvalib/mandala-navina/pull/250)
   (2026-09-24), pending merge.** Verified locally: effective URL empty, hooks unconfigured,
   `kmassets:index/audit/delete` abort with "solr_master_url is not configured", shared master
   untouched, opt-in via `MANDALA_DDEV_SOLR_MASTER_URL`. The DDEV `search_api` reader is still
   shared (read-only) and is not changed by it. Original plan: In `settings.php`'s DDEV block set
   `$config['mandala_kmassets_sync.settings']['solr_master_url'] = ''` so the sync module is
   unconfigured and writes nothing -- fail closed for every DDEV on the next `git pull`. **Verify
   the drush commands (`kmassets:index/index-all/delete/audit --fix`) also fail closed when it is
   unset**, not only the save hooks. Re-point (or disable) the DDEV `search_api.server.kmassets`
   the same way.
2. **A real local index.** Add a Solr container to DDEV (DDEV Solr add-on) with the kmassets
   configset, and point both the write URL and the search server at it (single node, so master =
   reader). Open decisions: full index (~122k docs, roughly 2.5 h at earlier rates) vs a scoped
   sample; configset source (check `solr-shanti-configsets` against the kmassets schema).
3. **Make it stick.** A short ADR (local environments never write shared search indexes), a
   `CLAUDE.md` line, and a `scripts/session-start-check.sh` warning when the effective Solr write
   URL is not local.
4. Supersedes the narrower "per-environment host override" idea in
   [spike-solr-demo-enabled-with-anonymous-route.md](spike-solr-demo-enabled-with-anonymous-route.md);
   the same mechanism also covers staging/production (each gets its own value).

**Owner:** not assigned -- decide with Yuji in the session. Nothing above has been executed
beyond the read-only investigation.

## What was found (D7 legacy, 2026-08-13)

The D7 "staging" installation on `mandala-drupal-dev-1` is a **configuration clone of
production** — its Solr settings were copied along with the databases and never
repointed. The result is write paths that cross environment boundaries in both
directions.

### Staging → production (the risky direction)

| Staging site | Mechanism | Target |
|---|---|---|
| `mandala-sources-staging` | `search_api` server `solr` (enabled) | `mandala-solr-master-**production**-private:8080/solr/mandala-sources` |
| `mandala-av-staging` | `apachesolr` env `mandala_library_rw` | `mandala-solr-master-**production**-private:8080/solr/mandala-av` |

These point at the **master**, which is the write endpoint. A `search_api` reindex,
a cron run that flushes a pending queue, or a content edit on the staging site can write
into — or delete from — the production `mandala-sources` and `mandala-av` cores.

Also on staging: `shanti_kmaps_admin_server_solr` / `_terms` point at the production
`mandala-solr-proxy` / `mandala-index` hostnames on four and six sites respectively
(read-only, but still production data being read by a staging site).

### Production → staging (the confusing direction)

| Production site | Mechanism | Target |
|---|---|---|
| `mandala-visuals` | `search_api` server `mandala_library_rw` (enabled, index `visuals_drupal_index` bound to it) | `mandala-solr-master-**staging**-private:8080/solr/mandala-visuals` |

A production site indexing into the staging cluster. This is the enabled, bound server
for that site's only live index — it is not a disabled leftover.

It also explains an oddity in the core inventory: `mandala-visuals` has **1 document** on
staging (last written 2022-05-31) and **0 documents** on production. The production core
is empty because production Visuals has never written to it.

## Why this matters now

1. **The staging D7 site is the migration source rehearsal environment.** Work on
   `dev-1` — including anything that triggers D7 cron or a reindex — can reach production
   Solr. Anyone doing migration rehearsal there should know this before running commands.
2. **It compounds the frozen-index question.** If production kmassets and these per-site
   cores have odd write histories, cross-environment wiring is one candidate explanation
   to rule in or out — see
   [`kmassets-production-index-frozen.md`](kmassets-production-index-frozen.md).
3. **It is a pattern, not a one-off.** Three separate cross-environment paths in a
   six-site installation suggests the staging clone was never audited after creation.
   Assume more of `dev-1`'s configuration still points at production resources —
   Solr is simply where we happened to look.

## Suggested actions

1. **Audit `dev-1` for every remaining production reference**, not just Solr: file
   systems, external APIs, mail, IIIF, the KMaps servers. Solr was found incidentally.
   **Still open — no owner assigned yet.**
2. ~~**Disable the two staging → production write servers**~~ **DONE 2026-09-02** (group
   decision, executed live on `dev-1`):
   - `mandala-sources-staging`: `solr` search_api server disabled via
     `drush search-api-server-disable solr -y`. Verified via `search-api-server-list` →
     `solr` now shows `disabled`.
   - `mandala-av-staging`: `mandala_library_rw` apachesolr environment repointed via
     `drush solr-set-env-url http://127.0.0.1:8983/solr/disabled-was-production --id=mandala_library_rw`
     (a full module disable was rejected — `apachesolr` cascades through 6+ dependent
     modules on that site, too big a blast radius for this fix). Verified in
     `apachesolr_environment` — URL no longer resolves to production.
3. **Production Visuals writing into the staging master** — **assigned to Yuji,
   2026-09-02 (group decision)** to review and decide. Given
   [`searchstax-defunct-external-solr-config.md`](searchstax-defunct-external-solr-config.md)
   shows its other backend is dead too, Visuals search on D7 is probably already
   non-functional; the honest fix may be to turn it off rather than repoint it. Lower
   urgency than the two fixed above — this direction is production→staging, not the
   staging→production risk that motivated fixing #2 first.
4. **Note it as a cutover consideration:** whatever D11 does for Sources and AV search
   must not inherit these targets.

## Related

- [`searchstax-defunct-external-solr-config.md`](searchstax-defunct-external-solr-config.md)
- [`kmassets-production-index-frozen.md`](kmassets-production-index-frozen.md)
- A fourth finding from the same inventory pass — an access-control issue in the
  legacy D7 Solr routing — is being tracked outside this repo pending review.
  Ask Yuji before working on production Solr endpoints.
