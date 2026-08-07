# Authenticated users are D7's contributor tier (create/edit-own/delete-own on all asset + collection types) — D11 wires none of it

**Area:** access / users / migration / content model
**Raised during:** ADR 015 Q1 follow-up, 2026-08-07 (Than clarified the D7 contributor model; verified against the Images per-site dump)
**Jira:** (add when available)
**Priority:** **High** — this is the baseline authoring capability for **all ~1,538 migrated users**, D11 grants none of it, and it is what makes the ADR 015 Q1(b) decision (142 rid-4 editors → plain authenticated) non-destructive rather than a total loss of authoring on cutover day.

## Context

ADR 015 settled the *editor* tiers of Mandala's access model (global `content_editor`
for former shanti_editors; per-group editors deferred to Phase B). It did **not**
address the baseline: what a plain authenticated user can do. During the Q1
follow-up, Than clarified how Mandala was actually set up:

> Authenticated users should be able to create, edit, and delete their **own**
> content — the collection content types **and** all the asset content types.

The scrubbed `mandala_shared` dump can't confirm this (its core `role_permission`
carries almost nothing — Mandala's real permissions live in the per-*site*
databases, exactly as [`d7-editor-permissions-og-group-scoped-not-migrated.md`](d7-editor-permissions-og-group-scoped-not-migrated.md)
found). Verified instead against the **Images per-site prod dump**
(`mandala-prod-images-db_2026-06-29-930.sql.gz`, core `role_permission` +
`og_role` + `og_role_permission`).

## Evidence — D7 Images, core `role_permission`, rid 2 (authenticated user)

Authenticated users hold, **site-wide (not group-scoped)**:

| Capability | Content types |
|---|---|
| `create X content` | `collection`, `subcollection`, `shanti_image`, `asset_link`, `image_agent`, `image_descriptions`, `external_classification` |
| `edit own X content` | (same seven) |
| `delete own X content` | (same seven) |

So the clarification is **confirmed and it is a core role grant**: any registered
user may author their own collections and their own assets anywhere on the site —
no group membership required to create. Group membership governs access to *other
people's* content, not one's own.

For contrast, the OG layer (`og_role_permission`) adds the *shared-content* tiers
on top, per collection:

- **OG `member`** — create + edit/delete **own** within the group (mirrors the core baseline, scoped)
- **OG `editor`** (og rid 31/51) — create + edit/delete **any** content within the group ← this is the real per-collection editor, the Phase B target
- **OG `administrator member`** — the above + `update group`

This nails down the full four-tier model:

| Tier | D7 source | Scope | D11 status |
|---|---|---|---|
| **Contributor** | core `role_permission` rid 2 | CRUD **own** collections + own assets, site-wide | ❌ **not wired** (this note) |
| Group editor | OG `editor` role | edit **any** content *within* a collection | Deferred → Phase B ([d7-editor-permissions…](d7-editor-permissions-og-group-scoped-not-migrated.md)) |
| content_editor | rid 6 (`shanti editor`, 0 users) | edit **any** content globally | ✅ ADR 015 (PR #75) |
| admin | rid 3 (`bypass node access`) | everything | ✅ committed |

## The D11 gap

`drupal/config/sync/user.role.authenticated.yml` grants **no content permissions
at all** — only `access content`, comments, contact form, `delete own files`,
OAuth codes, `basic_html`. As committed, a migrated authenticated user can *view*
content and nothing else. The contributor tier does not exist in D11 today.

Two model shifts to get right when wiring it:

1. **Collections are Groups now, not nodes.** In D7 Images, `collection` /
   `subcollection` are **node types** with node permissions (see table above).
   In D11 ([ADR 011](../adr/011-group-collections-inheritance.md)) collections
   are **Group entities**. So "create/edit-own/delete-own own collection" maps to
   **Group** permissions (`create <group_type> group`, `edit own …`), a different
   permission namespace than the node-based asset types — don't wire it as node
   perms.
2. **Only `shanti_image` exists as a D11 node type so far** (plus `article`,
   `page`). The other D7 asset types authenticated could create — `asset_link`,
   `image_agent`, `image_descriptions`, `external_classification` — are, in the
   D11 content model, largely paragraphs/fields rather than standalone nodes. The
   contributor grant must be re-expressed against whatever the D11 model actually
   is per site, not copied verbatim from the D7 node-type list.

## Why this matters for ADR 015 / Q1(b)

The Q1(b) decision (leave `content_editor` empty, hand-assign; migrate the 142
rid-4 editors as plain authenticated) is only non-destructive **if** the
contributor tier is wired. With it, those 142 keep full CRUD on their own
content and lose only *others'*-content editing until Phase B. **Without it —
i.e. against D11 as committed today — the 142 (and every other authenticated
user) can author nothing.** That reframes the cutover blast radius from "editors
lose cross-collection editing" to "the entire user base loses all authoring,"
which is almost certainly not intended.

## To resolve (open)

- Wire the contributor tier into D11's `authenticated` role: node
  `create/edit own/delete own` for the real D11 asset node types, **plus** the
  Group-permission equivalents for collection/subcollection creation.
- Decide per-site (Images first) exactly which D11 content entities the grant
  covers, given the node→paragraph and node→Group remodeling.
- Because ADR 015 makes "grant the editorial floor" a required checklist item for
  every per-site migration, add "confirm the authenticated contributor grant" to
  that same checklist so it isn't re-derived per site.
- Sequence **before** the next real user-migration cutover on dev-0 — this is the
  gate that determines whether migrated users can author anything at all.
