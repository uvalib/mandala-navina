# Production kmassets has taken no writes in ~12 months

**Area:** solr / kmassets / production / ingest pipeline
**Raised during:** Session 2026-08-13 (Solr index inventory across dev / staging / production)
**Jira:** (add when available)
**Priority:** **Medium–High — needs confirmation, then a decision.** This is an
observation with two corroborating measurements, **not a diagnosis**. If it is what it
looks like, live Mandala search has been serving a frozen index for a year and nobody
raised it — which is itself information worth having before cutover.

## Measured (2026-08-13)

**Core-level.** `lastModified` on the production kmassets core, from
`admin/cores?action=STATUS` on both the production master and replica (they agree,
generation in sync):

| Cluster | kmassets `lastModified` | kmterms `lastModified` |
|---|---|---|
| production | **2025-08-11T00:31:04Z** | 2026-08-11T15:27:15Z |
| staging | 2026-07-07T14:21:48Z | 2026-06-24T12:03:43Z |

**Document-level.** Newest document per `asset_type`, sorted by the `timestamp` field:

| `asset_type` | production newest | staging newest |
|---|---|---|
| images | 2025-05-22 | 2025-04-10 |
| audio-video | 2025-08-04 | 2025-04-10 |
| sources | 2025-07-21 | 2025-04-10 |
| texts | **2025-08-11** | 2025-04-15 |
| terms | **2024-05-24** | 2024-05-16 |
| subjects | **2024-05-20** | 2024-01-10 |
| places | **2024-05-20** | 2024-01-10 |

Two independent signals agree: the core's last commit and the newest document both land
on 2025-08-11. Nothing has been written since.

The KMaps shadow types are older still — **terms / subjects / places have not been
touched since May 2024**, i.e. ~15 months. That is the `reindeer_x` kmterms→kmassets
shadow population described in [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md),
and it has been stale for over a year.

Note the contrast: **kmterms is alive** (production, written 2026-08-11 — two days before
this inventory). That index is owned by the Rails KMaps applications, and they are still
writing to it normally. The problem is specific to kmassets.

## `reindeer_x` is running and idle

The `reindeer_x` container on `mandala-drupal-0` is up (5 days) and healthy, but has
processed nothing at all:

```
JobCreator HEALTH: {"waiting":0,"active":0,"succeeded":0,"failed":0,"delayed":0,"newestJob":0}
```

`newestJob: 0` — not a backlog, not failures: no jobs have ever been created in this
process's lifetime. It is polling and finding nothing to do.

## No D7 site has a kmassets write endpoint configured

On **all six** production D7 sites (and the `dev-1` staging clones),
`shanti_kmaps_admin_server_solr_write` and `shanti_kmaps_admin_server_solr_writedir` are
both **empty strings**. So the Drupal side has no configured path to write kmassets
documents at all. Whatever populated kmassets historically, it was not these sites
writing directly at the time of this inventory.

## What is NOT established

- **The cause.** kmassets was fed by an ingest path outside Drupal (S3 → ECS, per the
  original architecture; `mandala_s3_synch` / the legacy `mandala-ingest-production-deploy`
  pipeline). Whether that pipeline was switched off, broke silently, or was deliberately
  quiesced has not been checked. Do that before concluding anything.
- **Whether it matters operationally.** Production kmassets still answers queries against
  557,483 documents. Search works; it just has not ingested anything new. If the D7 sites
  themselves stopped receiving new content around the same time, the practical impact may
  be nil. The Images site's `watchdog` table stopping dead at 2025-05-12 — within days of
  the newest Images kmasset (2025-05-22) — is a hint in that direction, but it is a hint,
  not evidence.
- **Whether anyone noticed.** No ticket, note, or session log in this repo mentions it.

## Why it matters for the rebuild

1. **It changes what "parity with D7" means.** Sprint 1 acceptance and the eventual
   cutover implicitly assume production search is a working baseline to match. If the
   baseline has been frozen for a year, D11 populating a *current* index is an
   improvement, not a regression — and some comparisons between D7 and D11 search results
   will differ for that reason alone. Do not debug those differences as D11 bugs.
2. **It bears on the `reindeer_x` "do we need an always-on rdx" question**
   ([`reindeer-x-has-no-ecr-repo-or-pipeline.md`](reindeer-x-has-no-ecr-repo-or-pipeline.md),
   still open and under review). Evidence that the production shadow sync has been idle
   for 15 months with no apparent consequence is directly relevant to that decision.
3. **It affects cutover planning.** If the ingest path is genuinely dead, there is no
   running production writer to coordinate with, decommission, or race against when D11
   starts writing.

## Suggested next steps

1. Check the state of the legacy ingest pipeline (ECS tasks / `mandala-ingest-production-deploy`)
   — running, stopped, or failing?
2. Check whether the D7 sites have actually received new content since mid-2025. If not,
   this is a symptom of the platform winding down, not a broken pipeline.
3. Fold the answer into the open `reindeer_x` review rather than treating it separately.

## Related

- [ADR 006](../adr/006-kmterms-in-kmassets-shadow-pattern.md) — the kmterms shadow pattern
  that has been idle since 2024-05.
- [`reindeer-x-has-no-ecr-repo-or-pipeline.md`](reindeer-x-has-no-ecr-repo-or-pipeline.md)
- [`rdx-alb-target-unhealthy-in-production.md`](rdx-alb-target-unhealthy-in-production.md)
- [`searchstax-defunct-external-solr-config.md`](searchstax-defunct-external-solr-config.md),
  [`solr-cross-environment-write-targets.md`](solr-cross-environment-write-targets.md) —
  same inventory pass.
- A fourth finding from the same inventory pass — an access-control issue in the
  legacy D7 Solr routing — is being tracked outside this repo pending review.
  Ask Yuji before working on production Solr endpoints.
