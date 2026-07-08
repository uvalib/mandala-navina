# Spike 10: SAML + OAuth2 coexistence on Drupal 11

**Status:** ○ Pending
**Lead:** Yuji Shinozaki
**Mode:** Individual (blocks 1b.1 mob work)
**Date:** —
**Branch/commit:** —

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

**Blocks:** 1b.1 implementation (proxy fork, OAuth2 client registration, Redis
visibility token write hooks). Do not begin 1b.1 code until pass criteria 1–3 are
confirmed.

**Does not block:** 1b.2 (Group collections + OG migration) — that work is
independent and can run in parallel.
