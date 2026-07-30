# D7's real editorial permissions are OG group-scoped, and D11's `content_editor` role grants none of them

**Area:** migration / users / roles / Group access architecture
**Raised during:** Live dev-0 investigation following the `d7_user_role` permission-wipe bug (2026-07-22, Than)
**Jira:** (add when available)
**Priority:** **High — reframes the user-migration permission question; affects real editorial access on day one post-migration; connects to open tasks 1b.3/1b.4**
**Status (2026-07-30):** Being resolved by [ADR 015](../adr/015-editorial-access-model-global-content-editor.md) (Proposed). ADR 015 **narrows** this note's "make `content_editor` global" direction: `content_editor` becomes global + non-admin but is assigned **only to former `shanti editor`s** (rid 6), which — per project history — was itself a global override role. The group-scoped `editor` (rid 4) is **not** promoted to global; its per-group editing is deferred to a later Group-role migration (Phase B). Open question (c) below (design group-role now vs. sitewide fix first) is answered: sitewide `content_editor` now for shanti_editors, per-group roles in Phase B.

## Context

While scoping the fix for
[`d7-user-role-migration-wipes-committed-role-permissions.md`](d7-user-role-migration-wipes-committed-role-permissions.md),
the team questioned whether *not* migrating D7's role permissions into D11
(leaving D11's committed `user.role.*.yml` as the source of truth) was itself
an "improvement" under [ADR 008](../adr/008-mvp-migrate-not-improve.md) /
[ADR 010](../adr/010-adr-008-scope-clarification.md). Answering that required
finding out what D7's roles actually granted. That investigation, run live
against dev-0's RDS-backed D7 databases (`mandala_d7_shared`,
`mandala_d7_images`), turned up a bigger and more decisive finding than the
original question.

## What we found

**1. Core `role_permission` is empty for the custom editorial roles, in both
the shared DB and the Images site DB.**

`role` (shared/global across all 5 sites, per
[d7-shared-user-database.md](d7-shared-user-database.md)) lists:

| rid | name |
|---|---|
| 1 | anonymous user |
| 2 | authenticated user |
| 3 | administrator |
| 4 | editor |
| 5 | workflow editor |
| 6 | shanti editor |

But `role_permission` — **not** on the shared-prefix list, so it is local to
each site's own database — has **zero rows for rids 3/4/5/6** in
`mandala_d7_images` (only `administrator`, rid 3, has explicit grants: ~190
permissions, the expected full admin set). `mandala_d7_shared`'s own
`role_permission` table is unrelated data entirely — it has rows only for
rids 1/2 plus a mystery rid 11 that doesn't exist in the shared `role` table
at all (confirmed: `SELECT rid,name FROM role WHERE rid=11` → empty). That
table is a leftover/foreign artifact, not authoritative for anything.

**2. The real editorial permissions live in Organic Groups' own tables,
scoped per group, under a completely different id space.**

`mandala_d7_images` has `og_role` / `og_role_permission` (OG's own
role/permission system, independent of core `role`/`role_permission`).
`og_role` (all rows `gid=0`, i.e. bundle-default roles applied across all
groups of that bundle):

| rid (OG) | group_type | group_bundle | name |
|---|---|---|---|
| 1 | node | collection | non-member |
| 6 | node | collection | member |
| 11 | node | collection | administrator member |
| 16 | node | subcollection | non-member |
| 21 | node | subcollection | member |
| 26 | node | subcollection | administrator member |
| **31** | node | collection | **editor** |
| 36 | node | group | administrator member |
| 41 | node | group | member |
| 46 | node | group | non-member |
| **51** | node | subcollection | **editor** |

Only a generic **`editor`** OG role exists (once per bundle: `collection` and
`subcollection`). **`workflow editor` and `shanti editor` have no OG
counterpart under any name** — their real capability, if any, remains
unaccounted for by anything checked so far.

`og_role_permission` for rid 31/51 (`editor`, both bundles — near-identical,
~19 permissions each):

- create / update / delete `shanti_image` content (own + any)
- create / update / delete `subcollection` content (own + any)
- create / update / delete `asset_link` content (own + any)
- publish any content / publish editable content
- unpublish any content / unpublish editable content
- unsubscribe

This is real, meaningful, domain-specific editorial capability — **and it is
granted per-group** (a user is `editor` of a specific collection/subcollection
instance, not sitewide), not through the sitewide `role`/`role_permission`
system at all.

**3. D11's committed `content_editor` role has zero overlap with any of
this.**

`drupal/config/sync/user.role.content_editor.yml` (22 permissions):
`article`/`page` create/edit/delete, `tags` taxonomy terms, url aliases,
revisions, files overview, admin toolbar/theme access. **Not one permission
touches `shanti_image`, `subcollection`, `collection`, or `asset_link`** — the
actual content types this migration exists to move (111,343 `shanti_image`
nodes alone). `article`/`page` are Drupal core's generic demo content types;
nothing in Mandala uses them. This looks like Drupal's unmodified
stock/starter editorial role, never actually connected to Mandala's real
content model.

## Why it matters

This is no longer just "should the migration copy D7 permissions forward."
Two separate, concrete problems exist independent of the migration-wipe bug:

1. **D11's `content_editor` role, as currently committed, cannot do real
   Mandala editorial work at all** — it has no permission to touch
   `shanti_image`/`subcollection`/`asset_link`/`collection`. Every migrated
   D7 editor loses their actual editorial capability on day one, regardless
   of whether `d7_user_role`'s destructive-wipe bug is fixed. Fixing the wipe
   bug alone (leaving the committed role's permissions untouched) is not
   sufficient — the thing it would leave untouched is itself wrong.
2. **D7's real editor grant is group-scoped (per-collection), which a single
   global D11 role cannot represent structurally**, even with the right
   permission strings added. An editor of Collection A having edit rights on
   Collection B's content would be a correctness regression the sitewide-role
   model can't avoid. Faithfully reproducing this needs a **group-scoped
   role** — Drupal's Group module (already the basis for
   [ADR 011](../adr/011-group-collections-inheritance.md)'s collections work)
   has its own group-role/permission system, directly analogous to OG's
   `og_role`/`og_role_permission`. This is squarely in the territory of the
   still-open **1b.3 (Solr-proxy visibility coherence)** and **1b.4
   (paragraph access inheritance)** tasks — this may be the same underlying
   gap surfacing from a different angle.

Under ADR 008's faithful-migration floor and ADR 010's user-facing-change
test, this is unambiguous: real editors losing the ability to manage the
content they manage is a regression, not a neutral internal choice.

## Open questions

- What did `workflow editor` and `shanti editor` actually do in D7? No
  evidence of any grant has turned up yet (core `role_permission`: empty; OG:
  no matching role name). Possibilities: genuinely vestigial/no-op roles;
  capability implemented in custom module code (`hook_node_access` or similar,
  keyed on rid) rather than any permission table; or a mechanism not yet
  checked (Panels/Page Manager per-role rules, a custom access module).
  Needs a legacy-codebase grep (`mandala-drupal` repo) for hardcoded rid
  checks before concluding they were no-ops.
- Does `og_users_roles` show these OG roles actually in active use (how many
  users hold `editor` on how many groups), or were they rarely/never
  assigned? Affects how urgent the gap is in practice.
- Should the fix be: (a) design a Group-module group-role equivalent to OG's
  `editor` role now, as part of closing this gap, or (b) land a *sitewide*
  `content_editor` fix first (correct the permission list to at least cover
  the right content types, accepting the group-scoping loss as a known,
  separately-tracked gap) to unblock the user migration sooner? This is a
  scope/sequencing call, not a technical one.
- Same question applies beyond Images — Sources/Texts/AV/Home likely have
  their own local `og_role`/`role_permission` data that hasn't been checked;
  the `editor` grant found here may not generalize across all five sites.

## Cross-references

- [d7-user-role-migration-wipes-committed-role-permissions.md](d7-user-role-migration-wipes-committed-role-permissions.md) — the original migration-code bug; this note supersedes its "leave the committed permissions alone" assumption with "the committed permissions are also wrong."
- [d7-shared-user-database.md](d7-shared-user-database.md) — shared vs. per-site table split; established that `role` is shared but did not previously note that `role_permission` is *not*.
- [ADR 011](../adr/011-group-collections-inheritance.md) — Group collections inheritance; the group-scoped role question belongs in this architecture.
- 1b.3 (Solr-proxy visibility coherence) / 1b.4 (paragraph access inheritance) — open Sprint 1 tasks, likely the same underlying access-model gap.
- [ADR 015](../adr/015-editorial-access-model-global-content-editor.md) — the editorial access model this note is resolved by; global non-admin `content_editor` for former shanti_editors, per-group editors deferred to Phase B Group roles.
