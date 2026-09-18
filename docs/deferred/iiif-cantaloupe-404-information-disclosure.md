# Cantaloupe: legacy S3 key layout breaks 10 known-test images + 404 page leaks stack trace

**Area:** infrastructure / security / IIIF
**Raised during:** Sprint 1 (Step 1a.5 — IIIF wiring reachability probe, 2026-06-22); re-examined 2026-09-18 while investigating two broken demo images
**Jira:** (add when available)
**Priority:** Low for the broken-images half -- confirmed with Yuji (2026-09-18) that
all 10 affected nodes are known test/dev data, not real archival content, so
nothing user-facing is actually at stake. Kept documented (not deleted) because
the underlying Cantaloupe key-derivation bug is real and could recur if any
future real content ever lands in that same low-id/legacy-key situation.
Medium for the info-disclosure half, unchanged.
**Owner:** UVa Library DevOps (Cantaloupe owners) — not us

## UPDATE 2026-09-18: 10 images 404 on a legacy S3 key layout -- confirmed test data, not lost archival content

Prompted by a live report that `/image/blue-grosbeak` (nid 5) and the
Hummingbird image (nid 4) showed no image. Traced fully, not assumed:

- Both nodes' `field_iiif_id` (`shanti-image-36`, `shanti-image-31`) 404 at
  `iiif.lib.virginia.edu`, with the same leaked stack trace this doc already
  documented for `shanti-image-1`.
- Checked S3 directly (`aws s3 ls s3://mandala-assets/mandala-assets/36/`):
  **the JP2 files genuinely exist** -- `shanti-image-36.jp2` (2.46MB) and
  `shanti-image-31.jp2` (1.64MB), both dated 2020-11-07. This is not a
  missing-source-data problem. Cantaloupe's S3Source is computing the wrong
  key (`mandala-assets/36//shanti-image-36.jp2`, note the double slash) for
  an object that actually lives at `mandala-assets/mandala-assets/36/
  shanti-image-36.jp2` -- a flat layout, no sharding.
- Scope, checked against real data (not guessed): first suspected this might
  be corpus-wide (a shallow first check correlated it with `field_iiif_
  mms_id` being empty, ~38% of all Images nodes -- wrong, disproven by
  testing a wide random sample of mms_id-less nodes that all resolved fine
  at 200). The real, confirmed boundary: every real `field_iiif_id` numeric
  value 100 or below 404s; every one checked above 100 resolves. There are
  **exactly 10 such nodes** in the entire corpus, all nid 1-10 (the first 10
  Images nodes ever migrated) -- `shanti-image-{16,21,26,31,36,41,61,71,76,96}`:

  | nid | title | iiif id |
  |---|---|---|
  | 1 | Crab Nebula | shanti-image-16 |
  | 2 | "We Replaced You" | shanti-image-21 |
  | 3 | Perseids over Mongolia | shanti-image-26 |
  | 4 | Hummingbird | shanti-image-31 |
  | 5 | Blue Grosbeak | shanti-image-36 |
  | 6 | Ritual Vigil.8.16.17 | shanti-image-41 |
  | 7 | From "Fifty plates of green-house plants..." | shanti-image-61 |
  | 8 | from "The snakes of Australia" | shanti-image-96 |
  | 9 | Banksy 71 (detail) | shanti-image-71 |
  | 10 | Banksy 63 | shanti-image-76 |

  Reads like the very first pilot batch uploaded to S3 under an old flat
  naming convention, before Cantaloupe's delegate was set up for whatever
  sharded/nested key scheme every later upload uses (S3 also has unrelated
  numbered `00/`-`99/` prefixes sitting alongside these flat files under the
  same `36/` folder, evidence of a second, different id space sharing the
  same top-level numeric prefix -- consistent with a scheme change mid-way,
  not a random corruption).
- **Confirmed test data, not real content (Yuji, 2026-09-18):** all 10 are
  known test/dev images, not Mandala archival material. A first attempt to
  corroborate this from the data alone (checking each node's real collection
  membership -- properly filtered to the `group_node:shanti_image`
  relationship type, after an initial pass wrongly conflated it with
  uid=1's unrelated 176 *user* group memberships via a numeric nid/uid
  collision) found 8 of the 10 in single, thematically-coherent but
  non-Mandala-core collections ("The Universe", "Birds", "Banksy", "Resist"
  -- side/community collections the shared Group platform also hosts) --
  suggestive but not conclusive on its own. Yuji's direct confirmation
  settles it.

**Not our codebase to fix** (Cantaloupe/S3 key delegate, Library DevOps
territory, same as the info-disclosure half below), and now also not
urgent -- no real content is affected. Worth a mention to DevOps opportunistically
(concrete 10-node list, root cause understood) rather than filing proactively;
deleting the 10 test nodes outright would make this moot entirely, if/when
someone's doing test-data cleanup.

## What we observed (original finding, still accurate)

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
