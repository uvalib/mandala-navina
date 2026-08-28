# `kmassets:audit` validates the write master, not the Solr host search actually reads from — a real 70-doc gap slipped through clean

**Area:** solr / kmassets / mandala_kmassets_sync / search_api
**Raised during:** Session 2026-08-28, running the diacritic-fidelity Solr-side spot check after the dev-0 reindex (Sprint 1 close-out follow-up)
**Jira:** (add when available)
**Priority:** **High — for Yuji.** The tool the team is relying on to certify "Solr retrievability" for Sprint 1 does not check retrievability; it checks write-success against the wrong host.

## What was found

Two different Solr hosts are in play on dev-0 for the `kmassets` core, and they disagree:

| Role | Host | Source |
|---|---|---|
| **Write master** (what `kmassets:index-all`, `kmassets:audit`, and the direct-sink node-save hooks all read/write) | `mandala-solr-master-staging-private.internal.lib.virginia.edu:8080/solr/kmassets` | `mandala_kmassets_sync.settings:solr_master_url` |
| **Search reader** (what `search_api.server.kmassets`, and therefore the live app / any real query, actually reads) | `mandala-index-dev.internal.lib.virginia.edu` (HTTPS, port 443) | `search_api.server.kmassets:backend_config.connector_config` |

After the 2026-08-27 from-scratch reindex (`kmassets:index-all shanti_image` — 111,339 indexed, 0 errors) and a clean `kmassets:audit --check-stale` run (**"Index is in sync — no discrepancies found"**), a diacritic-fidelity spot check against 2,044 sampled titles found 4 published nodes with pure-Tibetan-script titles (nids 95394–95397, title `ཀམ་པ་ལ་མོ།`) returning **zero results** from the search reader, `mandala-index-dev`, when queried directly. The exact same uids resolve fine against the write master.

Widening the check to a bare count confirmed this isn't a sampling fluke:

```
Reader  (mandala-index-dev,          uid:images-11-*): numFound = 111,269
Master  (mandala-solr-master-staging, uid:images-11-*): numFound = 111,339
```

**A 70-document gap.** `kmassets:audit`'s own report (`checked_nodes: 111339, checked_docs: 111339, missing: 0, orphaned: 0`) is entirely consistent with itself and entirely blind to this, because both `KmassetAuditor`'s Pass A (missing/stale) and Pass B (orphan cursor) go through `KmassetDirectSink::select()`, which is hard-wired to `solr_master_url` — the same host the writes went to. The audit is, structurally, checking "did my write succeed" against itself. It cannot detect a master/reader divergence by construction, no matter how thorough its own bookkeeping is.

## Why this matters now

1. **Sprint 1's "Solr retrievability" acceptance criterion is written against what a real user's search would return** — i.e. the reader, `mandala-index-dev`. A clean `kmassets:audit` was being treated as evidence for that criterion in the 2026-08-27 close-out session; it is not sufficient evidence. The honest state is: write path proven, read path has a known 70-doc hole, exact overlap with the diacritic/Tibetan-title AC unconfirmed beyond the 4 samples above.
2. **Not a new-and-isolated failure of the rebuild** — this shape of problem (a staging install with Solr routes that silently point somewhere other than where you'd assume) is already a documented pattern on this project; see [`solr-cross-environment-write-targets.md`](solr-cross-environment-write-targets.md), which found three separate D7 cross-environment Solr write paths from an unaudited staging clone. This may be the same root cause (an unaudited/never-repointed config) or a distinct issue (replication lag between two intentionally-separate cores) — undetermined without infrastructure-side visibility this session didn't have.
3. **Whatever it is, it is silent** — no error, no failed job, no audit failure. A doc simply isn't there for a live query. Same failure shape flagged before in [`kmassets-index-has-no-d11-uids.md`](kmassets-index-has-no-d11-uids.md): "no error, no 500, correct-looking results — just … quietly missing."

## What's not yet known

- Whether `mandala-index-dev` is a genuinely separate Solr core from the master (in which case something needs to replicate/sync it, and currently doesn't for all docs), or the same core reached through a different path with a caching/replica lag, or a leftover config pointing at a stale/frozen index.
- Whether the 70 missing docs share a real pattern (e.g., all recently reindexed, all containing non-Latin scripts) or are incidental — only 4 were hand-verified, all Tibetan-script titles, but the sample size is far too small to conclude script is the cause.
- Whether this also affects the diacritic-fidelity Sprint 1 AC's Solr leg beyond the 4 confirmed cases (2,040 of 2,044 sampled diacritic-bearing titles matched byte-exact against the reader; the check cannot even run against the 4 that are missing).

## Suggested actions

1. **Yuji: please confirm what `mandala-index-dev` actually is** — a real independent Solr instance/core, a replica of the master, or a caching layer — and what (if anything) is supposed to keep it in sync with `mandala-solr-master-staging`.
2. If it's meant to replicate: check for a stalled or broken replication/sync process; 70 docs is a small, plausibly-explicable gap (partial batch, timing, etc.) rather than a wholesale failure.
3. Once root-caused, **either point `kmassets:audit` at the reader too (or add a second check), or repoint the app's `search_api.server.kmassets` connector at the master** — whichever is the intended architecture. Right now the audit gives false confidence about the thing Sprint 1 actually needs proven.
4. Re-run the diacritic Solr-leg check and the retrievability AC against whichever host is confirmed as canonical, once this is resolved.

## Related

- [`solr-cross-environment-write-targets.md`](solr-cross-environment-write-targets.md) — same shape of problem (staging config pointing somewhere unaudited), different specific hosts
- [`kmassets-index-has-no-d11-uids.md`](kmassets-index-has-no-d11-uids.md) — the same "silent, correct-looking-but-wrong" failure mode, previously root-caused and fixed
- [`kmassets-audit-hardening.md`](kmassets-audit-hardening.md) — prior audit-tool follow-ups; this finding should be folded into that hardening backlog
- `drupal/web/modules/custom/mandala_kmassets_sync/src/KmassetAuditor.php`, `KmassetDirectSink.php`
