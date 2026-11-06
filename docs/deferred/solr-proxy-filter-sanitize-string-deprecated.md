# solr-proxy uses FILTER_SANITIZE_STRING, which PHP 9 removes

**Category:** solr-search, infrastructure-deploy
**Raised during:** #336 / PR #392 (moving the proxy to PHP 8.3), 2026-11-06
**Issue:** none
**Priority:** Low. Works on PHP 8.3; blocks only a move to PHP 9.
**Owner:** unassigned

## What is deferred

`FILTER_SANITIZE_STRING` has been deprecated since PHP 8.1 and is removed in PHP 9. The proxy
passes it to `filter_input_array(INPUT_GET, ...)` in three files:

- `solr-proxy/proxy/Searcher.php` (constructor)
- `solr-proxy/proxy/auth.php`
- `solr-proxy/proxy/proxysess.php`

On PHP 8.3 it behaves as before. Each use raises an `E_DEPRECATED`, which production `php.ini`
(`error_reporting = E_ALL & ~E_DEPRECATED`) hides, so nothing is logged today.

## What closes it

Replace the filter in all three places with an explicit equivalent, and prove the result does
not change what reaches Solr. The old filter strips tags and encodes quotes, so a plain swap to
`FILTER_DEFAULT` or `htmlspecialchars` changes some inputs. Re-run the A/B method from #392
(old and new image against a stub Solr, same requests, diff the forwarded queries) with inputs
that contain tags, quotes and ampersands, then run `scripts/verify-solr-proxy-visibility.py`
and `scripts/verify-oauth2-userinfo.sh` on dev-0.

## Sequence, not a date

Do it before any move to PHP 9, or alongside the next change that touches these files.

## Related

- [solr-proxy-end-of-life-base-image.md](solr-proxy-end-of-life-base-image.md), #336, PR #392
