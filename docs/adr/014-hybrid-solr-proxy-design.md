# ADR 014: Hybrid Solr proxy design — Drupal-written Redis visibility tokens, lightweight standalone proxy

**Status:** Accepted
**Date:** 2026-07-08
**Deciders:** Than Grove, Yuji Shinozaki (Lead Architect)
**Relates to:** [ADR 013](013-drupal-source-of-truth-solr-client-compatibility.md) (Drupal is source of truth),
[ADR 011](011-group-collections-inheritance.md) (Group collections inheritance),
[Sprint 1 Step 1b](../sprints/sprint-01-images-implementation.md) (1b.1 consumes this)

## Context

The legacy Mandala Solr proxy (`shanti-uva/mandala-solr-proxy`) enforces visibility
filtering on Solr queries so that direct-Solr clients (the React KMaps app) see only
content they are authorized to see. The proxy's intent is correct: Solr clients should
follow the same authorization as Drupal clients.

However, reading the proxy code in light of [ADR 013](013-drupal-source-of-truth-solr-client-compatibility.md)
reveals a structural problem:

**The current proxy has a circular dependency on Solr for auth decisions.** After
OAuth2 login, the proxy calls Solr itself — `members_uid_ss:user-{uid}` on the
`kmassets` index — to load the user's private collections into its own PHP session.
It then uses that list to build the visibility `fq` filter on subsequent queries.
This means access control correctness depends on `kmassets` being accurate —
but `kmassets` is the very index the proxy is protecting. If `mandala_kmassets_sync`
is behind, users may see content they should not, or be denied content they should see.

Under ADR 013, Drupal is the source of truth for access. Collection membership
lives authoritatively in Drupal's Group module DB tables — not in Solr.

**Three approaches were considered:**

| Approach | Correctness | Performance | Infrastructure |
|---|---|---|---|
| Current standalone proxy | ⚠ Depends on kmassets sync | Low (session cache) | Separate service |
| Full Drupal-as-proxy | ✅ Always authoritative | High (full Drupal bootstrap per Solr query) | Drupal only |
| Hybrid (this ADR) | ✅ Always authoritative | Low (Redis read per query) | Proxy + Redis |

Full Drupal-as-proxy was rejected on performance grounds: every Solr query — including
the multiple parallel queries the React app fires per user interaction — would require
a full Drupal PHP bootstrap. For a search-heavy interface this overhead is prohibitive.

## Decision

**Adopt a hybrid design:**

1. **Drupal writes a per-user visibility token to Redis** whenever a user's access
   state changes: on login, on Group membership change, and on logout/session expiry.
   The token is the complete Solr `fq` string for that user, derived from Drupal's
   authoritative Group membership data. Key format: `mandala_solr_fq:{uid}`.

2. **The standalone proxy reads the Redis token** for authenticated queries. The
   proxy session stores only the Drupal uid (obtained via OAuth2 at login). On each
   Solr request, the proxy fetches `mandala_solr_fq:{uid}` from Redis and injects it
   as the visibility `fq`. No Solr query is made for membership — the circular
   dependency is eliminated.

3. **Anonymous queries are unchanged.** The proxy applies the static filter
   `(visibility_i:1 OR asset_type:(places subjects terms))` with no Redis lookup
   and no Drupal involvement.

4. **The D11 proxy is forked into the monorepo** at `solr-proxy/`. The original
   `shanti-uva/mandala-solr-proxy` remains as the D7 proxy, unchanged. The two
   codebases diverge cleanly: D7 proxy serves D7 sites; D11 proxy serves D11.

5. **D11 exposes an OAuth2 server** (via `simple_oauth`) so the proxy and the
   React KMaps app can obtain access tokens identifying the Drupal user. The proxy's
   OAuth2 client flow targets D11's `/oauth2/*` endpoints instead of D7's.

### Visibility token structure

The `fq` string written to Redis follows the same logic as the current proxy's
`setVisibility()`, but sourced entirely from Drupal:

```
(
  visibility_i:(1 3)                          -- non-private records
  OR asset_type:(places subjects terms)        -- KMaps taxonomy (always visible)
  OR node_user_i:{uid}                         -- content owned by this user
  OR members_uid_ss:user-{uid}                 -- collections this user is a member of
  OR collection_uid_s:({coll-uid-1} {coll-uid-2} ...)  -- assets in those collections
)
```

Drupal computes this string from Group relationships and writes it to Redis with a TTL
of 1 hour. Explicit invalidation (re-write) occurs on Group membership change events,
ensuring access changes take effect immediately without waiting for TTL expiry.

uid=1 (Drupal admin) receives no filter — the token is omitted and the proxy applies
no visibility `fq`, matching the current proxy's behaviour.

### React app flow

The React KMaps app's external behaviour is **unchanged**:

1. App initiates OAuth2 flow → D11's `/oauth2/authorize` (was D7's) → receives
   access token encoding D11 uid
2. App passes token to proxy on every Solr query (existing `?sid=` mechanism or
   `Authorization: Bearer`)
3. Proxy validates token → extracts uid → reads Redis `mandala_solr_fq:{uid}` →
   injects `fq` → forwards to Solr

From the React app's perspective this is a configuration change (endpoint URL from
D7 to D11), not a protocol change.

### D11 infrastructure components confirmed by this ADR

This ADR depends on three infrastructure components that are confirmed for the D11
platform:

| Component | Purpose |
|---|---|
| **Redis** | Shared cache between Drupal and proxy; stores visibility tokens |
| **`simplesamlphp_auth`** | User authentication via UVA SAML identity provider (Shibboleth); establishes Drupal sessions from SAML assertions |
| **`simple_oauth`** | OAuth2 server in D11; issues access tokens for proxy and React app client flows |

### Open question flagged for a follow-on ADR

**SAML + OAuth2 coexistence:** users who authenticate via `simplesamlphp_auth`
(SAML/Shibboleth) must also be issuable OAuth2 tokens by `simple_oauth` so the proxy
and React app can identify them. `simple_oauth` supports this pattern (OAuth2
authorization against an existing Drupal session), but the exact configuration and
whether UVA's IDP requires any special handling should be confirmed before
implementation begins. A follow-on ADR or spike will cover the auth stack design.

## Consequences

- **`mandala_kmassets_sync` obligation clarified.** `visibility_i`, `node_user_i`,
  `members_uid_ss`, and `collection_uid_s` in kmassets are now **client compatibility
  fields** (for the D7 proxy and any direct-Solr clients that read them). They are
  not used by the D11 proxy for access decisions. D11 access decisions come from
  Drupal → Redis. The sync obligation (ADR 013) still applies — these fields must be
  accurate for D7 clients and for any non-proxy Solr consumers.

- **1b.1 work breakdown:**
  1. Fork proxy into `solr-proxy/`; wire `$OAUTH_ROOT` to D11; replace
     `setCollections()` with Redis read
  2. Install and configure `simple_oauth` in D11; register proxy as OAuth2 client
  3. Drupal event hooks: write/invalidate `mandala_solr_fq:{uid}` on login, Group
     membership change, logout
  4. Confirm `simplesamlphp_auth` + `simple_oauth` coexistence (follow-on spike)

- **Access control freshness improves.** The current proxy caches `mcolls` for
  up to 1 hour. The hybrid invalidates the Redis token immediately on Group
  membership change — access changes take effect on the next Solr query.

- **Redis is a new shared infrastructure dependency.** Both Drupal and the proxy
  must reach the same Redis instance. Failure of Redis degrades authenticated search
  (proxy falls back to anonymous filter) but does not break public access or Drupal
  page rendering.

- **D7 proxy is unaffected.** `shanti-uva/mandala-solr-proxy` continues to serve
  D7 sites unchanged. No cross-pollination between D7 and D11 proxy codebases after
  the fork.
