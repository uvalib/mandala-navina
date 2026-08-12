# mandala-wp-proxy's json_proxy route is an open proxy (SSRF)

**Area:** mandala-wp-proxy (external repo `shanti-uva/mandala-wp-proxy`) / security / Spike 6
**Raised during:** Spike 6 (2026-08-12) — URL-strategy decision (Option A)
**Jira:** (add when available)
**Priority:** High — blocks generalizing the proxy to all sites/apps

## What we found

`mandala-proxy.php`'s `json_proxy` route (registered as `/proxy/json`) is the mechanism behind
the Sources WAF-503 fix (2026-07-29) and is now the decided long-term URL strategy for the
whole React app (Spike 6, Option A — see
[spike-06-api-compatibility.md](../spikes/spike-06-api-compatibility.md)). Its handler:

```php
if (get_query_var('json_proxy')) {
    parse_str($_SERVER['QUERY_STRING'], $params);
    $base_url = $params['url'];
    $wf = $params['wf'] ?? false;

    $response = wp_remote_get($base_url, [
        'headers' => ['Accept' => 'application/json'],
        'sslverify' => false,
    ]);
    // ...
    header('Access-Control-Allow-Origin: *');
    echo $body;
    exit;
}
```

It takes the `url` query param **with no host restriction** and fetches it server-side via
`wp_remote_get()`, then returns the response with `Access-Control-Allow-Origin: *`. This is a
classic open-proxy / SSRF pattern: any caller can make thlib.org's server (or any other WordPress
install running this plugin) issue arbitrary outbound requests — internal-only hosts, other
services, or used as a fetch relay — and read the response back, with `sslverify` disabled on top.

## Why it's low-risk today but won't be tomorrow

Today the client only routes Sources queries through this proxy (a narrow, low-traffic stopgap).
Spike 6's decision generalizes it to **every asset type, every site, on any WordPress install
that embeds the app** — turning a quiet edge case into the primary, high-traffic request path for
the whole application. An open proxy at that scale is a materially different risk than the one
that's gone unnoticed so far.

## What needs to happen

Add a host allowlist to the `json_proxy` handler before client-side generalization ships —
restrict `$base_url` to expected hosts (the `*.mandala.library.virginia.edu` D7 subdomains today,
plus whatever D11 serves them from post-consolidation). Reject/ignore requests for any other host
rather than silently proxying them. Consider also scoping `Access-Control-Allow-Origin` rather
than leaving it wildcard, though that's secondary to the host allowlist.

This is a fix in the external `shanti-uva/mandala-wp-proxy` repo, not in this monorepo — it has
no PR mechanism established (single `main` branch, direct commits per the repo's history), so
apply and verify carefully before pushing.

## Cross-references

- [Spike 6](../spikes/spike-06-api-compatibility.md) — the URL-strategy decision this blocks
- [wp-kmaps-mandala-proxy-dependency.md](wp-kmaps-mandala-proxy-dependency.md) — the sibling
  packaging decision (keep as separate plugin, declare dependency)
