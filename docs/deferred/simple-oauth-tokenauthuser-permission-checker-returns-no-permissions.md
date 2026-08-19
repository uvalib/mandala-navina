# `TokenAuthUser` gets zero effective permissions from `permission_checker`, even with correct roles

**Area:** simple_oauth / Drupal core Access Policy API / OAuth2 authenticated path
**Raised during:** Session 2026-08-19 (re-verifying the two 2026-08-18 OAuth2 defects after fixing both)
**Jira:** (add when available)
**Priority:** High — blocks the entire OAuth2-authenticated path (proxy UserInfo call, and by extension anything else that authenticates via a `simple_oauth` Bearer token) regardless of the two defects fixed this session

## Issue

With both prior defects fixed and deployed —
[oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md) and
[solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md)
— the full SAML → OAuth2 → `/oauth/userinfo` chain was re-run live against dev-0 with the
real non-admin test user (uid 600, linked via the `staff` test identity). Both fixes work
exactly as intended: `solr-proxy`'s `auth.php` now sends a correct `Authorization: Bearer
<jwt>` header (confirmed via temporary debug logging — a well-formed JWT with `sub:"600"`,
`aud:"solrproxy"`, `scope:["openid"]`), and Drupal's `simple_oauth` **does** authenticate
it — the response carries `X-Consumer-ID: solrproxy`, proving the resource server validated
the token and identified the consumer.

But the request still doesn't return JSON. Instead: `302` redirect to `/`, with an HTML
meta-refresh body ("Redirecting to /"). Drupal's watchdog logs an `access denied` entry for
**path `/`** (not `/oauth/userinfo`) with the message *"The 'access content' permission is
required."*

## Root cause, confirmed via direct testing (not assumption)

`simple_oauth`'s `UserInfo` controller
(`web/modules/contrib/simple_oauth/src/Controller/UserInfo.php`) requires
`$this->user instanceof TokenAuthUser`, and — more importantly — anything downstream that
checks the resulting account's permissions goes through
`Drupal\simple_oauth\Authentication\TokenAuthUser`, a **decorator** implementing
`AccountInterface` but wrapping a real `User` entity rather than being one.

Isolated the exact failure point via `drush php:eval` against the real token entity
(id 16, `auth_user_id=600`, the one issued during this exact test run):

```
token roles: []                                    <- Oauth2Token::getRoles() is empty (expected: "openid" scope is `umbrella: true` with no granularity/role-granting config, per Spike 10's design)
user roles: ["authenticated"]                       <- the real Drupal user's own roles, correct
user status (active): 1
TokenAuthUser roles: ["authenticated"]               <- TokenAuthUser::getRoles() correctly intersects token+user roles, resolves to the expected role
TokenAuthUser hasPermission("access content"): NO    <- but permission_checker denies it anyway
```

Then isolated further, comparing the **same permission_checker service** against a real
`User` entity with the identical role:

```
real user hasPermission("access content") via $user->hasPermission(): YES
permission_checker->hasPermission("access content", $realUser):        YES
permission_checker->hasPermission("access content", $tokenAuthUser):    NO   <- same permission, same effective role, different account object
```

**`TokenAuthUser::getRoles()` is correct.** The defect is in
`Drupal\Core\Session\PermissionChecker::hasPermission()` →
`AccessPolicyProcessorInterface::processAccessPolicies($account)`, Drupal core's
Access Policy API (D10.2+) — it computes an empty/negative permission set for a
`TokenAuthUser` decorator despite `getRoles()` returning `["authenticated"]`, identical to
what a real `User` entity with the same role correctly resolves permissions from.

**Versions:** `drupal/core` 11.3.11, `drupal/simple_oauth` 6.1.1, `drupal/consumers`
1.24.0. Not yet checked against simple_oauth's issue queue for a known
incompatibility with core's Access Policy API — worth checking before attempting a fix.

## What this does NOT affect

- Both 2026-08-18 defects' fixes are confirmed working exactly as designed — this is a
  **third**, independent blocker found only because those two are now fixed and the chain
  runs far enough to reach it.
- The OAuth2 authorization-code exchange (`/oauth/authorize` → `/oauth/token`) is
  unaffected — that path doesn't go through `TokenAuthUser`'s permission checking.
- No mandala custom code is involved — no custom `AccessPolicy` plugin exists in this
  codebase (checked). This is `simple_oauth`/Drupal core interaction, not a mandala bug.

## Fix options (not yet investigated)

1. Check `drupal/simple_oauth`'s issue queue for a known Access Policy API
   incompatibility — 6.1.1 may predate proper support, or there may be a required companion
   config/patch.
2. Debug `AccessPolicyProcessor::processAccessPolicies()` directly against a
   `TokenAuthUser` instance to find which registered `AccessPolicyInterface` plugin is
   returning an empty/forbidding result for it specifically (likely the core `PermissionAccessPolicy`
   plugin doing a type check or role-lookup that doesn't recognize the decorator).
3. Consider whether upgrading/downgrading `simple_oauth` resolves it, once (1) is checked.

## Related

- [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md)
- [solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md) — both fixed and verified working this session; this note is the next blocker in the same chain
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- `docs/session-logs/2026-08-19-...md` (session log for this session, to be written)
