# Two AV collection memberships are skipped because their D7 user no longer exists

**Area:** migration / AV / users / group membership
**Raised during:** Sprint 3 AV4 (2026-09-09); moved here from a `config/sync` comment 2026-10-06
**Jira:** (add when available)
**Priority:** Low — 2 rows, recorded as "no action needed" at AV4; filed so the decision is findable

## What was found

AV records user memberships under both `og_group_ref` (1,207 rows) and OG's user-side
`og_user_node` (30 rows); two pairs appear in both, so 1,237 rows collapse to **1,235
(user, group) pairs**. Of the 688 uids that hold AV memberships, **uid 7471 has no D11 user**: it
is an account deleted from D7 at some point, with its OG rows left behind. Two memberships are
skipped as a result (the `entity_exists` guard in `d7_av_user_memberships` skips them rather than
letting the destination plugin throw):

| D7 `og_membership` | Destination |
|---|---|
| 57286 | AV collection 24616 |
| 57321 | AV subcollection 41916 |

This is **not** a user-migration gap. The user migration imported all 1,542 shared-DB users with
zero failures (verified on dev-0 2026-09-09; `migrate_map_d7_users` = 1,542 rows, all status 0).
The two rows point at an account that does not exist in the source user base either.

## Why it is filed

AV4 recorded this as "no action needed, an explanation, not an open item"
([av-node-migration-notes.md](../planning/av-node-migration-notes.md)), and repeated it in a
comment inside `migrate_plus.migration.d7_av_user_memberships`, which is being removed from
`config/sync`. Nothing here is broken; the note exists so a future reader who finds 1,235 pairs
against 1,237 source rows can see the reconciliation.

## Only open question

Is a membership for a deleted account worth recreating under some other owner? The expected
answer is no (a deleted user has no one to act on the membership). If Than or AV staff confirm,
add a dated line saying so and close the note.

## Related

- [D7 shared user database](d7-shared-user-database.md)
- [AV4 node/group/membership/alias migrations](../planning/av-node-migration-notes.md)
