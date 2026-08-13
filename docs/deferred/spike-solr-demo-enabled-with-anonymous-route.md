# Spike 2's demo module was enabled, anonymous, and pointed at production Solr

**Area:** search_api / spike hygiene / dev environment / configuration
**Raised during:** Session 2026-08-13 (checking what consumes the D11 kmassets Search API index)
**Jira:** (add when available)
**Priority:** **Low — mostly addressed in the same session (see Resolution).** One
residual item is genuinely open: the connector host is environment-specific config
sitting in a shared `config/sync`.

## How this surfaced

While answering "does anything actually consume the D11 `search_api.index.kmassets`?"
the answer turned out to be: **exactly one thing, and it was a proof-of-concept.**

`spike_solr_demo` — whose own `.info.yml` reads *"Proof-of-concept config for Spike 2 —
search_api_solr read-only connection to kmassets. **Not for production use.**"* — was
enabled in `config/sync` and live on dev-0. It owns both `search_api.server.kmassets` and
`search_api.index.kmassets` (they ship in its `config/optional/`), so the D11 Search API
index existed *because* the spike module existed.

Everything else came back negative, verified live on dev-0 rather than only in
`config/sync`:

- no Views on any `search_api` base table (all 17 views are core defaults)
- no other custom module references `search_api`
- no custom theme references it
- no facets, no search pages

## Why it mattered

The module exposes a route with no meaningful access gate:

```yaml
spike_solr_demo.comparison:
  path: '/spike/solr-comparison'
  requirements:
    _permission: 'access content'
```

`access content` includes anonymous. Confirmed by an unauthenticated request to
`https://mandala-dev.internal.lib.virginia.edu/spike/solr-comparison` on 2026-08-13 —
**HTTP 200, 46 KB**, rendering live results with a free-text box accepting arbitrary
`?q=` and `?rows=` input.

And the server it queried was pointed at a production Solr route rather than the dev
proxy (the endpoint problem is tracked separately — ask Yuji). So a demo module marked
not-for-production was enabled, routed, anonymous, and serving arbitrary queries against
production data. Practical severity was limited — it returns 5–20 rows of
title/uid, and dev-0 sits behind the uvaonly ALB, not the public one — but each of those
properties was accidental rather than chosen.

## Resolution (2026-08-13, same session)

1. **Connector repointed** to `mandala-index-dev.internal.lib.virginia.edu` — the dev
   proxy, which reads the staging replica and applies the visibility filter — in both
   `drupal/config/sync/search_api.server.kmassets.yml` and the module's
   `config/optional/` copy, and applied directly on dev-0.
2. **`spike_solr_demo` disabled** (removed from `core.extension`, uninstalled on dev-0),
   which removes the anonymous route. The module *code* is deliberately retained: Spike 2
   is Proven and the comparison page is its demo artifact. Re-enable with
   `drush en spike_solr_demo` when it is wanted.
3. **Server and index config deliberately kept.** Neither declares a dependency on
   `spike_solr_demo` (only on `search_api` / `search_api_solr`), so uninstalling the
   module does not delete them. `search_api.server.kmassets` remains as the D11 read
   connection to kmassets, now correctly aimed at the dev proxy, awaiting a real consumer.
4. **Known cosmetic side-effect:** the Search API admin UI now reports the server as
   *unavailable*, because the hardened proxy 404s `/admin/system` (it allows
   `/admin/ping` and `/select`). Queries work — verified end to end on dev-0,
   `numFound = 562,952`, matching the dev proxy's anonymous count.
5. **Spike 2's write-up corrected** — see
   [`spike-02-solr-integration.md`](../spikes/spike-02-solr-integration.md).

## Residual — still open

**The connector host is environment-specific configuration in a shared `config/sync`.**
`mandala-index-dev` is correct for dev and wrong everywhere else. Today that is harmless
because dev is the only D11 environment, but staging and production will each need a
different value, and `config/sync` is a single tree imported everywhere.

The solr-proxy container already solves this for itself with layered env vars
(`container_0.env.{generated,managed,secret}`, per the drupal-netbadge shape). Search
API's Solarium connector does not read env vars natively, so this needs a deliberate
mechanism — most likely a `$config['search_api.server.kmassets']['backend_config']
['connector_config']['host']` override in the environment's `settings.php`, delivered by
Ansible the same way other per-environment values are.

**Decide this before a second D11 environment exists**, not after — otherwise the first
staging deploy silently points staging's Search API at dev's proxy.

## Related

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the proxy read path this now uses.
- [`deploy-never-imports-config-sync.md`](deploy-never-imports-config-sync.md) — why the
  repoint had to be applied to dev-0 by hand as well as committed.
- [Spike 2](../spikes/spike-02-solr-integration.md) — the spike whose demo this was.
