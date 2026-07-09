# `openid` scope on `client_credentials` grant crashes (no end-user to resolve)

**Area:** auth / simple_oauth / OAuth2 (1b.1 proxy + OIDC)
**Raised during:** Spike 10 (SAML + OAuth2 coexistence)
**Jira:** (add when available)
**Priority:** Medium (implementation guardrail for 1b.1)

## Issue

Requesting the `openid` scope on a **`client_credentials`** token request throws a
fatal `AssertionError` (HTTP 500), not a graceful OAuth error:

```
AssertionError: Cannot load the "user" entity with NULL ID
  (assert(), EntityStorageBase.php:266)
  Drupal\simple_oauth\OpenIdConnect\UserIdentityProvider->getUserEntityByIdentifier()
  Drupal\simple_oauth\OpenIdConnect\OpenIdConnectIdTokenResponse->getExtraParams()
  League\OAuth2\Server\ResponseTypes\BearerTokenResponse->generateHttpResponse()
```

Observed on `simple_oauth 6.1.1`, D11 / PHP 8.3.

## Why

The `openid` scope switches the token response to `OpenIdConnectIdTokenResponse`, which
mints an OIDC **id_token** describing *the end user*. To do that it calls
`getUserEntityByIdentifier($accessToken->getUserIdentifier())` and loads that Drupal
user. `client_credentials` is a machine-to-machine grant with **no end user**, so the
token's user identifier is `NULL` → `User::load(NULL)` asserts out.

This is semantically correct (OIDC = user authentication; `client_credentials` = userless);
simple_oauth just enforces it with a hard assertion instead of a clean error.

## Implication for 1b.1

- The **uid `sub` claim always originates from the user's Authorization Code token**
  (React KMaps app → D11), never from a service token. Proven in Spike 10: the
  Authorization Code flow returns `sub = <integer uid>` in the access token, id_token,
  and `/oauth/userinfo`.
- Any **service-to-service** token (e.g. Solr proxy → D11, if it ever needs its own
  client token) must use `client_credentials` and **must NOT request `openid`**. The
  proxy does not need a uid from its *own* token — it reads the uid from the *user's*
  token and looks up Redis (`mandala_solr_fq:{uid}`) by that.
- If a userless flow ever legitimately requests `openid`, guard it (omit the scope, or
  patch simple_oauth to return `invalid_request` instead of asserting).

## Related

- [Spike 10 findings](../spikes/spike-10-saml-oauth2-coexistence.md)
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- Endpoint paths in `simple_oauth 6.x` are `/oauth/token|authorize|userinfo|jwks`
  (not `/oauth2/*`) — noted in the Spike 10 findings for the same 1b.1 work.
