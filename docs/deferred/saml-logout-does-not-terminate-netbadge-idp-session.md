# Logging out of Drupal does not end the NetBadge IdP session (no SP-initiated SLO configured)

**Area:** simplesamlphp_auth / SimpleSAMLphp SP configuration / NetBadge (Shibboleth)
**Raised during:** Session 2026-08-20 (re-rooting the "logout doesn't work" report)
**Jira:** (add when available)
**Priority:** Medium-High — user-visible, and it is the leading explanation for a real report
from Xiaoming. Config-only to fix, but needs one fact from UVA ITS we don't have yet.
**Status:** 🟡 Root cause **strongly indicated, not yet confirmed against the reported symptom** —
identified by reading live SP config, not by reproducing Xiaoming's exact experience. Needs
(a) the canonical NetBadge logout URL and (b) an interactive browser re-test.

> ⚠ **Classification check pending — Yuji to confirm.** This note is written at the level of
> mechanism and SP configuration only, which is the same level the upstream Drupal issue queue
> discusses openly and which anyone can read out of published InCommon IdP metadata. It
> deliberately does not walk through consequences on any specific live deployment. If the team
> would rather this sit in `uvalib/mandala-navina-docs` until the config change lands, move it
> and leave a pointer here per `docs/non-public-documentation.md`.

## Background — this used to be "Case 2"

This started as a second case inside
[simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md),
on the theory that the same `checkAuthStatus()` subscriber was force-logging-out real browser
sessions. **That theory is withdrawn.** A 48h uid-keyed watchdog sweep on dev-0 showed every
`Session closed` / `SessionManager->destroy()` entry belonged to uid 600 — the test identity
our own 08-18/19/20 curl replays used — inside our own testing windows, interleaved with
`simple_oauth` errors from those same tests. No other authenticated uid was force-logged-out.
The subscriber is not implicated in the browser report.

## What is actually configured

`/var/simplesamlphp/metadata/saml20-idp-remote.php` on dev-0:

```php
'entityid' => 'urn:mace:incommon:virginia.edu',
'SingleSignOnService' => [ /* four bindings, all present */ ],
'SingleLogoutService' => [],          // ← empty
```

The dev `example-userpass` test IdP (`urn:mandala:dev:test-idp`) declares an empty
`SingleLogoutService` too, so the dev environment cannot exercise logout realistically either.

And in `simplesamlphp_auth.settings`:

```yaml
logout_goto_url: null
```

## Why that produces "logout doesn't work"

`simplesamlphp_auth_user_logout()` calls `SimplesamlphpAuthManager::logout()` →
`\SimpleSAML\Auth\Simple::logout()`. That method only reaches the IdP at all via
`Source::logout()`, and the `saml:SP` auth source only emits a `LogoutRequest` when the IdP's
metadata advertises a `SingleLogoutService` endpoint. With none advertised, logout tears down
the Drupal session and the local SP session and stops there — **the IdP session at
`shibidp.its.virginia.edu` is untouched.** With `logout_goto_url` unset, the user is not sent
anywhere that would end it either; `logout()` falls back to `base_path()`, i.e. `/`.

Net effect: the user logs out, and the next authentication request is satisfied silently by the
still-live IdP session with no credential prompt. From the user's side that reads as logout
having done nothing.

This is a known and openly-discussed shape, not a Mandala-specific defect: drupal.org
[#2674158](https://www.drupal.org/project/simplesamlphp_auth/issues/2674158) reports exactly it
and was closed *works as designed*, with the accepted resolution being "set the logout URL
within the module to the logout URL of the IdP."

## Candidate fix — config only, but two settings, not one

1. Set `simplesamlphp_auth.settings:logout_goto_url` to UVA's canonical NetBadge/Shibboleth
   logout URL. **We do not have that URL confirmed** — do not guess at
   `https://shibidp.its.virginia.edu/idp/profile/Logout`; ask UVA ITS what NetBadge publishes,
   and whether it actually terminates the IdP session or only renders a "close your browser"
   page (many Shibboleth deployments do the latter, which changes what we can promise users).
2. Add that host to SimpleSAMLphp's `trusted.url.domains` (or `trusted.url.regex`) in
   `config.php`. It is **absent entirely** on dev-0 today, so SimpleSAMLphp will refuse an
   off-domain `ReturnTo` and the redirect will fail rather than log anyone out. This is the step
   that would make an otherwise-correct `logout_goto_url` look broken.

### Known limitation of that fix

`simplesamlphp_auth_user_logout()` wraps its whole body in
`if ($simplesaml->isActivated() && $simplesaml->isAuthenticated())`. If the SimpleSAMLphp session
has already expired — which happens after 8h, see the sibling note — the hook does nothing at
all and the user is never sent to the IdP logout, `logout_goto_url` set or not. So the config
change covers logout while the SAML session is live, which is the common path, but not the
expired path.

## Next steps

1. Get the canonical NetBadge logout URL and its actual semantics from UVA ITS.
2. Apply both settings on dev-0, then test an **interactive browser** logout → login round trip
   and confirm a credential prompt appears. This has to be a real browser; curl cannot observe
   the IdP's own cookie state.
3. Confirm with Xiaoming that the symptom matches this description before calling it fixed —
   the report predates this analysis and was never reproduced step by step.
4. Decide whether the dev test IdP should advertise an SLO endpoint so this path is testable
   without depending on production NetBadge.

## Related

- [simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md) — where this began, and the 8h SAML-session expiry that limits the fix
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md) — the shared Redis session store
- `docs/session-logs/2026-08-20-checkauthstatus-forces-logout-root-caused.md`
