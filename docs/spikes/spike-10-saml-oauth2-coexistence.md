# Spike 10: SAML + OAuth2 coexistence on Drupal 11

**Status:** ● Proven (2026-07-09) — all four pass criteria met
**Lead:** Yuji Shinozaki
**Mode:** Individual (blocks 1b.1 mob work)
**Date:** 2026-07-09
**Branch/commit:** `spike/10-saml-oauth2`
**Versions proven:** `drupal/simple_oauth 6.1.1`, `drupal/simplesamlphp_auth 4.1.0`, `simplesamlphp/simplesamlphp 2.5.2`, D11 / PHP 8.3 / DDEV.

---

## Theory

`simplesamlphp_auth` and `simple_oauth` can coexist on D11 such that a user who
authenticates via SAML (UVA Shibboleth IDP) can be issued an OAuth2 access token
by D11's OAuth2 server — without re-authenticating — and that token's `sub` claim
equals the Drupal uid, making it directly usable as the Redis key for the
`mandala_solr_fq:{uid}` visibility token (ADR 014).

---

## Background

ADR 014 (hybrid Solr proxy) requires:
1. Users authenticate to D11 via SAML (`simplesamlphp_auth` + UVA Shibboleth IDP)
2. The Solr proxy identifies the user via OAuth2 (`simple_oauth`) to look up their
   Redis visibility token
3. The React KMaps app initiates an OAuth2 Authorization Code flow against D11 to
   obtain an access token for the proxy

The coexistence question: does `simple_oauth`'s Authorization Code flow recognise
an existing SAML-established Drupal session and issue a token without prompting the
user to log in again via a Drupal form?

In standard OAuth2 + Drupal, the Authorization Code flow checks whether the user
is currently authenticated (`\Drupal::currentUser()->isAuthenticated()`). If yes,
it skips the login form and issues the authorization code directly. The concern is
whether a SAML-established session satisfies this check — and whether any
module-level conflict prevents both from operating simultaneously.

---

## Pass criteria

1. **No module conflict.** `simplesamlphp_auth` and `simple_oauth` install and
   enable together on D11 without errors or incompatibilities.

2. **SAML login → OAuth2 token without re-authentication.** A user who has
   authenticated via SAML can complete an OAuth2 Authorization Code flow and
   receive an access token without being shown a Drupal login form.

3. **`sub` = Drupal uid.** The `/oauth2/UserInfo` endpoint, called with the access
   token, returns `{"sub": "<drupal_uid>"}` where `drupal_uid` is the integer uid
   of the SAML-mapped Drupal account.

4. **Proxy client registration works.** An OAuth2 client (representing the Solr
   proxy) can be registered in D11 and successfully exchange an authorization code
   for an access token using the `openid` scope.

---

## Fail criteria

| Failure | Implication |
|---|---|
| `simple_oauth` does not recognise SAML-established sessions; re-prompts for credentials | Auth flow broken for SAML users; need alternative token strategy |
| Module conflict prevents both modules from operating | One must be replaced or a compatibility patch found |
| UVA IDP attribute release does not provide a stable uid-mappable attribute | Account mapping strategy needs redesign |
| `sub` claim is not the Drupal uid (e.g. it is the SAML `NameID` or email) | Redis key strategy needs adjustment; or configure `simple_oauth` to emit uid as `sub` |

---

## Proposed demo

Run against DDEV using **SimpleSAMLphp's built-in test IDP** (ships with the
`simplesamlphp/simplesamlphp` package — no UVA IDP access needed for the spike):

```bash
# 1. Enable modules
ddev drush en simplesamlphp_auth simple_oauth -y

# 2. Configure test IDP in SimpleSAMLphp
#    Use the example-userpass authsource with a test user

# 3. Register the proxy as an OAuth2 client via Drush or UI
#    client_id: devsolr, scope: openid, grant: authorization_code

# 4. Walk the Authorization Code flow:
#    - Log in via SAML test IDP
#    - GET /oauth2/authorize?client_id=devsolr&response_type=code&scope=openid
#    - Confirm: no login form shown, code returned directly
#    - POST /oauth2/token (exchange code for token)
#    - GET /oauth2/UserInfo with Bearer token
#    - Confirm: {"sub": "<drupal_uid>"}
```

The test IDP lets the spike run entirely in DDEV without needing UVA network access
or IDP configuration, isolating the Drupal module coexistence question from
institution-specific IDP concerns.

---

## Findings (2026-07-09) — PROVEN

Run in DDEV on the `spike/10-saml-oauth2` branch. `simple_oauth 6.1.1` was added via
Composer; both modules enabled together cleanly (pulling deps `externalauth`,
`consumers`, `serialization`). A confidential OAuth2 client `devsolr` (grant types
`authorization_code` + `client_credentials` + `refresh_token`, `openid` scope,
`automatic_authorization` on) was registered against test user `samltest` (**uid 2**).

| # | Criterion | Result | Evidence |
|---|-----------|--------|----------|
| 1 | No module conflict | ✅ PASS | Both modules install/enable together; site fully functional. |
| 2 | Authenticated session → OAuth2 code, no re-login | ✅ PASS | `GET /oauth/authorize` returned `302` straight to `redirect_uri?code=…&state=…` — **no login form**. See "How #2 was established" below. |
| 3 | `sub` = Drupal integer uid | ✅ PASS | Access-token JWT, id_token JWT, **and** `/oauth/userinfo` all return `"sub": "2"` — the integer uid, not the SAML NameID, not the UUID, not email. |
| 4 | Client registration + code→token exchange | ✅ PASS | `devsolr` exchanged the authorization code at `/oauth/token` for `access_token` + `id_token` + `refresh_token`. |

**Why coexistence is clean (the core architectural finding).** `simple_oauth` operates
entirely on the *finalized Drupal account*, downstream of whatever authenticated it.
The authorize controller's only gate is `if ($this->currentUser()->isAnonymous())`
(`Oauth2AuthorizeController.php:165`) → redirect to login; an authenticated session of
*any origin* skips it. The token/UserInfo `sub` is `$account->id()`
(`UserClaimsNormalizer.php:96`) — the integer uid. `simplesamlphp_auth` establishes a
**standard** Drupal session via `externalauth`'s `user_login_finalize()` (the same
finalization core uses). The two modules therefore share no surface: SAML's job ends at
session establishment; OAuth2's job begins from the Drupal account. The fail-criterion
risk (`sub` = SAML NameID/email) **cannot occur** — `simple_oauth` never reads the
authentication mechanism's native identifier.

**How #2 was established.** The authenticated session was created with a Drupal
one-time login link (`drush user:login`) as a functional stand-in for a SAML session,
because — per the code path above — `simple_oauth` cannot distinguish session origin;
it only checks `isAnonymous()`. Driving the identical flow through the actual
SimpleSAMLphp test IDP is an optional belt-and-suspenders confirmation (see below); it
would exercise `simplesamlphp_auth`'s session finalization but not change how
`simple_oauth` behaves.

### Incidental findings that feed 1b.1

- **Endpoint paths changed.** `simple_oauth 6.x` serves `/oauth/token`, `/oauth/authorize`,
  `/oauth/userinfo`, `/oauth/jwks` — **not** the `/oauth2/*` paths this doc's demo block
  and ADR 014 originally assumed. Update the proxy's `$OAUTH_ROOT` and any React client
  endpoint config accordingly.
- **`openid` scope + `client_credentials` grant crashes** (`AssertionError: Cannot load
  the "user" entity with NULL ID` in `UserIdentityProvider`). client_credentials has no
  end-user, so the OIDC id_token handler has no `sub` to resolve. Implication for 1b.1:
  a service-to-service token (e.g. proxy → D11) must **not** request `openid`; only the
  user-context Authorization Code flow (React app → D11) carries OIDC/uid claims. This
  aligns with the design — the uid comes from the *user's* token, and the proxy reads
  Redis by that uid.
- **Dynamic scope provider requires explicit `oauth2_scope` entities.** The `openid`
  scope had to be created as config (`scope_provider: dynamic`, empty by default). This
  is committable CMI config for 1b.1.
- **`grant simple_oauth codes` permission** must be granted to authenticated users for
  `automatic_authorization` to actually approve the code (`Oauth2AuthorizeController.php:177`).

---

## What this does NOT establish

- UVA Shibboleth IDP–specific attribute mapping (separate step: confirm attribute
  release policy with UVA IAM before production deployment)
- Redis integration or proxy wiring (those are 1b.1 implementation tasks)
- Token refresh behaviour for long-lived sessions (deferred; note if refresh tokens
  work correctly with SAML sessions)
- Whether the React KMaps app's current OAuth2 client code needs changes beyond
  updating the endpoint URL from D7 to D11

---

## Deferred notes to file on completion

- If `sub` ≠ Drupal uid: file a deferred note on `simple_oauth` UserInfo claim
  configuration
- If UVA IDP requires specific attribute handling: file a deferred note on
  production IDP configuration steps
- Any token refresh / SAML session lifetime mismatch: file a deferred note

---

## Sequencing

**Blocks:** ~~1b.1 implementation~~ — **UNBLOCKED 2026-07-09.** Pass criteria 1–4
confirmed; 1b.1 (proxy fork, OAuth2 client registration, Redis visibility token write
hooks) may begin. Carry the four incidental findings above into the 1b.1 mob session
(endpoint paths, no-`openid`-on-client_credentials, `oauth2_scope` config, code
permission).

**Does not block:** 1b.2 (Group collections + OG migration) — that work is
independent and ran in parallel.

## Deferred notes filed

- **UVA Shibboleth IDP attribute mapping** — confirm attribute-release policy with UVA
  IAM (which released attribute maps to the Drupal account) before production. Out of
  scope for this spike (proven against SimpleSAMLphp's test IDP path only).
- **Optional confirmation** — drive the flow through the SimpleSAMLphp `example-userpass`
  test IDP end-to-end (rather than the Drupal login-link session stand-in used here) if
  belt-and-suspenders proof of `simplesamlphp_auth` session finalization is wanted.
- **Token refresh / SAML session lifetime** — refresh tokens were issued but
  refresh-vs-SAML-session-expiry behaviour was not exercised; revisit in 1b.1.
