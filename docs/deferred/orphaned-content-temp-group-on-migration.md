# Orphaned (collection-less) content must migrate into a temporary review group, not drop

**Category:** migration-general, access-control
**Raised during:** ADR 015 Q2 decision, 2026-08-07 (Than, team present)
**Issue:** #289
**Priority:** **Medium–High — direction CONFIRMED 2026-09-28 (Than, Yuji); open specifics RESOLVED 2026-10-02 (Than); BUILT and the AV exposure FIXED on local DDEV 2026-10-02; MERGED to `main` 2026-10-05 ([PR #275](https://github.com/uvalib/mandala-navina/pull/275)).** Applies to **every** per-site asset migration. Confirmed 2026-10-02: the current Images membership migration genuinely drops orphans today, not just a theoretical risk. AV orphans are quantified and carry a live access consequence — see "Confirmed 2026-09-28" below. **Images and AV sweeps RUN on dev-0 by Than, 2026-10-14** (see "Run on dev-0" below); **Texts/Sources sweeps wait on those sites' migrations**, not yet run.
**Owner:** Than

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

## Open specifics — resolved 2026-10-02 (Than)

- **Confirmed this is a live gap, not just a future requirement.** Checked
  `migrate_plus.migration.d7_images_image_collection_membership.yml`: it only creates a
  `group_node` relationship when a D7 `og_membership` row exists for that node. A node with no
  membership row gets no relationship at all — no fallback, no sweep step. Every orphan today
  migrates into D11 exactly as group-less as it was in D7. Applies the same way to the AV
  migration once it reads its own membership source.
- **Group identity: one parent group, with a child per site.** A single top-level "Orphaned
  Content" group, holding one child subgroup per site (AV, Images, Texts, Sources), each
  holding that site's own orphans. This reuses the existing hierarchy structurally: the parent
  is a `collection`-bundle group, and the four children are `subcollection`-bundle groups under
  it — the same parent/child relationship real collections and subcollections already have, so
  no new entity-reference field is needed to express the nesting.
  - **Revised 2026-10-02 (Than): these groups are created by hand** by admin editors through
    the normal Group UI, not by a drush command — see "Built" below.
- **Group bundle: reuse `collection`/`subcollection`, don't add a new bundle.** Add a new
  boolean flag field (e.g. `field_is_review_holding`) to both existing group bundles instead of
  introducing a distinct entity bundle. Set `true` on the parent "Orphaned Content" group and
  each of its four site children.
  - **Still open, not decided -- ASSIGNED to Than, 2026-10-05:** does every piece of code that treats "is in a collection" as
    sufficient for visibility/listing need to check this flag directly on each group, or can a
    subcollection inherit "is review holding" from its parent without the flag being set
    redundantly on every child? Whoever implements this should decide and document it — getting
    it wrong either re-exposes review content (flag not checked somewhere) or makes every new
    child subgroup need its own manual flag (easy to forget).
  - Every listing surface that currently shows collections/subcollections (`/collections`,
    `/my_collections`, any gallery/view keyed off group membership) must be checked against the
    flag and must exclude flagged groups — this follows the same enforcement gap already found
    for private/UVA content in
    [[collection-visibility-not-enforced-in-listings]], so don't assume a flag field alone is
    sufficient without also auditing the views.
- **Ownership: AV/content staff per site**, matching the precedent already set for the AV14
  media-less-nodes handoff ([[av14-media-less-nodes-staff-handoff]]) — engineering files the
  content into the right per-site holding group, content staff decide per node whether to
  reassign it to a real collection or delete it. No tracked-task mechanism exists yet (Jira
  integration is itself still deferred); for now the per-site count in this note (and whatever
  the AV/Texts/Sources sweeps find) is the handoff list.
- Add "sweep orphans into the review group" to the per-site migration checklist alongside ADR
  015's content_editor / contributor-tier items.

## Built 2026-10-02, verified on local DDEV

- **Field:** `field_is_review_holding` (boolean) added to both the `collection` and
  `subcollection` group bundles. **Editable on the group edit form** (not hidden) with an
  editor-facing description, since an admin now creates a review group by hand and has to be
  able to flag it themselves.
- **Listing exclusion:** `GroupQueryAlter` now joins the flag unconditionally and excludes any
  flagged group from a `group_access`-tagged query (e.g. `/collections`) for every viewer,
  including an account with bypass permission — the one case in this module where bypass is
  deliberately NOT honoured, since a review-holding group is never a real, browsable collection
  for anyone. Covered by a new kernel test, `ReviewHoldingGroupVisibilityTest`.
- **Resolved the "still open" question above:** the flag is set explicitly on every group
  (parent and each child) rather than inherited — simpler to reason about and query, at the cost
  of needing to remember to set it on any future child. Not inheritance-based.
- **Revised design (Than, 2026-10-02): group creation is manual, the sweep is the only
  tool.** An admin editor creates the parent/child group(s) by hand through the Group UI,
  checks `field_is_review_holding` on it, and notes its group id. There is **no**
  `group:create-review-holding-groups` command — an earlier version of this build had one,
  assuming a fixed one-parent-plus-four-children shape with fixed labels; removed because the
  shape and labels are now the editor's call, not something to hardcode.
- **Drush command** (`mandala_group_inheritance` module, `OrphanedContentReviewCommands.php`):
  `group:sweep-orphans` (alias `sweep-orphans`), options `--gid` (required — the target group
  id, created by hand first) and `--site` (optional — `audio-video`/`images`/`texts`/`sources`;
  omit it to sweep every known site into the same `--gid`), plus `--dry-run`. Scoped to
  `CollectionVisibility::groupNodeBundles()` and `field_legacy_site`; a node's own
  `field_group_content_access` override is left alone, so a restriction already set is kept.
  Warns (but still proceeds) if the target `--gid` isn't flagged `field_is_review_holding`.
- **Batching fix, found live:** sweeping Images (111k+ nodes) with the first version OOM'd —
  `loadMultiple()` was called on every candidate id at once. Fixed by chunking in batches of
  500 and resetting the node storage's static cache per batch. Verified: a full `--site=images`
  dry-run now completes cleanly and finds exactly the 36 known orphans, no OOM.
- **Verified live on local DDEV:** ran the real (non-dry-run) sweep for AV. Swept 66 AV
  orphans. Checked all 18 previously-exposed nodes (6 private, 12 UVA-only, the exact count
  from "Confirmed 2026-09-28" below) by id: every one now resolves into the chosen group and
  `$node->access('view', $anonymous_user)` returns `FALSE` for all 18, with zero remaining
  exposed nodes of that shape. `config:status` stayed clean throughout.
- **Images swept too, 2026-10-05 (Than), local DDEV.** Real (non-dry-run) sweep found and
  moved exactly the 36 known `shanti_image` orphans, no OOM — the batching fix held under
  the real run, not just the earlier dry-run. The sweep command also gained a live progress
  counter (`N of TOTAL checked`) in the same session, since orphans are rare enough on a
  111k+-node site that the per-match notice alone can go silent for a long stretch and look
  hung.
- **Review groups owned and populated, 2026-10-05 (Than), local DDEV.** All five groups
  (414 parent, 415 AV / 416 Images / 417 Texts / 418 Sources) now have `ShantiAdmin` (uid 1)
  set as both owner and member — they'd defaulted to Anonymous on creation.
- **Merged 2026-10-05, [PR #275](https://github.com/uvalib/mandala-navina/pull/275).**
  Branch `feat/orphaned-content-review-group` deleted (local and remote) post-merge.
- **Still not done:** Texts/Sources sweeps, which wait on those sites being migrated —
  there's nothing to sweep yet. Nothing has been run on dev-0 (local DDEV only); dev-0 needs
  its own review group(s) created by hand (and `ShantiAdmin`, or whichever account should
  own them there, set as owner/member) before any sweep can target it.

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

## Run on dev-0, 2026-10-14 (Than)

Than ran `group:sweep-orphans` on dev-0 for Images and for AV; both completed. The groups were
created by hand: a parent collection "Orphaned Nodes" with a child subcollection **per content
type**. That is the same thing the 2026-10-02 design called a child "per site": D7 had one site
per content type, and D11 has one site, so the grouping is named by content type. Claude did not
run, read or verify anything on dev-0; the counts and group ids belong in Than's own record of the
run.

## Decision 2026-10-14 (Than): a child always follows its parent's flag

**Supersedes** the 2026-10-02 "set explicitly on every group, not inherited" call above. Found on
dev-0: with the parent flagged, the child subcollections could not be edited, and the flag could
not be set on them.

- **Cause (reproduced on DDEV):** the parent picker (`field_parent_collection`) lists groups
  through the `group_access` query, which now excluded the flagged parent, even for uid 1. So the
  parent was not a valid choice in its own field and the child could not be saved. Separately,
  nothing carried the flag from parent to child, so an unflagged child of a flagged parent was
  still listed.
- **Rule:** a subcollection is review-holding exactly when its parent collection is. No override.
  No parent, or an unflagged parent, means unflagged.
- **Built** (`mandala_group_inheritance`): `group_presave` derives the flag from the parent on
  every subcollection save (creation, re-parenting, hand edits); `group_update` on a collection
  re-saves its children when the collection's flag changes; the flag is disabled on the
  subcollection add/edit forms (form alter, no config change); `GroupQueryAlter` also excludes a
  subcollection whose parent is flagged, so a stale child value cannot expose review content; and
  it leaves a flagged parent visible to an account with group bypass in the subcollection's own
  parent picker only, never anywhere else. Kernel tests in `ReviewHoldingGroupVisibilityTest`,
  each shown failing without its fix.
- **Not done here:** re-parenting a subcollection still does not re-sync its visibility or
  inherited memberships; see [[subcollection-reparenting-does-not-resync-visibility-or-members]].

### For Than to check on dev-0 after this deploys

Claude does not touch dev-0. These are yours to run, read-only unless noted:

1. Each child under "Orphaned Nodes" shows the flag ticked (the form shows it greyed out).
   Existing children saved before this change are only corrected the next time each is saved, or
   when the parent's flag is next changed; the query also hides them now regardless, via the parent
   check, so listings are safe before that.
2. `drush group:sweep-orphans --dry-run` per site reports no remaining group-less nodes.
3. None of the review groups appear on `/collections`, for an administrator or anonymous.

