# solr-proxy starts a PHP session on every anonymous request

**Area:** solr-proxy / performance / availability
**Raised during:** Session 2026-08-11 (checking the proxy against the "public access is the 90% case" principle)
**Jira:** (add when available)
**Priority:** ~~Medium~~ — **RESOLVED 2026-08-11** (called out as a major anti-pattern; fixed same session)

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

## ✅ FIXED 2026-08-11

`Searcher::setSession()` now starts a session only when the caller supplies a `sid`
parameter or already holds a session cookie. Anonymous callers get
`sessionStarted = false`, `isLoggedIn = false` and no session at all —
`setVisibility()` reaches the anonymous filter from `isLoggedIn === false` without
needing any session state.

Two call sites needed guarding, which is why this was filed rather than done blind:
`endSession()` (returns early — `session_unset()`/`session_destroy()` warn with no
active session) and `getReturnUrl()` (falls back to `?returl=`, then the session,
then `$DEFAULT_RETURL`, instead of reading an undefined index).

**Measured after the fix: 50 anonymous requests → 0 session files** (was 1 per
request). Verified unbroken: anonymous search returns the correct filter; the `sid`
and session-cookie paths both still resume and inject the Redis visibility token;
`ping` returns `{"loggedIn":false}`; `logout` with neither session nor `returl`
redirects to `$DEFAULT_RETURL` instead of fataling. No PHP warnings in the logs.

A smoke test in `solr-proxy/pipeline/buildspec.yml` now guards it, and was confirmed
to **fail against the pre-fix `Searcher.php`** — a guard never seen failing is not
known to be a guard.

## Original fix direction (kept for context)

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
