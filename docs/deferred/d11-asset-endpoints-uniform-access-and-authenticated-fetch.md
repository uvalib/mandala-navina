# D11 asset endpoints: enforce node access uniformly, and let authenticated users fetch what they may see

**Area:** `mandala_node_api` + per-site endpoint equivalents / SimpleSAMLphp + OAuth2 + Redis /
ADR 014 / migration consistency
**Raised:** 2026-08-21 by Than Grove, following [Spike 6](../spikes/spike-06-api-compatibility.md)'s
endpoint audit
**Priority:** **High** — a required property of every asset endpoint D11 builds; the per-site
migration checklist depends on it
**Status:** Requirement stated, design not started. Candidate for an ADR (the API-endpoint analogue
of [ADR 014](../adr/014-hybrid-solr-proxy-design.md)) — Than's call whether it needs one.

## Why this exists

The four D7 apps (AV, Images, Sources, Texts) were **built separately by different groups at
different times**, and their detail endpoints diverged accordingly — different modules, different
response shapes, different JSONP conventions, and different approaches to access enforcement. Spike
6 documented that divergence in detail.

**Than's decision (2026-08-21): the D11 migration is the right moment to converge them.** This is
deliberately an *improvement*, not a faithful port, and it is a considered exception to the
"migrate, don't improve" rule in the [roadmap](../roadmap.md) — justified because the endpoints are
being rebuilt from scratch anyway, and rebuilding four inconsistent contracts into four
*differently* inconsistent D11 contracts would bank the divergence for another decade.

> A specific access-control defect in the legacy D7 stack motivated this and was **confirmed on
> 2026-08-21**. It is not described here — it concerns a live system, so it is tracked privately
> per [non-public documentation policy](../non-public-documentation.md). **Ask Than Grove.** The D11
> requirement below stands on its own and does not depend on those details.

## The requirement, in two halves

### 1. Every asset endpoint enforces node access — public-only by default

Every D11 endpoint that emits node content — JSON detail endpoints, and any AJAX/embed equivalent a
site decides it needs — **must gate on the node's real access check**, with no endpoint exempt and
no per-site variation. Anonymous callers get public nodes and nothing else.

`mandala_node_api` already does this correctly: `/api/json/{nid}` delegates to
`node->access('view')`, and a private-collection node returns a real 403 via
`mandala_group_inheritance` (verified live in DDEV during Spike 6). **It is the pattern to copy** —
the requirement is that every subsequent site matches it rather than reinventing the gate.

### 2. Authenticated users can fetch what they are entitled to see

Public-only is the default, not the ceiling. A logged-in user with legitimate private-collection
membership should be able to make JSON and AJAX calls for the nodes they may see — which means the
endpoints must tie into the authentication stack Yuji has been building: **SimpleSAMLphp/NetBadge →
Drupal session → OAuth2 (`simple_oauth`) → Redis**, the same path ADR 014 uses for Solr visibility.

**This half is currently blocked by a known gap**, already filed: no caller identity reaches
`mandala_node_api` through the Option A proxy path, because the proxy forwards nothing and the
React client never holds a raw OAuth token — only opaque `sid`/`uid` cookies. See
[mandala-node-api-no-identity-forwarded-through-json-proxy.md](mandala-node-api-no-identity-forwarded-through-json-proxy.md),
which is the same problem stated from the client side. **That note now has a stated destination**
rather than being an open question: identity should arrive via the SAML/OAuth2/Redis path, not via
a new bespoke trust channel.

## Open design questions

- **How identity crosses the proxy hop.** The proxy is a WordPress plugin on third-party hosting
  (Option A); it currently forwards nothing. Whether it forwards a token, or the client acquires
  one directly, or the architecture changes, is undecided.
- **Whether this needs an ADR.** It is an access-control architecture decision spanning several
  services, which is ADR-shaped — and it is a deliberate departure from "migrate, don't improve",
  which is exactly the kind of thing ADRs exist to record.
- **Interaction with the standalone deployments**, which have no proxy at all — see
  [option-a-proxy-unavailable-on-standalone-deployments.md](option-a-proxy-unavailable-on-standalone-deployments.md).
- **Whether any AJAX/embed equivalents get built at all.** Per Than, the D7 AJAX endpoints are
  low-importance and largely without consumers; the default answer is no. If a site does build one,
  it inherits this requirement in full.

## Related

- [Spike 6](../spikes/spike-06-api-compatibility.md) — endpoint contracts and the audit behind this
- [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md) —
  per-site checklist that must carry this requirement
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the Solr-side analogue of half 2
