# Migrated Images nodes are all owned by Anonymous; D7 authorship was not carried

**Area:** migration / Images / authorship
**Raised during:** Sprint 3 AV4 (2026-09-09) — found while writing the AV node migration
**Jira:** (add when available)
**Priority:** Medium

## What was found

`d7_images_shanti_image.yml` has **no `uid` mapping at all**. Every one of the
111,340 migrated `shanti_image` nodes on dev-0 is therefore owned by uid 0
(Anonymous):

```
SELECT uid, COUNT(*) FROM node_field_data WHERE type='shanti_image' GROUP BY uid;
  0  |  111340
```

The D7 source has **18 distinct authors** across those nodes. None of that
survived.

This was not noticed in Sprint 1 because nothing in the D11 UI surfaces node
ownership yet, and the migration reported no errors — an unmapped destination
property is simply left at its default, which is exactly the silent-success shape
this project keeps running into.

## Why it matters

Node ownership is not cosmetic here:

- **Access.** ADR 015's editorial model and the Group permissions built in
  Sprint 1 include "own content" style checks. Everything owned by Anonymous
  cannot be reasoned about correctly.
- **Fidelity.** ADR 008 is *migrate, not improve*; dropping the author is a
  regression against D7, not a deliberate simplification.
- **It is cheap to fix and expensive to fix late.** The correct mapping is one
  line (`uid: node_uid`); re-running 111,340 nodes with `--update` is not.

## The adjacent bug, already fixed

The same investigation found that `d7_images_collections.yml` and
`d7_images_subcollections.yml` mapped **`uid: uid`**, which resolves to nothing —
the `d7_node` source exposes the author as `node_uid`, and there is no `uid`
source field:

```
$ ddev drush migrate:fields-source d7_images_collections
  node_uid       Node authored by (uid)
  revision_uid   Revision authored by (uid)
```

dev-0's 171 groups *do* carry correct D7 uids today, so this never bit — but it
would have, silently, the next time those two migrations ran. **Corrected to
`uid: node_uid` on 2026-09-09** as part of the AV4 branch, with the reasoning
recorded in the config comments. The AV migrations (`d7_av_audio`,
`d7_av_video`, `d7_av_collections`, `d7_av_subcollections`) all use `node_uid`
and were verified against the source.

## What is deferred

The **`shanti_image` node authorship** itself. Adding `uid: node_uid` to
`d7_images_shanti_image.yml` is trivial; applying it to already-migrated content
means a `--update` pass over 111,340 nodes, which is a scheduling and
re-verification question rather than a code one, and it interacts with the
kmassets Solr re-index. It also touches
[`kmassets-uid-identity-across-migration.md`](kmassets-uid-identity-across-migration.md),
which already asks what `uid` means downstream.

**Proposed:** fix the mapping now so any future re-run is correct, and decide
separately whether to backfill the existing 111,340. Verified on 2026-09-09 that
all 614 distinct D7 AV author uids and all 87 collection author uids exist in
D11, so the identity mapping itself is sound — this is purely a missing mapping,
not a missing user.
