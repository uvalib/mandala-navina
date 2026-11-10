# solr-proxy runs on an end-of-life base image (PHP 7.4 / Debian 11) — resolved 2026-11-06

**Category:** infrastructure-deploy, solr-search
**Raised during:** Spike 11 / #327 (the solr-proxy build failed after PR #315 merged, 2026-10-14)
**Issue:** #336 (closed 2026-11-06)
**Priority:** ~~Medium~~ — **RESOLVED 2026-11-06.** The proxy runs on PHP 8.3 / Debian 13 on dev-0, and both verify scripts pass.
**Owner:** n/a (resolved)

## What is deferred

`solr-proxy/Dockerfile` builds `FROM php:7.4-apache`, which is Debian 11 (bullseye). Both are past
end of life: PHP 7.4 since 2022, and Debian 11's packages have left the main mirror
(`deb.debian.org`), which broke the build on 2026-10-14 (apt returned 404 for `libssl-dev` and
`unzip`). The last successful build before that was 2026-08-19.

PR #335 is a stopgap only: it points apt at `archive.debian.org` and turns off the expired-Release
check, so the image builds again. It does not make the image supported. It still runs PHP 7.4 on
a Debian release that gets no security updates, and it sits in front of Solr on the access-control
path (ADR 014), so this is not a place to leave unsupported software indefinitely.

## What closes it

1. Pick a supported base (a current PHP image on a supported Debian) and build the proxy on it.
2. Check the proxy code on that PHP version: it is old PHP, with Composer dependencies pinned in
   `proxy/composer.lock` (Guzzle 7 line, Redis extension). Run
   `scripts/verify-solr-proxy-visibility.py` and `scripts/verify-oauth2-userinfo.sh` against it;
   those are the regression tests for the authenticated path (ADR 014).
3. Revert the archive.debian.org stopgap in `solr-proxy/Dockerfile`.
4. Look for other images in this repo built on the same or an older base and note them here.

## Resolved (2026-11-06)

PR #392 moved the proxy to `php:8.3-apache` (PHP 8.3.36 on Debian 13) and removed the apt
stopgap. Built by `uva-mandala-solr-proxy-codepipeline` as `build-20261106163420` and deployed to
dev-0 with `deploy_solrproxy.yml` the same day. The previous image, `build-20261014193854`
(PHP 7.4), is the rollback.

- **Base:** `composer.json` platform pin moved to 8.3.0; `composer.lock` changed only in its hash
  and platform entry (no package changed, no Composer security advisories).
- **Before deploy (local):** every PHP file lints clean, and the old 7.4 image and the new 8.3
  image gave identical responses and sent identical queries to a stub Solr for anonymous search
  on all three cores (including the join core), a logged-in user whose token comes from Redis,
  the 404 path, `ping.php` and `status.php`.
- **After deploy (dev-0):** `scripts/verify-solr-proxy-visibility.py` 12 of 12 PASS;
  `scripts/verify-oauth2-userinfo.sh` PASS (`sub=600`); `/solr/kmassets/status`, `/ping.php` and a
  search return 200; Apache config test passes; no errors in the container log.
- **Other images (step 4):** the only other Dockerfile is `package/Dockerfile`
  (`drupal:11-php8.4-apache`), already supported.
- **Left over:** `FILTER_SANITIZE_STRING` is still used (see
  [solr-proxy-filter-sanitize-string-deprecated.md](solr-proxy-filter-sanitize-string-deprecated.md)).
- **Not deployed anywhere else.** Staging and production pick up the image through the normal
  deploy path; production's legacy D7 proxy was already on PHP 8.3 and is a separate codebase.

## Sequence, not a date

Schedule this for soon (Yuji, 2026-10-14), ahead of any other change to the proxy that is not
urgent. It follows PR #335 (the stopgap) and the Spike 11 join check (#327), and it should land
before production cutover so the production proxy is not on an unsupported base. No calendar
date is set.

## Related

- PR #335: the apt stopgap
- [Spike 11](../spikes/spike-11-av-transcript-replication.md), #327, PR #315
- [ADR 014: hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [solr-proxy CI/CD pipeline](solr-proxy-has-no-cicd-pipeline.md)
