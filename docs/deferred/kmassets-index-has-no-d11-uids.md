# kmassets holds only D7-format uids, so the D11 authenticated path matches nothing

**Area:** solr / kmassets / ADR 014 / migration / identity
**Raised during:** Session 2026-08-12 (testing the authenticated case after the solr-proxy pipeline went live)
**Jira:** (add when available)
**Priority:** **High — blocks the entire authenticated half of ADR 014.** The public
path is unaffected and works today.

## Measured, not inferred

Queried the deployed kmassets replica directly from dev-0 (2026-08-12):

| Query | Docs |
|---|---|
| `collection_uid_s:images-11-*` — the D11 format | **0** |
| `uid:*-11-*` — any D11 uid at all | **0** |
| `collection_uid_s:images-collection-*` — the D7 format | **111,416** |
| Non-public docs (`-visibility_i:1`) | 406,496 |

**The index contains no D11 documents whatsoever.**

## Why that breaks authenticated access specifically

`VisibilityTokenBuilder::restrictedCollectionUids()` emits
`'images-11-' . $group->id()` — the D11 group id, in the frozen
`{service}-11-{d11-entity-id}` uid format. Its own docblock is explicit that this must
mirror `CollectionFieldContributor::groupKmassetUid()` exactly, "since a mismatch here
silently breaks the entire private-collection access path".

Both D11 sides agree with each other. **Neither agrees with what is in the index**,
which is all D7-era `images-collection-{d7-nid}`. So the `fq` clause
`(collection_uid_s:(images-11-55))` matches zero documents, and an authenticated user
sees **exactly what an anonymous user sees**.

The failure is silent in the worst way: no error, no 500, correct-looking results —
just private content quietly missing. Indistinguishable from "that collection is
empty".

## Why the index is in that state

The D11 kmassets write path has never run against it. Consistent with the
long-standing note that `solr_master_url` is unset on dev-0, which makes
`kmassets:index-all` and `kmassets:audit` no-ops there. The index is still being fed
by the **D7** pipeline, which is expected — D7 is still in production — but it means
D11 and the index disagree about identity.

## How this was found

Not by a failing test — by asking whether the authenticated case had actually been
tested after the solr-proxy pipeline went green. It had not. Constructing the test
surfaced the mismatch:

- A token written in **D7** format (`images-collection-1314411`) correctly exposed
  **3,112 documents** that return 0 anonymously — so the proxy, Redis, and Solr
  enforcement all work.
- The same test with a **D11**-format token would have returned nothing extra, and
  would have looked like a broken proxy rather than an index-format mismatch.

## What needs to happen

Not solvable in the proxy or the visibility module — both are correct. Options, in
rough order of likely preference:

1. **Run the D11 kmassets write path against a target index**, so documents carry
   `{service}-11-{d11-id}` uids and `collection_uid_s` values the token can match.
   Requires settling `solr_master_url` on dev-0 and deciding *which* index D11 writes
   to while D7 still owns the live one.
2. **Decide the cutover story for uid identity.** Already partly tracked — see
   [kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md)
   and [kmassets-uid-consumer-analysis.md](kmassets-uid-consumer-analysis.md), which
   cover the `uid_legacy_s` shim for old-format *requests*. This note is the mirror
   image: new-format *tokens* against old-format *documents*.
3. **Interim, if an authenticated demo is needed sooner:** have
   `VisibilityTokenBuilder` emit the D7-format uid via `field_legacy_nid`. A
   deliberate, reversible bridge — but it re-couples D11 to D7 identity and should be
   a decision, not a quiet patch.

## What is NOT blocked

The public/anonymous path — the 90% case — works today and is unaffected: 562,952
docs served through the proxy with the anonymous filter applied, ALB target healthy.

## Also worth knowing

dev-0 currently has **2 users** (anonymous + admin) and **22 private/restricted
groups**, so there is nobody who could be a member of one. The user migration has not
been run there. Even with matching uids, an end-to-end authenticated test needs users
with real group memberships.

## Cross-references

- [ADR 014](../adr/014-hybrid-solr-proxy-design.md) — the design this blocks
- [ADR 013](../adr/013-drupal-source-of-truth-solr-client-compatibility.md)
- `drupal/web/modules/custom/mandala_solr_visibility/src/VisibilityTokenBuilder.php`
- [kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md)
- [kmassets-uid-consumer-analysis.md](kmassets-uid-consumer-analysis.md)
- [solr-proxy-has-no-cicd-pipeline.md](solr-proxy-has-no-cicd-pipeline.md) — found while closing that out
