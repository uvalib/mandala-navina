# How-To: Verify Solr proxy visibility filtering (ADR 014)

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-08-20

## Goal

Prove that an authenticated user querying through the Solr proxy sees **exactly** the
private content they are entitled to — and nothing more. This is the acceptance test for
[ADR 014](../adr/014-hybrid-solr-proxy-design.md)'s central claim, not just a check that
authentication works.

## Why this test has to be shaped this way

`Searcher::setVisibility()` **fails closed**. If the Redis token `mandala_solr_fq:{uid}` is
missing, or Redis is unreachable, a logged-in user silently drops back to the anonymous
filter:

```
(visibility_i:1 OR asset_type:(places subjects terms))
```

That is the right direction to fail in, but it means **a broken deployment looks identical
to a working one from outside**. Public results still come back; nothing errors; no log
line appears in the response. A test that only asserted "logged-in users still get results"
would pass against a completely broken visibility system.

So the test pins a document the user **should** see and fails if it is absent — the
positive discriminator — as well as one they should not. Asserting only the negative would
pass against a proxy that filtered everything; asserting only "logged in sees more" would
pass against one that filtered nothing.

| Case | Anonymous | uid 600 |
|---|---|---|
| Public doc | visible | visible |
| Private doc in **their** collection | hidden | **visible** ← positive discriminator |
| Private doc in someone else's | hidden | **hidden** ← negative discriminator |

## Prerequisites

- SSH access to an app node — the internal hostnames do not resolve from outside the VPC,
  so the script runs there (see [howto-access-mandala-nodes.md](howto-access-mandala-nodes.md))
- A test IdP identity with real Group memberships (dev-0: `staff`/`staffpass` → uid 600)
- Python 3 (stdlib only)

## Steps

```bash
scp scripts/verify-solr-proxy-visibility.py <node>:/tmp/
ssh <node> python3 /tmp/verify-solr-proxy-visibility.py
```

Useful flags: `--drupal`, `--proxy`, `--core`, `--user`, `--password`,
`--public-id`, `--mine-id`, `--not-mine-id`, and `--discover` (prints the queries that
regenerate the fixture set).

## Verify

```
== 4. authenticated: entitlement ==
  PASS  public doc still visible: 1
  PASS  private doc in THEIR collection NOW VISIBLE: 1   <- 0 here means fail-closed
  PASS  private doc of ANOTHER user STILL HIDDEN: 0
  (authenticated *:* = 751566, anonymous was 751032)
  PASS  authenticated sees more than anonymous (+534)

passed=12 failed=0
```

Exit codes: `0` PASS, `1` FAIL (a real visibility defect), `3` SETUP (could not test).

## ⚠ Two traps that produced false results on the first run

Both of these made a **correctly working proxy look broken**. Read them before trusting
any Solr assertion you write.

### 1. `id` is NOT unique in kmassets

Four separate documents share the id `1821`:

```
id=1821  vis=None  type=places        <- kmaps taxonomy shadows (ADR 006)
id=1821  vis=None  type=subjects
id=1821  vis=None  type=terms
id=1821  vis=1     type=audio-video   <- a public asset
```

The first draft of this test used `1821` as the "not mine" fixture and reported
`got 4, expected 0` — which reads exactly like a visibility breach. It was not one: the
four documents anonymous could see were the **public** taxonomy shadows, and the private
`visibility_i=2` asset was correctly withheld.

**An id alone does not address a document in kmassets.** When choosing fixtures, verify
index-side that the id resolves to exactly one document:

```
/solr/kmassets/select?q=id:"<id>"&rows=10&fl=id,visibility_i,asset_type
```

Prefer prefixed ids (`images-11-95599`) over bare numerics, which collide with the
taxonomy shadow entries.

### 2. Client `fq` filters are deleted, not intersected

`setVisibility()` strips any client-supplied `fq` containing `visibility` before applying
its own. So a successfully blocked injection looks like **no effect at all** — the
un-injected count — *not* zero. The first draft expected zero, on the assumption the
client filter would be applied and then intersected. It is not. Assert
`injected == baseline`.

## Fixtures are data, not code

The default ids were discovered on 2026-08-20 against dev-0 by faceting `kmassets` on
`visibility_i` and intersecting with uid 600's real Group memberships:

| `visibility_i` | count | meaning |
|---|---|---|
| 1 | 276,924 | public |
| 2 | 8,816 | private ← the interesting class |
| 3 | 452 | semi-private (allowed by the token) |
| *(none)* | 474,108 | kmaps taxonomy |

uid 600 belongs to 4 collections, and 25 private documents sit inside them — small and
specific enough that a fail-closed regression makes them vanish. If the dev index is
rebuilt these ids may change; `--discover` prints the queries to find new ones.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `private doc in THEIR collection` returns 0 | Fail-closed: no Redis token for that uid | Check `mandala_solr_fq:{uid}` exists in Redis **db0**; it is written by `hook_user_login` with a 1h TTL |
| Authenticated total == anonymous total | Same as above; the token is missing or Redis is unreachable | Proxy logs `Redis unavailable, falling back to anonymous visibility` |
| `private doc of ANOTHER user` returns >0 | Either a real leak **or** an ambiguous fixture id | Check trap 1 first — confirm the id resolves to one document index-side |
| `SETUP: proxy /auth returned no sid` | The OAuth2 chain is broken | Run `scripts/verify-oauth2-userinfo.sh` first; it isolates that layer |
| Everything returns 0 | Wrong core path | The map is `/solr/kmassets` and `/solr/kmterms` |

## Related

- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [ADR 006 — kmterms in kmassets shadow pattern](../adr/006-kmterms-in-kmassets-shadow-pattern.md) — why ids collide
- [howto-verify-oauth2-authenticated-path.md](howto-verify-oauth2-authenticated-path.md) — the auth layer underneath this test
