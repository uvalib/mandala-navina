# ADR 015: D11 editorial access model — global non-admin `content_editor` (shanti_editor equivalent); per-group editors deferred to Group roles

**Status:** Accepted
**Date:** 2026-07-30 (proposed); 2026-08-06 (accepted)
**Deciders:** Than Grove (authoring); Yuji Shinozaki, Xiaoming Wang (sign-off 2026-08-06)
**Relates to:** [ADR 008](008-mvp-migrate-not-improve.md) (MVP is migrate, not improve), [ADR 010](010-adr-008-scope-clarification.md) (scope clarification), [ADR 011](011-group-collections-inheritance.md) (Group collections inheritance)
**Resolves:** deferred note [`d7-editor-permissions-og-group-scoped-not-migrated.md`](../deferred/d7-editor-permissions-og-group-scoped-not-migrated.md)

## Context

The cross-cutting D7→D11 user migration collapses D7's three editorial roles —
`editor` (rid 4), `workflow editor` (rid 5), `shanti editor` (rid 6) — onto D11's
committed `content_editor` role. Investigation for the `d7_user_role` permission-wipe
fix (see the resolved deferred note above) surfaced that this collapse is broken on
two counts:

**1. D11's committed `content_editor` is disconnected stock config.** Its permission
set covers only Drupal's demo `article`/`page`/`tags` types. It grants nothing on
`shanti_image` — the only real Mandala content type in the Images pilot, and the only
Group content type. A migrated editor would therefore land unable to edit any of the
content they are meant to manage.

**2. The three D7 roles are not equivalent; collapsing them conflates distinct
capabilities.** Core `role_permission` was empty for all three editorial rids — their
real meaning lived elsewhere, and differed:

- **`shanti editor` (rid 6)** was a **global** editorial role that **overrode
  per-group permissions** across the apps — a site-wide content editor by intent.
- **`editor` (rid 4)** was **group-scoped** — real editorial capability granted
  per-collection via Organic Groups (`og_role`/`og_users_roles`, generic `editor`
  role, ~19 permissions), never sitewide.
- **`workflow editor` (rid 5)** was **AV-only and effectively vestigial** — never
  meaningfully used.

Naively "fixing" `content_editor` by granting the collapsed role global edit rights
would silently promote former group-scoped editors to global editors — a genuine
privilege escalation. Under [ADR 008](008-mvp-migrate-not-improve.md) /
[ADR 010](010-adr-008-scope-clarification.md), correcting this is a **fix of D7/D11
brokenness, not a user-facing improvement**: the committed role was never wired to
Mandala's content model, and the migration mapping conflated three different roles.

## Decision

**1. D11 `content_editor` is a global, non-administrative editorial role.** It may
create / edit / delete any content item of any content type in any collection or
subcollection — **including private ones** — but holds **no** administrative
privilege (no site configuration, views, user management, or module/config
administration). This is the D11 realization of D7 `shanti editor`'s original
global-override intent.

**2. `content_editor` is assigned only to former `shanti editor`s.** The
user-migration role map (`mandala_role_map` in `d7_users.yml`) is:

| D7 role (rid) | D11 outcome |
|---|---|
| administrator (3) | `administrator` |
| shanti editor (6) | `content_editor` (global) |
| editor (4) | authenticated only in this phase; per-group editing restored in the group phase |
| workflow editor (5) | authenticated only (vestigial) |

**3. Per-group editorial capability is not represented by any sitewide role.** D7's
group-scoped `editor` (OG `og_users_roles`, and the legacy core `editor` rid 4) is
reconstructed **later**, as a per-group Group role (Group 3.x `individual` scope),
during the group user-membership/role migration — not carried by `content_editor`.

**4. Sequencing.** *Phase A* (this decision): migrate users and set the sitewide role
permissions; `content_editor`'s global reach is implemented so former shanti_editors
can edit the already-migrated group content. *Phase B* (a later, separate task):
migrate group memberships and per-group roles.

**5. Mechanism.** Global reach is implemented via **synchronized Group roles**
(`scope: outsider`/`insider`, `global_role: content_editor`) on the `collection` and
`subcollection` group types, granting `create` / `update any` / `delete any
group_node:<type> entity`; plus a bypass permission honored by the
`mandala_group_inheritance` view hook so `content_editor` can reach content in
**private** collections. No new core `bypass node access` grant (too broad for the
non-admin constraint).

**6. `content_editor`'s remit spans all Mandala content types, and expands with each
migration.** `content_editor` is the single global editorial role for real Mandala
content across all five apps — it is not Images-specific. Every subsequent per-site
migration **must** grant `content_editor` full create / edit / delete on that site's
content types on the same footing Images establishes for `shanti_image` (via the
synchronized Group roles). E.g., the AV migration grants `content_editor` the same
full CRUD on `audio` and `video` content, within their groups, that it holds on
`shanti_image`. A migrated editor's reach therefore grows to cover new content as it
arrives, with no per-site role redesign.

**Operative test** ([ADR 010](010-adr-008-scope-clarification.md)): *no user gains a
capability they did not have in D7.* Shanti editors were already global; group editors
remain group-scoped (deferred, not dropped); the vestigial role grants nothing.

## Consequences

- The `mandala_role_map` map in `d7_users.yml` changes to
  `{3: administrator, 6: content_editor}`. Rids 4 and 5 migrate as plain
  authenticated users in Phase A.
- `user.role.content_editor.yml` is corrected to a global non-admin editorial
  permission set (`is_admin: false`, no config/views/user administration); new
  synchronized `group.role.*-content_editor.yml` configs are added; the
  `mandala_group_inheritance` module gains a bypass permission plus hook wiring so
  `content_editor` can view/edit content in private collections.
- **Granting `content_editor` full CRUD on the site's content types becomes a required
  checklist item for every per-site content migration** (Texts, Sources, AV, Mandala
  Home), alongside the `field_legacy_nid` convention
  ([`migration-legacy-nid-required-convention.md`](../deferred/migration-legacy-nid-required-convention.md)).
  Omitting it would leave editors unable to manage that site's content — the same
  brokenness this ADR fixes for Images.
- Faithfulness guardrail: the former-`shanti_editor` population must be confirmed on
  dev-0 before running, so the global grant lands only where intended.
- Per-group editor reconstruction is formally owed by the Phase B group-role migration
  (`og_users_roles` → `group_roles`), and connects to the still-open **1b.3**
  (Solr-proxy visibility coherence) / **1b.4** (paragraph access inheritance) tasks.
- This does not change external API response shapes the React app depends on; it is not
  a Phase 5 compatibility concern.
- Faithful migration remains the hard floor (ADR 008); this decision changes an
  internal access model that was disconnected/broken, not a user-facing feature.
