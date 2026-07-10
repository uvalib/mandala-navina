# solr-proxy (D11)

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
cp settings/creds.php.template settings/creds.php   # fill in real client secret + redirect URI
cp settings/paths.php.template settings/paths.php   # fill in Redis host if not localhost
cp example.env .env                                  # SOLR_BASEURL / DEFAULT_RETURL / REDIS_HOST / REDIS_PORT
docker compose up --build
```

`settings/creds.php` and `settings/paths.php` are gitignored (generated from
the `.template` files) since they carry environment-specific secrets/hosts.

**Note (Spike 10):** D11's `simple_oauth` paths are `/oauth/*`, not the D7
proxy's `/oauth2/*` — `$OAUTH_ROOT` in `creds.php.template` reflects this.

## Redis contract

| Key | Written by | Read by | Value |
|---|---|---|---|
| `mandala_solr_fq:{uid}` | Drupal (login / Group membership change / logout) | this proxy | Full Solr `fq` string, TTL 1h, re-written on access change |

`uid=1` (Drupal admin) has no token written — the proxy applies no
visibility filter for uid 1, matching prior D7 proxy behaviour.
