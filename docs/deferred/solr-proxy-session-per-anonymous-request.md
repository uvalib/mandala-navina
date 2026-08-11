# solr-proxy starts a PHP session on every anonymous request

**Area:** solr-proxy / performance / availability
**Raised during:** Session 2026-08-11 (checking the proxy against the "public access is the 90% case" principle)
**Jira:** (add when available)
**Priority:** Medium — no correctness or access-control impact, but it puts avoidable
per-request disk I/O and unbounded file growth directly in the hot public path

## Measured, not inferred

Ran the built image against a stub Solr and counted session files (2026-08-11):

```
sessions before:                          1
sessions after 20 anonymous requests:    21
```

**One session file written per anonymous request.** None of them is ever reused —
each request arrives with no cookie, gets a fresh session id, and writes a file that
nothing will read.

## Why it happens

`Searcher::__construct()` unconditionally calls `setSession()`, which calls
`session_start()` on every code path — including a bare
`/solr/kmassets/select?q=…` from an anonymous reader with no cookie and no `sid`
parameter. With the default file session handler that is a `write()` per request into
`session.save_path` (`/tmp` in this image), plus the later cost of PHP's session GC
scanning that directory.

The proxy only actually needs a session when there is something to look up in it —
a `sid` query parameter or an existing `PHPSESSID` cookie. For the anonymous case
the session is pure overhead: `setVisibility()` reaches the anonymous filter by way
of `isLoggedIn === false`, which does not require a started session to determine.

## Why it matters

Directly against the stated design principle (see `solr-proxy/README.md`):
**unauthenticated public access is the 90% case and must be highly available and
performant at all times.** This puts an unnecessary disk write on every one of those
requests, and:

- **Unbounded accumulation** between GC runs. `/tmp` inside the container grows with
  one file per public request. This is very likely why `proxysess.php` carries a
  `destroyall` action at all.
- **It scales with exactly the wrong traffic.** Mandala has already taken a
  distributed multi-bot crawl (the 2026-08-04 D7 outage, ~14.7h worker-pool
  exhaustion). Bots do not send cookies, so every bot request is a fresh session
  file — the load pattern that most needs the public path to be cheap is the one
  that generates the most sessions.
- Container-local `/tmp` also means sessions do not survive a redeploy, so nothing
  is gained by writing them for anonymous callers.

## Fix direction

Start the session only when one is actually implicated:

```php
// sketch — in setSession(), before session_start()
$has_sid    = array_key_exists('sid', $this->getvars);
$has_cookie = isset($_COOKIE[session_name()]);
if (!$has_sid && !$has_cookie) {
    // anonymous, no session to resume: skip session_start() entirely
    $this->isLoggedIn = false;
    $this->uid = null;
    return;
}
```

Care needed on two points, which is why this is filed rather than done inline:

1. `logout.php` and `ping.php` also construct a `Searcher` and expect session
   semantics — confirm each still behaves when no session is started.
2. `$this->sid` is currently always populated from `session_id()`; anything relying
   on a sid being present for an anonymous caller would need to tolerate null.

Worth pairing with
[solr-proxy-session-id-forwarded-to-solr.md](solr-proxy-session-id-forwarded-to-solr.md),
which touches the same `setSession()`/`setParams()` seam.

## Not a regression

The D7 proxy behaves the same way; this is inherited, not introduced by the D11
fork. It surfaced only because the "public is the 90% case" principle was stated
explicitly and the image was actually run and measured.

## Cross-references

- `solr-proxy/README.md` — the design principle this violates
- `solr-proxy/proxy/Searcher.php` (`setSession`)
- [solr-proxy-session-id-forwarded-to-solr.md](solr-proxy-session-id-forwarded-to-solr.md) — same seam
- [fail2ban-need-and-ownership.md](fail2ban-need-and-ownership.md) — the bot-load context
