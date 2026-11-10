# Node.js end-of-life sweep: DDEV pin fixed, reindeer_x base image still on Node 18

**Category:** infrastructure-deploy
**Raised during:** Session 2026-11-04 (Node.js EOL sweep across the legacy Mandala stack and this repo)
**Issue:** none
**Priority:** Low. Nothing in this repo runs Node in production; the open item lives in another repo.
**Owner:** unassigned

## What was found

This repo has almost no Node.js exposure:

- **`.ddev/config.yaml` pinned `nodejs_version: "18"`.** Node 18 has been end-of-life since
  April 2025. It only affects the local DDEV web container.
- **Nothing uses it.** There is no `package.json` here, no Node CI, and no `npm`, `yarn` or
  `nvm` calls in `scripts/` or the docs. The only `yarn.lock` files are Drupal core's own, under
  `drupal/web/core`. `docs.yml` and `pipeline/` use Python, and the app image is
  `drupal:11-php8.4-apache`.

## Resolved 2026-11-04

`.ddev/config.yaml` now sets `nodejs_version: "22"` (Node 22 LTS, supported to April 2027). DDEV
1.24.10 parses the setting; the web image was not rebuilt as part of this change. If a future
frontend build needs Node, 22 is the supported baseline. If none ever does, the line could be
removed.

## Still open

- **`uvalib/mandala-reindeer_x`** (the kmterms→kmassets sync service, see
  [ADR 007](../adr/007-reindeer-x-independent-service.md)) builds `FROM node:18-bullseye`: Node 18
  and Debian 11, both past end of life, plus an `npm audit` backlog. It lives in its own repo, so
  the fix belongs there (base image to a current Node LTS, then a dependency pass), and it should be
  done before the D11 deployment of that service relies on it. The older D7-era deployment of the
  service is handled separately and is not part of this note.
- **`solr-proxy`'s PHP 7.4 / Debian 11 base image** is a related end-of-life item, tracked in
  [solr-proxy-end-of-life-base-image.md](solr-proxy-end-of-life-base-image.md) (#336).
- **Out of scope here:** the standalone React app (`mandala-om`), which Than's CSC/THL team
  supports. A CI Node 14 → 22 change for it is up for review in that repo (shanti-uva/mandala-om
  PR #90).
