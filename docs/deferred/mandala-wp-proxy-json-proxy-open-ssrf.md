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

**Done (2026-08-12):** host allowlist added (`av`/`images`/`sources`/`texts`/`visuals`
`.mandala.library.virginia.edu` + bare `mandala.library.virginia.edu`, matched via
`parse_url($base_url, PHP_URL_HOST)`, exact string match — not a suffix match, so
`mandala.library.virginia.edu.evil.com`-style spoofing is rejected). Verified `php -l` clean and
unit-tested the allowlist logic standalone against 8 cases (legit hosts, spoofed subdomain
suffix, cloud-metadata SSRF target `169.254.169.254`, `file://`, empty/malformed input) — all
correct. Also added `X-Content-Type-Options: nosniff` to the response (the endpoint is not
exploitable via XSS today — `Content-Type` is hardcoded to `application/json` regardless of
upstream response, and the current client consumes it via `axios.get` + JSON-parse, never as
executed script — but `nosniff` closes the legacy MIME-sniffing gap for non-compliant browsers).
Not yet committed/pushed — sitting as a diff in a scratch clone pending final review.

**⚠️ Landmine noticed while fixing this, not yet acted on:** `$wf = $params['wf'] ?? false;` is
parsed from the query string but **never used** anywhere in the handler — dead code, zero live
risk today. But it strongly resembles a half-built JSONP-callback parameter (the client elsewhere
uses `?json_wrf=` for Texts). If anyone later "completes" it the conventional way —
`echo $wf . '(' . $body . ')';` with `Content-Type: application/javascript` — that's a textbook
reflected-XSS-via-callback-name vulnerability: an attacker-controlled `$wf` becomes raw executed
JavaScript on the page. Left as-is (not removed) since it may be intentionally reserved, but flag
this note for whoever touches JSONP support in this file next — validate `$wf` as a safe JS
identifier (e.g. `preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $wf)`) before ever echoing it back.

This is a fix in the external `shanti-uva/mandala-wp-proxy` repo, not in this monorepo — it has
no PR mechanism established (single `main` branch, direct commits per the repo's history), so
apply and verify carefully before pushing. `v1.0.0` tags the pre-fix state.

## Cross-references

- [Spike 6](../spikes/spike-06-api-compatibility.md) — the URL-strategy decision this blocks
- [wp-kmaps-mandala-proxy-dependency.md](wp-kmaps-mandala-proxy-dependency.md) — the sibling
  packaging decision (keep as separate plugin, declare dependency)
