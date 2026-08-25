# Session Log: JSON API path standardization confirmed with Yuji

**Date:** 2026-08-25
**Participants:** Than Grove, Yuji Shinozaki, Claude Code
**Outcome:** Confirmed the group wants a single, un-namespaced JSON API path
(`/api/json/{nid}`) applied uniformly across all content types, rather than a
per-content-type path segment. Yuji agreed. This matches decision 4 of the still-open
[ADR 016 draft](../adr/016-public-url-structure-single-host.md) (branch
`docs/adr-016-url-structure`, Proposed, not yet merged) and was verified directly against
the live routing code rather than assumed from the draft.

## What was checked

`drupal/web/modules/custom/mandala_node_api/mandala_node_api.routing.yml` declares one
route, `/api/json/{node}`, with no bundle-specific path variant — every node type resolves
through the same flat path. A code comment ties this directly to the `url_json` template
already live in `mandala_kmassets_sync.settings.yml` and indexed in production Solr
(111,340 docs as of 2026-08-13), so the flat shape is not just a proposal, it is what the
one migrated collection (Images) already emits and what Solr already expects.

The controller (`NodeJsonController.php`) currently rejects any bundle other than
`shanti_image`, but that reflects migration scope — only Images exists in D11 yet — not a
type-specific path design. The comment there says as much explicitly. When Texts, Sources,
and AV migrate, they are expected to resolve through the same `/api/json/{nid}` route, not
a new per-type one.

## Decision

**Standardize on one flat, un-namespaced JSON API path for every content type:
`/api/json/{nid}`.** No per-type path segment (e.g. no `/api/json/images/{nid}` vs
`/api/json/texts/{nid}`). This is consistent with, and now double-confirmed alongside,
ADR 016 decision 4.

No code change was required — the routing already implements this. This session only
confirms the direction so ADR 016 doesn't need to re-litigate it when it comes up for
Accepted status.
