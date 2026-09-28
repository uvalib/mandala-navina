# Collection visibility is not enforced in listings (nodes or collections)

**Area:** access / Group / Views / node grants / ADR 011 / ADR 013 / ADR 015
**Raised during:** Sprint 3 AV7 (session 2026-09-25, verifying the AV access fix in PR #255)
**Jira:** (add when available)
**Priority:** **High — RESOLVED 2026-09-28. Both halves decided and implemented; see
"Decision and implementation" at the end. The gap and the options are kept below as the
record of what was weighed.**

## The gap

Mandala enforces collection visibility through `hook_entity_access()`
(`mandala_group_inheritance_entity_access()`). That hook governs **individual entity access
checks** — a node or collection page, `$entity->access()`, `EntityQuery` with
`accessCheck(TRUE)`. It does **not** govern Views listings.

There are two independent listing surfaces, and neither is covered:

| Surface | Filtered by | Leaking to anonymous |
|---|---|---|
| Node listings (`/av`, `/images`) | the node **grants** table | 2,027 AV nodes + 70 private images |
| Collection listing (`collections` view) | nothing — groups are not nodes | 125 private + 24 UVA collections |

**Node grants would fix the first and do nothing for the second.** Worth knowing before this
is scoped as one job.

### Measured, as anonymous, after PR #255

Node listings — every published node is returned, restricted ones included:

```
av_gallery    / page_1 -> 11,582 rows   (= all published audio+video)
image_gallery / page_1 -> 111,339 rows  (= all published shanti_image)

UVA nid 119235      present in anonymous result set: YES -- listed
private nid 116898  present in anonymous result set: YES -- listed
UVA title     visible in rendered output: YES -- LEAKED
private title visible in rendered output: YES -- LEAKED
```

The cause is core's default grant record, since nothing claims node access:

```
node_access table rows: 1  (realms: all)
```

With SQL rewriting enabled (`disable_sql_rewrite: false` in both gallery views, which is
correct), the node-access query alter therefore filters nothing.

Collection listing — `views.view.collections`, `page_1`:

```
387 rows to anonymous | by field_group_access: {"0":238, "1":125, "2":24}
(0=public, 1=private, 2=UVA)
```

So private and UVA collection names and descriptions are listed to anonymous users.

## What is and is not exposed

- **Exposed:** titles and whatever else the teaser/card/row renders, for restricted content
  and restricted collections, to anonymous users.
- **Not exposed:** the entity pages themselves. Node pages and collection pages are
  correctly denied as of PR #255 (see
  [[av-private-uva-content-not-enforced-in-drupal]]), so links 403.
- **Not exposed:** search. The Solr path is independent and fails closed — the proxy applies
  `visibility_i:1` to anyone without a token.
- **Reachability:** dev-0 is VPN-internal and there is no D11 production yet, so this is not
  a live public exposure today. It is a cutover blocker.

## Why it was not caught earlier

The Images implementation has always had both gaps — the 70 private images have always been
listed in `/images`. Earlier verification checked entity-page access (`public 200 / private
403`) and the Solr `fq` path, both of which are correct, and never asserted on what a
listing returns for anonymous. Sprint 3's AV work inherited the same shape.

## Half 1: node listings — options, none chosen

Nothing in the codebase implements node grants today, and Group 3.x does not either (hence
the single `all` row). This would be the codebase's first grants implementation — an
architectural step, not a patch.

1. **Node grants, dependency-encoded realms.** Write records that encode what the decision
   *depends on* rather than its resolved answer: node value 1 → `mandala_public`/gid 0;
   value 2 → `mandala_member`/gid = collection id; value 3 → `mandala_uva`/gid 0; value 0 or
   absent → `mandala_group_default`/gid = collection id. `hook_node_grants()` then returns
   `mandala_public => [0]` always, `mandala_uva => [0]` when authenticated,
   `mandala_member => ` the user's membership gids, and `mandala_group_default => ` the gids
   whose effective access the user satisfies (244 public + 30 UVA if authenticated +
   memberships — a few hundred ids, a fine `IN()` list). **Changing a collection's
   visibility then needs zero node writes**, only grant-cache invalidation.
2. **Node grants, resolved at save.** Simpler realms storing the resolved visibility, at the
   cost of re-saving every member node when a collection's `field_group_access` changes — up
   to 26,130 images for group 31, so realistically queued. This is D7's own staleness trap.
3. **Per-view query alters.** Cheap for the two known galleries, but fails open on every
   future view, block, REST resource or access-checked query nobody audits.
4. **Denormalised `effective_visibility` field + views filter.** Considered and looks worse
   than 1: same staleness problem as 2, opt-in per view like 3, and no protection for
   EntityQuery or REST.

**Unmeasured and gating:** a one-time `node_access_rebuild()` across **122,923 nodes**. Nobody
has timed this on dev-0, and it becomes a mandatory cutover step. Note every node in the
corpus is asset content (111,340 `shanti_image` + 7,396 `video` + 4,187 `audio` = 122,923),
so there is no article/page case to special-case.

**Risk to respect:** grants fail closed hard. If the hook returns no records for a node it
becomes invisible to *everyone* — exactly PR #201's failure mode, where every grouped AV node
was invisible including to a site administrator. Verification must assert through a view, not
`$node->access()`; the 21-combination matrix from PR #255 should be re-run against
`av_gallery`/`image_gallery` output.

## Half 2: the collections listing — options, none chosen

1. **Check whether the Group module already ships an access-aware Views filter** and use it,
   before writing anything custom.
2. **Filter `views.view.collections` on group entity access**, either via a query alter or an
   existing filter handler. Small and self-contained; would close the collection-name leak
   independently of the node-grants question.
3. **Bundle it with the node work** so the whole access story lands coherently, accepting
   that this leak stays open longer.

## Decisions taken so far

- **2026-09-25 — no stopgap.** Explicitly decided not to add interim gating to `/av`,
  `/images` or `/collections` while the real fix is designed. Rationale: dev-0 is
  VPN-internal, there is no D11 production, and nothing is publicly exposed, so it is worth
  doing once and properly rather than adding filtering that has to be removed again.
- **2026-09-25 — approach deferred to the group.** Both halves above were raised with Than,
  Yuji and Xiaoming present and deliberately left open rather than decided in-session.

## Open questions for the group

- Node grants or per-view alters — and if grants, dependency-encoded or resolved-at-save?
- What does `node_access_rebuild()` actually cost on the real 122,923-node corpus, and who
  measures it?
- Does the cutover plan need an explicit `node_access` rebuild step?
- Is the collections-view leak fixed now as its own small change, or held with the node work?

## Decision and implementation (2026-09-28)

Decided with Than driving and Yuji present, then built the same session.

**Approach: filter at query time, keyed on the `node_access` and `group_access` tags —
not node grants.** The options above framed this as grants versus *per-view* alters and
dismissed the latter, correctly, for failing open on every unnamed view. The framing
missed a third shape: core attaches those tags to any query that declares itself
access-checked (Views via `NodeViewsData`'s `access query tag`, `EntityQuery` via
`accessCheck(TRUE)`), so one implementation reaches views that do not exist yet, without
naming any of them.

Three things decided it:

- **The existing rule is a veto.** `mandala_group_inheritance_entity_access()` returns only
  `forbidden()` or `neutral()`, never `allowed()`, composing on top of Group's permission
  grid. Grants are positive, so a grants implementation would have to reproduce that grid
  as a second copy — the exact shape that has drifted four times here.
- **Grants fix only half.** `views.view.collections` is group-based, so it needed a group
  query alter regardless. One mechanism covers both.
- **Grants add a cutover step.** `node_access_rebuild()` over 122,923 nodes, still
  unmeasured. Query-time filtering adds nothing to cutover, which answers the third open
  question below: no, the cutover plan does not need a rebuild step.

Also worth recording, because it was not obvious: **Group 3.3.5 already ships access-aware
Views SQL rewriting** (`group_views_query_alter()` → `EntityQueryAlter`/`GroupQueryAlter`),
and it was already running on all three views. It filtered nothing because it filters by
Group *permissions*, and `group.role.collection-anonymous` grants `view group` and
`view group_node:* entity`. The listing layer was never missing enforcement machinery — the
enforcement present was keyed on the wrong thing.

**Both halves in one PR**, since they share the resolver, the cache context and the test
harness.

### What was built

- `CollectionVisibility` — the rule extracted to one place, in PHP and as the SQL
  expression, so the entity-page and listing paths cannot diverge.
- `NodeQueryAlter` / `GroupQueryAlter` on the two tags.
- `user.mandala_collection_memberships` cache context. Grants would have supplied
  `user.node_grants:view` free; this does not, and `user.group_permissions` is **wrong** —
  it varies by calculated permissions, not membership identity, so two accounts with
  identical roles and different memberships would collide and serve each other's listings.
- The first test suite under `modules/custom`: 10 tests, 324 assertions.

### Measured after

Each figure independently matches what `hook_entity_access()` returns for the same account.

| Account | `av_gallery` | `image_gallery` | `collections` |
|---|---|---|---|
| anonymous | 9,555 (was 11,582) | 111,269 (was 111,339) | **238** (was 387) |
| authenticated non-member | 9,892 | 111,269 | 262 |
| bypass | 11,582 | 111,339 | 387 |

238 is 387 − 125 private − 24 UVA, exactly as predicted above.

**Performance**, answering the other open question: a tagged `COUNT` over the full corpus
runs at 353ms for images and 99ms for AV, against 22ms and 9ms for a bypass account. All
three joins are index-driven (`group_relationship__load_by_entity`, `PRIMARY` ×2) — no new
index needed. `total_rows` is the count query, so the pager filters in step.

### Two things this deliberately does NOT fix

- **Collection-less nodes keep their current behaviour.** The hook returns neutral before
  reading a node's own override, so 18 published `video` nodes marked private or UVA with no
  collection stay visible. Decided 2026-09-28 (Than, Yuji): those orphans are migrated into
  a temporary review group instead — see
  [[orphaned-content-temp-group-on-migration]].
- **Queries that never carry the tag**, which no approach here or under grants would reach:
  `accessCheck(FALSE)` callers (`SiblingCarouselService`, `shanti_collections_view`'s item
  count) and any view shipping `disable_sql_rewrite: true`. All 24 views in `config/sync` are
  `false` today; that is worth a CI guard.
