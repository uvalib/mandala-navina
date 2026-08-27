# D11 asset endpoints: enforce node access uniformly, and let authenticated users fetch what they may see

**Area:** `mandala_node_api` + per-site endpoint equivalents / SimpleSAMLphp + OAuth2 + Redis /
ADR 014 / migration consistency
**Raised:** 2026-08-21 by Than Grove, following [Spike 6](../spikes/spike-06-api-compatibility.md)'s
endpoint audit
**Priority:** **High** — a required property of every asset endpoint D11 builds; the per-site
migration checklist depends on it
**Status:** **DECIDED 2026-08-26.** Than confirmed with Yuji directly (Xiaoming not required for
the decision itself — see below); the convergence is accepted, scoped under ADR 010, and does not
need its own ADR. Owned by Than for Sprint 2. Design of the authenticated-fetch half (§2) is not
started and is the remaining work — see "Decision and plan" below.

## Decision and plan — 2026-08-26 (Than, confirmed with Yuji)

1. **The convergence is accepted.** Uniform node-access enforcement across every D11 asset
   endpoint, public-only by default, no per-site exemptions — `mandala_node_api`'s existing
   `node->access('view')` gate is the pattern every subsequent site's endpoint copies.
2. **In scope under ADR 010, no exception to ADR 008 needed.** This is a **correctness /
   maintainability improvement with no user-visible behavior change** — anonymous access to public
   nodes is unchanged; the divergence being closed is internal (which module gates which route,
   not what an end user can see). That is squarely [ADR 010](../adr/010-adr-008-scope-clarification.md)'s
   "internal architecture, not user-facing features" carve-out from ADR 008's "migrate, not
   improve." Resolves question 2 from the prior version of this note.
3. **No ADR.** The requirement is a straightforward application of ADR 010, not a new
   architectural decision — resolves question 3.
4. **Half 2 (authenticated fetch) precedent already exists.** AV's D7 endpoint already handles the
   authenticated case correctly, so this is not a from-scratch design — it is bringing the other
   three sites' endpoints up to a pattern D7 itself already proved once. (Separately: AV did this
   via Services module, which the team does not expect to carry into D11 — the *behavior* is the
   precedent, not the module.)
5. **Ownership (resolves question 4):** **Than owns this**, planning the authenticated-fetch half
   with Claude — likely via a small spike, given it still depends on the identity-forwarding gap
   below. Not blocked on Xiaoming; loop him in only if D11 module/deployment specifics come up
   during implementation.
6. **Scheduling:** lands in **Sprint 2**, alongside the D11 base theme/UI work
   ([theme-ui-commonalities-audit.md](../planning/theme-ui-commonalities-audit.md)) and the Images
   IIIF viewer ([images-missing-interactive-viewing-surfaces.md](images-missing-interactive-viewing-surfaces.md)).
   Sprint 2 itself is not yet written up in `docs/sprints/`.

Related context: a legacy D7 access-control defect motivated this and is tracked privately — **ask
Than**. Filed 2026-08-24 in `uvalib/mandala-legacy-docs` per
[CONVENTION.md](https://github.com/uvalib/mandala-legacy-docs/blob/main/CONVENTION.md) (routing is
by which stack the *fix* serves, and this fix serves D7). Status OPEN. Not described here.

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
