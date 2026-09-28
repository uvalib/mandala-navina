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

## dev-0 (2026-09-28)

PR #255 merged and deployed clean (`a321e6c5…`; `config:status` shows only the known
pre-existing `simplesamlphp_auth.settings` drift). Post-deploy checks from the PR's own test
plan, run against real dev-0 data:

- **Spot-check, all three bundles, both tiers:** video/audio private-collection nodes
  (nid 116898/111738) now denied to anonymous, matching the pre-fix "EXPOSED" cases above.
  A `shanti_image`/`video` node in a UVA collection (nid 29035/116905) denied to anonymous,
  viewable to an authenticated non-member (uid 600).
- **Scoped kmassets re-index:** 511 published nodes sit in a UVA-only collection on dev-0
  (453 video, 53 `shanti_image`, 5 audio — narrower than AV alone, since the fix applies to
  every bundle). Re-indexed via `KmassetDirectSink::indexNode()`, not `kmassets:index-all`
  (511/511, 0 skipped, 0 errors). Confirmed on the write master: sampled docs across all
  three bundles now carry `visibility_i:3`/`visibility_s:uva`. The search reader
  (`mandala-index-dev`) returns 0 for the same uids — expected, not a regression: it's the
  same public-only/stale-vs-master gap already tracked in
  [[kmassets-audit-checks-master-not-search-reader]], not something this re-index could fix.

**Still outstanding:** staging and any future environment need the same backfill/spot-check/
scoped re-index sequence.

## Listings (PR #259) and the Solr node-override gap (PR #261), 2026-09-28

The residual above is now fixed. **PR #259** ("Enforce collection visibility in Views
listings", Than driving, Yuji present, decided as a group 2026-09-28) closes it with
query-time filtering keyed on the `node_access`/`group_access` tags — see
[[collection-visibility-not-enforced-in-listings]] for the full detail, including why
query-time filtering was chosen over node grants and the extracted `CollectionVisibility`
resolver that `hook_entity_access()`, the new query alters, and
`CollectionFieldContributor` all now share.

Verifying #259 surfaced a second, independent gap: **`CollectionFieldContributor` read the
collection's `field_group_access` directly and ignored the node's own
`field_group_content_access` override**, which Drupal treats as authoritative. Solr and
Drupal disagreed about **376 published nodes** (measured on the authoring database), in
both directions — some restricted nodes indexed as public (an exposure that would have
*grown* on the next full `index-all`, since the mapping derived from the public collection),
others public nodes indexed as restricted (a correctness bug, not an exposure). Fixed in
**PR #261** by resolving through the same `CollectionVisibility::forNode()` resolver
`hook_entity_access()` and the listings alters use, so Solr can no longer disagree with
Drupal about a node's visibility.

### dev-0 verification (2026-09-28)

Deploy (`b268ca7c…`, commits through PR #261) succeeded; `config:status` clean (only the
known `simplesamlphp_auth.settings` drift).

- **Listings (#259):** live matrix across all three views, all three account tiers —
  monotonic and correctly shaped (anonymous < member < bypass in every view):

  | View | Anonymous | uid 600 (member) | Bypass |
  |---|---|---|---|
  | `av_gallery` | 8,601 | 8,996 | 11,582 |
  | `image_gallery` | 108,098 | 108,176 | 111,339 |
  | `collections` | 219 | 250 | 387 |

  (dev-0's real counts differ from the PR's own DDEV-measured 9,555/111,269/238 — same
  pattern, different corpus.) Targeted check via the actual `node_access`-tagged
  `EntityQuery` path (not the Views pager, which only samples the current page): nid 116898
  (the private video from the case above) correctly denied to anonymous, correctly visible
  to bypass.
- **Solr node-override fix (#261):** dev-0's live mismatch count is **255** nodes (audio/
  video only — `shanti_image` has no `field_group_content_access`), not the PR's 376; same
  "different database" explanation as the listings numbers. Re-indexed all 255 via
  `indexNode()` — 255/255, 0 errors. Confirmed on the write master: e.g. nid 111761 flipped
  from the old, wrong `visibility_i:3`/`uva` (written during PR #255's own re-index, before
  #261 fixed the mapping) to the correct `visibility_i:1`/`public`, matching both its own
  node-level override and live `hook_entity_access()`.

**Still outstanding:** the 18 published `video` nodes with an explicit restriction but no
owning collection (documented in
[[orphaned-content-temp-group-on-migration]], decided 2026-09-28 to migrate into a
temporary review group — not yet built). Staging has received none of today's fixes.
