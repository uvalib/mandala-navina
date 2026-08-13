# SearchStax/Measured Search Solr is gone, but four D7 sites are still configured to use it

**Area:** solr / D7 legacy / dead external dependency / credentials / cleanup
**Raised during:** Session 2026-08-13 (Solr index inventory across dev / staging / production)
**Jira:** (add when available)
**Priority:** **Medium — vestigial config to remove, plus one credential to burn.**
Confirmed by Yuji 2026-08-13: **the SearchStax / Measured Search instances are long
gone.** Every configuration pointing at them is therefore defunct. Nothing to migrate —
this is cleanup, and one live symptom to stop.

## The configuration

A third-party hosted Solr — `ss395824-us-east-1-aws.measuredsearch.com` (SearchStax,
formerly Measured Search) — is still wired in as an *enabled* search backend on four of
the six production D7 sites, and identically on their `mandala-drupal-dev-1` staging
clones. It appears in no architecture doc, ADR, or migration plan in this repo.

| Site | Mechanism | Server / environment | Core | Enabled |
|---|---|---|---|---|
| Images | `search_api` | `ms_solr_server` | `/solr/images_solrapi` | ✅ |
| Texts | `search_api` | `solr_search` ("MS Solr Server") | `/solr/texts_prod` | ✅ |
| Visuals | `search_api` | `shiva_solr_server` ("Shiva Solr Server") | `/solr/visuals` | ✅ |
| AV | `apachesolr` | env `solr` ("mediabase") | `/solr/av` | ✅ |

Sources and Mandala Home have no SearchStax configuration.

## It is not inert — Texts is erroring against it continuously, today

Because the servers are enabled and the indexes are not read-only, D7 cron keeps trying
to reach a host that no longer exists. On **mandala-texts** (production), watchdog shows
this firing on every cron run:

```
SearchApiException on index "Full Text Local Solr":
  Could not index items since important pending server tasks could not be performed.
  search_api_index_specific_items() — search_api.module:1787   [severity 3 / error]
```

Volume, measured 2026-08-13: **4,633 watchdog rows in a single 3-hour window**
(11:51–14:51), of which 201 are `search_api` errors. This is ongoing log noise and
wasted cron work on a live production site, with a permanently undrainable queue behind
it (`full_text_local_2`: 7,633 tracked items, 2,170 pending, none moving since
2024-05-21).

**Images could not be assessed the same way** — that site's `watchdog` table stops dead
at **2025-05-12** (1,042 rows total, none newer), so dblog tells us nothing about its
current behaviour. Its index `images_solr_index` tracks 111,511 items with a **22,342
backlog**; given the backend is gone, that backlog cannot drain either. Note the site's
dblog going quiet (2025-05-12) is within days of the newest Images document in
production kmassets (2025-05-22) — the Images site appears to have stopped doing much of
anything around then. Worth a look, but see the separate note on the frozen kmassets
index before drawing conclusions.

**Visuals is genuinely inert** — the `shiva_solr_server` object is enabled but no index
is bound to it (`visuals_drupal_index` points at the staging Solr master instead; see
the cross-environment note).

## The credential must be treated as burned

A single account (`solrprod`) with one shared password is stored **in cleartext** across
at least three locations per site:

- `search_api_server.options` — serialized PHP, `http_user` / `http_pass`
- `apachesolr_environment.url` — embedded in the URL as `https://user:pass@host/…`
- the `shanti_kmaps_admin_solr_password` variable — set on **all six** production sites
  plus staging, *including the two sites with no SearchStax backend at all*

The service being decommissioned does not make this safe to leave: the same password may
have been reused elsewhere, and it sits in database rows readable by anything with site
DB access, in a system we are still operating. Treat it as compromised and confirm it is
not in use anywhere else.

## What to do

1. **Disable and delete** the four server/environment definitions and the indexes bound
   to them, on production **and** on `dev-1`. This stops the Texts cron error stream.
2. **Purge the credential** from all three storage locations on all six sites plus
   staging — including `shanti_kmaps_admin_solr_password` on the sites that never had a
   SearchStax backend.
3. **Confirm the `solrprod` password is not reused** on any live service; rotate wherever
   it is.
4. **Check what Images and Texts search actually do now** for end users. If a site's only
   search backend has been dead for a year, its search is either broken or silently
   falling back — either way that is a fact worth knowing before D11 cutover, and it may
   lower the bar for what D11 search has to match on day one.
5. **Confirm no billing continues** for the SearchStax subscription.

## Why it matters beyond tidiness

The D11 rebuild's search story (ADR 014, kmassets/kmterms behind the read-only proxy)
was planned without knowing this dependency existed. Now that it is known to be dead, the
useful consequence is the inverse of the original worry: there is no external Solr to
cut over *from* for Images, Texts, Visuals or AV. Whatever those sites' search does
today, it is not being served by SearchStax.

## Related

- [`kmassets-production-index-frozen.md`](kmassets-production-index-frozen.md) — the
  UVA-hosted production kmassets index has taken no writes since 2025-08-11.
- [`solr-cross-environment-write-targets.md`](solr-cross-environment-write-targets.md) —
  production Visuals writes to the staging master; D7 staging writes to the production
  master.
- A fourth finding from the same inventory pass — an access-control issue in the
  legacy D7 Solr routing — is being tracked outside this repo pending review.
  Ask Yuji before working on production Solr endpoints.
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the D11 replacement read path.
