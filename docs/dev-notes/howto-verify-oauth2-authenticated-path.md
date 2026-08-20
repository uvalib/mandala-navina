# How-To: Verify the OAuth2-authenticated Solr proxy path

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-08-20

## Goal

Prove, end to end and against a real deployed environment, that a Bearer-token request
survives the whole ADR 014 authenticated chain — SAML login → OAuth2 `authorization_code`
exchange → `/oauth/userinfo` returning JSON.

## Why this needs a dedicated test

Four separate defects have blocked this chain. Each was only reachable once the previous
one was fixed, and — this is the important part — **every one of them left steps 1 through 6
passing**:

| # | Defect | Fixed |
|---|---|---|
| 1 | OAuth2 signing keys not persisted across deploy | 2026-08-19 |
| 2 | `solr-proxy` sent no Bearer header to `/oauth/userinfo` | 2026-08-19 |
| 3 | The `openid` scope granted zero permissions | 2026-08-19 |
| 4 | `simplesamlphp_auth` force-logged-out Bearer requests | 2026-08-20 |

Defect 4 makes the point. `simple_oauth` authenticated the token perfectly — the response
even carried `X-Consumer-ID: solrproxy` — and then `SimplesamlSubscriber::checkAuthStatus()`
called `user_logout()` and redirected to `/`, looping until the proxy's Guzzle client gave up
with `TooManyRedirectsException`. A successful token exchange told us nothing.

**Only step 7 is the assertion.** Treat a green step 6 as setup, not as a result.

## Prerequisites

- SSH access to the target node (see [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md))
- The `solr-proxy` container running on that node — the script reads the OAuth2 client
  credentials from its environment, so no secret is typed, echoed, or left in shell history
- A test IdP identity on the target environment (dev-0 has `example-userpass`; see
  `docs/deferred/dev-0-needs-test-idp-for-saml-login-testing.md`)
- Python 3 on the node (stdlib only — no packages to install)

## Steps

Run from the repo root on your workstation:

```bash
./scripts/verify-oauth2-userinfo.sh                        # dev-0, the default
./scripts/verify-oauth2-userinfo.sh <ssh-host>             # another node
./scripts/verify-oauth2-userinfo.sh <ssh-host> --show-claims
```

The wrapper copies `scripts/verify-oauth2-userinfo.py` to the node and runs it there — the
internal hostnames do not resolve from outside the environment's network.

Environment overrides: `MANDALA_SSH_USER`, `MANDALA_SSH_KEY`, `MANDALA_PROXY_CONTAINER`,
`MANDALA_DRUPAL_CONTAINER`. The Python script takes `--base-url`, `--user`, `--password`,
`--scope` and `--client-*` if you need to point it somewhere unusual.

## Verify

A pass ends like this:

```
== 7. GET /oauth/userinfo with Bearer   <-- THE ASSERTION ==
   HTTP 200
   Location:      (none)
   X-Consumer-ID: solrproxy
   Content-Type:  application/json

   claims: {"sub": "600", "name": "<redacted>", ...}

PASS -- /oauth/userinfo returned JSON, sub=600
```

Exit codes: `0` PASS, `1` FAIL, `2` INCONCLUSIVE, `3` SETUP (never got far enough to test).

### On the redaction

`/oauth/userinfo` is an OpenID Connect endpoint, so it returns the authenticated user's
profile claims by design — and the test identity is authmapped to a real user account, so
those are a real person's name and email. This output routinely gets pasted into a public
repo, so `name`, `email` and friends are **redacted by default**. Pass `--show-claims` when
you genuinely need them.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `FAIL` at step 7, **`X-Consumer-ID` present** | The token authenticated and something then discarded the session — defect 4's signature | Check `mandala_saml_oauth` is enabled (below). Cross-check watchdog for a `Session closed` with no page navigation in between |
| `FAIL` at step 7, **`X-Consumer-ID` absent** | The request never authenticated at all | Look at the Bearer header and the OAuth2 signing keys — defects 1 and 2 territory |
| `FAIL` immediately after a deploy, but the fix is definitely merged | **Timing trap, see below** | Wait for *Deploy Succeeded* and re-run |
| `SETUP` at step 1 or 2 | Test IdP missing or credentials changed | Check `SIMPLESAML_ENABLE_EXAMPLE_AUTH` and `authsources.php` on the node |
| `SETUP` at step 5 | Consumer misconfigured | Confirm the client's `grant_types` include `authorization_code` and the `redirect_uri` matches exactly |
| `SETUP` at step 6 | Client secret mismatch, or signing keys missing | Confirm `keys/` is bind-mounted and the keypair exists (defect 1) |
| `ERROR: could not read SOLRPROXY_CLIENT_SECRET` | Proxy container not running, or renamed | `docker ps`; override with `MANDALA_PROXY_CONTAINER` |

### The timing trap — do not test during a deploy

`deploy_backend.yml` starts the new container roughly **50 seconds before** it runs
`import full site configuration`, which is what actually *enables* modules. A
`ServiceProvider` — which is how the defect-4 fix is wired — only runs for an **enabled**
module. Testing inside that window produces a confident, completely false `FAIL`:

```
15:27:17  TASK [run the appropriate container]        <- module directory on disk
15:27:55  ** a test here fails, wrongly **
15:28:07  TASK [import full site configuration]       <- module actually enabled here
15:29:19  Deploy Succeeded
```

This happened on 2026-08-20 and cost a round of misdiagnosis. Gate on the pipeline reaching
*Deploy Succeeded*, never on files being present. The wrapper prints `fix module enabled:
YES/NO` before running for exactly this reason — believe that line over the test result.

## Related

- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [Spike 10 — SAML + OAuth2 coexistence](../spikes/spike-10-saml-oauth2-coexistence.md)
- `docs/deferred/simplesamlphp-checkauthstatus-forces-logout-oauth-and-maybe-browser.md` — defect 4
- `docs/deferred/simple-oauth-tokenauthuser-permission-checker-returns-no-permissions.md` — defect 3
- [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md)
