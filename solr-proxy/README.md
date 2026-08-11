# solr-proxy (D11)

## Design principle: public access is the 90% case

**Unauthenticated public access is the overwhelming majority of traffic and must be
highly available and performant at all times.** Authenticated access to private
collections is the minority case, and no failure in the authenticated path may be
allowed to take the public path down with it.

Consequences that are already load-bearing in this code — do not "tidy" them away:

- **Every dependency of the authenticated path degrades rather than fails.**
  Redis unreachable, no visibility token, OAuth2 client unconfigured: each falls
  back to the anonymous (public-only) filter and keeps serving. None of them
  returns a 5xx to a public reader.
- **The public path does no work it does not need.** Anonymous requests make no
  Redis connection at all (`Searcher::getVisibilityToken()` returns early when not
  logged in) and no membership lookup of any kind — that was the whole point of
  ADR 014 moving the decision to Drupal.
- **Degradation must be loud in the logs but cheap.** A public-only proxy is
  externally indistinguishable from a fully working one, so a misconfiguration has
  to be obvious in `docker logs`/syslog — while costing the hot path as little as
  possible (hence a single consolidated log line, not one per missing variable).

Applied consequence: `Searcher::setSession()` starts a PHP session **only** when the
caller supplies a `sid` parameter or already holds a session cookie. It previously
called `session_start()` unconditionally, writing a session file on every anonymous
request that nothing ever read. A smoke test guards this — see
[`docs/deferred/solr-proxy-session-per-anonymous-request.md`](../docs/deferred/solr-proxy-session-per-anonymous-request.md).

Solr authentication proxy for the D11 platform. Forked from
[`shanti-uva/mandala-solr-proxy`](https://github.com/shanti-uva/mandala-solr-proxy)
(vendored at `../` history via the D7 monorepo migration) per
[ADR 014](../docs/adr/014-hybrid-solr-proxy-design.md). The original repo
continues to serve the D7 sites unchanged — the two codebases diverge here.

## What changed from the D7 proxy

The D7 proxy loaded a logged-in user's private collections by querying Solr
itself (`members_uid_ss:user-{uid}` against `kmassets`) and cached the result
in its PHP session. [ADR 013](../docs/adr/013-drupal-source-of-truth-solr-client-compatibility.md)
identified this as circular: the proxy's own access decision depended on the
very index it's supposed to be protecting, so a lagging `mandala_kmassets_sync`
could leak or hide content incorrectly.

The D11 proxy makes **no Solr call and no membership decision of its own**.
Drupal is the sole authority on Group membership and visibility. It computes
the full Solr `fq` filter string for a user and writes it to Redis at
`mandala_solr_fq:{uid}` on login, on Group membership change, and on logout.
The proxy's job on each request is just: read the uid from the OAuth2
session, `GET mandala_solr_fq:{uid}` from Redis, and inject it as `fq`. See
`proxy/Searcher.php` (`getVisibilityToken()` / `setVisibility()`).

If Redis is unreachable or has no token for a logged-in uid, the proxy fails
closed to the anonymous filter rather than serving unfiltered results.

## 1b.1 work breakdown (ADR 014)

1. **This fork** — proxy code moved into the monorepo, `$OAUTH_ROOT` wired to
   D11, `setCollections()` replaced with the Redis read. ✅
2. Install and configure `simple_oauth` in D11; register this proxy as an
   OAuth2 client (`clientId` in `settings/creds.php`).
3. D11 event hooks: write/invalidate `mandala_solr_fq:{uid}` on login, Group
   membership change, and logout. Lives in a Drupal module, not here.
4. Confirm `simplesamlphp_auth` + `simple_oauth` coexistence in the real
   (non-DDEV) environment — proven feasible in [Spike 10](../docs/spikes/spike-10-saml-oauth2-coexistence.md),
   but that was a DDEV-only proof.

Steps 2–4 are tracked as follow-on work on the `feat/1b1-hybrid-solr-proxy`
branch; this fork alone doesn't make the proxy functional end-to-end yet —
there's no D11 OAuth2 server or Redis writer for it to talk to until those
land.

## Local setup

```
cp example.env .env    # SOLR_BASEURL / DEFAULT_RETURL / REDIS_HOST / REDIS_PORT
                       # plus SOLRPROXY_OAUTH_ROOT / _CLIENT_SECRET / _REDIRECT_URI
docker compose up --build
```

**No settings files to copy or fill in.** `settings/{paths,creds}.php.template` read
every value from the container environment via `getenv()`, contain no secrets, and
are baked into the image (see `Dockerfile`) — that is what makes the image
deployment-agnostic, the same shape `drupal-netbadge` uses. Configuration comes from
the environment in every context: `.env` locally, and the layered
`container_0.env.{generated,managed,secret}` files when deployed.

`settings/creds.php` and `settings/paths.php` (no `.template`) remain gitignored and
are still honoured if present, because `docker-compose.yml` mounts `./settings` over
the baked directory — useful for poking at local overrides, but not required.

If `SOLRPROXY_CLIENT_SECRET` is unset the proxy starts anyway in **public-only**
mode: anonymous search works, login is disabled, and the reason is logged on every
request. See the design principle at the top.

**Note (Spike 10):** D11's `simple_oauth` paths are `/oauth/*`, not the D7
proxy's `/oauth2/*` — `$OAUTH_ROOT` in `creds.php.template` reflects this.

## Redis contract

| Key | Written by | Read by | Value |
|---|---|---|---|
| `mandala_solr_fq:{uid}` | Drupal (login / Group membership change / logout) | this proxy | Full Solr `fq` string, TTL 1h, re-written on access change |

`uid=1` (Drupal admin) has no token written.

⚠ **This next part was long documented incorrectly, here and in three other
places.** It said the proxy "applies no visibility filter for uid 1". It does not:
`Searcher::setVisibility()` skips the *token lookup* for uid 1 and then falls
through to the **anonymous** filter, so admin sees public content only. D7 behaves
identically, so this is not a D11 regression — but `VisibilityTokenBuilder`
deliberately writes no token for uid 1 *on the strength of the false claim*. Which
way to reconcile it is an open decision:
[`docs/deferred/solr-proxy-uid1-admin-gets-anonymous-filter.md`](../docs/deferred/solr-proxy-uid1-admin-gets-anonymous-filter.md).
