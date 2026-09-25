# Collection visibility is not enforced in listings — nothing writes node grants

**Area:** access / Group / Views / node grants / ADR 011 / ADR 015
**Raised during:** Sprint 3 AV7 (session 2026-09-25, verifying the AV access fix)
**Jira:** (add when available)
**Priority:** **High — a real anonymous exposure of restricted titles and metadata, in
Drupal listings.** Pre-existing (it has always applied to Images), widened in visibility by
the AV work. Needs a team decision on approach before implementation.

## The gap

Mandala enforces collection visibility through `hook_entity_access()`
(`mandala_group_inheritance_entity_access()`). That hook governs **individual entity access
checks** — a node page, `$node->access()`, `EntityQuery` with `accessCheck(TRUE)`. It does
**not** govern Views listings, which filter via Drupal's node **grants** system: the
`node_access` table, populated by `hook_node_access_records()` and queried through
`hook_node_grants()`.

Nothing in the codebase implements either hook. Confirmed live on local DDEV:

```
node_access table rows: 1  (realms: all)
```

That single `realm = all` row is core's default grant-everything record, written when no
module claims node access. With SQL rewriting on (`disable_sql_rewrite: false` in both
gallery views, which is correct), the node-access query alter therefore filters nothing.

Measured as anonymous, after the 2026-09-25 page-level fix:

| View | Rows returned to anonymous | Published total |
|---|---|---|
| `av_gallery` / `page_1` | 11,582 | 11,582 |
| `image_gallery` / `page_1` | 111,339 | 111,339 |

Every published node, including every restricted one. Directly confirmed on two samples:

```
UVA nid 119235      present in anonymous result set: YES -- listed
private nid 116898  present in anonymous result set: YES -- listed
UVA title     visible in rendered output: YES -- LEAKED
private title visible in rendered output: YES -- LEAKED
```

## What is and is not exposed

- **Exposed:** titles, and whatever else the teaser/card renders (thumbnails, dates), for
  restricted content in `/av`, `/images`, and any other node listing — for anonymous users.
- **Not exposed:** the node pages themselves. Those are correctly denied as of 2026-09-25
  (see [[av-private-uva-content-not-enforced-in-drupal]]), so the links 403.
- **Not exposed:** search. The Solr path is independent and fails closed — the proxy applies
  `visibility_i:1` to anyone without a token, and restricted documents are labelled
  `private`/`uva`.
- **Scope:** 2,027 AV nodes plus 70 private images on the current corpus. Also any
  contributed or custom listing nobody has audited, which is the part that makes a per-view
  fix unattractive.
- **Reachability:** dev-0 is VPN-internal and there is no D11 production yet, so this is not
  a live public exposure today. It is a cutover blocker.

## Why this was not caught earlier

The Images implementation has always had the same gap — the 70 private images have always
been listed in `/images`. Earlier verification checked node-page access (`public 200 /
private 403`) and the Solr `fq` path, both of which are correct, and never asserted on what a
listing returns for anonymous. Sprint 3's AV work then inherited the same shape.

## Options

1. **Implement node grants** (`hook_node_access_records()` + `hook_node_grants()`) — what D7
   actually did in `mb_access.module`, which wrote records for `all`, `og_access:node` and
   `group_access_uva_member`. Every listing, view, and access-checked query is then filtered
   for free, and Drupal's own access API becomes the single source of truth, which is what
   ADR 013 asks for. Costs: a full `node_access` rebuild across ~122,921 nodes (needs timing
   on dev-0 before committing to it), and the realm/grant design has to encode the same
   node-wins-over-collection resolution the access hook now implements — with the resolution
   frozen into table rows, so a collection visibility change must re-save its members.
   Interaction with the Group module also needs checking: Group 3.x evidently does not write
   grant records, hence the single `all` row.
2. **Per-view query alter.** Cheap for the two known galleries, but it does not generalise —
   every future view, block, REST resource or migration-adjacent listing would need the same
   treatment, and missing one fails open.

Option 1 is the architecturally right answer and the one that matches D7. It is
substantially more work than the page-level fix and should be scoped deliberately rather
than bolted onto it.

## Open questions for the team

- Node grants, or per-view alters as a stopgap while grants are designed?
- If grants: how is the "use group defaults" resolution kept fresh when a collection's
  `field_group_access` changes — re-save all member nodes, or store the collection
  reference in the grant realm so no rebuild is needed?
- Does the cutover plan need a `node_access` rebuild step, and what does it cost on the
  real corpus?
