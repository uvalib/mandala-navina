# solr-proxy runs on an end-of-life base image (PHP 7.4 / Debian 11)

**Category:** infrastructure-deploy, solr-search
**Raised during:** Spike 11 / #327 (the solr-proxy build failed after PR #315 merged, 2026-10-14)
**Issue:** #336
**Priority:** Medium. Builds work again with the stopgap (PR #335), but the image is on unsupported PHP and Debian and gets no security fixes.
**Owner:** unassigned

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
