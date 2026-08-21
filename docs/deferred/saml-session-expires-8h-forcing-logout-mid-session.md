# SAML session expires at 8h while the Drupal session lasts 23 days, force-logging-out users mid-session

**Area:** simplesamlphp_auth / SimpleSAMLphp session config / Drupal session config
**Raised during:** Session 2026-08-20 (live session-config inspection while fixing the OAuth2 Bearer defect)
**Jira:** (add when available)
**Priority:** Medium-High — user-visible on every working day longer than eight hours, and it
silently discards whatever the user was in the middle of. Not a blocker for any sprint item.
**Status:** 🔴 **Open, not started.** Mechanism verified live on dev-0; no fix applied. An
upstream merge request fixes the worst half of it and is RTBC against our exact branch.

## Symptom

A user logs in via NetBadge in the morning and keeps working. At some point past the eight-hour
mark, an ordinary page request logs them out with no warning and dumps them on the site root —
losing whatever page they were on. Logging back in works, and the cycle repeats the next long day.

For deep links this is worse than an inconvenience: a user following a link to a specific asset
lands on the homepage instead, with nothing indicating why.

## Mechanism

Two session lifetimes are configured independently and disagree by nearly two orders of magnitude.

**SimpleSAMLphp — 8 hours.** `session.duration` is unset in `/var/simplesamlphp/config/config.php`,
so it takes SimpleSAMLphp's default of 28800s. Verified live rather than inferred: the
`SIMPLESAML_MANDALA:session.*` keys in Redis db 4 read TTLs of 26,000–27,500s for sessions
created roughly 30–45 minutes earlier.

**Drupal — 23 days.** No `sites/default/services.yml` exists on dev-0, so core's defaults from
`default.services.yml` apply unchanged:

```yaml
gc_maxlifetime: 200000     # 2.3 days
cookie_lifetime: 2000000   # 23.1 days
```

The two stores are also on different backends — SimpleSAMLphp in Redis (ADR 014), Drupal's in
the database — so nothing keeps them in step.

Once the SAML session has expired but the Drupal session has not,
`SimplesamlSubscriber::checkAuthStatus()` sees a non-anonymous account with no live SimpleSAMLphp
session and does what it always does: `user_logout()`, redirect to `/`, stop propagation. The
account is not exempt, because the only exemption is `allow.default_login` plus uid `1` or role
`administrator`. See
[simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md)
for that subscriber in full.

Note this is the subscriber behaving **as designed** — unlike the OAuth2 Bearer case, which was a
genuine category error. Here the SAML session really has expired; the complaint is about the
timings and about what happens next.

## Two distinct decisions, worth separating

### 1. Where the user lands — upstream MR !48

Upstream [#3568305](https://www.drupal.org/project/simplesamlphp_auth/issues/3568305)
("User redirected to homepage if not SAML authenticated while Drupal authenticated") is exactly
this symptom. It redirects to the current page instead of `/`, so the user is re-authenticated
back onto the page they wanted rather than the site root.

- **RTBC** as of 2026-05-22, confirmed by multiple reporters
- Merge request **!48**, targets **`4.x`** — our branch (we run `4.1.0`)
- Called out specifically for path-based multisites, where landing on `/` can mean landing on a
  *different site* — relevant to us, given the five-site consolidation

This is the cheap, low-risk half. Taking it means adopting a contrib patch, so it interacts with
the tooling decision recorded in the sibling note: we deliberately avoided `composer-patches` for
the OAuth2 fix because that change had no upstream issue behind it. This one does, which is
exactly the case patches are for.

### 2. Whether 8h is the right number at all — ours to decide

MR !48 treats the symptom, not the asymmetry. Options, none yet chosen:

- **Leave 8h and accept a daily re-auth.** Shortest-lived session wins; arguably the correct
  security posture, since the SAML session is the one anchored to an actual IdP authentication.
  Re-auth is usually silent anyway while the NetBadge IdP session is alive — though see the
  [logout note](saml-logout-does-not-terminate-netbadge-idp-session.md), because "silent re-auth
  is fine" and "logout doesn't stick" are two readings of the same IdP behaviour.
- **Raise `session.duration`** to match Drupal's, via `SIMPLESAML_SESSION_DURATION` (the config
  already reads env vars for its neighbours, though not yet for this key).
- **Lower Drupal's** `gc_maxlifetime`/`cookie_lifetime` toward 8h in `services.yml` so Drupal
  expires first and users get a normal Drupal login prompt rather than a forced logout.

Worth checking what D7 does today before picking, so we don't change behaviour users are used to
without meaning to.

## Not yet checked

- Whether the same asymmetry exists on staging and production, or only on dev-0.
- Whether SimpleSAMLphp's `session.rememberme` settings are in play for anyone.
- Whether MR !48 applies cleanly to `4.1.0` as released (it targets `4.x`, not necessarily our
  tag) — needs an actual apply before we commit to it.

## Related

- [simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md) — the subscriber that performs the logout, and the OAuth2 Bearer defect fixed 2026-08-20
- [saml-logout-does-not-terminate-netbadge-idp-session.md](saml-logout-does-not-terminate-netbadge-idp-session.md) — the other open logout issue; the two interact via the IdP's own session
- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md) — the shared Redis session store
- `docs/session-logs/2026-08-20-checkauthstatus-forces-logout-root-caused.md`
