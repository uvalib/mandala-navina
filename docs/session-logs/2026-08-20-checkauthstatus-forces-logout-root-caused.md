# Session Log: 4th OAuth2 defect root-caused; browser "logout doesn't work" report investigated, not resolved

**Date:** 2026-08-20
**Participants:** ys2n, Claude Code
**Outcome:** Root-caused the session-handling redirect loop left open at the end of
[2026-08-19](2026-08-19-oauth2-fixes-deployed-and-third-defect-found.md) — it's
`simplesamlphp_auth`'s `SimplesamlSubscriber::checkAuthStatus()` forcing a Drupal logout
on any non-admin authenticated request that isn't proven to hold a live SimpleSAMLphp
session. Proven deterministic for OAuth2 Bearer requests (fixes the tracked 4th defect).
Also investigated a separate live report from Xiaoming ("logout doesn't work") — found
strong circumstantial evidence it's the same subscriber, but could not pin the exact
trigger despite substantial live testing. **Fix designed but not applied** — session
ended (driver switching models) mid-decision on implementation path. Full writeup:
[simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](../deferred/simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md).

**⚠ This log is hand-written, not machine-generated from the raw transcript.** Pulling
live watchdog entries to diagnose the browser-logout report initially rendered a real
user's display name in full (before switching to a parameterized `drush php:eval` query
that returns only `%name`/uid). The name was never committed and this summary uses the
`uid 600` convention throughout, but the raw transcript would have republished it
verbatim into a public repo, so this summary was written by hand instead.

---

## Where the session started

Picking up the explicit next-session starting point from 2026-08-19: root-cause the
session-handling redirect loop blocking `/oauth/userinfo` even after the scope-permission
fix (PR #125) was confirmed correct at the access-control layer. Also brought in fresh
context: Xiaoming had separately reported that logout doesn't work for real users.

## Root cause, found by reading code before touching anything live

`SimplesamlSubscriber::checkAuthStatus()` (`web/modules/contrib/simplesamlphp_auth/src/EventSubscriber/SimplesamlSubscriber.php`)
runs on every request where the current account isn't anonymous. If
`$this->simplesaml->isAuthenticated()` — which checks SimpleSAMLphp's own Redis-backed
session store, not the Drupal session — reads false, it force-calls `user_logout()` and
redirects to `/`. The only exemption is `allow.default_login` + uid `1` or role
`administrator` (confirmed in `simplesamlphp_auth.settings.yml`). A stateless
`simple_oauth` Bearer request never has a SimpleSAML session by design, so this fires on
every hop of the OAuth2 chain — a self-sustaining redirect loop, matching the exact
`Session closed for [uid 600].` watchdog message and `TooManyRedirectsException` from
2026-08-19.

## Confirmed live, not just in theory

SSH'd to dev-0 (`ys2n@mandala-drupal-dev-0.internal.lib.virginia.edu`) and pulled recent
`watchdog` entries. Uid 600 (role: `authenticated` only, confirmed via
`drush php:eval` — not covered by the admin/uid-1 exemption) shows repeated
`Session opened` → `External login of user` → `Session closed` bursts across
2026-08-18 through 2026-08-20 — a login followed almost immediately by a forced logout,
recurring throughout the day. Strong circumstantial match for both the OAuth defect and
Xiaoming's report.

## Live SAML replay — what was ruled out for the browser case

Reconstructed the full SAML flow by hand via curl against dev-0's `example-userpass` test
IdP (`staff`/`staffpass`, the same test identity linked to uid 600 since 2026-08-18),
mirroring the 2026-08-19 session's method (Chrome extension was disconnected again this
session). Login succeeded cleanly through to `/user/600`. Checked and ruled out, in order:

- **Cookie-domain mismatch** — the specific risk flagged as unverified on 2026-08-18.
  Checked live: `SIMPLESAML_BASE_URL`, `SIMPLESAML_SP_ENTITY_ID`, and
  `SIMPLESAML_COOKIE_DOMAIN` are all consistently `mandala-dev.internal.lib.virginia.edu`
  today. Not the cause.
- **Short SimpleSAML session TTL** — no `session.duration` override; defaults to ~8h.
- **Redis eviction/instability** — `noeviction` policy, ~1.6MB used, no evicted keys; the
  test login's own session key was confirmed present and correctly persisted.
- **A simple request race** — 5 sequential and 10 fully concurrent requests against the
  freshly-authenticated session all stayed logged in. No forced logout reproduced.

None of these reproduced the bug. The exact trigger for a false `isAuthenticated()` read
on a real, recent browser login remains **unconfirmed** — reported honestly as open, not
papered over.

Two secondary findings surfaced along the way, neither chased further: `RelayState`/ACS
redirect URLs are generated with `http://` instead of `https://` (self-heals today only
because Apache on dev-0 redirects plain-HTTP hits back to HTTPS before it matters — but
points at a missing reverse-proxy/HTTPS-detection setting in `settings.php`); and cron is
currently stuck in a rapid re-run loop on dev-0 (`Attempting to re-run cron while it is
already running`, dozens of times in the same window as the logout bursts) — cause not
investigated.

## Fix path — patch route started, then explicitly stopped by Yuji

Designed the fix: exempt `Drupal\simple_oauth\Authentication\TokenAuthUserInterface`
accounts from `checkAuthStatus()`'s SAML-liveness check entirely (deterministic, closes
the OAuth2 case). Since the bug lives in the **contrib** `simplesamlphp_auth` module,
started wiring up `cweagans/composer-patches` (added to `composer.json`, drafted a patch
file, staged `extra.patches`) to apply it cleanly rather than hand-editing vendor code.

**Yuji stopped this explicitly, asking to exhaust config-only options first.** Reverted
all of it (`composer.json` restored via `git checkout`, patch file/directory removed,
vendor file restored to pristine — verified `git status` clean before moving on).

Checked the module's entire config surface against `LocalSettingsForm.php` (authoritative
— it's the only form that writes `simplesamlphp_auth.settings`): `allow.default_login`,
`allow.default_login_users`, `allow.default_login_roles`, `allow.set_drupal_pwd`,
`logout_goto_url`. No route-level or auth-method-level exclusion exists. The only
exemption path (`allow.default_login_*`) is keyed by **who the user is**, not **how this
request authenticated** — and since `simple_oauth`'s `sub` claim is the real uid of
whoever's browser session started the OAuth2 exchange (ADR 014's design: the proxy must
query Solr *as that real user* for visibility filtering, not a fixed service account),
exempting by uid or role would also blanket-exempt that same user's real browser sessions
from the SAML-liveness check — defeating whatever it's meant to catch, for everyone it
was meant to catch it for. **Config alone cannot do this without introducing a worse
bug.** Reported this back plainly rather than re-proposing the patch unprompted.

## Where the session ended

Yuji is switching models mid-decision on implementation path (contrib patch via
composer-patches vs. a custom-module service override of
`simplesamlphp_auth.event_subscriber`) — no fix has landed. Everything above is captured
in
[simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](../deferred/simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md)
for the next session to pick up directly.

## Handoff — next session starting point

1. Decide implementation path for the Case 1 (OAuth2) fix — patch vs. custom-module
   service override — and land it.
2. Verify live: replay the SAML→OAuth2→`/oauth/userinfo` chain (curl, same method as
   2026-08-19/08-20) and confirm the redirect loop is gone.
3. The browser-logout report (Case 2) is **not** proven fixed by the Case 1 change — it
   involves ordinary session cookies, not Bearer tokens. Re-test with a real interactive
   browser logout once the Chrome extension reconnects, or capture the next live
   occurrence with `simplesamlphp_auth.settings:debug` enabled to get the actual
   `isAuthenticated()` failure context instead of guessing further.
4. RelayState `http://` scheme and the stuck dev-0 cron loop are both still open,
   uninvestigated, and not confirmed related to Case 2.

---

## Addendum — same day, later session (model switched, work continued)

Two of this log's conclusions were revised by further debugging on 2026-08-20. Recorded here
rather than edited above, so the original reasoning stays legible.

1. **The Case 1 fix as designed would not have worked.** It tested
   `$this->account instanceof TokenAuthUserInterface`, but `$this->account` is `@current_user`
   → `AccountProxy`, which *wraps* the real account; the check never matches. Landed instead as
   a service override in a new `mandala_saml_oauth` module, detecting the request via
   simple_oauth's own `SimpleOauthRequestPolicyInterface::isOauth2Request()`. Behaviour proven
   locally in DDEV; live verification on dev-0 still pending a deploy.
2. **The Case 2 watchdog evidence was our own test traffic.** A 48h uid-keyed sweep shows every
   `Session closed` entry belongs to uid 600 — the test identity these curl replays used —
   inside our own testing windows. No other authenticated uid was force-logged-out. The
   browser-logout report is re-rooted to an unrelated cause (the IdP advertises no
   `SingleLogoutService` and `logout_goto_url` is unset) and now tracked in
   [saml-logout-does-not-terminate-netbadge-idp-session.md](../deferred/saml-logout-does-not-terminate-netbadge-idp-session.md).

Also corrected: the cron re-run loop is 26 entries across 48h clustered in the test windows,
not "dozens within the same minute". And a new, genuinely separate finding — SimpleSAMLphp
sessions expire at 8h while Drupal's last 23 days, so ordinary users get force-logged-out by
the same subscriber; upstream MR !48 (RTBC) targets our 4.x branch.
