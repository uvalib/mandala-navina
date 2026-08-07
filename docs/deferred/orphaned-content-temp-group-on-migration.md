# Orphaned (collection-less) content must migrate into a temporary review group, not drop

**Area:** migration / Group / content model / access
**Raised during:** ADR 015 Q2 decision, 2026-08-07 (Than, team present)
**Jira:** (add when available)
**Priority:** **Medium–High** — applies to **every** per-site asset migration; if the current membership migration silently drops orphans, content is lost on cutover.

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
