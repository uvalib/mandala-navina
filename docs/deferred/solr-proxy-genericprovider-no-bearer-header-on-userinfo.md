# solr-proxy's OAuth2 client never sends a Bearer header to `/oauth/userinfo`

**Area:** solr-proxy / OAuth2 client / ADR 014 authenticated path
**Raised during:** Session 2026-08-18 (first real browser walkthrough of the SAML → OAuth2 → proxy chain)
**Jira:** (add when available)
**Priority:** High — blocks the authenticated (private-collection) path through the proxy entirely; the public/anonymous path is unaffected

## Issue

Logging into Drupal via SAML and completing the OAuth2 authorization-code flow against
`solrproxy` (`/oauth/authorize` → `/oauth/token`) works correctly end to end — the proxy's
`auth.php` gets a real access token back with `HTTP 200`. But the very next call,
`getResourceOwner()` (which hits `/oauth/userinfo` to read the `sub` claim), fails:

```
Unexpected Value Invalid response received from Authorization Server. Expected JSON.
```

Access log for that request shows why — it's not a JSON error response, it's a redirect:

```
POST /oauth/token       -> 200  (token exchange succeeded)
GET  /oauth/UserInfo    -> 302  (redirected to /user/login)
```

A `302` to Drupal's login page means the request was treated as **fully anonymous** — not
"bad token", but "no credentials presented at all".

## Why

`auth.php` constructs the OAuth2 client as a vanilla
`League\OAuth2\Client\Provider\GenericProvider`. In `league/oauth2-client`, the base
`AbstractProvider::getAuthorizationHeaders($token)` — the method responsible for adding
`Authorization: Bearer <token>` to authenticated requests — has **no default implementation**
and returns `[]`:

```php
// AbstractProvider.php
protected function getAuthorizationHeaders($token = null)
{
    return [];
}
```

Provider-specific subclasses (Google, GitHub, etc.) override this to add the Bearer header.
`GenericProvider` does **not** override it. So `fetchResourceOwnerDetails()` →
`getAuthenticatedRequest()` sends the `/oauth/userinfo` request with no `Authorization`
header whatsoever. Drupal's `simple_oauth.userinfo` route requires `_role: authenticated`
with `_auth: ['oauth2']`; with no Bearer token to authenticate against, the request is
anonymous, and Drupal's default anonymous-access-denied handling for an HTML-negotiated
route is a redirect to `/user/login`, not a JSON 403.

Confirmed empirically on dev-0 (not just from source):
- A garbage `Authorization: Bearer garbage-token-value` header → clean `401` from the
  oauth2 auth provider (proves the route *does* consume the header when one is sent, and
  case sensitivity in `/oauth/UserInfo` vs `/oauth/userinfo` is a red herring — both
  behave identically once a header is present).
- The real access token issued to a real authenticated non-admin test user (uid 600, via
  the new dev test-IdP — see below) is well-formed in the `oauth2_token` entity table
  (`type=access_token`, correct `client=2` (`solrproxy`), correct `user=600`,
  10-minute expiry) — the token itself isn't the problem, it's simply never sent.

**Not a Drupal/simple_oauth bug.** The endpoint paths are already correct
(`$OAUTH_ROOT` = `/oauth`, not `/oauth2`, per Spike 10's finding). This is purely
missing client-side behavior in how the proxy uses `GenericProvider`.

## Why this has never been caught before

Per the project's own notes (`project-solr-proxy-cicd` session log, 2026-08-12/13), the
real browser-driven OAuth2 flow — SAML login → `/oauth/authorize` → proxy → `/oauth/userinfo`
— had **never been exercised end to end**. All prior validation either used a synthetic
Redis token written by hand, or stopped short of a real browser session. This session was
the first time anyone actually walked the whole chain with a real user, and it surfaced
immediately.

## Fix options (not yet decided)

1. Subclass `GenericProvider` (or configure it, if the installed version supports an
   option for this) to override `getAuthorizationHeaders()`:
   ```php
   protected function getAuthorizationHeaders($token = null)
   {
       return $token ? ['Authorization' => 'Bearer ' . (string) $token] : [];
   }
   ```
2. Or bypass `getResourceOwner()` entirely and issue the `/oauth/userinfo` GET manually
   with an explicit `Authorization: Bearer` header in `auth.php`.

Either is a small, contained change in `auth.php`/wherever the provider is instantiated —
this is the `uvalib/mandala-solr-proxy` repo, not this monorepo.

## What's already proven and doesn't need re-testing

- SAML login through the new dev test-IdP → real Drupal session (non-admin user, uid 600).
- `/oauth/authorize` → `/oauth/token` full authorization-code exchange, with a real
  `solrproxy` client secret, real `state` round-trip, real `automatic_authorization`.
- The issued access token is correctly scoped to the right user/client in Drupal's own
  token storage.

Only the client-side "fetch resource owner details" step is broken. Once fixed, the
remaining unproven link is the proxy's own Redis visibility-token read for a *real*
OAuth2-authenticated session (previously only proven with a hand-written Redis key, per
`project-solr-proxy-cicd`'s 2026-08-13 note) — that should be the next thing to check
once this is fixed.

## Related

- [Spike 10 findings](../spikes/spike-10-saml-oauth2-coexistence.md) — endpoint paths,
  `openid`-on-`client_credentials` crash, `automatic_authorization` permission requirement
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [oauth-openid-scope-client-credentials-crash.md](oauth-openid-scope-client-credentials-crash.md) — a different simple_oauth OAuth2 gap found the same week as Spike 10
- [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md) — a second, independent OAuth2 defect found in the same session, fixed live on dev-0 but not yet fixed at the infrastructure level
