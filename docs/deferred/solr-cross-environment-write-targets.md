# D7 staging writes to the production Solr master, and production Visuals writes to staging

**Area:** solr / D7 legacy / environment isolation / production risk
**Raised during:** Session 2026-08-13 (Solr index inventory across dev / staging / production)
**Jira:** (add when available)
**Priority:** **Medium–High — a staging site holds live write credentials/routes into a
production index.** Low probability of harm today (the sites are quiet), high
consequence if anyone runs a reindex on staging.

## What was found

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
2. **Disable the two staging → production write servers** (`mandala-sources-staging`
   server `solr`, `mandala-av-staging` env `mandala_library_rw`) or repoint them at the
   staging master. Disabling is safer and matches the fact that these sites are clones,
   not a real staging tier anyone tests search on.
3. **Decide what production Visuals should do.** Given
   [`searchstax-defunct-external-solr-config.md`](searchstax-defunct-external-solr-config.md)
   shows its other backend is dead too, Visuals search on D7 is probably already
   non-functional; the honest fix may be to turn it off rather than repoint it.
4. **Note it as a cutover consideration:** whatever D11 does for Sources and AV search
   must not inherit these targets.

## Related

- [`searchstax-defunct-external-solr-config.md`](searchstax-defunct-external-solr-config.md)
- [`kmassets-production-index-frozen.md`](kmassets-production-index-frozen.md)
- A fourth finding from the same inventory pass — an access-control issue in the
  legacy D7 Solr routing — is being tracked outside this repo pending review.
  Ask Yuji before working on production Solr endpoints.
