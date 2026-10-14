# How-To: Verify Solr proxy visibility filtering (ADR 014)

**Audience:** developers working in the monorepo
**Last reviewed:** 2026-10-14

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

Prefer prefixed ids (`images-11-74591`) over bare numerics, which collide with the
taxonomy shadow entries.

### 2. Client `fq` filters are deleted, not intersected

`setVisibility()` strips any client-supplied `fq` containing `visibility` before applying
its own. So a successfully blocked injection looks like **no effect at all** — the
un-injected count — *not* zero. The first draft expected zero, on the assumption the
client filter would be applied and then intersected. It is not. Assert
`injected == baseline`.

## Fixtures are data, not code

**Refreshed 2026-10-14:** `images-11-95599` had become public (`visibility_i=1`), which made
sections 1 and 6 fail even though the proxy was correct. The "mine" fixture is now
`images-11-74591` (private, in collection `images-11-111`, which is one of uid 600's seven
collections: `images-11-33/-111/-44/-160`, `audio-video-11-301/-292/-389`). Check a fixture's
`visibility_i` and collection before trusting a failure in sections 1 and 6.

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

## Transcript core (`--transcripts`, Spike 11 / #327)

The `mandala-av-transcripts` core has no visibility fields of its own. The proxy wraps the
user's kmassets filter in `{!join from=trid_i to=is_trid fromIndex=kmassets}` (solr-proxy PR
#315), so a transcript unit is visible only if its kmassets parent document is. A unit with
no parent matches nothing (fails closed).

No real kmassets document carries `trid_i` yet, so this check uses **synthetic** data in the
reserved `trid` range 990000-990999, written by `scripts/load-transcript-test-units.py`:

| trid | kmassets parent | Anonymous | uid 600 |
|---|---|---|---|
| 990001 | public | visible | visible |
| 990002 | private, in uid 600's collection | hidden | **visible** ← positive discriminator |
| 990003 | private, someone else's collection | hidden | **hidden** ← negative discriminator |
| 990004 | none | hidden | hidden (fail closed) |

Prerequisites beyond the above: the core exists on dev Solr (#327), `trid_i` has
docValues on the dev kmassets core (otherwise the join errors), and the proxy running on
dev-0 includes PR #315.

```bash
# 1. Load (dry run by default; add --apply to write). Writes go to the MASTER.
#    --link-kmassets also writes 3 parent docs into the SHARED dev kmassets core.
scripts/load-transcript-test-units.py --master http://<dev-master>:8080 \
    --replica http://<dev-replica>:8080 \
    --mine-collection <collection_uid_s uid 600 belongs to> \
    --other-collection <collection_uid_s uid 600 does not> \
    --link-kmassets --apply

# 2. Verify (on an app node, as above)
ssh <node> python3 /tmp/verify-solr-proxy-visibility.py --transcripts

# 3. Remove the test data when finished
scripts/load-transcript-test-units.py --master http://<dev-master>:8080 --cleanup --apply
```

Get the two collection uids from `--discover` (queries 2-4). `--replica` times
master-to-replica lag for the new core; record the number in
`docs/deferred/transcript-core-replica-lag-review.md`.

Expected output adds sections 7 (anonymous) and 8 (authenticated). Exit `3` with "no test
units visible" means the core is missing, the join is misconfigured, or the loader was
not run.

**Clean up afterwards.** The parent documents are real entries in the dev kmassets core until
`--cleanup` runs, and a `KmassetAuditor --fix` pass could delete or flag them. The loader
refuses to write parents if any other kmassets document already uses a `trid_i` in the
reserved range.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `private doc in THEIR collection` returns 0 | Fail-closed: no Redis token for that uid | Check `mandala_solr_fq:{uid}` exists in Redis **db0**; it is written by `hook_user_login` with a 1h TTL |
| Authenticated total == anonymous total | Same as above; the token is missing or Redis is unreachable | Proxy logs `Redis unavailable, falling back to anonymous visibility` |
| `private doc of ANOTHER user` returns >0 | Either a real leak **or** an ambiguous fixture id | Check trap 1 first — confirm the id resolves to one document index-side |
| `SETUP: proxy /auth returned no sid` | The OAuth2 chain is broken | Run `scripts/verify-oauth2-userinfo.sh` first; it isolates that layer |
| Everything returns 0 | Wrong core path | The map is `/solr/kmassets` and `/solr/kmterms`; the transcript core is `/solr/mandala-av-transcripts` (via `JOIN_ACCESS`) |
| Section 8 "THEIR collection" returns 0 but section 7 passes | Parent's `collection_uid_s` is not in uid 600's token, or the token is missing | Re-check `--mine-collection` against the token's collection list; then the Redis token as above |
| Join error mentioning `docValues` or `bytesPerDim` | kmassets `trid_i` lacks docValues, or `is_trid` type mismatch | See `solr/mandala-av-transcripts/README.md` (join prototype findings) |

## Related

- [ADR 014 — hybrid Solr proxy design](../adr/014-hybrid-solr-proxy-design.md)
- [ADR 006 — kmterms in kmassets shadow pattern](../adr/006-kmterms-in-kmassets-shadow-pattern.md) — why ids collide
- [howto-verify-oauth2-authenticated-path.md](howto-verify-oauth2-authenticated-path.md) — the auth layer underneath this test
