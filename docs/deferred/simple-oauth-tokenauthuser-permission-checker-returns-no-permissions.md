# OAuth2 tokens get zero effective permissions — the `openid` scope has no permission grant configured

**Area:** simple_oauth / OAuth2 scope configuration / ADR 014 authenticated path
**Raised during:** Session 2026-08-19 (re-verifying the two 2026-08-18 OAuth2 defects after fixing both)
**Jira:** (add when available)
**Priority:** High — blocks the entire OAuth2-authenticated path (proxy UserInfo call, and by extension anything else that authenticates via a `simple_oauth` Bearer token) regardless of the two defects fixed this session
**Status:** 🟡 The scope-permission fix itself is CONFIRMED CORRECT live on dev-0 (2026-08-19) — `TokenAuthUser->hasPermission('access content')` now YES, route access ALLOWED, both verified directly, not assumed. But the live end-to-end call still fails, for a **fourth**, distinct, not-yet-root-caused reason (a session-handling redirect loop) — see the bottom of the Fix section

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

## Root cause, confirmed by reading the actual mechanism (not assumption)

Isolated step by step via `drush php:eval` against the real token entity involved
(id 16, `auth_user_id=600`):

```
Oauth2Token::getRoles():                              []              <- expected, see below
TokenAuthUser::getRoles():                             ["authenticated"]  <- correct
TokenAuthUser->hasPermission("access content"):        NO
permission_checker->hasPermission(..., $realUserEntity): YES  <- same permission, same role, real User entity instead of the decorator
```

So role resolution is correct, but the *permission* computed for the exact same
effective role differs depending on whether the account is a real `User` entity or
`simple_oauth`'s `TokenAuthUser` decorator. Reading `simple_oauth` 6.1.1's source
explains why — this is a **deliberate security fix**, not a bug:

`Drupal\simple_oauth\Access\Oauth2AccessPolicy::alterPermissions()` (registered as a
Drupal core `AccessPolicy` plugin) runs for every `TokenAuthUser` request and does this:

```php
$oauth2_scopes = $token->get('scopes')->getScopes();
$allowed_permissions = [];
foreach ($oauth2_scopes as $oauth2_scope) {
    $allowed_permissions = array_merge($allowed_permissions, $this->scopeProvider->getPermissions($oauth2_scope));
}
foreach ($calculated_permissions->getItems() as $item) {
    $permissions = $token->get('auth_user_id')->isEmpty()
        ? $allowed_permissions
        : array_intersect($item->getPermissions(), $allowed_permissions);   // <-- here
    ...
}
```

The user's real role permissions (correctly computed by a companion policy,
`DecoratedUserRolesAccessPolicy`, added specifically to fix
**SA-CONTRIB-2025-114**) get **intersected with `$allowed_permissions`** — the union of
whatever each of the token's granted OAuth2 *scopes* explicitly confers via a
`ScopeGranularity` plugin. Our token's only scope is `openid`, whose config
(`simple_oauth.oauth2_scope.openid.yml`) is:

```yaml
umbrella: true
granularity_id: null
granularity_configuration: null
```

No granularity plugin configured → `$allowed_permissions` for that scope is `[]` →
the intersection with the user's real permissions is **always empty**, for any user,
regardless of their actual roles. This is exactly right per OIDC semantics — `openid`
is an *identity* scope (`description: "OpenID Connect: identifies the authenticated
Drupal user (sub = uid)"`, Spike 10's original design), not an *authorization* scope —
but nothing was ever configured to additionally grant the permissions that
`/oauth/userinfo`'s route (or anything downstream of it) actually requires.

Two granularity plugins exist and are usable today: `Permission`
(`web/modules/contrib/simple_oauth/src/Plugin/ScopeGranularity/Permission.php` —
grants exactly one named permission) and `Role` (grants a whole role's permissions).
Neither is wired up on any scope in this environment.

**Versions:** `drupal/core` 11.3.11, `drupal/simple_oauth` 6.1.1, `drupal/consumers`
1.24.0.

## What this does NOT affect

- Both 2026-08-18 defects' fixes are confirmed working exactly as designed — this is a
  **third**, independent blocker found only because those two are now fixed and the chain
  runs far enough to reach it.
- The OAuth2 authorization-code exchange (`/oauth/authorize` → `/oauth/token`) is
  unaffected — that path doesn't go through scope-permission intersection.
- Not a mandala or Drupal-core bug, and not something introduced by either of today's
  two fixes — this scope-configuration gap has existed since Spike 10 first wired up
  `openid`; it was simply never exercised against a route with a real permission
  requirement until this session's live UserInfo call.

## Fix — DECIDED and applied 2026-08-19 (Yuji)

**Reuse the `openid` scope** — the pattern already used elsewhere in this project,
rather than introducing a second scope. `simple_oauth.oauth2_scope.openid.yml` now
carries:

```yaml
umbrella: false
granularity_id: permission
granularity_configuration:
  permission: 'access content'
```

**The `umbrella: false` line matters and was not part of the first attempt.**
`Oauth2ScopeProvider::getPermissions($scope)` — the method `Oauth2AccessPolicy`
actually calls, not `Oauth2Scope::getPermissions()` — checks `isUmbrella()` first: if
true, it ignores the scope's own granularity entirely and instead unions permissions
from *child* scopes (of which `openid` has none), silently making any granularity
config on an umbrella scope dead code on this path. The module's own admin form
enforces this by force-nulling `granularity_id`/`granularity_configuration` whenever
`umbrella` is checked — hand-editing the YAML bypassed that UI constraint but not the
functional one. First attempt (granularity only, `umbrella` left `true`) tested as
correct in DDEV via `Oauth2Scope::getPermissions()` — the wrong method to test against —
and failed identically live. Retested using `Oauth2ScopeProvider::getPermissions()`
(the real call path) after adding `umbrella: false`; both entity and provider methods
now agree, returning `["access content"]`.

**Tested directly on dev-0 first, via `drush config:set --input-format=yaml` (not a
full pipeline redeploy)** — per Yuji's request, to iterate faster than a ~6-8 minute
pipeline cycle per attempt. Confirmed with hard evidence against the real token from a
live SAML→OAuth2 walkthrough (not a synthetic token): `TokenAuthUser->hasPermission('access
content')` → **YES** (was NO before the `umbrella: false` fix), and
`\Drupal::service('access_manager')->checkNamedRoute('simple_oauth.userinfo', ..., $account)`
→ **isAllowed: YES**. This fix is genuinely correct and necessary — confirmed at the
Drupal access-control layer, not assumed.

**But the live end-to-end `/oauth/userinfo` HTTP request still doesn't return JSON —
a fourth, distinct issue.** With this fix live, the failure mode changed: watchdog no
longer logs an `access denied` entry (consistent with access now correctly being
allowed) — instead it logs repeated `Session closed for [uid 600]` /
`session_destroy(): Trying to destroy uninitialized session` pairs, and the HTTP
response is still a redirect loop bouncing between `/oauth/userinfo` and `/`
(`GuzzleHttp\Exception\TooManyRedirectsException` after 5 hops on the proxy side).
This looks like something in Drupal's session-handling layer reacting badly to a
*stateless* Bearer-authenticated request that resolves to a real user identity with no
matching session cookie — repeatedly treating it as a logout event. Not yet
root-caused; a genuinely new problem layered under this one, only reachable now that
this scope-permission fix works. **Next-session starting point.**

If a permission beyond `access content` turns out to be needed once the Redis
visibility-token path is exercised, extend the same `granularity_configuration`
(`Permission` grants exactly one permission per config — for more than one, switch to
`Role` granularity instead, or reconsider a second scope at that point).

## Related

- [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md)
- [solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md) — both fixed and verified working this session; this note is the next blocker in the same chain
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [Spike 10 findings](../spikes/spike-10-saml-oauth2-coexistence.md) — original `openid` scope design intent
- `docs/session-logs/2026-08-19-oauth2-fixes-deployed-and-third-defect-found.md`
