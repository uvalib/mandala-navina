# Spike 12: Authenticated asset-fetch identity forwarding (design only)

**Status:** ✅ Complete (design only, 2026-09-04) — a direction is recommended;
implementation is explicitly out of scope for this spike and remains open work
**Lead:** Than Grove (owner per the deferred note), Claude Code
**Mode:** Individual — design spike, no code changes
**Date:** 2026-09-04
**Relates to:**
[`d11-asset-endpoints-uniform-access-and-authenticated-fetch.md`](../deferred/d11-asset-endpoints-uniform-access-and-authenticated-fetch.md)
(§2, the half this spike resolves the design question for),
[`mandala-node-api-no-identity-forwarded-through-json-proxy.md`](../deferred/mandala-node-api-no-identity-forwarded-through-json-proxy.md)
(the original problem statement, "Option 1" of which this spike develops further),
[ADR 014](../adr/014-hybrid-solr-proxy-design.md) (the Redis visibility-token pattern
this design reuses), Spike 10 (proves `simple_oauth`'s `sub` claim = Drupal uid)

**Explicitly out of scope**, per the Sprint 2 D2 task definition: touching
`mandala-wp-proxy` (external repo), and implementing the recommended direction. This
document names a direction; it does not build it.

---

## The question

`mandala_node_api` (and any future D11 endpoint of the same shape) correctly enforces
`node->access('view')` per [Workstream D1's convention](../planning/entity-access-endpoint-convention.md)
— but every request reaching it through the decided Option A path (client →
`mandala-wp-proxy`'s `json_proxy` → this controller) arrives with **no identity at
all**, so it can only ever resolve to anonymous access. **Can a caller's real Drupal uid
be verified using only the `sid` cookie already available to the client, without
trusting a client-supplied uid directly and without inventing a brand-new cross-service
trust channel?**

## Confirmed facts (not assumptions)

- **The React client never holds an OAuth2 bearer token.** Read `mandala-om`'s
  `MandalaSession.js`/`useSolr.js`/`useMandala.js` directly (`grep -rniE
  "access_token|bearer|authorization"` — zero matches). After OAuth2 login, the browser
  only ever receives two plain cookies: `solrsid` (solr-proxy's own PHP session id) and
  `muid` (the uid, plaintext).
- **The real token is captured and held server-side only**, inside
  `solr-proxy/proxy/auth.php`'s PHP session (`$_SESSION['muid'] = $info['sub']`, keyed
  by that same session id) — confirmed by reading the file directly in this repo.
  Spike 10 already proved `sub` is the real Drupal uid, not a separate OAuth-side
  identifier.
- **`json_proxy` (the `mandala-wp-proxy` handler) forwards nothing** — its
  `wp_remote_get()` call sends no cookies, no `Authorization` header. Every request it
  relays is anonymous to whatever it targets, regardless of the actual browser session.
- **Trusting a client-supplied `uid` is a real spoofing hole, not a shortcut** — `muid`
  is a plain, browser-controlled cookie. This is the same class of client-trust problem
  ADR 014 already rejected for Solr visibility filtering, for the same reason.
- **ADR 014's Redis token doesn't transfer directly.** `mandala_solr_fq:{uid}` is
  legitimate proof of *what an already-verified uid may see* — it says nothing about
  *whether the browser's claimed uid is real* in the first place. It solves a different
  half of the problem.
- **The write side of ADR 014 is live in this repo today**, confirmed by reading it:
  `mandala_solr_visibility`'s `VisibilityTokenStore` (`drupal/web/modules/custom/
  mandala_solr_visibility/src/VisibilityTokenStore.php`) writes `mandala_solr_fq:{uid}`
  to a shared Redis instance via plain `\Redis` (phpredis), TTL-based, fails-closed and
  swallows errors on a Redis outage. `solr-proxy/proxy/Searcher.php` is the read side —
  it only ever calls `$redis->get()`, never writes.

## Options evaluated

### Option A — synchronous `sid`→uid resolution call (the original deferred note's proposal)

Drupal (or `mandala-wp-proxy`) makes a trusted, server-to-server HTTP call to a new
solr-proxy endpoint on every asset fetch, passing the client's `sid`; the endpoint looks
up its own PHP session store and returns the uid if the session is valid.

- **Correct in principle** — `sid` is genuinely a valid trust anchor, since only
  `auth.php`'s own server-side session store can resolve it to a real uid; nothing
  client-controlled is trusted.
- **Real costs, not yet resolved**: a new authenticated endpoint needs a shared secret
  or IP-allowlist between `mandala_node_api` and solr-proxy (a fourth trust
  relationship, on top of the three services already in play); a synchronous
  cross-service HTTP round-trip on every private-collection asset fetch (latency,
  and a new failure mode — what happens when solr-proxy is slow or down mid-request);
  and PHP's default session store is typically file-based per-instance, which doesn't
  necessarily generalize cleanly if solr-proxy is ever run as more than one instance
  (not currently the case, per `solr-proxy/docker-compose.yml`, but worth flagging).

### Option B — solr-proxy writes `sid`→uid into the same shared Redis ADR 014 already uses (recommended direction)

Extend `auth.php`'s login handler to also write a `mandala_solr_sid:{sid}` → `{uid}`
key into the same Redis instance `mandala_solr_visibility` already writes
`mandala_solr_fq:{uid}` into (confirmed shared infra per ADR 014's own components
table), with a TTL matching the PHP session lifetime. `mandala_node_api` then resolves
`sid` → uid with a plain Redis `GET` — the same read pattern the proxy's own
`Searcher.php` already uses for the visibility token, just in the other direction.

- **No new cross-service HTTP endpoint, no new shared secret.** Both sides already read
  and write to the same Redis instance for a closely related purpose; this adds one more
  key namespace, not a new trust relationship.
- **No added per-request latency class** — a Redis `GET` is the same cost D11 already
  pays reading `mandala_solr_fq:{uid}` for Solr queries elsewhere; it's the identical
  infrastructure call shape, just a different key.
- **Fails closed the same way the rest of this system already does** — a Redis miss (TTL
  expired, session logged out, Redis down) resolves to "no verified identity," which
  `mandala_node_api` should then treat as anonymous, exactly matching
  `VisibilityTokenStore`'s existing "swallow and log" failure philosophy. No new failure
  mode is introduced.
- **Real cost**: `auth.php` (currently PHP-session-only, no Redis client at all) needs a
  new Redis write call added — a small, scoped, well-understood change given
  `mandala_solr_visibility`'s existing implementation is a direct model to follow, but
  it is still new code in `solr-proxy` that this spike does not write.

### Option C — scope as a known limitation, unchanged (the original note's fallback)

Leave private-collection asset detail fetches public-only-visible through the React
app's node-JSON path, consistent with this system's existing fail-closed-to-public
philosophy elsewhere. Revisit only when it becomes a real user-facing gap.

## Recommendation

**Option B is the right direction if and when this is built** — it reuses
already-proven, already-live infrastructure (the same Redis instance, the same
key/TTL/fail-closed shape `mandala_solr_visibility` already implements) rather than
introducing a fourth inter-service trust relationship. It is a strictly better fit for
this codebase's established patterns than Option A.

**But per the existing deferred note's own priority note and Than's stated default**
("the D7 AJAX endpoints are low-importance and largely without consumers; the default
answer is no [new AJAX endpoints]"), **this spike does not recommend building Option B
now.** Images — the only migrated site today — is mostly public collections, so the
practical blast radius of the current public-only fallback (Option C, already in
effect) remains low. Revisit when either (a) a site actually needs a private-collection
detail-view endpoint reached through the Option A proxy path, or (b) enough
private-collection Images content migrates that the current gap becomes a real,
reported user complaint — not preemptively.

## What this spike does NOT establish

- No code was written or run; Option B is a design recommendation, not a proof.
- No changes to `mandala-wp-proxy` (external repo) — explicitly out of scope, per the
  D2 task definition.
- No confirmation of `auth.php`'s exact session lifetime/TTL value (needed to size the
  `mandala_solr_sid:{sid}` key's TTL correctly if Option B is ever implemented) — a
  concrete implementation detail for whoever picks this up, not resolved here.
- No decision on *whether* any site actually needs an authenticated AJAX/embed endpoint
  at all — that remains a per-site call, per the original deferred note's third open
  question.

## Sequencing

**Blocks:** nothing currently — Option C (the existing public-only fallback) remains in
effect and requires no action. **Unblocks:** a future implementation session, if/when a
site decides it needs one, now has a concrete, evaluated direction (Option B) to start
from instead of re-deriving the trust-model analysis from scratch.

## Deferred notes filed

None new — this spike's findings are folded directly into the two existing deferred
notes it relates to (updated alongside this spike, same session) rather than creating a
third overlapping note.
