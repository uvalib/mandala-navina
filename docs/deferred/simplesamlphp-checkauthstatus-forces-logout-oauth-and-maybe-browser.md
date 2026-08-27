# `SimplesamlSubscriber::checkAuthStatus()` forces logout on every simple_oauth Bearer request

**Area:** simplesamlphp_auth (contrib) / session handling / ADR 014 authenticated path
**Raised during:** Session 2026-08-20
**Jira:** (add when available)
**Priority:** High — root cause of the tracked 4th OAuth2 defect; **raised to Critical
2026-08-27, see "Browser case confirmed" below — affects every real migrated user, not just OAuth2**
**Status:** 🟢 **FIXED for the OAuth2 path, 2026-08-20, VERIFIED LIVE on dev-0.** Service
override in the new `mandala_saml_oauth` module. The full SAML→OAuth2→`/oauth/userinfo` chain
was replayed against the deployed image (`build-20260820151220`): `/oauth/userinfo` now returns
`HTTP 200 application/json` with the correct `sub`, via the ALB and direct to the container. The
same replay against the previous image (`build-20260819195654`) returns `302 → /`, so the
before/after is measured, not asserted. **🔴 The browser case this note's title flagged as
"maybe" is now confirmed OPEN — see below — and is not fixed by the OAuth2-only exemption.**

## Browser case confirmed, 2026-08-27 (Sprint 1 step 10 smoke test)

While running the Sprint 1 close-out URL smoke tests (public/private/bogus access checks),
uid 600's SAML test identity was re-linked (rebuild runbook step 7) and a `drush user:login
--uid=600` one-time link was used to establish a direct browser session — the same kind of
link `drush uli`, the admin "log in as" action, or a password-reset email produces for a real
user. Watchdog shows the session dying within the same minute it opened:

```
Session opened for Nicholas Osborne.
User Nicholas Osborne used one-time login link at time 1787845844.
Session closed for Nicholas Osborne.
```

The very next request (`GET /user`) bounced anonymous, straight back to `/user/login`. This is
`checkAuthStatus()` exactly as documented above — `isAuthenticated()` reads SimpleSAMLphp's own
Redis-backed session store, a one-time-login link never creates one, and the account isn't on
the admin exemption list — but it confirms the mechanism fires on an ordinary Drupal-native
browser session, not just the OAuth2 Bearer loop this note was originally opened for.

**Scope is not "one test account."** `allow.default_login_roles` on dev-0 currently exempts
only `administrator`; `allow.default_login_users` is empty. A live count of `authmap`:

```
provider              count
simplesamlphp_auth    1385
```

Every one of the 1,384 rows created by the July D7 user migration (plus uid 600's manual link)
is `simplesamlphp_auth` — i.e. **every real migrated non-admin user is SAML-linked**, which
means every non-SAML path into their account is currently broken in production, not just on
dev-0's test rig: `drush uli` for support/debugging, the admin toolbar's "log in as" action,
and Drupal's native forgot-password email flow all hand out a session `checkAuthStatus()`
immediately destroys. This was not visible in the 2026-08-20 investigation because that testing
went through uid 600 via the SAML→OAuth2 path specifically, which has a live SimpleSAML session
by construction.

**Needs a team decision, not a unilateral fix** — same tension the "Why config alone cannot fix
it" section below already identifies for the OAuth2 case: any uid/role-keyed exemption
(`allow.default_login_users`/`_roles`) also exempts that same user's *real* SAML-should-be-required
browser sessions, defeating the enforcement rather than narrowly permitting the operational
path (impersonation, password reset, `drush uli`) that needs it. Options raised, undecided:
1. A request-context-keyed exemption analogous to the OAuth2 fix — e.g. only allow a session
   that was *just* established via `user.reset`/`user.reset.login` (one-time login) or `drush
   uli`, for one request, then re-arm the check — narrower than a standing uid/role allowlist.
2. Scope the admin-impersonation ("log in as") path specifically, since it already runs as a
   privileged action.
3. Accept the current behavior as intended (SAML-linked accounts must always come in through
   SAML) and instead fix the *operational* paths that assume otherwise — i.e. treat `drush uli`
   / forgot-password as not applicable to migrated users, with a different support mechanism.

**Decided 2026-08-27 (Than, in the Sprint 1 close-out meeting): every user must be able to log
in both ways** — local password and SAML, not one or the other. That rules out options 2 and 3
above (both leave most users SAML-only) and makes a request-scoped exemption (option 1)
unnecessarily narrow for what's actually a blanket requirement. The mechanism the module already
ships for exactly this is simpler: `allow.default_login_roles` — currently only
`administrator` — controls who is exempt from the SAML-liveness check entirely, so they can
authenticate either way without `checkAuthStatus()` ever forcing a logout. Adding the
`authenticated` role (held by every logged-in user) to that list, in
[`simplesamlphp_auth.settings.yml`](../../drupal/config/sync/simplesamlphp_auth.settings.yml),
is a **config change, not a code patch** — [PR #165](https://github.com/uvalib/mandala-navina/pull/165),
merged 2026-08-27. This is a deliberate, site-wide relaxation of the SAML-liveness enforcement
for every account, not a narrow carve-out; recorded here so the tradeoff is visible, not just
the mechanism.

**🟢 VERIFIED LIVE on dev-0, 2026-08-27**, same session. Manually applied ahead of the AWS
pipeline (which hadn't run for this commit yet — GitHub Actions' "deploy" check on the merge is
the docs-site publish, unrelated to the Drupal backend) by copying the merged
`simplesamlphp_auth.settings.yml` into the container's `config/sync` and running `drush
config:import`; will be superseded cleanly by the next real pipeline deploy building from the
same commit. Re-ran the Sprint 1 step 10 URL smoke tests as uid 600 before/after:

| Path | Before (pre-fix) | After (post-fix) |
|---|---|---|
| Private image (`/image/food-truck-beidou`) | 403 (session died) | **200** |
| Private collection (`/collection/cis-cultural-documentation-projects`) | 403 (session died) | **200** |
| `/user` | bounced to `/user/login` | shows "Nicholas Osborne", "Log out" |

Public paths and the bogus 404 were unaffected throughout, both before and after — confirming
the fix only touches the SAML-liveness enforcement, not the underlying node-access model.

> **Scope correction (2026-08-20, later in the same session).** This note originally also
> carried Xiaoming's "logout doesn't work" report as a "Case 2", on the strength of watchdog
> evidence. That evidence turned out to be our own test traffic — see
> [Where Case 2 went](#where-case-2-went) below. The browser-logout report is now tracked
> separately in
> [saml-logout-does-not-terminate-netbadge-idp-session.md](saml-logout-does-not-terminate-netbadge-idp-session.md).

## The mechanism

`drupal/web/modules/contrib/simplesamlphp_auth/src/EventSubscriber/SimplesamlSubscriber.php`, method `checkAuthStatus()`, runs on **every request** (`KernelEvents::REQUEST`) where the current account is not anonymous:

```php
public function checkAuthStatus(RequestEvent $event) {
  if ($this->account->isAnonymous()) return;
  if (!$this->simplesaml->isActivated()) return;
  if ($this->simplesaml->isAuthenticated()) return;   // separate session store, see below
  // ...unless allow.default_login + uid/role is on the allowlist...
  user_logout();
  $event->setResponse(new RedirectResponse('/'));
  $event->stopPropagation();
}
```

`$this->simplesaml->isAuthenticated()` does **not** check the Drupal session — it delegates to `\SimpleSAML\Auth\Simple::isAuthenticated()`, which reads SimpleSAMLphp's own session store (Redis, per ADR 014, `store.type=redis`, db 4, prefix `SIMPLESAML_MANDALA:`). Any Drupal-authenticated request that doesn't carry live evidence of a SimpleSAML session gets a hard, unconditional `user_logout()` — no exceptions except `allow.default_login: true` plus uid `1` or role `administrator` (`simplesamlphp_auth.settings.yml`, confirmed live on dev-0). A non-admin real user is never exempted by any config knob.

A `simple_oauth` Bearer-token request (e.g. `solr-proxy`'s call to `/oauth/userinfo`) is stateless by design and never carries a SimpleSAMLphp session, so this fires on **every hop**: the proxy resends the same Bearer header, authenticates again, is logged out again — an infinite loop terminating client-side with `GuzzleHttp\Exception\TooManyRedirectsException` after 5 hops. This is the "fourth defect" of
[simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md](simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md).

**Confirmed against upstream:** `checkAuthStatus()` on the current `4.x` branch still has exactly those four early returns — no route, request-format, or authentication-provider exclusion. There is no upstream fix to wait for. The nearest issue,
[#3274315](https://www.drupal.org/project/simplesamlphp_auth/issues/3274315) ("Only check for SimpleSAMLphp session if user is not allowed to log in locally"), targets `8.x-3.x` and is *needs review*; we are on `4.1.0`.

## Why config alone cannot fix it

The module's only exemption mechanism is `allow.default_login` + `allow.default_login_users`/`allow.default_login_roles` (the full config surface — checked against `LocalSettingsForm.php`, which is authoritative). That mechanism is keyed by **who the user is**, not **how this specific request authenticated**. Because `simple_oauth`'s `sub` claim is the real uid of whoever's browser session initiated the SAML→OAuth2 exchange (by design — ADR 014's visibility-token filtering needs the proxy to query Solr *as that real user*, not a fixed service account), exempting via role or uid would also blanket-exempt that same user's **real browser sessions** from the SAML-liveness check, silently defeating whatever it is supposed to catch. Rejected for that reason, not attempted.

## The fix as landed

New module `drupal/web/modules/custom/mandala_saml_oauth`, two classes:

- `MandalaSamlOauthServiceProvider::alter()` — repoints the `simplesamlphp_auth_event_subscriber`
  service at our subclass and injects simple_oauth's request policy **by setter**, not by
  appending a constructor argument, so an upstream change to `SimplesamlSubscriber::__construct()`
  cannot silently shift our argument onto the wrong parameter. Both service lookups are guarded
  by `hasDefinition()`.
- `OauthAwareSimplesamlSubscriber::checkAuthStatus()` — early-returns on an OAuth2 request,
  otherwise calls `parent::checkAuthStatus()`.

Chosen over `cweagans/composer-patches` because there is no upstream issue behind our change,
so a patch would have to be re-rolled on every module update — and it would conflict with
upstream MR !48, which we do want (see [the 8h expiry note](#separate-real-bug-behind-the-same-subscriber)).
Vendor tree stays pristine.

### Two traps worth recording

1. **The originally-designed fix was a no-op.** It read
   `if ($this->account instanceof TokenAuthUserInterface) return;`. But `$this->account` is
   `@current_user` → `Drupal\Core\Session\AccountProxy`, which *wraps* the real account
   (`AuthenticationSubscriber.php:79` → `AccountProxy::setAccount()`); `AccountProxy` does not
   implement `TokenAuthUserInterface`, so the check never matches. Confirmed empirically —
   the live service's `$account` property really is an `AccountProxy`. Had it shipped, we would
   have deployed, retested, and seen the identical loop.
2. **The service ID is `simplesamlphp_auth_event_subscriber`** (underscores), not
   `simplesamlphp_auth.event_subscriber`.

Detection uses `SimpleOauthRequestPolicyInterface::isOauth2Request()` — the same service
`SimpleOauthAuthenticationProvider::applies()` consults — rather than testing the resolved
account. That interface is not marked `@internal`, whereas `TokenAuthUserInterface` is.

### Verified locally (DDEV), not yet on dev-0

```
service class          : Drupal\mandala_saml_oauth\EventSubscriber\OauthAwareSimplesamlSubscriber
policy injected        : Drupal\simple_oauth\PageCache\DisallowSimpleOauthRequests
  parent::$account     : Drupal\Core\Session\AccountProxy      <- trap 1, confirmed
isActivated=true  isAuthenticated=false ; acting as uid=2 roles=authenticated

non-Bearer (browser-style)   -> response=RedirectResponse -> /   propagation stopped=YES
Bearer token                 -> response=NONE (request proceeds) propagation stopped=no
```

`Authorization: Basic ...` and a missing header both correctly stay unexempted.

### Verified live on dev-0

Same replay script, two images, everything else identical:

| step | pre-fix `build-20260819195654` | post-fix `build-20260820151220` |
|---|---|---|
| 1–4 SAML login → `/user/600` | 200 | 200 |
| 5 `GET /oauth/authorize` | 302 + code | 302 + code |
| 6 `POST /oauth/token` | 200, access_token | 200, access_token |
| **7 `GET /oauth/userinfo` + Bearer** | **302 → `/`** | **200 `application/json`** |

`X-Consumer-ID: solrproxy` is present in both, which is what makes the before case unambiguous:
`simple_oauth` authenticated the token successfully and `checkAuthStatus()` discarded the
session anyway. Post-fix, watchdog records the `Session opened` for the login with **no**
matching `Session closed` — `user_logout()` no longer runs on the Bearer hop.

**Testing trap worth recording.** The first post-deploy run still failed, and it was the test
that was wrong, not the fix: the watcher triggered on the module *directory* appearing, which is
true the moment the container starts, but a `ServiceProvider` only runs for an **enabled**
module, and `deploy_backend.yml` enables it ~50s later in `import full site configuration`. The
replay landed 12 seconds inside that window. Gate post-deploy verification on the pipeline
reaching *Deploy Succeeded*, or on `drush pm:list` showing the module enabled — never on files
being present.

## Separate real bug behind the same subscriber

Verified live on dev-0 while investigating this: SimpleSAMLphp sessions expire at **8h** while
Drupal's last **23 days**, so the same subscriber force-logs-out ordinary users mid-session and
bounces them to `/`. That is a distinct, still-open issue with its own decisions to make (upstream
MR !48 is RTBC against our branch), so it is tracked separately in
[saml-session-expires-8h-forcing-logout-mid-session.md](saml-session-expires-8h-forcing-logout-mid-session.md)
rather than buried under this note's FIXED status.

## Where Case 2 went

This note originally claimed strong circumstantial evidence that the same subscriber caused
Xiaoming's "logout doesn't work" report, based on repeated `Session opened` → `External login`
→ `Session closed` bursts for uid 600 across 2026-08-18…20.

**That evidence does not survive.** A 48h uid-keyed sweep of `watchdog` shows every single
`Session closed` / `SessionManager->destroy()` warning belongs to uid 600 — the *test identity*
the 08-18/19/20 curl replays used — and every one falls inside those sessions' own testing
windows (08-18 16:18–16:41, 08-19 14:23–15:49, 08-20 09:57–10:22), interleaved with
`simple_oauth` errors from the same tests. The runs of 4–6 closes within one second
(08-19 15:49:25) are the 5-hop redirect loop above. **No other authenticated uid was force
logged out at all** — uid 105 logged in at 10:19 on 08-20 and was not closed.

We were watching ourselves. The browser-logout report has a different, independently-evidenced
root cause and now lives in
[saml-logout-does-not-terminate-netbadge-idp-session.md](saml-logout-does-not-terminate-netbadge-idp-session.md).

## Still open, unrelated to the fix above

1. **RelayState/redirect URLs are generated with `http://`, not `https://`.** Re-confirmed at
   source: `reverse_proxy`, `reverse_proxy_addresses`, and `trusted_host_patterns` are all still
   commented out in dev-0's `settings.php`, so Drupal never trusts `X-Forwarded-Proto` and
   `Request::getUri()` — which `SimplesamlphpAuthManager::externalAuthenticate()` passes as
   `ReturnTo` — yields `http://`. Self-heals today only because Apache 301s plain HTTP back to
   HTTPS before it matters. Fragile; fix the `settings.php` side.
2. **Cron re-run loop — overstated in the first draft.** Actual count is 26
   `Attempting to re-run cron while it is already running` entries across 48h (2026-08-18
   14:06 → 2026-08-20 09:59), not "dozens within the same minute", and they cluster in the same
   test windows. Cause still uninvestigated; lower priority than first written.
3. **One-off crash seen once**, 2026-08-19 15:43:54:
   `TypeError: Cannot assign null to property TokenAuthUser::$consumer` in
   `TokenAuthUser->__construct()` — a token with no consumer. Not chased.

## Related

- [simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md](simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md) — the original 4th-defect note; this note root-causes and fixes it
- [saml-logout-does-not-terminate-netbadge-idp-session.md](saml-logout-does-not-terminate-netbadge-idp-session.md) — the former "Case 2", re-rooted
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- `docs/session-logs/2026-08-20-checkauthstatus-forces-logout-root-caused.md`
