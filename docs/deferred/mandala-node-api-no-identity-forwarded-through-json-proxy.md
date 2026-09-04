# mandala_node_api sees every request as anonymous — private-collection assets can't be fetched through the JSON proxy

**Area:** mandala_node_api / mandala-wp-proxy (external repo) / solr-proxy / ADR 014 / access
**Raised during:** Spike 6 (2026-08-12) — after building the D11 node-JSON endpoint
**Jira:** (add when available)
**Priority:** Medium — Sprint 1 (Images) is mostly public collections, so low blast radius today;
becomes real once private-collection content needs to be viewable via the React app's detail view

## What we found

`mandala_node_api`'s `/api/json/{node}` controller (see
[spike-06-api-compatibility.md](../spikes/spike-06-api-compatibility.md)) correctly delegates
access to `node->access('view')`, so `mandala_group_inheritance`'s private-collection gating
works exactly as designed — **verified live**: a node in a private collection returns 403.

But every request reaching this endpoint through the decided Option A path (client →
`mandala-wp-proxy`'s `json_proxy` → this controller) arrives **anonymous**, regardless of
whether the actual browser user is logged in and a legitimate group member. Two independent
facts combine to cause this:

1. **`json_proxy` forwards no identity at all.** Its `wp_remote_get($base_url, ...)` call sends
   no cookies, no `Authorization` header, nothing — every fetch is anonymous to whatever it
   targets (see
   [mandala-wp-proxy-json-proxy-open-ssrf.md](mandala-wp-proxy-json-proxy-open-ssrf.md) for the
   same handler's other issues).
2. **There is no token to forward even if it did.** Checked the React client
   (`mandala-om`, `kmaps-app/src/main/MandalaSession.js` +
   `kmaps-app/src/hooks/useSolr.js`/`useMandala.js`): after the OAuth2 login flow, the browser
   only ever receives an opaque `sid` (solr-proxy's own PHP session id) and `uid`, stored in
   plain cookies (`solrsid`, `muid`). **The real OAuth2 bearer token never reaches the browser**
   — it's captured and held entirely inside `solr-proxy/auth.php`'s own server-side PHP session,
   keyed by `sid`. Confirmed via `grep -rniE "access_token|bearer|authorization"` across the
   entire client source tree: zero matches.

**Consequence:** the practical effect is a *coherence* gap between what the search results claim
a user can see (visibility_i / the solr-proxy's Redis-token filter) and what the detail-view
fetch can actually retrieve (always public-only) — the same class of gap 1b.3 (Solr-proxy
visibility coherence) and 1b.4 (paragraph access inheritance) already exist to track, just
surfacing at a different layer (node detail vs. search results).

## Why the obvious fixes don't work as first proposed

- **"Forward an OAuth2 bearer token"** — not available; the client never holds one (see above).
- **"Trust a client-supplied `uid` query param"** — a spoofing hole, not a fix. `muid` is a
  plain cookie fully under the browser's control; anyone can set it to any uid and claim to be
  that user. This is exactly the class of client-trust problem ADR 014 already rejected for
  Solr visibility filtering.
- **"Reuse ADR 014's Redis token directly"** — doesn't transfer cleanly either. The Redis token
  (`mandala_solr_fq:{uid}`) is legitimate proof *once you already know the real uid*, but nothing
  in this chain currently proves the browser's claimed `uid` is real — Redis doesn't solve the
  "who is actually asking" problem, only "what can this already-verified uid see."

## What would actually work (not yet decided)

**Option 1 — resolve `sid` → uid server-side, mirroring how solr-proxy already trusts it.**
`sid` is an unguessable session identifier; solr-proxy's own `Searcher`/session logic already
treats it as a valid trust anchor because only `auth.php`'s server-side session store can map it
to a real uid. Something (Drupal itself, or `mandala-wp-proxy`) would need to make a trusted
call back to solr-proxy to resolve `sid` before `mandala_node_api` runs its access check. Correct
in principle, but introduces a new cross-service dependency between three separate codebases
(`mandala-navina`'s `mandala_node_api`, `mandala-navina`'s `solr-proxy`, and the external
`mandala-wp-proxy` plugin) that doesn't exist today and needs its own design pass (new
solr-proxy endpoint? shared secret between services? synchronous latency cost on every detail
fetch?).

**Option 2 — scope it as a known limitation for now.** Private-collection asset detail fetches
stay public-only-visible through the React app's node-JSON path, consistent with this system's
existing fail-closed-to-public philosophy elsewhere (solr-proxy's own anonymous/Redis-down
fallback). Sprint 1 (Images) is mostly public collections, so this is a real but currently
low-blast-radius gap — revisit before any private-collection content needs to be viewable via
the app's asset detail page.

**Decision (2026-08-12, Than): defer.** Filed here rather than building a new cross-service
trust path immediately. No option chosen yet between 1 and 2 above — revisit alongside 1b.3/1b.4.

**Direction evaluated (2026-09-04), still deferred: [Spike 12](../spikes/spike-12-authenticated-asset-fetch.md)**
develops Option 1 further and recommends a specific mechanism if this is ever built:
solr-proxy writes a `mandala_solr_sid:{sid}` → uid key into the same shared Redis
instance `mandala_solr_visibility` already writes `mandala_solr_fq:{uid}` into (ADR
014), rather than a new synchronous cross-service HTTP endpoint — reuses live,
already-proven infrastructure instead of a fourth trust relationship. **Still not
implemented and still not recommended to build now** — low blast radius stands
(Images is mostly public collections); revisit when a real consumer needs it.

## Cross-references

- [Spike 6](../spikes/spike-06-api-compatibility.md) — where this was found, building
  `mandala_node_api`
- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the Redis-token pattern this gap is related
  to but doesn't directly solve
- [mandala-wp-proxy-json-proxy-open-ssrf.md](mandala-wp-proxy-json-proxy-open-ssrf.md) — the
  same `json_proxy` handler's other open issue
- 1b.3 (Solr-proxy visibility coherence) / 1b.4 (paragraph access inheritance) — the existing
  Sprint 1 tasks this gap is a specific instance of
