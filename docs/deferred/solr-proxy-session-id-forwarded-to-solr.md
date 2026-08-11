# solr-proxy forwards the session id to Solr as a query parameter

**Area:** solr-proxy / hygiene / logging
**Raised during:** Session 2026-08-11 (running the proxy locally to validate the pipeline specs)
**Jira:** (add when available)
**Priority:** Low — no access-control impact; a logging-hygiene and tidiness issue

## What we found

Observed directly while running the built image against a stub Solr (2026-08-11).
A request to:

```
/solr/kmassets/select?q=*:*&sid=<session-id>
```

produces this outbound request to Solr:

```
http://<solr>/solr/kmassets/select?fq=(...)&q=*:*&sid=<session-id>
```

The `sid` is passed straight through.

## Why it happens

Two independent readings of the query string that do not agree:

- `Searcher::setSession()` pulls `sid` out of **`$this->getvars`** (the sanitised
  `INPUT_GET` copy) and `unset()`s it there — so from the session code's point of
  view it has been consumed.
- `Searcher::searchAPI()` then calls `search($_SERVER['QUERY_STRING'])`, and
  `setParams()` re-parses that **raw** string, which still contains `sid`. Every
  parameter it finds is reflected back out by `getQueryStr()`.

So the `unset()` in `setSession()` has no effect on what is forwarded.

## Impact

- **No access-control impact.** Solr ignores unknown query parameters, and the
  visibility `fq` is applied independently. Nothing is exposed to the caller.
- **Session ids land in Solr's query logs**, and in anything else along that path
  that logs URLs. A proxy session id is a bearer credential for that session: with
  it, `?sid=` re-attaches to the session (this is precisely how the tests in the
  2026-08-11 session drove a logged-in request). Leaking them into a
  separately-administered system's logs is worth avoiding on principle, even though
  Solr is internal and IP-gated.
- Also pure noise — Solr receives a parameter that means nothing to it.

The same is true of any other proxy-control parameter that gets bounced through
(`json_wrf` is handled explicitly; `sid` is not).

## Fix

In `setParams()`, drop the parameters the proxy owns rather than forwarding them —
the natural place is next to the existing loop that strips caller-supplied
`visibility` clauses, which already exists to prevent query-string tampering. A
one-line exclusion of `sid` (and an explicit allow/deny list, if the shape is worth
generalising) would do it.

**Not worth a standalone change** — fold it into the next substantive edit of
`Searcher.php`, e.g. whichever option is chosen in
[solr-proxy-uid1-admin-gets-anonymous-filter.md](solr-proxy-uid1-admin-gets-anonymous-filter.md).

## Cross-references

- `solr-proxy/proxy/Searcher.php` (`setSession`, `setParams`, `getQueryStr`, `searchAPI`)
- [solr-proxy-has-no-cicd-pipeline.md](solr-proxy-has-no-cicd-pipeline.md) — found while validating those specs
