# Session Log: AV8 (kmassets/Solr sync) wired, run live on dev-0, two pre-existing bugs found and fixed along the way

**Date:** 2026-09-14
**Driver:** Yuji Shinozaki (with Claude Code)
**Outcome:** AV8 is done. `audio`/`video` are wired into `mandala_kmassets_sync`,
indexed live on dev-0, and a full audit confirms the index is in sync — 0
missing, 0 orphaned, 0 stale across all 122,921 published nodes. Two
pre-existing bugs surfaced by AV's non-English, shared-service data (not
AV-specific, just never exercised before) were found, root-caused precisely,
fixed, and verified. PRs [#198](https://github.com/uvalib/mandala-navina/pull/198)
and [#199](https://github.com/uvalib/mandala-navina/pull/199) merged.

---

## 1. Wiring `audio`/`video` into kmassets_sync (AV8, PR #198)

Added `audio` and `video` bundle entries to
`mandala_kmassets_sync.settings.yml`. Both map to `service`/`asset_type:
audio-video` — not invented, matched live against the ~8,579 real production
kmassets docs the legacy mediabase pipeline already indexes for AV, confirming
ADR 016 decision 3 ("the Solr `asset_type` facet is a single `audio-video`
value... do not align" it with the two-bundle URL grammar). `url_html`/
`url_ajax`/`url_json` are placeholders in exactly the sense `shanti_image`'s
already are (ADR 016 found those "known wrong" and accepted them rather than
block indexing) — same treatment here, not a new decision.

While wiring this, found `CollectionFieldContributor::groupKmassetUid()`
hardcoded `'images'` for every collection/subcollection group regardless of
actual site — a real bug, not cosmetic, since `collection_uid_s` feeds ADR
014's proxy access-control `fq`. Fixed to read `field_legacy_site`, which
already carries the right per-site token. Verified live: `collection_uid_s`
for a real AV video node changed from `images-11-197` to `audio-video-11-197`.

## 2. Tracing the real read/write/replication/visibility topology

Before running anything at scale, traced the actual infrastructure with
evidence rather than assumption, per the standing principle: *precise
understanding of what writes where and what should appear where, before
assessing whether a write is correct or timely.*

- **Public read path** (what `mandala-index-dev` serves): AWS ALB
  `uva-alb-uvaonly-staging` (IP-gated to UVA egress) → target group
  `alb-mandala-drupal-staging-idx-0` port 8765 → dev-0's `mandala-solr-proxy-0`
  container → internal-only `mandala-solr-replica-staging.private.staging:8080`.
- **Write path**: `KmassetDirectSink` posts straight to the Solr **master**
  (`mandala-solr-master-staging-private:8080`), synchronous, one document per
  HTTP `POST /update?commit=true`.
- **A documented exception**: `KmassetAuditor`/`KmassetDirectSink::select()`
  read the master directly, not the proxy — deliberate, now explicit in
  [`docs/planning/kmasset-solr-doc-contract.md`](../planning/kmasset-solr-doc-contract.md) §2.2.

Two things I got wrong initially and corrected in place rather than letting
stand:

- **Misdiagnosed a "replication lag."** A private test doc never appeared via
  the public endpoint. First read: master→replica lag. Real cause: ADR 014's
  visibility proxy correctly filtering all private content from anonymous
  queries — verified 0 of 8,580 audio-video docs with `visibility_i:2` are
  ever visible via that endpoint. An entirely separate mechanism from
  replication.
- **Overstated master read-freshness.** Wrote that reading the master gives
  "immediate consistency, no replication lag" as a general property. Corrected
  by Yuji: the master is write-optimized and doesn't necessarily keep its own
  read-indexes current either. Verified against the actual `solrconfig.xml`:
  `autoCommit` has `openSearcher=false` (60s interval), `autoSoftCommit` is
  disabled entirely. So master read-immediacy is specific to
  `KmassetDirectSink`'s explicit per-document `?commit=true` (which triggers
  Solr's default `openSearcher=true` for *explicit* commits) — not a general
  property of reading the master. Documented as an explicit correction, credited,
  with the config evidence.

## 3. The live run — real numbers

Node counts, queried directly (not estimated): **4,187 published `audio`,
7,395 published `video`** — chosen to run `kmassets:index-all` scoped
per-bundle rather than bare, which would have needlessly re-indexed all
111,339 already-synced `shanti_image` docs (~10x more work for zero benefit).

Launched detached (`setsid nohup`, survives SSH/session disconnect), with the
CLI `memory_limit` override applied *proactively* — dev-0's default is still
128M (checked directly: `ini_get('memory_limit')` → `128M`), three weeks after
[the fix was scheduled](../deferred/migrate-large-migration-oom-and-resume-behavior.md)
2026-08-27. Used `vendor/bin/drush.php` (the real PHP entrypoint), not
`vendor/bin/drush` (a bash wrapper that silently no-ops under `php -d`) — a
documented trap from the same deferred note.

**Result:** `audio` 4,187/4,187 (0 errors), `video` 7,391/7,395 (4 errors) — 11,582
nodes in ~13–14 minutes, ~830–890/min combined. Much faster than a rough
2-sample extrapolation mid-run suggested; the noisy early estimate was
explicitly flagged as low-confidence at the time and shouldn't be reused for
future planning — the ~830–890/min figure is the real one.

## 4. Bug #1 — `title_sort_s` UTF-8 corruption (`KmassetDocBuilder`)

All 4 `video` errors were `json_encode error: Malformed UTF-8 characters`.
Traced precisely by building the actual doc for each failing node and
checking every field for invalid UTF-8 — landed on `title_sort_s`, computed by:

```php
$doc['title_sort_s'] = trim(strip_tags((string) $title), "'\"“”‘’()-: []");
```

PHP's `trim()` charlist is byte-oriented. The curly quotes in it are
multi-byte UTF-8 (`“` = `E2 80 9C`), so passing them to `trim()` silently adds
the raw bytes `0x80`/`0x98`/`0x99`/`0x9C`/`0x9D` to the strip set — and
`0x80`–`0xBF` is the entire UTF-8 continuation-byte range. Any title ending in
a multi-byte character whose last byte collides gets that byte silently
stripped: Cyrillic "р" (`D1 80`), CJK "局" (`E5 B1 80`) — all 4 failing titles
were Cyrillic or Chinese. This never surfaced on `shanti_image` because
English titles essentially never end in a colliding byte — it's a **latent
bug that predates AV8**, not something AV introduced.

Worse than the 4 crashes: for titles where the corruption doesn't happen to
produce a fully invalid trailing sequence, this would silently **truncate**
`title_sort_s` rather than crash — meaning some already-indexed `shanti_image`
docs may have a quietly wrong sort key. Not re-scanned this session (scope
call, not a finding) — worth a follow-up if `title_sort_s` browsing behavior
is ever suspect for Images.

**Fix:** replaced with a Unicode-aware `preg_replace(..., '/u')`, which
matches whole characters, not bytes, so it can't split a multi-byte sequence.
Verified locally against all 4 real failing titles before shipping — all
produce valid, correctly-preserved UTF-8 and encode cleanly.

## 5. Bug #2 — `KmassetAuditor`'s orphan pass is unsafe across shared-service bundles

Ran `kmassets:audit audio` and `kmassets:audit video` (deliberately without
`--fix`) to get the missing/orphaned picture before fixing bug #1. Results
were absurd: auditing `audio` reported **7,391 orphaned** docs (exactly
`video`'s indexed count); auditing `video` reported **4,187 orphaned**
(exactly `audio`'s). Root cause, read directly from `KmassetAuditor::audit()`:
the "expected" published-node set is scoped to the requested **bundle**, but
the orphan pass's Solr cursor (`solrUidCursor()`) queries by **service** —
and `audio`/`video` share one service, `audio-video` (ADR 016 decision 3).
Every video doc's uid isn't in an audio-only expected set, so it looks
orphaned, and vice versa.

**This is not just a false report.** With `--fix`, `repair()` executes a real
`deleteByQuery` against every "orphaned" uid — `kmassets:audit audio --fix`
would have deleted all of `video`'s freshly-indexed docs. No damage occurred;
`--fix` was deliberately never passed with a bundle filter, precisely because
the two scoped audits' numbers didn't add up and warranted checking before
trusting them. A full **unscoped** audit (which builds its expected set from
every bundle at once) confirmed the true state was clean except for the 4
known `title_sort_s` failures — proving the scoped runs were pure false
positives, not real drift.

**Fix:** widened the orphan pass's published-uid set to cover every bundle
sharing an in-scope service, even when the caller only asked to audit one of
them — while keeping `missing`/`stale` correctly scoped to what was actually
requested. **Operational note for future ops:** don't trust a
bundle-scoped `kmassets:audit --fix` for `audio` or `video` individually
against pre-fix code; post-fix (`main` as of PR #199) it's safe.

## 6. Fix, verify, close out

Both fixes shipped together in PR #199, merged, deployed. On dev-0 post-deploy:

- Re-indexed the 4 previously-failed video nodes individually
  (`kmassets:index <nid>`) — all 4 succeeded.
- Re-ran the exact previously-unsafe scoped audit, `kmassets:audit audio` —
  now reports **"Index is in sync — no discrepancies found"**, proving the
  sibling-bundle fix works (it no longer sees `video`'s docs at all, let alone
  flags them).
- Ran the full unscoped `kmassets:audit --check-stale` across all 122,921
  published nodes — **0 missing, 0 orphaned, 0 stale.**

AV8's acceptance criterion ("KMaps tagging fields are wired and indexed in
kmassets/Solr for AV content" — the indexing half; KMaps field wiring itself
is AV6) is met.

## What's left

- **AV6** (KMaps field wiring) and **AV7** (Group access mapping) — both
  unblocked, next up per the sprint doc.
- **The CLI `memory_limit=128M` landmine is still unfixed at the image level**
  — now confirmed on 2026-09-14, three weeks past its 2026-08-27 scheduling.
  See [the deferred note](../deferred/migrate-large-migration-oom-and-resume-behavior.md).
  Kept as SCHEDULED, not reopened — this is the same known issue recurring, not new information.
- **Possible silent `title_sort_s` truncation on already-indexed `shanti_image`
  docs**, from the same bug fixed in §4 — not scoped or re-audited this
  session; worth a follow-up if Images' sort-by-title behavior is ever
  reported wrong.
- **Two pending memory refreshes** (this driver's, per the session-end
  ritual): AV8 completion + both bug findings, and the corrected
  master-read-freshness / visibility-vs-replication topology understanding.
