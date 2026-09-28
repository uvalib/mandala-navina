# Orphaned (collection-less) content must migrate into a temporary review group, not drop

**Area:** migration / Group / content model / access
**Raised during:** ADR 015 Q2 decision, 2026-08-07 (Than, team present)
**Jira:** (add when available)
**Priority:** **Medium–High — direction CONFIRMED 2026-09-28 (Than, Yuji).** Applies to **every** per-site asset migration; if the current membership migration silently drops orphans, content is lost on cutover. AV orphans are now quantified and carry a live access consequence — see "Confirmed 2026-09-28" at the end.

## Context

ADR 015 Q2 (see [`adr-015-unanswered-questions-at-merge.md`](adr-015-unanswered-questions-at-merge.md))
established a **universal D11 rule: no asset content may exist outside a group** — enforced for
all roles. This is faithful to D7's *intended* model (content lives in a collection); D7 only
failed to *technically* constrain it.

Because the constraint wasn't enforced in D7, a small amount of **orphan content** exists — asset
nodes belonging to no collection or subcollection. These are **anomalies**: data-entry mistakes,
or nodes created before collections existed. They are not a supported "collection-less" feature,
but they are **real content and must not be silently dropped** by a migration that assumes every
asset is group content.

## Evidence (Images, prod dump `mandala-prod-images-db_2026-06-29-930.sql.gz`)

- `shanti_image` nodes: **111,340**
- …in a collection/subcollection: **111,304**
- **…orphaned (no collection/subcollection membership): 36** (~0.03%)

Sample orphan nids skew to old/low IDs plus a cluster in the 15,000s — consistent with "mistakes
or pre-collection legacy." This 36 matches the long-standing migration gap (111,343 nodes vs
111,307 memberships in the staging snapshot). Other asset node types (`asset_link`, `image_agent`,
`image_descriptions`, `external_classification`) and **every other site** (Texts, Sources, AV,
Mandala Home) must each be swept for their own orphans — the count is site- and type-specific.

## Requirement

- On migration, detect asset content with **no group membership** and place it into a dedicated
  **temporary review group** rather than dropping it or force-fitting it into an arbitrary
  collection.
- The review group holds these anomalies until a human reviews each and either **reassigns it to
  a real collection or deletes it.**
- The group must be **non-public** (these are unreviewed anomalies) and clearly named as a
  holding area.

## Open specifics (to decide when implementing)

- **Temp group identity:** one global review group, or one per site? Group type — a normal
  `collection`, or a distinct holding bundle?
- **Ownership / review workflow:** who owns the review, and is there a tracked task per item?
- **Does 1b.2 already drop these?** Check whether the current
  `d7_images_collection_memberships` migration silently skips orphans (the 36-node gap suggests
  it may). If so, this is a live gap, not just a future requirement.
- Add "sweep orphans into the review group" to the per-site migration checklist alongside ADR
  015's content_editor / contributor-tier items.

## Confirmed 2026-09-28 — AV orphans quantified, with an access consequence

Surfaced while implementing the listings-visibility enforcement
([[collection-visibility-not-enforced-in-listings]]), which needed to reproduce exactly what
`hook_entity_access()` does for a node with no collection.

**The direction in "Requirement" above is confirmed:** orphans migrate into a temporary
review group. Decided by Than with Yuji present.

### The new evidence

`mandala_group_inheritance_entity_access()` returns `neutral()` as soon as a node has no
owning collection — **before** it reads the node's own `field_group_content_access`. So a
node explicitly marked private or UVA is fully visible to anonymous users purely because it
has no collection.

Measured on a dev-0-parity local database, published nodes only:

| Bundle | `field_group_content_access` | Count |
|---|---|---|
| `video` | 2 (private) | 6 |
| `video` | 3 (UVA only) | 12 |

**18 nodes, all `video`** — none in `audio` or `shanti_image`. Small, but it is the *shape*
that matters: someone set a restriction and the restriction is not being honoured, in the
one case the model does not cover.

This is also what accounts for an 18-node discrepancy that had to be reconciled before the
listings work could proceed — a naive resolution query hid them (reading the node override
first), while the live hook allows them. The hook's behaviour is authoritative and the
enforcement reproduces it faithfully; the 18 are a *content* problem, not an enforcement one.

### Why it is handled here rather than in the access layer

Two options were weighed. Changing the hook to honour a collection-less node's own override
would fix these 18 immediately, but it makes the access model depend on a state that ADR 015
Q2 says must not exist (no asset content outside a group), and it would silently change
behaviour for any future orphan. Migrating orphans into a review group removes the state
itself, at which point the question dissolves and the access layer needs no special case.

The second was chosen. The access layer now reproduces the current behaviour deliberately,
with a comment pointing here, in both `mandala_group_inheritance_entity_access()` and
`NodeQueryAlter`.

### Consequences for the open specifics above

- **The temp group must be non-public** — already required above, and these 18 make it
  concrete: 12 are UVA-only and 6 private in D7, so a public holding group would preserve
  the exposure it is meant to end.
- **The sweep must cover AV**, not just Images. The 36 `shanti_image` orphans recorded above
  were the only ones counted when this note was written; AV has its own, and Texts and
  Sources have not been checked at all.
- **Orphans with a node-level restriction should keep it.** These 18 carry a real
  `field_group_content_access` value that the review group's own visibility must not
  override — the same "default loosens" failure mode as
  [[av-collections-without-d7-group-access]].
