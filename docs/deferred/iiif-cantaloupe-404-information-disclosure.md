# Cantaloupe 404 page leaks S3 bucket name + full Java stack trace

**Area:** infrastructure / security / IIIF
**Raised during:** Sprint 1 (Step 1a.5 — IIIF wiring reachability probe, 2026-06-22)
**Jira:** (add when available)
**Priority:** Medium — information disclosure, not a vulnerability per se. Worth
mentioning to Dave Goldstein / Library DevOps next time IIIF infra is in scope.
**Owner:** UVa Library DevOps (Cantaloupe owners) — not us

## What we observed

When you request a missing IIIF identifier:

```
GET https://iiif.lib.virginia.edu/mandala/shanti-image-1/info.json
HTTP/2 404
```

…the response body is a plain-text page that contains:

```
404 Not Found

mandala-assets/mandala-assets//1/shanti-image-1.jp2


java.nio.file.NoSuchFileException: mandala-assets/mandala-assets//1/shanti-image-1.jp2
    at edu.illinois.library.cantaloupe.source.S3Source.getObjectAttributes(S3Source.java:302)
    at edu.illinois.library.cantaloupe.source.S3Source.checkAccess(S3Source.java:279)
    at edu.illinois.library.cantaloupe.resource.InformationRequestHandler.handle(InformationRequestHandler.java:252)
    ... (full stack into Jetty internals)
```

This leaks: the S3 bucket name (`mandala-assets`), the internal key-derivation
pattern (`mandala-assets/mandala-assets//<digit>/<i3fid>.jp2`), the Cantaloupe
version (5.0.6), and the Jetty version (9.4.53.v20231009 — visible in the
`Server` header).

## Why it matters

Standard verbose-error-page anti-pattern. Knowing the bucket name + key
template makes it cheaper for an attacker to enumerate keys directly against
S3 if the bucket is ever misconfigured. Knowing the Cantaloupe + Jetty
versions makes it easier to look up known CVEs against the deployed versions.

Not a vulnerability by itself — but it lowers the bar for any future
escalation.

## What to do

Cantaloupe has a config knob for this — `error.show_stacktrace = false` in
`cantaloupe.properties` (or set the `CANTALOUPE_ERROR_STACKTRACE` env var) will
return a generic 404 body instead of the trace.

Not our codebase to fix — this is for whoever runs the Cantaloupe service on
`iiif.lib.virginia.edu`. File this with Library DevOps next time IIIF infra is
under discussion.

## Related

- ADR 004 (Solr/IIIF source of truth — external infra stays as-is, but ops
  hardening is fair game when convenient)
- Discovered during the [1a.5 IIIF wiring](../sprints/sprint-01-images-implementation.md) probe
