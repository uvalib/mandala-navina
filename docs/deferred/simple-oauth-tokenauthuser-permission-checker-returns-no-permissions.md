# OAuth2 tokens get zero effective permissions — the `openid` scope has no permission grant configured

**Area:** simple_oauth / OAuth2 scope configuration / ADR 014 authenticated path
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

## Fix options (not yet decided)

1. **Add a `Permission` granularity to the `openid` scope itself**, granting `access
   content` (the specific permission the `/oauth/userinfo` route requires). Simplest,
   but muddies an identity-only scope with an authorization grant.
2. **Add a new, separate scope** (e.g. `solrproxy_access` or similar) configured with
   `Permission` granularity for `access content`, and have `solr-proxy`'s `auth.php`
   request it alongside `openid` (`'scope' => 'openid solrproxy_access'` in the
   `getAccessToken()`/authorization URL calls). Cleaner separation of identity vs.
   authorization scopes, but touches both the Drupal scope config and the proxy's
   requested-scope list.
3. Consider `Role` granularity instead of `Permission` if more than just `access
   content` ends up needed once the Redis visibility-token read (the next unproven
   link) is exercised for real.

Decision needs a human call on scope design before implementing — not purely a bug fix.

## Related

- [oauth2-signing-keys-not-persisted-across-deploy.md](oauth2-signing-keys-not-persisted-across-deploy.md)
- [solr-proxy-genericprovider-no-bearer-header-on-userinfo.md](solr-proxy-genericprovider-no-bearer-header-on-userinfo.md) — both fixed and verified working this session; this note is the next blocker in the same chain
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [Spike 10 findings](../spikes/spike-10-saml-oauth2-coexistence.md) — original `openid` scope design intent
- `docs/session-logs/2026-08-19-oauth2-fixes-deployed-and-third-defect-found.md`
