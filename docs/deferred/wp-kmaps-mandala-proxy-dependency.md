# wp-kmaps needs a declared dependency on mandala-wp-proxy

**Area:** wp-kmaps (external repo `shanti-uva/wp-kmaps`) / mandala-wp-proxy (external repo
`shanti-uva/mandala-wp-proxy`) / Spike 6
**Raised during:** Spike 6 (2026-08-12) — URL-strategy decision (Option A)
**Jira:** (add when available)
**Priority:** Medium — needed before Option A's generalized rollout, not before the SSRF fix

## What we found

Spike 6 decided the React app's API-reachability strategy (Option A: generalize the same-origin
`/proxy/json` proxy — see [spike-06-api-compatibility.md](../spikes/spike-06-api-compatibility.md)).
That proxy turned out to live in its own plugin, `shanti-uva/mandala-wp-proxy`, separate from
`wp-kmaps` (the plugin that embeds the React app itself). Finding it took three lookups across
`wp-kmaps`, `mandala-kadence`, and finally `mandala-wp-proxy` — it isn't referenced from either
of the other two repos or their READMEs.

Once Option A is generalized (every asset type routes through the proxy, not just Sources),
`wp-kmaps` **cannot function correctly without `mandala-wp-proxy` also being installed and
active** on the same WordPress site. Today that dependency is invisible: nothing prevents
activating `wp-kmaps` alone, and the failure mode (silently broken asset detail fetches) doesn't
announce its own cause.

## Decision (2026-08-12, Than): keep them as separate plugins, declare the dependency explicitly

Considered folding `mandala-wp-proxy` into `wp-kmaps` to fix the discoverability problem
directly. Rejected:

1. **`mandala-wp-proxy` isn't Mandala-specific.** It also proxies Geoserver/WFS
   (`places.kmaps.virginia.edu`) and a THDL Solr endpoint (`texts.thdl.org`) unrelated to KMaps.
   Merging would misscope a general-purpose CORS proxy into an app-embedding plugin.
2. **It's about to become security-sensitive** (see
   [mandala-wp-proxy-json-proxy-open-ssrf.md](mandala-wp-proxy-json-proxy-open-ssrf.md)) and
   benefits from its own release/review cycle, independent of `wp-kmaps`'s UI/display churn.

Instead: fix the actual problem (undiscoverable dependency), not the code location.

## What needs to happen

- Add a WordPress `Requires Plugins: mandala-proxy` header to `wp-kmaps/mandala.php` (WP 6.5+
  plugin-dependencies feature — WordPress refuses to activate a plugin whose declared
  dependencies aren't installed/active). The header value must match the *installed* plugin's
  folder slug — document that `mandala-wp-proxy` should be installed into a folder named
  `mandala-proxy` (matching its main file `mandala-proxy.php`) so the check resolves correctly;
  the GitHub repo name (`mandala-wp-proxy`) doesn't have to match the install folder name, but
  callers cloning it directly need to know to rename it, or a release/zip process needs to
  produce that folder name.
- Add a README section to `wp-kmaps` documenting the dependency explicitly (mirroring how it
  already documents `mandala-om` and `mandala-kadence` as sibling repos), so it's discoverable
  from prose even before anyone hits the WP dependency check.

## Cross-references

- [Spike 6](../spikes/spike-06-api-compatibility.md) — the URL-strategy decision this supports
- [mandala-wp-proxy-json-proxy-open-ssrf.md](mandala-wp-proxy-json-proxy-open-ssrf.md) — do this
  after the SSRF fix, not before (no point advertising a dependency on an unhardened open proxy)
