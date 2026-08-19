# OAuth2 tokens get zero effective permissions — the `openid` scope has no permission grant configured

**Area:** simple_oauth / OAuth2 scope configuration / ADR 014 authenticated path
**Raised during:** Session 2026-08-19 (re-verifying the two 2026-08-18 OAuth2 defects after fixing both)
**Jira:** (add when available)
**Priority:** High — blocks the entire OAuth2-authenticated path (proxy UserInfo call, and by extension anything else that authenticates via a `simple_oauth` Bearer token) regardless of the two defects fixed this session
**Status:** 🟡 Fix decided and applied in config (2026-08-19); verified in DDEV only — not yet redeployed/re-verified live against dev-0

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
granularity_id: permission
granularity_configuration:
  permission: 'access content'
```

Verified locally in DDEV: `config:import` applies cleanly, `config:status` shows no
drift afterward, and `Oauth2Scope::getPermissions()` for `openid` now correctly
returns `["access content"]`. Not yet re-verified live against dev-0 — that's the
next-session (or later-this-session) step: redeploy, then redo the full SAML → OAuth2 →
UserInfo live walkthrough to confirm `/oauth/userinfo` actually returns JSON, and that
the proxy's own Redis visibility-token read (the one remaining unproven 1b.3 link)
works for a real authenticated session.

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
