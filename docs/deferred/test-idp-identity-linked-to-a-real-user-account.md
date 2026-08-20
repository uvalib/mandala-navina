# The dev test-IdP identity is linked to a real person's user account, so verification runs print their PII

**Area:** dev test IdP / externalauth authmap / verification tooling / privacy
**Raised during:** Session 2026-08-20 (live verification of the OAuth2 Bearer fix)
**Jira:** (add when available)
**Priority:** Medium — no live exposure, but it puts real personal data into the output of a
routine test that the team runs repeatedly and pastes into notes and PRs
**Status:** 🔴 Open. Identified, not fixed. Nothing has been committed containing the data.

## What happened

Verifying the OAuth2 fix meant calling `/oauth/userinfo` with a real Bearer token, which is an
OpenID Connect endpoint and returns the authenticated user's profile claims by design:

```json
{"sub":"600","name":"<real full name>","preferred_username":"<real full name>",
 "email":"<real personal email address>","email_verified":true,
 "profile":"http://mandala-dev.internal.lib.virginia.edu/user/600"}
```

The `staff`/`staffpass` identity on dev-0's `example-userpass` test IdP is linked, via
`externalauth`'s authmap, to **uid 600 — a real user account carried over from D7**, not a
throwaway test account. So the endpoint correctly returns that person's name and email.

This is the same underlying problem the 2026-08-20 session already hit from a different
direction, when pulling watchdog entries rendered the same person's display name in full and the
session log had to be hand-written to avoid republishing it (see
`docs/session-logs/2026-08-20-checkauthstatus-forces-logout-root-caused.md`). Watchdog was
worked around by querying uid instead of `%name`. **`/oauth/userinfo` has no such workaround —
returning those claims is the entire point of the endpoint.**

## Why it matters here specifically

- This repo is public, and verification output is exactly the kind of thing that gets pasted
  into deferred notes, session logs, and PR descriptions.
- The `/oauth/userinfo` replay is now the standard regression test for ADR 014's authenticated
  path, so this will recur every time anyone runs it.
- The `example-userpass` credentials are fixed, published in `authsources.php`, and shared —
  anyone who can reach dev-0 can log in as `staff` and read that person's profile.

## Options

1. **Re-link the test identity to a dedicated test account** (preferred). Point the `staff`
   authmap entry at a purpose-made account with synthetic name and email. `authsources.php`
   already supplies `displayName`/`mail` for `staff` (`Test Staff Member`,
   `staff@example.edu`) — the link to uid 600 appears to predate that, so this may be a
   leftover from the 2026-08-18 session that first wired the test IdP up.
2. **Check the other two test identities** (`student`, `faculty`) for the same problem — not
   yet checked.
3. **Sanitise by default in tooling** — have the replay script redact `name`, `preferred_username`
   and `email` unless explicitly asked for them, so a careless paste cannot leak.

Option 1 is the real fix; option 3 is worth doing anyway as defence in depth.

## Not yet checked

- Whether staging and production have the same authmap linkage.
- Whether uid 600 is an account the person still uses, or a dormant D7 carryover.
- Whether any already-committed doc in this repo contains the data. A name sweep was run over
  everything written this session and came back clean, but earlier sessions were not re-checked.

## Related

- [simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md](simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md) — the fix whose verification surfaced this
- [dev-0-needs-test-idp-for-saml-login-testing.md](dev-0-needs-test-idp-for-saml-login-testing.md) — where the test IdP came from
- `docs/session-logs/2026-08-20-checkauthstatus-forces-logout-root-caused.md` — the watchdog instance of the same problem
