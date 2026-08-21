# 2026-08-21 — Spike 6 closed Proven: endpoint audit live-verified end to end

**Driver:** Than Grove
**Branch:** `feat/av-node-json-controller` → **PR [#134](https://github.com/uvalib/mandala-navina/pull/134)** (open, docs-only)
**Outcome:** [Spike 6](../spikes/spike-06-api-compatibility.md) **CLOSED — Proven**

> **This log is hand-written, not machine-generated.** Part of this session confirmed an
> access-control defect in the live D7 stack. `scripts/save-session-log.py` commits the raw
> transcript verbatim, which would have published the reproduction into this public repo. Same
> precedent as the 2026-08-13 Solr-inventory and 2026-08-18 SAML logs. The defect itself is
> **not described here** — see "Tracked privately" below.

## Starting point

Session opened mid-merge: `main` merged into the branch with conflicts in `docs/deferred/.pages`
and `docs/deferred/README.md`, resolved but uncommitted, and Than asked for the resolution to be
checked.

## What happened

### 1. The merge resolution was half wrong

`.pages` was correct. `README.md` was not — six rows where three belong. Two of the duplicates
**predated this merge** (introduced by `2e7ccd7`, "merging conflicts from rebasing"), and the
resolution added a fifth by prepending main's fuller `simple-oauth` row instead of replacing the
stale short copy already there. Fixed, merged, pushed. Verified afterwards that no `docs/` file on
main was missing from the branch.

### 2. Two stale framings retired

- **Duplicate `Option A/B/C` lettering.** The doc carried two independent schemes — a pre-spike
  sketch and the 2026-08-12 decision table — whose letters *disagreed*: the sketch's Option B is
  the decision table's Option D. A bare "Option B" in the lower half meant the opposite of the same
  string in the upper half. The superseded sketch is now named, not lettered, so only one scheme is
  citable.
- **"React app cannot be changed"** (a fail criterion) was never true. Than: `mandala-om` is
  Mandala's own repo; the real constraint is that changes must not break the running D7
  integration, so D11 work goes on a branch or fork cut when cutover starts. Noted that this does
  **not** reopen Option B, which was rejected for WAF exposure, not client immutability.

### 3. Sources and Texts endpoints live-verified — the audit-reliability caveat is closed

Both rows held up: correct module, callback, route, response family. No repeat of the AV situation.
Two refinements and one real discovery:

- **Sources' augmentations are conditional by node type**, not unconditional. Proven by walking a
  real collection tree: a `biblio` node gets none of them; `subcollection` gets `parent`;
  `collection` gets `subcollections` + `description`. A generic D11 controller can neither always
  nor never emit these keys.
- **Texts' `node_json` collapses any page nid to its book root** — five page nids returned
  byte-identical documents. So `nid → document` is many-to-one, and `parent`/`children` are
  **unreachable dead code** (`hook_menu` never passes the `$is_page` argument). D11 need not
  implement them for parity.
- **Error handling diverges:** Sources returns HTTP 200 with a JSON 404 payload; Texts a real 404
  with an HTML page.

### 4. A tooling constraint that would have poisoned the next session

Than noticed `/sources-api/ajax/62716` works in a browser but returns `202`/empty to `curl`.
Probing all six endpoints the same minute showed the edge bot-challenge keys on **response content
type**: every JSON endpoint returns 200 with a body, every HTML-returning endpoint returns
`202`/empty, across all sites. A `curl`-based AJAX audit would have concluded all of them were dead.
This sharpens the standing rule — it is not that `curl` cannot test this WAF, it is that `curl`
fails on the HTML-typed responses.

### 5. AJAX/embed audit — six routes, not four

Browser-verified all four sites. The endpoint matrix undercounted: it missed
`sources-api/ajax/{nid}/{type}` (renders **citations**, biblio style from `arg(4)` — **Spike 5's**
territory) and `services/node/ajax/{nid}/player` (**Spike 7's** — a redirect off-site to Kaltura).
Also: `sources_misc_node_ajax()` is **dead code**, no route points at it; Texts' `node_embed` keeps
the requested nid where `node_json` collapses to the book root; all four return HTML fragments,
never JSON. Kaltura's live partner/uiconf/entry_id values were handed to Spike 7 rather than kept
here. AV's embed exposing the internal `Workflow` field group to anonymous callers was **reviewed
and accepted by Than** — those fields are rarely used.

### 6. A field-coverage caveat that qualifies everything above

Than's observation: these endpoints **omit a field entirely when it is empty**, so every inventory
captured in this spike is a **lower bound, not a complete contract** — absence in a sample is not
evidence the endpoint never emits it. Filed as
[endpoint-field-inventories-are-lower-bounds.md](../deferred/endpoint-field-inventories-are-lower-bounds.md)
with two unchosen approaches (fully-populated test records; programmatic derivation from D7 field
definitions). Nothing captured is known to be wrong; the *completeness* claim is what is
unsupported.

### 7. Spike 6 closed Proven, per-site controllers handed off

All four pass criteria met, with the formats criterion satisfied on **live evidence rather than
source reading**. Building the Sources/Texts/AV controllers is gated on each site migrating, so it
moved to the per-site checklist in
[migration-legacy-nid-required-convention.md](../deferred/migration-legacy-nid-required-convention.md)
rather than holding a Phase 3 gate open indefinitely. The checklist carries each site's shape
differences.

### 8. New D11 requirement: uniform access + authenticated fetch

**Than's decision.** The four D7 apps were built separately by different groups and diverged,
including in how they enforce access. The D11 migration is the moment to converge them — a
considered exception to "migrate, don't improve", justified because the endpoints are being rebuilt
anyway. Two halves, filed as
[d11-asset-endpoints-uniform-access-and-authenticated-fetch.md](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md):

1. Every asset endpoint gates on the real node access check, **public-only by default**, no
   exemptions and no per-site variation. `mandala_node_api` already does this and is the pattern.
2. **Authenticated users can fetch what they may see**, via the SimpleSAMLphp/NetBadge → Drupal
   session → OAuth2 → Redis stack Yuji has been building (the same path ADR 014 uses for Solr).
   Blocked today by the already-filed identity-forwarding gap — which now has a *destination*
   rather than being an open question.

## Tracked privately

An access-control defect in the live D7 stack was **confirmed by Than on staging**. It is not
described in this public repo, per
[non-public documentation policy](../non-public-documentation.md). **Ask Than Grove.**

**Blocker found while trying to file it:** the policy doc (authored by `ys2n`, `af9d13b`,
2026-08-13) points at two private repos, `uvalib/mandala-legacy-docs` and
`uvalib/mandala-navina-docs`. Neither resolves for Than's account, and
`gh repo list uvalib --visibility private` returns **nothing** despite a `repo`-scoped token — so
Than has no access to any private uvalib repo. That is inconclusive as to whether the repos exist
(GitHub returns the same error either way), but conclusive that the documented filing destination
is **not usable by Than today**. The write-up exists only as a session-local draft.

**→ Action for Yuji:** do these repos exist? If so Than needs access; if not, the policy doc points
the team at a destination that was never created.

## Open decisions

- **Patch D7 now, or accept the exposure until cutover?** The D11 fix is filed, but cutover is not
  close — Phase 2 has not forked off. Against patching: regression risk in a stack being retired.
- **Does the D11 access requirement need an ADR?** It spans several services and is a deliberate
  departure from "migrate, don't improve" — ADR-shaped, but not written.
- **`mandala-om` `feat/generalize-json-proxy-all-sites`** — pushed, **unmerged, no PR**, and only
  the Sources detail page was browser-exercised.

## Artifacts

| | |
|---|---|
| PR | [#134](https://github.com/uvalib/mandala-navina/pull/134) — docs-only, 9 files, ~+2,900 |
| Spike closed | Spike 6 → ● Proven |
| New deferred notes | `endpoint-field-inventories-are-lower-bounds.md`, `d11-asset-endpoints-uniform-access-and-authenticated-fetch.md` |
| Updated | `spike-06-api-compatibility.md`, `spike-07-kaltura-av-integration.md`, `migration-legacy-nid-required-convention.md`, `docs/spikes/README.md`, deferred `README.md` + `.pages` |

> **Branch name is a misnomer** — `feat/av-node-json-controller` contains no controller. AV is not
> migrated to D11 (no `video`-equivalent bundle), so the original session became a doc correction.
> Kept rather than rewriting pushed history; PR #134's title and body say so.
