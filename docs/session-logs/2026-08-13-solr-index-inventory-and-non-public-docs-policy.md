# Session Log: Solr index inventory across environments; non-public documentation policy

**Date:** 2026-08-13
**Participants:** Yuji Shinozaki (driving), Claude Code
**Outcome:** A full inventory of which Solr indexes are actually in use across dev, staging
and production; three findings documented; the D11 kmassets connector repointed and the
Spike 2 demo module disabled; and a project-wide policy — with two private repos — for
documentation that cannot live in this public repo.

**⚠ This log is hand-written and deliberately abridged.** `scripts/save-session-log.py` was
**not** used. Part of this session concerns a live, unfixed access-control issue in the
legacy D7 Solr routing, and the raw transcript would publish it in full. That work is
tracked privately — **ask Yuji**. Everything else is recorded below in the normal way.

PRs merged: [#108](https://github.com/uvalib/mandala-navina/pull/108),
[#109](https://github.com/uvalib/mandala-navina/pull/109).

---

## What prompted it

A sanity check: *which Solr indexes are actually in use across dev, staging and
production?* Answered by logging into all three hosts and both Solr clusters rather than
reading configuration.

## The inventory

Two clusters (staging and production), each master + replica, **seven cores apiece**:
`kmassets`, `kmterms`, `mandala-av`, `mandala-images`, `mandala-sources`, `mandala-texts`,
`mandala-visuals`. On production, three of those hold **zero documents**.

Per-site D7 search turned out to be a patchwork rather than one system — a mix of
`search_api`, `apachesolr`, an external service, and direct writes to Solr masters, differing
site by site.

A small correction to a long-standing assumption: the Solr **master is readable** over HTTP.
Our notes had it as write-only.

## Three findings, all now in `docs/deferred/`

**[SearchStax external Solr is defunct but still configured](../deferred/searchstax-defunct-external-solr-config.md)** —
a third-party hosted Solr is still an *enabled* backend on four of six production D7 sites
and their staging clones. Yuji confirmed the service is long gone, so it is all vestigial.
Not inert, though: Texts logs `SearchApiException` on every cron run — 4,633 watchdog rows
in a single three-hour window — against a host that no longer exists. A shared credential
sits in cleartext in three places per site and should be treated as burned.

**[Production kmassets is frozen](../deferred/kmassets-production-index-frozen.md)** — no
writes since 2025-08-11 (~12 months); the KMaps shadow types have not moved since 2024-05.
`reindeer_x` is running but has created zero jobs, and no D7 site has a kmassets write
endpoint configured. Recorded as an observation, **not** a diagnosis — the state of the
legacy ingest path was not investigated. It matters mainly because it changes what "parity
with D7" means at cutover: some D11-vs-D7 differences will be explained by this, not by D11
bugs.

**[Cross-environment Solr write targets](../deferred/solr-cross-environment-write-targets.md)** —
the D7 staging clone was never repointed after cloning, so two staging sites target the
**production** Solr **master**, the write endpoint. Inversely, production Visuals indexes
into the **staging** master. Three crossings in six sites suggests the clone was never
audited; assume more un-audited production references exist on `dev-1` beyond Solr.

## D11 change: the kmassets connector, and Spike 2's demo

Asking *what actually consumes the D11 `search_api.index.kmassets`?* produced exactly one
answer: `spike_solr_demo`, whose own `info.yml` says **"Not for production use."** It owns
both `search_api` config objects, so the index existed because the spike existed. Verified
live on dev-0, not just in `config/sync`: no Views on any `search_api` base table, no other
custom module or theme references it, no facets, no search pages.

Its route was gated only by `access content` — anonymous — and returned HTTP 200 with a
free-text query box.

Actions ([#108](https://github.com/uvalib/mandala-navina/pull/108)): connector repointed to
the dev proxy in `config/sync`, in the module's `config/optional`, and directly on dev-0;
`spike_solr_demo` disabled, code retained (`drush en` restores it); server and index config
kept, since neither depends on the module.

**Spike 2's write-up was corrected.** Its finding 4 attributed the visibility filter to the
wrong endpoint. The filter *semantics* it recorded are right — 549,312 documents for
`visibility_i:1 OR asset_type:(places subjects terms)`, exactly what the filtered proxy
returns anonymously — but the endpoint was not. Findings 2, 3, 5–9 stand; **Spike 2 remains
Proven**.

Two things worth carrying forward. Applying the change needed a direct `drush cset` as well
as a commit, because
[the deploy never imports `config/sync`](../deferred/deploy-never-imports-config-sync.md).
And the Search API admin UI now reports the server as *unavailable* — the hardened proxy
404s `/admin/system` while allowing `/admin/ping` and `/select`, so `isAvailable()` fails
while queries work (verified end to end, `numFound = 562,952`). Documented in the spike so
nobody "fixes" it by repointing back.

## Non-public documentation — a project-wide policy

The session produced material that could not go in this public repo, and there was nowhere
to put it. That forced a decision Spike 9 had designed but never scheduled.

**Yuji's framing was the useful part:** the legacy/rebuild separation is load-bearing, but
**sensitivity cuts across it** — a finding can be *about* the legacy stack, *found during*
rebuild work, and *affect* both. So the split is mirrored rather than resolved:

| Repo | Holds |
|---|---|
| `uvalib/mandala-legacy-docs` (private) | material whose fix serves the **legacy D7 stack** |
| `uvalib/mandala-navina-docs` (private) | material whose fix serves the **D11 rebuild** |

Both carry an identical `CONVENTION.md`. The tie-breaker is **file by where the fix lands,
not where the problem was found**. Both are in `uvalib` although the legacy code is in
`shanti-uva` — documentation ownership follows the Library, and the code stays put only
because legacy is still in production; it migrates after cutover, when moving it no longer
churns live infrastructure.

Scope was kept deliberately narrow. **Almost everything stays public** — ADRs, spikes,
deferred notes and session logs — because over-classifying hides work from the team.

Public-facing outputs ([#109](https://github.com/uvalib/mandala-navina/pull/109)):
[docs/non-public-documentation.md](../non-public-documentation.md), an entry in `CLAUDE.md`
so every session picks the rule up at startup, and an amendment to
[Spike 9](../spikes/spike-09-docs-hosting-confluence.md) — which had proposed *one* repo
holding the whole internal corpus as a submodule with Confluence sync. **Spike 9 stays
Pending**; the Confluence half is untouched and is still the only way to reach PM,
directors and external partners who are not GitHub collaborators.

A rehomed orphan: an August 2026 production incident postmortem (566 lines, three outages,
eight releases) had been sitting untracked in a local working directory on one laptop, with
no backup. It is now in the private legacy repo.

## Lessons

- **Verify live before trusting a note** — recurring. Spike 2's endpoint attribution had
  been wrong since June and was only caught by re-measuring.
- **Holding a finding back means policing everything that *references* it**, not just the
  note itself. A correction table drafted for Spike 2 would have disclosed the private
  finding as effectively as publishing it; it was caught by a pre-commit scan. The same
  hazard is why this log is hand-written.
- **"Is it ready?" is worth asking twice.** Two rounds of readiness checks on a handoff
  document each found a real defect — both in the parts telling someone what to *do*, not
  in the analysis. Instructions drift when the plan changes underneath them.

## Addendum — written after the log was first merged

Two further pieces landed after this log was written, both continuations of the same work.

**[The Solr inventory as a reference document](../planning/solr-index-inventory.md)**
([#111](https://github.com/uvalib/mandala-navina/pull/111)). The inventory had produced three
deferred notes and this log, but **no single document that was the inventory** — topology,
cores, counts, freshness, read and write paths, the D7 backend patchwork. That document now
exists in `docs/planning/`, alongside the KMasset doc contract: that one is the document
*format*, this one is the deployed *reality*. It cross-references the deferred notes rather
than duplicating them, and it ships the re-measurement commands, since every figure was
measured rather than read from configuration — and one core is about to change when the D11
writer runs.

Its §7 states plainly that one aspect of the legacy D7 Solr routing is omitted and who to
ask. That split is drawn on **inference, not just content**: the public document has to say
which endpoint is the filtered proxy in order to describe the read path at all, so
publishing the per-site endpoint mapping beside it would give the withheld finding away to
anyone reading both. The mapping is therefore withheld whole rather than partially.

**A backup of the `mandala-legacy/` umbrella-directory files.** The read-first banners added
to that directory's `CLAUDE.md` and `AGENTS.md` were tracked by *nothing* — the umbrella
root is a folder of clones, not a repository — so they existed on one laptop only. That is
the same failure mode as the orphaned postmortem rehomed earlier the same day, recreated
while fixing the original. They are now mirrored into the private legacy repo, with restore
commands and an explicit warning that the mirror is manual.

**A fourth lesson, from that:** *"is it closed out?" deserves the same scepticism as "is it
ready?"* Asking it twice found stale close-out artifacts both times — a merged session log
that under-reported, and a memory snapshot naming a superseded commit. Work done after a
close-out does not announce itself.

## Next

Unchanged and still the next action: set `mandala_kmassets_sync.settings.solr_master_url`
on dev-0 and run `drush kmassets:index-all`. Nothing found today blocks it. Two operational
cautions: the deploy will not carry `config/sync` to dev-0, and the nightly 23:00–06:00
instance stop will kill a long-running job.
