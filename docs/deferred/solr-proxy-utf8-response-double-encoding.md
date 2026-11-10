# solr-proxy double-encoded UTF-8 responses on PHP 8 (Tibetan text garbled)

**Category:** solr-search
**Raised during:** Session 2026-11-09 (thlib.org reported Tibetan `name_tibt` / `names` in kmassets terms looking double-encoded)
**Jira:** (add when available)
**Priority:** ~~High~~ — **RESOLVED 2026-11-09** (fixed and deployed to staging and production; reporter confirmed 2026-11-10)
**Owner:** n/a (resolved)

## What it looked like

Tibetan text in `asset_type:terms` documents came back as UTF-8 → Latin-1 → UTF-8 mojibake
(`à¼...`). It looked like a corrupt index and was first investigated as an indexer bug.

## What it was

**The index was never corrupt.** The Solr replica and master return correct UTF-8. The proxy
(`Searcher::search()`) damaged the response on the way out:

```php
mb_convert_encoding($results, 'UTF-8', mb_detect_encoding($results, 'UTF-8, ISO-8859-1', true))
```

On PHP 7.x `mb_detect_encoding` returned the first candidate that validates (UTF-8). On
PHP >= 8.1 it ranks the candidates heuristically and can return `ISO-8859-1` for a body that is
entirely valid UTF-8 — large or Tibetan-heavy ones in practice. The whole response was then
transcoded as if it were Latin-1. The regression arrived with the proxy's move to PHP 8.3
(2026-10 on production, 2026-11-06 on dev-0).

Why it was hard to see: the damage is **per response, not per document**. A request for one short
document usually came back fine; the same document inside a larger response was garbled. That
made it look like some documents were corrupt in the index, and led to a wrong first diagnosis
(a stale or buggy indexer, plus a "garbled uid" list that was entirely a read-path artifact).

## Fix

```php
$this->results = mb_check_encoding($results, 'UTF-8')
    ? $results
    : mb_convert_encoding($results, 'UTF-8', 'ISO-8859-1');
```

Solr answers in UTF-8, so a valid body is returned untouched, and only a genuinely invalid one
is treated as Latin-1. PR #398 here; the same change went into the legacy D7 proxy repo
(`shanti-uva/mandala-solr-proxy`, PR #5) for production.

## Verification (before/after through the public path)

12 documents (6 terms, 3 subjects, 3 places), each fetched through the proxy and directly from
Solr and compared byte for byte:

| Environment | Before | After |
|---|---|---|
| Staging | 8 garbled, 4 identical | 12 identical |
| Production | 8 garbled, 4 identical | 12 identical (and 40/40 on a wider pool) |

No re-index was needed. The OAuth2 and visibility regression scripts also pass on dev-0.

## Regression guard

`solr-proxy/tests/utf8-response.php`, run as smoke test 8 in `solr-proxy/pipeline/buildspec.yml`
before any image is tagged or pushed. It serves three fixtures from a loopback server and
asserts `Searcher::search()` returns them correctly:

- a large Tibetan-heavy valid-UTF-8 body: must come back **byte-identical**;
- plain ASCII: unchanged;
- a genuinely Latin-1 body: still transcoded to UTF-8 (the fallback is kept).

Verified to **fail against the pre-fix `Searcher.php`** (returns 162,118 bytes for a 90,118-byte
body) and pass against the fix. It also prints whether the old idiom corrupts the fixture on the
running PHP, so if a future PHP release changes the heuristic the test says so instead of
silently guarding nothing.

Run it by hand against a built image:

```shell
docker run --rm \
  -e SOLR_BASEURL=http://smoke.invalid:8080 -e DEFAULT_RETURL=https://smoke.invalid/home \
  -v "$PWD/solr-proxy/tests:/tests:ro" <image> php /tests/utf8-response.php
```

## Sweep for the same idiom (2026-11-10)

Searched for `mb_detect_encoding`, `mb_convert_encoding`, `utf8_decode`/`utf8_encode`,
`iconv(` and Latin-1 literals across this monorepo (Drupal modules and themes, `package/`,
`scripts/`, `solr-proxy/`), the legacy D7 checkout (`docroot/sites`, simplesamlphp, Drupal core
includes), `mandala-om`, `mandala_drupal_docker`, `kmaps_engine`, and the JS indexers
(`reindeer_x`, kmaps-solr-sync, kmaps-solr-proxy).

| Where | Result |
|---|---|
| `solr-proxy/proxy/Searcher.php` (this repo) | **The bug; fixed** (the only hit besides the explanatory comment) |
| Legacy `mandala-solr-proxy` `Searcher.php` | **The same bug; fixed** (production) |
| Rest of this monorepo, `mandala-om`, `mandala_drupal_docker`, `kmaps_engine`, JS indexers | No occurrences |
| Legacy D7 Drupal core `includes/unicode.inc` | Uses an explicit source encoding, not detection; not affected |
| Legacy D7 simplesamlphp InfoCard module | Explicit `iconv` between fixed encodings for a dead card-issuer feature; not related |
| `shanti_general_solr_query` (D7 module) | An earlier read of the deployed code showed the same detect-then-convert idiom. It was **not found in the checked-out repos on 2026-11-10** (it is probably deployed from outside the repo), and the production box was unreachable to re-check. It is read-only and runs on PHP 7.2, where the idiom behaves; **it would break the same way if the D7 stack were ever moved to PHP >= 8.1.** Worth a look if that move is ever planned |

## Related

- The indexer-side suspicion was disproved; stored data is correct. The 200 staging `kmassets`
  documents rewritten while testing that theory have correct content but carry
  `schema_version_i` 41 and dev-environment URLs; a normal sync normalizes them.
