# Private and UVA collection content was not enforced in Drupal for AV

**Area:** access / Group / AV / mandala_group_inheritance / ADR 011 / ADR 015
**Raised during:** Sprint 3 AV7 (session 2026-09-25, reassessing the AV7 row)
**Jira:** (add when available)
**Priority:** **High -- FIXED 2026-09-25 for node pages.** Residual: listings are still
unfiltered, tracked separately in
[[collection-visibility-not-enforced-in-listings]]

## What happened

Three independent gaps meant D11 granted anonymous users view access to AV content that
D7 restricted:

1. **`mandala_group_inheritance_entity_access()` was hardcoded to `shanti_image`.** The
   `audio`/`video` bundles never reached `_mandala_group_inheritance_node_access()`, so the
   group-role view grant added by PR #201 was never restricted for private collections.
2. **The check only tested `field_group_access !== 1`.** UVA-only collections (value `2`)
   fell straight through to neutral, for *every* bundle, not just AV.
3. **`field_group_content_access` was read by nothing at all.** A node's own explicit
   Private/UVA value had no effect, and the ~78% of AV nodes storing `0` ("use group
   defaults") had no resolution path from their collection.
4. **The same `!== 1` test in `_mandala_group_inheritance_group_access()`** left UVA
   collections' *own pages* public: **24 of 30** published UVA-only collections were
   anonymously viewable while their contents were not. Found by surveying all 408 groups —
   a single spot-check had been misleading, because the first UVA collection sampled was
   denied only for being unpublished. Private collections were correctly enforced (0 of 134
   viewable).

Verified empirically on both local DDEV and dev-0 before the fix:

```
video        nid 116898  private collection => anon CAN VIEW  <-- EXPOSED
audio        nid 111738  private collection => anon CAN VIEW  <-- EXPOSED
shanti_image nid 1       private collection => anon DENIED (correct)
```

**2,027 published AV nodes** were viewable by anonymous users that D7 restricted:

| Cause | Nodes |
|---|---|
| AV in private collections (the `shanti_image` bundle hardcode) | 1,457 |
| Anything in UVA collections (`field_group_access = 2` never enforced) | 444 |
| Node-level Private/UVA inside a public collection (field read by nothing) | 126 |

Images were unaffected: correctly denied at `field_group_access = 1`, and no published
image sits in a UVA collection.

**Not an incident.** dev-0 is VPN-internal, there was no D11 production, and D7 remained
the live site enforcing correctly. This was a rebuild defect that would have become a real
exposure at cutover. Search never leaked it either — the Solr side failed closed, see below.

## Why it happened

This is the **fourth** bug of the same shape: a bundle name hardcoded while Images was the
only migrated site, then inherited wrongly by AV. The others were PR #201 (group role
grants), PR #199 (`groupKmassetUid()`), and `SiblingCarouselService::getCollectionMemberNids()`.

## The fix (2026-09-25)

- **Bundle-agnostic dispatch.** `_mandala_group_inheritance_group_node_bundles()` reads
  `group_relationship_type` config for `group_node:*` plugins instead of naming bundles, so
  Texts and Sources are covered the moment their bundles are enabled. Statically cached
  because the hook runs per node on listing pages.
- **Effective-visibility resolution.** New
  `_mandala_group_inheritance_effective_visibility()` implements the rules Than confirmed
  2026-09-24 against D7's own `mb_access.module`: a non-zero `field_group_content_access`
  (1 public / 2 private / 3 UVA) **wins**; `0` or an absent field resolves from the
  collection's `field_group_access` (0 public / 1 private / 2 UVA). Resolved live, not
  denormalised onto nodes, because a collection's visibility is editable and ~9,000 nodes
  would go stale.
- **UVA = any authenticated user**, copying D7 exactly (its grant went to
  `DRUPAL_AUTHENTICATED_RID` and never checked NetBadge). Anonymous never sees UVA.
- **Owning-collection lookup** matches `CollectionFieldContributor::getOwningGroup()`
  deliberately, so Drupal and search answer identically (ADR 013/014).
- **D7's published-status quirk deliberately not copied.** In D7 a group-default node's
  public grant took the *collection's* published status, so an unpublished node in a
  published public collection could be publicly visible. Confirmed with Than as a bug to
  fix, not replicate.
- **Cache contexts:** private denial is `cachePerUser()` (depends on membership); UVA denial
  uses `user.roles:authenticated` (identical for every logged-in account, so per-user would
  fragment the cache for nothing). All outcomes depend on both the node and the group.

Two Solr-side corrections landed with it:

- **`CollectionFieldContributor::ACCESS_TO_VISIBILITY`** mapped `field_group_access` 2 to
  `private` on the reading that it meant "subscribable" — recorded at the time as a decision
  not yet made. It was made 2026-09-24: 2 is UVA, so it now maps to `visibility_i` 3. The old
  mapping failed closed rather than leaking, but it meant no document was ever labelled 3,
  and **the UVA tier was already correctly implemented** in the proxy's anonymous filter
  (`visibility_i:1`, `solr-proxy/proxy/Searcher.php:190`) and `VisibilityTokenBuilder`'s base
  clause (`visibility_i:(1 3)`) — it simply had nothing to act on. No `fq` work was needed.
- **`VisibilityTokenBuilder::restrictedCollectionUids()`** still hardcoded `images-11-`
  while PR #199 had fixed its writer counterpart to derive the service from
  `field_legacy_site`. Both docblocks said they must never drift; they had. A member of a
  private *AV* collection got `images-11-{gid}` against documents carrying
  `audio-video-11-{gid}` and could not find their own content. Failed closed, so no leak.
  The derivation is duplicated rather than shared because `mandala_solr_visibility` runs on
  the login path and depends only on `group`/`user`; depending on `mandala_kmassets_sync`
  would drag in paragraphs, `shanti_iiif` and `shanti_kmaps_admin`. Both docblocks now name
  the other.

## Verification

All 21 live `(collection access, node access, bundle)` combinations on the real local corpus
behave as designed, for anonymous and for an authenticated non-member. Highlights:

- 2,027 nodes newly denied to anonymous; the 70 private images stay denied and the 111,233
  public images stay viewable.
- The **27 `video` nodes explicitly marked Public inside a private collection stay
  viewable** — the reason the node-level rule had to land in the same change as the bundle
  fix, rather than after it.
- UVA nodes: denied for anonymous, viewable for any authenticated user.
- A real non-privileged member (uid 600) still views private-collection content that
  anonymous is denied.
- All three bypass permissions still reach private content.
- Collection pages across all 408 groups: public unchanged (238 viewable, 6 denied as
  unpublished); private 0 viewable for anonymous *and* for an authenticated non-member; UVA
  now 0 for anonymous and 24 for authenticated (the 6 denied being unpublished).

## Residual

Node **pages** are enforced; **listings are not**, because this is
`hook_entity_access()` and Views filters on the node *grants* table instead. The
`node_access` table holds exactly one row (realm `all`), so `/av` and `/images` still list
restricted titles to anonymous. Pre-existing and wider than AV — see
[[collection-visibility-not-enforced-in-listings]].

Also outstanding: staging and any future environment need the scoped kmassets re-index that
flips UVA collection members from `private` to `uva`.
