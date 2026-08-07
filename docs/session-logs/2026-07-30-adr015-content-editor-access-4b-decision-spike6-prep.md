# Session Log: Adr015 Content Editor Access 4B Decision Spike6 Prep

**Date:** 2026-07-30  
**Participants:** Than Grove, Claude Code (Opus 4.8)  
**Outcome:** [ADR 015](../adr/015-editorial-access-model-global-content-editor.md) (PR #74) + implementation (PR #75, verified); [Spike 4b](../spikes/spike-04b-ckeditor5-footnotes.md) decision Option 1+3 (PR #76); [Spike 6](../spikes/spike-06-api-compatibility.md) pre-spike findings (branch `spike/6-api-compatibility`)

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-07-30 13:26

ok pull the latest code and remember where we are.

---

## Claude — 2026-07-30 13:26

I'll pull the latest and get oriented.

---

## Claude — 2026-07-30 13:26

Current branch is clean and up to date, but `main` is 6 behind origin. Let me check what's happened on the remotes and whether PR #73 merged.

---

## Claude — 2026-07-30 13:26

PR #73 is now **MERGED** (and #67 too, which my memory had as OPEN). The current branch's work is done and landed on main. Let me sync `main` locally and switch to it.

---

## Claude — 2026-07-30 13:27

Now `main` is at `b21fe47`. Let me check the deferred/README to see the current state of the user-migration gates, since that's the live thread.

---

## Claude — 2026-07-30 13:27

Let me look at the two open gates my memory tracked — OG-perm correctness and dev-0 delivery — to see their current state.

---

## Claude — 2026-07-30 13:27

I have the full picture now. Let me update my project-state memory to reflect that PR #73 merged and the gates have shifted.

---

## Claude — 2026-07-30 13:28

Everything's synced and I have the current state. Now let me update memory to reflect that PR #73 merged.

---

## Claude — 2026-07-30 13:28

Pulled and oriented. Here's where we are:

## Repo state
- **On `main`, fast-forwarded** `0853897 → b21fe47`. Working tree clean.
- **PR #73 is MERGED** — the `d7_user_role` permission-wipe fix (`mandala_role_map` process plugin + `handle_multiples`) is now **on main**, not just ready-for-merge. Your local `fix/user-role-permission-wipe` branch is fully landed and safe to delete whenever.

## User-migration gates (the live thread)
The user migration to dev-0 was triple-gated. Status now:

1. **`d7_user_role` permission-wipe** — ✅ **RESOLVED + MERGED** (PR #73). Verified twice: Xiaoming's synthetic fixture (caught the `handle_multiples` bug) and your DDEV run against the full 1,538-user scrubbed shared DB (0 failed, no wipe). **Gate 1 cleared and landed.**
2. **OG-scoped editor permissions** — ⏳ open. D11's committed `content_editor` only grants stock article/page perms; D7's real editorial grant was Organic-Groups group-scoped (`d7-editor-permissions-og-group-scoped-not-migrated.md`). This fix stopped *destruction*, not *wrongness*. Connects to tasks 1b.3/1b.4.
3. **dev-0 delivery mechanism** — ⏳ **DECISION OPEN**, deferred to Yuji/Dave. No ECR repo, no CodePipeline — merging to main deploys nothing to dev-0. (PR #67 that *documented* this merged; the decision Option A vs B is what's still open.)

So the migration still can't run on dev-0 until gates 2 and 3 resolve, and both are outside your solo lane (gate 2 is a design call tied to Group-module roles; gate 3 is Yuji/Dave's infra decision).

I've refreshed `project-mandala-state` and the memory index to reflect the merge. What would you like to work on?

---

## User — 2026-07-30 13:39

delete the merged fix/user-role-permission-wipe branch

---

## Claude — 2026-07-30 13:39

Deleted the local branch (was at `79e5af0`).

The remote `origin/fix/user-role-permission-wipe` still exists. Want me to delete that too? It's a safe cleanup since the PR is merged — just confirming since it's a push to the shared remote.

---

## User — 2026-07-30 13:40

yes, delete the remote too

---

## Claude — 2026-07-30 13:40

Done. Remote branch `fix/user-role-permission-wipe` deleted. Both local and remote copies are now gone; the work lives on `main` at `b21fe47`.

---

## User — 2026-07-30 13:40

explain the second gate

---

## Claude — 2026-07-30 13:40

Let me read the full deferred doc so I explain it accurately rather than from memory.

---

## Claude — 2026-07-30 13:41

Here's the second gate, explained in full.

## Gate 2: D11's editor role can't do real Mandala editorial work — and D7's real permissions can't fit in a sitewide role anyway

This gate came out of a live investigation on dev-0's D7 databases while scoping the gate-1 fix. The team asked a reasonable question — "if we don't migrate D7's role permissions and just trust D11's committed `content_editor` role, is that an *improvement* (banned by ADR 008/010) rather than a faithful migration?" Answering it meant actually looking at what D7's roles granted. What turned up was bigger than the question.

### Three findings that stack up

**1. D7's core permission table is empty for the editorial roles.**
The shared `role` table lists six roles (`anonymous`, `authenticated`, `administrator`, `editor`, `workflow editor`, `shanti editor`). But `role_permission` — which, unlike `role`, is *not* shared across the five sites, it's local to each site's DB — has **zero rows** for the editorial rids (4/5/6) in `mandala_d7_images`. Only `administrator` (rid 3) has explicit grants. So at the core-Drupal level, the editor roles look like they grant nothing.

**2. The real editorial power lives in Organic Groups, scoped per-group.**
D7 Mandala used the Organic Groups module, which has its **own** parallel role/permission system (`og_role` / `og_role_permission`) in a completely separate id space. There, a generic **`editor`** OG role exists (once per bundle: `collection` and `subcollection`) with ~19 real, domain-specific permissions — create/update/delete `shanti_image`, `subcollection`, `asset_link`, plus publish/unpublish. **But these are granted per-group**: you're the editor of *Collection A specifically*, not an editor sitewide.

**3. D11's committed `content_editor` role has zero overlap with any of it.**
`user.role.content_editor.yml` grants 22 permissions covering `article`/`page`/`tags` — Drupal's stock demo content types. **Not one permission touches `shanti_image`, `subcollection`, `collection`, or `asset_link`** — the actual content types this whole migration exists to move (111,343 `shanti_image` nodes alone). It looks like Drupal's unmodified starter role, never wired to Mandala's real content model.

### Why this is a real gate, not a nitpick

Two independent problems, both regressions under ADR 008/010's faithful-migration floor:

1. **Correctness of the permission list.** As committed, `content_editor` literally cannot edit Mandala content. Every migrated D7 editor loses their editorial capability on day one — *even with gate 1 fixed*. Gate 1 stopped the migration from *wiping* the committed permissions; but the thing it preserves is itself wrong. Fixing destruction ≠ fixing wrongness.

2. **Structure of the access model.** D7's editor grant is group-scoped. A single global D11 role can't represent that even with the right permission strings — an editor of Collection A would get edit rights on Collection B's content, which is its own correctness regression. Faithfully reproducing it needs a **group-scoped role**, and Drupal's Group module (already the basis of ADR 011's collections work) has its own group-role/permission system directly analogous to OG's. This is very likely the same underlying access-model gap surfacing in open tasks **1b.3** (Solr-proxy visibility coherence) and **1b.4** (paragraph access inheritance).

### What's still unknown (the open questions)

- **What did `workflow editor` / `shanti editor` actually grant?** No evidence anywhere yet — empty in core `role_permission`, no OG counterpart. Could be vestigial no-ops, or hardcoded rid checks in legacy `mandala-drupal` module code (`hook_node_access`), which nobody has grepped for yet.
- **Were the OG `editor` roles actually used?** `og_users_roles` hasn't been checked — affects how urgent this is in practice.
- **Does this generalize?** Only Images was inspected. Sources/Texts/AV/Home have their own local `og_role`/`role_permission` data that could differ.

### The decision this gate is really waiting on

It's a **scope/sequencing call, not a technical one** (open question (c) in the doc):

- **(a) Do it right first** — design the Group-module group-role equivalent now, before running the migration. Faithful, but bigger, and ties into 1b.3/1b.4.
- **(b) Unblock sooner** — land a *sitewide* `content_editor` fix that at least covers the correct content types, accept the loss of group-scoping as a known, separately-tracked gap, and run the migration.

That's a call for the team (it's your migration turf, but the group-role design touches Yuji's Group/access architecture), which is why it sits as a gate rather than something you'd just knock out solo.

---

## User — 2026-07-30 14:18

ok. So what I know from the history of the project is the Workflow editor was a role meant only for AV which has workflow metadata but it was never really  used. Shanti editor was supposed to be a global role across the different apps that overrode the different group permissions. So, what I would like to propose is that we make the D11 content editor role into one that can edit any content item of any content type in any group/collection, but that role does not have the admin privileges. It can create, edit, or delete content anywhere but not change the settings on the site, modify views, add or modify users, etc. As you mention in your note, this is not an upgrade but a fix of brokenness in the D7 site. What do you think of that?

---

## Claude — 2026-07-30 14:20

Good — that historical context is exactly what the two open questions in the deferred note were missing. Let me react to the substance, then verify the technical feasibility before I write a plan.

**My honest take on the proposal:** I think it's defensible, and probably right, but the "it's a fix not an upgrade" framing needs one caveat made explicit so the team can sign off with eyes open.

- **The `shanti editor` half is clean.** You're describing shanti editor's *original design intent* — a global editor that overrides per-group permissions. Rebuilding that as D11's `content_editor` is faithful-to-intent, and ADR 010's test (user-facing change?) is satisfied: a shanti editor could already edit anything.
- **The `editor` (rid 4, group-scoped OG) half is where I'd be precise.** For a user who in D7 was *only* a per-collection OG `editor` of Collection A, a global content_editor is strictly *more* access than they had — they can now edit Collection B too. Whether that's a "fix of brokenness" or a "privilege escalation" depends on a data fact we flagged as an open question and haven't answered yet: **how many users actually held per-group OG editor, and are those the same people who'd get the global role?** If core rid 4/5/6 assignment ≈ the OG-editor population, the collapse is faithful. If lots of users have a vestigial core role but were never real OG editors, the proposal hands them global edit.

So I'm on board with the direction; I just want the plan to (a) confirm that data fact on dev-0 before we call it purely a fix, and (b) nail the *technical* question of how a sitewide role edits content that lives inside Group-module groups — because Group enforces its own access layer (ADR 011), and a global node permission may not be enough without a `bypass group access`-style hammer.

Let me verify those two things in the codebase.

---

## Claude — 2026-07-30 14:20

Both explorers are running. Let me capture the context and framing in the plan file while they work.

---

## Claude — 2026-07-30 14:20

I've written up the framing and the caveat. I'm now waiting on two codebase explorations to nail down the technical mechanism (how a sitewide role reaches into Group-scoped content without becoming a group admin) and the full content-type/role-migration inventory. I'll pull those together into a concrete plan once they land — including the exact permission set for the new role and the one dev-0 data check I want to run to confirm the "fix, not upgrade" framing holds.

---

## User — 2026-07-30 14:21

<task-notification>
<task-id>a32790e7deea2a32e</task-id>
<tool-use-id>toolu_01VkoFbgk76EXt6KtJrphv3i</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/fa3e8803-e594-46b0-88e6-8d284e990556/tasks/a32790e7deea2a32e.output</output-file>
<status>completed</status>
<summary>Agent "Explore content types and migration role map" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>I have everything needed. Here is the complete inventory.

## 1. Content types (node bundles) in D11

Only **three** `node.type.*.yml` exist in `drupal/config/sync/`:

| Machine name | Label | Origin |
|---|---|---|
| `shanti_image` | Shanti Image | Real Mandala type |
| `article` | Article | Drupal stock |
| `page` | Basic page | Drupal stock |

There is exactly **one** real Mandala node type: `shanti_image`. There are **no** node bundles for `subcollection`, `collection`, `asset_link`, Texts, Sources, AV, or Home. Only stock `article` and `page` besides it.

## 2. Other editable entity types

**Collections and subcollections are Group entities, not node bundles.** Defined as:
- `group.type.collection.yml` — id `collection`, label "Collection"
- `group.type.subcollection.yml` — id `subcollection`, label "Subcollection"

Group membership infrastructure also present: `group.relationship_type.collection-group_membership`, `group.relationship_type.subcollection-group_membership`, and group roles `group.role.collection-member` / `group.role.subcollection-member` (both label "Member", `admin: false`).

**Paragraph types** (`paragraphs.paragraphs_type.*.yml`), three:
- `external_classification` — "External Classification"
- `image_agent` — "Image Agent"
- `image_descriptions` — "Image Description"

## 3. Role migration

**RoleMap.php** (`drupal/web/modules/custom/mandala_migrations/src/Plugin/migrate/process/RoleMap.php`):
- Plugin id: `mandala_role_map`, and it **declares `handle_multiples = TRUE`** (in the `@MigrateProcessPlugin` annotation, lines 52-55). The docblock explains this is mandatory so migrate passes the whole rids array at once rather than element-wise.
- It is a data-only in-memory mapper; the actual D7-rid → D11-role dictionary is **not hardcoded in the PHP** — it comes from the `map:` config supplied by the migration. The class just looks up each rid in `$this-&gt;configuration['map']`, drops unmapped rids (including D7 rids 1/2 anonymous/authenticated), and de-duplicates by machine name.

**The actual mapping** is declared in `d7_users.yml`:
```
3: administrator
4: content_editor
5: content_editor
6: content_editor
```
So D7 rid 3 → `administrator`; D7 rids 4 (editor), 5 (workflow editor), 6 (shanti editor) all **collapse onto the single `content_editor` role**. This is the same in both copies (`config/install/` and `config/sync/`).

**d7_users migration** (`.../config/install/migrate_plus.migration.d7_users.yml`, mirrored in `config/sync/`):
- `roles` process uses `plugin: mandala_role_map`, `source: roles`, with the map above.
- `uid: uid` (uid preservation is mandatory per the comments — memberships reference raw D7 uids).
- **`migration_dependencies: optional: []`** — no required dependencies. The old `d7_user_role` lookup migration was removed (comment lines 77-80), because its `entity:user_role` destination wiped committed role permissions.

## 4. OG per-group editor memberships

**No migration carries D7 `og_users_roles` (per-group role assignments) into D11.** The membership migrations copy *who belongs to which group*, but assign **no role within the group**:

- `migrate_plus.migration.d7_images_collection_memberships.yml` (config/install + config/sync) — label "OG user memberships → Group membership relationships". Source `d7_image_user_group_membership`. Process sets only `gid`, `entity_id: etid`, and `plugin_id: group_membership`. It does **not** set `group_roles`. Members land in the default `-member` group role with no per-group editor role.
- `migrate_plus.migration.d7_images_image_collection_membership.yml` — this maps image nodes into groups (`group_node:shanti_image`), not user roles.

Confirmed by grep: **no migration YAML sets `group_roles`**, and the source plugin `D7ImageUserGroupMembership.php` reads no rid/role/og_users_roles field. The `group_roles` field storage/instances exist as config (`field.storage.group_relationship.group_roles.yml`, and per-bundle instances) but are never populated by any migration.

Relevant supporting files:
- Source plugins: `.../src/Plugin/migrate/source/D7ImageUserGroupMembership.php`, `D7ImageGroupMembership.php`
- Destination: `.../src/Plugin/migrate/destination/GroupRelationshipDestination.php` (plugin id `mandala_group_relationship`)

**Bottom line for your design change:** Sitewide roles come across via `mandala_role_map` (three D7 editor roles → one `content_editor`). There is no per-group editor concept migrated from OG — every migrated group membership is a plain "Member" with no in-group role — so making `content_editor` a global editor over all content types (currently effectively just `shanti_image`, plus stock `article`/`page`) has no per-group OG membership layer competing with it.</result>
<usage><subagent_tokens>18456</subagent_tokens><tool_uses>11</tool_uses><duration_ms>62966</duration_ms></usage>
</task-notification>

---

## Claude — 2026-07-30 14:21

Very useful. The second explorer surfaced a fact that reshapes my caveat: **no migration carries D7 per-group OG editor roles into D11** — every migrated group membership lands as a plain "Member" with no in-group role, and `group_roles` is never populated. So there is no per-group editor layer being built for the global role to "override." That actually strengthens your case: making `content_editor` global isn't overriding a migrated per-group capability — it's the *only* path by which migrated editors get any editorial ability at all, since their real D7 power lived in OG, which isn't being carried across.

It also means the collapse is already baked in: `RoleMap` maps D7 rids 4/5/6 → `content_editor` today.

I still need the first explorer's finding on *how* a sitewide role reaches content that lives inside Group entities (shanti_image nodes are group content), without becoming a group admin. Waiting on that before I finalize the mechanism.

---

## User — 2026-07-30 14:23

Are you saying that in D7 there were no per group editors at all?

---

## Claude — 2026-07-30 14:23

No — sorry, let me be precise, because that's an important distinction and I muddled it.

**D7 absolutely had per-group editors.** That's exactly what gate 2 found: Organic Groups defined a per-bundle `editor` role (OG rid 31 for `collection`, rid 51 for `subcollection`) with ~19 real permissions, and users were assigned it **per group** via OG's own `og_users_roles` table. So a user could be editor of Collection A specifically. That mechanism was real and is the whole reason the group-scoping question exists.

What the explorer found is a different thing — it's about the **D11 migration**, not about D7 reality:

> The migration that moves group memberships into D11 carries only *who belongs to which group*. It assigns everyone the plain **"Member"** group role and never populates `group_roles`. So the D7 **per-group `editor` assignments (`og_users_roles`) are dropped** — nothing in the current migration reconstructs them in D11.

So: per-group editors existed in D7; the current migration just doesn't preserve that layer.

And that exposes a sharper problem than I first framed, worth putting on the table before we design:

D7 had **two independent editor signals** that don't necessarily coincide:
1. **Core role assignment** (`users_roles`: rid 4/5/6) — this *is* what the migration carries, collapsing to `content_editor`.
2. **OG per-group editorship** (`og_users_roles`: OG rid 31/51) — the actual working editorial grant, and this is *dropped*.

Those are different tables and possibly different populations. A user could be an OG editor of a collection **without** holding any core editorial role — in which case the migration gives them nothing (plain authenticated user + plain group Member). Conversely, a user with core rid 4/5/6 gets `content_editor` whether or not they were ever a real OG editor.

So the population question I flagged isn't just "how many were real editors" — it's "**does core rid 4/5/6 membership actually line up with who held OG editor?**" If they diverge, then:
- Your global-`content_editor` proposal correctly captures the **core-role** editors, but
- silently **misses** anyone whose editorship was OG-only (no core editorial role).

That's a dev-0 data check we should run before finalizing — count core rid 4/5/6 holders, count distinct users in `og_users_roles` with an editor role, and see how much they overlap. It determines whether "collapse core roles → global content_editor" is actually sufficient, or whether we also need to derive editor status from the OG membership data.

Want me to fold that overlap check into the plan as a prerequisite, and have the first explorer's Group-access mechanism finish before I write the design? (The mechanism explorer is still running.)

---

## User — 2026-07-30 14:25

<task-notification>
<task-id>a0e8f4b498a293790</task-id>
<tool-use-id>toolu_018YTuCmzuHLSUY88SbLDBjW</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/fa3e8803-e594-46b0-88e6-8d284e990556/tasks/a0e8f4b498a293790.output</output-file>
<status>completed</status>
<summary>Agent "Explore Group module access model" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>I have a complete picture. Here are my findings.

## 1. Group module setup

- **Version:** `drupal/group: "^3.3"` in `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/composer.json`; locked to **3.3.5** in `composer.lock`. This is **Group 3.x** (the scoped-permission architecture, not OG).
- **Group types (2, siblings, one-level nesting):**
  - `group.type.collection.yml` — "Collection" (`creator_membership: true`)
  - `group.type.subcollection.yml` — "Subcollection", nested under a collection via the `field_parent_collection` entity-reference field.
- **Content relation plugins (`group_relationship_type.*`):** Only **`group_node:shanti_image`** (one per group type) plus **`group_membership`**. That is the load-bearing fact: **only the `shanti_image` node type is group content today.** `article` and `page` node types are NOT wired into any group — no `group_node:article` / `group_node:page` relationship type exists in config.
- **ADR 011** (`docs/adr/011-group-collections-inheritance.md`) confirms: Option D = entity-reference nesting + the custom `mandala_group_inheritance` module for visibility + membership inheritance. No `ggroup`/`subgroup` contrib. Production scale 55 collections / 116 subcollections, one level deep.

## 2. Group access enforcement

Custom module `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_group_inheritance/mandala_group_inheritance.module`.

- `hook_entity_access()` (`mandala_group_inheritance_entity_access`) only acts on the **`view`** operation, and only for `group` entities (collection/subcollection) and `shanti_image` nodes. For a **private** group (`field_group_access == 1`) it returns `AccessResult::forbidden()` for **non-members**. Create/update/delete are NOT touched here — those fall entirely to Group's own permission system.
- Its bypass checks: `_mandala_group_inheritance_group_access()` lets through `bypass group access`; `_mandala_group_inheritance_node_access()` lets through `bypass group access` **or** `bypass node access`.
- Access is otherwise **strictly per-group-membership** (`$group-&gt;getMember($account)`). Nothing here grants a global/outsider a private-group pass.

## 3. Key mechanisms (the real answer)

- **Group 3.x has three permission scopes** (`web/modules/contrib/group/src/PermissionScopeInterface.php`): `individual`, `insider`, `outsider`. `GroupPermissionChecker::hasPermissionInGroup()` resolves access by: individual grant → then insider (if member) OR outsider (if not), keyed on group **bundle**.
- **`bypass group access` DOES NOT EXIST in Group 3.3.5.** A full grep of `web/modules/contrib/group` (excluding tests) returns nothing. The custom module references it, but no module defines or grants it — that check is effectively **dead code**. Only `bypass node access` (Drupal core) is real.
- **Global group permissions** (`group.permissions.yml`) are only: `administer group` (`restrict access: TRUE`, "full control over all group configuration" — config-level, and it's the group-type admin permission) and `access group overview`. There is **no per-content sitewide bypass permission** shipped.
- **Outsider roles exist and are the mechanism:** `group.role.collection-outsider.yml` and `group.role.subcollection-outsider.yml` — both `scope: outsider`, `global_role: authenticated`, currently granting only `view group`, `view group_node:shanti_image entity`, `view group_node:shanti_image relationship`.

**How a sitewide role reaches into groups without per-group membership (Group 3.x idiom):** create a **synchronized group role** with `scope: outsider` (or `insider`) and `global_role: content_editor`, granting the gnode entity-operation permissions (`create group_node:shanti_image entity`, `update any group_node:shanti_image entity`, `delete any group_node:shanti_image entity`). A synchronized role applies to **every group of that bundle** for anyone holding the mapped Drupal global role — no per-group membership needed. Member role permission strings for reference are in `group.role.collection-member.yml` (e.g. `create group_node:shanti_image relationship`, `update own group_node:shanti_image relationship`).

**The catch for your goal:** even with such outsider grants, the custom `hook_entity_access` will still `forbidden()` the **view** of content in **private** groups for non-members, since it only bypasses on the nonexistent `bypass group access` or on core's `bypass node access`. So a truly *global* editor over private-group content requires either (a) granting core `bypass node access`, or (b) extending that hook to honor a new permission / the `content_editor` role. Outsider group-role grants alone won't reach private content.

## 4. Current `content_editor` role (verbatim)

`/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/config/sync/user.role.content_editor.yml` — `is_admin: false`, permissions:

```
- 'access administration pages'
- 'access content overview'
- 'access contextual links'
- 'access files overview'
- 'access toolbar'
- 'administer url aliases'
- 'create article content'
- 'create page content'
- 'create terms in tags'
- 'create url aliases'
- 'delete article revisions'
- 'delete own article content'
- 'delete own files'
- 'delete own page content'
- 'delete page revisions'
- 'edit own article content'
- 'edit own comments'
- 'edit own page content'
- 'edit terms in tags'
- 'revert all revisions'
- 'view all revisions'
- 'view own unpublished content'
- 'view the administration theme'
```

Notes: it has **zero group permissions** and **no bypass** of any kind. It only handles `article`/`page` node types, and only for **own** content (edit/delete own). It cannot touch `shanti_image` (the only group content type) at all today — no shanti_image node perms and no group-scoped role maps to it.

## Bottom line for your design

The mechanism you need is **synchronized Group roles** (scope `outsider` or `insider`, `global_role: content_editor`) on the `collection` and `subcollection` group types, granting `create/update any/delete any group_node:&lt;type&gt; entity` — this is how a sitewide role edits in-group content without per-group membership, and it does **not** exist yet (must be configured). Three gaps to plan for:

1. **Only `shanti_image` is group content.** If "any content type inside any group" must include article/page, you must first create `group_node:article` / `group_node:page` relationship types — they don't exist.
2. **No sitewide bypass permission exists** in Group 3.3.5 (`bypass group access` is referenced but undefined). Reaching content is done via synchronized outsider/insider roles, not a bypass.
3. **The custom `mandala_group_inheritance` hook will block viewing private-group content** for any non-member global editor, because its only bypass hooks are the nonexistent `bypass group access` and core's heavy `bypass node access`. Making `content_editor` global over private collections requires changing that hook or granting `bypass node access` (which also bypasses all node access site-wide — likely too broad for your "no site-admin" constraint).</result>
<usage><subagent_tokens>31772</subagent_tokens><tool_uses>27</tool_uses><duration_ms>287070</duration_ms></usage>
</task-notification>

---

## Claude — 2026-07-30 14:26

Both explorations are back, and the technical picture is now clear. Let me record the mechanism in the plan, then get your calls on the two real forks before I finalize.

The key technical findings:

- **Group 3.x, not OG.** The idiom for "a sitewide role edits inside all groups without per-group membership" is a **synchronized group role** (`scope: outsider`/`insider`, `global_role: content_editor`) granting `create / update any / delete any group_node:shanti_image entity`. This doesn't exist yet — it's config we'd add on the `collection` and `subcollection` group types.
- **Only `shanti_image` is group content today.** `article`/`page` aren't wired into groups at all, and there are no Texts/Sources/AV/Home node types yet — this is the Images pilot. So "any content type in any group" concretely means shanti_image now, plus a documented pattern to repeat as other sites' types land.
- **There is no `bypass group access` permission in Group 3.3.5** — it's referenced in the custom `mandala_group_inheritance` module but never defined (dead code). So there's no ready-made global hammer; reach is via synchronized roles.
- **The private-group snag:** `mandala_group_inheritance`'s `hook_entity_access` forbids *non-members* from **viewing** content in **private** collections, and only bypasses on that nonexistent `bypass group access` or on core's very broad `bypass node access`. You can't edit what you can't view — so making content_editor reach *private* collections requires either defining/activating a real bypass permission (and honoring it in that hook) or granting `bypass node access` (too broad for "no admin"). This is the one place the proposal needs a code change, not just config.

Let me update the plan file with this, then I have two decisions for you.

---

## Claude — 2026-07-30 14:27

The plan file now has the full mechanism. Two genuine forks change what gets built, plus one framing call — let me get your decisions.

---

## Claude — 2026-07-30 14:28

Got it — and I've noted your answer to the first: **yes, truly global including private collections** (so we'll make the code change to the visibility hook).

What would you like to clarify on the other two? To orient you, the two open ones are:

1. **Editor source** — the subtle bit is that D7 had *two* separate "editor" markers that may not point at the same people: the core role (rid 4/5/6, which the migration carries) and OG per-group editorship (`og_users_roles`, which the migration drops). My question was whether we should verify on dev-0 how much those two populations overlap before deciding that "core role = content_editor" is a complete definition — because if a chunk of real editors were OG-only (no core role), they'd migrate with no editing ability.

2. **Deliverable** — whether this should include a formal ADR ratifying the global-non-admin-editor access model, or just be a code/config change.

Ask away — happy to explain any of it further, or reframe if I've got the framing wrong.

---

## User — 2026-07-30 14:31

Ok. Because the Content Editor role is relatively powerful, I don't want a group content editor to become a global content editor. In fact, for the initial user migration only someone who had the role shanti_editor should become a content editor in d11. But I would still like to migrate users first and set the general role permissions before migrating groups and group users and roles. If someone is a content editor in a group, that can be assigned later when we fully migrate groups and their roles.

---

## Claude — 2026-07-30 14:32

That's a much cleaner decision, and it dissolves the two things I was worried about. Let me reflect it back to make sure I've got it exactly, because it changes the migration mapping, not just the role.

**What your decision means:**

- **`content_editor` = the `shanti_editor` equivalent only.** It's global and non-admin *because that's what shanti_editor was* — a global override across the apps. Only D7 users who held `shanti_editor` (rid 6) get it. That fully resolves my escalation worry: we are *not* promoting the group-scoped `editor` (rid 4) to global.
- **This requires changing the migration role map.** Right now `RoleMap` (in `d7_users.yml`) maps rid 4/5/6 all → `content_editor`. Per your call it becomes: **rid 3 → administrator, rid 6 → content_editor**, and **rids 4 (`editor`) and 5 (`workflow editor`) drop** — they migrate as plain authenticated users for now. (rid 5 is the vestigial AV one; rid 4's real per-group editing gets restored later — see below.)
- **Per-group editors are deferred to the group phase.** Anyone who was a per-group `editor` in D7 (the OG `og_users_roles` layer, and/or core rid 4) gets their editing capability re-granted *inside* the group migration, as a per-group group-role — not as a global role. So the OG-vs-core overlap question I raised is **moot for this phase**; it moves into the group-role migration where it belongs.
- **Sequencing: two phases.**
  - **Phase A (this task):** migrate users + set the sitewide role permissions. `content_editor` becomes the global non-admin editor and is assigned only to former shanti_editors.
  - **Phase B (later):** migrate group user-memberships and per-group roles, restoring per-group editors via Group roles.

One thing worth flagging so Phase A actually delivers value: the collections/subcollections and their `shanti_image` content **already exist in D11** (1b.2 is done). So for a former shanti_editor to actually edit that content, `content_editor` still needs the *global reach* mechanism now — the synchronized group roles + the private-group view-hook bypass we discussed. That reach is what makes it a real global editor rather than a role that can only touch content outside any group (of which there's essentially none). So Phase A includes that, and it's exactly faithful to shanti_editor's "override group permissions" nature.

Let me confirm two small implications and get your last call:

1. **rids 4 and 5 → plain authenticated user in Phase A** (no special sitewide role; rid 4's per-group editing comes back in Phase B). Correct?
2. **Deliverable:** given this now defines both the D11 editorial access model *and* a change to the migration mapping, do you want a short **ADR** recording it (framed as fixing D7 brokenness under ADR 008/010), or just the code/config change with a session-log/deferred note?

Let me get the plan file updated to this direction meanwhile.

---

## Claude — 2026-07-30 14:33

I've updated the plan file to this refined direction. Waiting on your two confirmations above (rids 4/5 → authenticated in Phase A; and ADR vs. lighter-weight record) before I write the final plan and the verification approach.

---

## User — 2026-07-30 14:36

I'm not saying I want it but what would the ADR say?

---

## Claude — 2026-07-30 14:36

Let me look at the house ADR format so the draft matches, then write it out.

---

## Claude — 2026-07-30 14:37

Here's the draft — written to the house ADR format (Context / Decision / Consequences), framed as a brokenness fix under ADR 008/010. This is what I'd propose; you're not committing to it by reading it.

---

# ADR 015: D11 editorial access model — global non-admin `content_editor` (shanti_editor equivalent); per-group editors deferred to Group roles

**Status:** Proposed
**Date:** 2026-07-30
**Deciders:** *(team — to ratify)*
**Relates to:** [ADR 008](008-mvp-migrate-not-improve.md), [ADR 010](010-adr-008-scope-clarification.md), [ADR 011](011-group-collections-inheritance.md); resolves deferred note `d7-editor-permissions-og-group-scoped-not-migrated.md`

## Context

The user migration collapses D7's three editorial roles — `editor` (rid 4), `workflow editor` (rid 5), `shanti editor` (rid 6) — onto D11's committed `content_editor` role. Investigation for the `d7_user_role` permission-wipe fix surfaced that this is broken on two counts:

1. **D11's committed `content_editor` is disconnected stock config.** Its 23 permissions cover only Drupal's demo `article`/`page`/`tags` types. It grants nothing on `shanti_image` (the sole real Mandala content type in the Images pilot, and the only Group content type) — so every migrated editor would land unable to edit the content they manage.
2. **The three D7 roles are not equivalent, and collapsing them conflates distinct capabilities.** Core `role_permission` was empty for all three; their real meaning lived elsewhere:
   - `shanti editor` (rid 6) was a **global** editorial role that **overrode per-group permissions** across the apps.
   - `editor` (rid 4) was **group-scoped** — real editorial power granted per-collection via Organic Groups (`og_role`/`og_users_roles`, generic `editor`, ~19 perms), never sitewide.
   - `workflow editor` (rid 5) was **AV-only and effectively vestigial** — never meaningfully used.

Naively "fixing" `content_editor` by giving the collapsed role global edit rights would silently promote former group-scoped editors to global editors — a real privilege escalation. Under [ADR 008](008-mvp-migrate-not-improve.md)/[ADR 010](010-adr-008-scope-clarification.md), correcting this is a **brokenness fix, not a user-facing improvement**: the committed role was never wired to Mandala's model, and the migration mapping conflated three different roles.

## Decision

**1. D11 `content_editor` is a global, non-administrative editorial role.** It may create / edit / delete any content item of any content type in any collection or subcollection — **including private ones** — but holds **no** administrative privilege (no site configuration, views, user management, or module/config administration). This is the D11 realization of D7 `shanti editor`'s original global-override intent.

**2. `content_editor` is assigned only to former `shanti editor`s.** The user-migration role map is:

| D7 role (rid) | D11 outcome |
|---|---|
| administrator (3) | `administrator` |
| shanti editor (6) | `content_editor` (global) |
| editor (4) | authenticated only in this phase; per-group editing restored in the group phase |
| workflow editor (5) | authenticated only (vestigial) |

**3. Per-group editorial capability is not represented by any sitewide role.** D7's group-scoped `editor` (OG `og_users_roles`, and legacy core rid 4) is reconstructed **later**, as a per-group Group role (Group 3.x `individual` scope), during the group user-membership/role migration — not carried by `content_editor`.

**4. Sequencing.** Phase A (this decision): migrate users and set sitewide role permissions; `content_editor` global reach implemented so former shanti_editors can edit the already-migrated group content. Phase B (later): migrate group memberships and per-group roles.

**5. Mechanism.** Global reach is implemented via **synchronized Group roles** (`scope: outsider`/`insider`, `global_role: content_editor`) on the `collection` and `subcollection` group types, granting `create` / `update any` / `delete any group_node:<type> entity`; plus a bypass permission honored by the `mandala_group_inheritance` view hook so `content_editor` can reach content in **private** collections. No new core `bypass node access` grant (too broad for the non-admin constraint).

Operative test ([ADR 010](010-adr-008-scope-clarification.md)): **no user gains capability they did not have in D7.** Shanti editors were already global; group editors remain group-scoped (deferred, not dropped); the vestigial role grants nothing.

## Consequences

- The `RoleMap` map in `d7_users.yml` changes to `{3: administrator, 6: content_editor}`.
- `user.role.content_editor.yml` is corrected to a global non-admin editorial permission set; new synchronized `group.role.*-content_editor.yml` configs are added; `mandala_group_inheritance` gains a bypass permission + hook wiring.
- The synchronized-role pattern is the **template every future site's content types repeat** (Texts/Sources/AV/Home) as their Group content types are introduced.
- Faithfulness guardrail: the former-`shanti_editor` population must be confirmed on dev-0 before running, so the global grant lands only where intended.
- Per-group editor reconstruction is formally owed by the Phase B group-role migration, and connects to the still-open **1b.3 / 1b.4** access tasks.
- Does not change external API response shapes (React app); not a Phase 5 concern.

---

That's the whole thing. Note it would need one small correction elsewhere to stay honest: gate-2's deferred note currently frames the fix as "make content_editor global for everyone" — the ADR narrows that, so the note should get a closing line pointing at the ADR.

Want me to fold this in as the deliverable (ADR + the Phase-A implementation), or keep the record lighter-weight? And still need your confirm on rids 4/5 → authenticated in Phase A (which the draft assumes).

---

## User — 2026-07-30 15:00

One thing I want to make sure is that it is noted that the content editor role will get full access to create/edit/delete other content types (d7 apps) as they get migrated. So when we migrate AV, it gets the same permissions for audio and video content types that it has for images. Understood?

---

## Claude — 2026-07-30 15:00

Understood — and that's an important strengthening. You're not just saying "reuse the pattern"; you're making it a **standing commitment**: `content_editor` is the global editorial role for *all* real Mandala content across every app, and each site's migration is obligated to grant `content_editor` full create/edit/delete on its content types on the same footing as Images. When AV lands, `content_editor` gets the same full CRUD on `audio`/`video` (in their groups) that it has on `shanti_image` today. It's a scope decision about the role's remit, not just an implementation note.

Let me bake that into the ADR as an explicit decision clause (Decision #6) and a consequence, and capture it in the plan file.

---

## Claude — 2026-07-30 15:01

Here's how those clauses would read in the ADR:

**Added to Decision:**

> **6. `content_editor`'s remit spans all Mandala content types, and expands with each migration.** `content_editor` is the single global editorial role for real Mandala content across all five apps — not Images-specific. Every subsequent per-site migration **must** grant `content_editor` full create / edit / delete on that site's content types on the same footing Images establishes for `shanti_image` (via the synchronized Group roles). E.g., the AV migration grants `content_editor` the same full CRUD on `audio` and `video` content, within their groups, that it holds on `shanti_image`. A migrated editor's reach therefore grows to cover new content as it arrives, with no per-site role redesign.

**Added to Consequences:**

> - Granting `content_editor` full CRUD on the site's content types becomes a **required checklist item for every per-site content migration** (Texts, Sources, AV, Home), alongside the `field_legacy_nid` convention (`migration-legacy-nid-required-convention.md`). Omitting it would leave editors unable to manage that site's content — the same brokenness this ADR fixes for Images.

So the role is defined once as "global editor of all Mandala content," and each site migration is on the hook to extend it. I'll also add a short line to that existing per-site migration convention note so the requirement is enforced where migrators actually look.

That leaves two things to close before I write the final plan:

1. **Confirm rids 4 and 5 → plain authenticated user in Phase A** (rid 4's per-group editing returns in Phase B; rid 5 vestigial). The ADR draft assumes this.
2. **ADR: yes or no?** — you said you weren't committing; now that you've seen it, do you want it as part of the deliverable, or should I keep the record lighter (session-log + updating the gate-2 deferred note)?

---

## User — 2026-07-30 15:02

yes, to #1. And I want to do the ADR but I want to make a draft to run by Yuji and Xiaoming for them to sign off before it becomes official. WOuld that be through a PR?

---

## Claude — 2026-07-30 15:03

Yes — a PR is exactly the vehicle, and it fits how the team already ratifies decisions (the gate-2 finding itself went in as docs-only PR #69). Here's the clean way to do it, with one distinction that matters:

**Keep the ADR's `Status: Proposed` field separate from GitHub's "draft PR" state.** You want their *review*, so it's a **normal, review-requested PR** — not a GitHub draft (a draft PR blocks review/merge, the opposite of what you want). The ADR *document* carries `Status: Proposed` while it's under discussion; their PR approval is the sign-off; on merge you flip the field to `Status: Accepted` with the ratification date and deciders. That merged, Accepted file is the immutable record (per the ADRs-are-immutable rule — a Proposed ADR is still editable, so you can freely address their review comments; it only locks once Accepted).

**Recommended sequencing — decision first, code second:**

1. **PR 1 (docs-only, this is the sign-off gate):** ADR 015 as `Status: Proposed`, plus the `docs/adr/README.md` table row, `docs/adr/.pages`, and the closing line on the gate-2 deferred note pointing at the ADR. Reviewers: **Yuji (`ys2n`) + Xiaoming** (you're the author, so you can't self-approve — same as PR #69). They approve → flip to `Accepted` → merge.
2. **PR 2 (implementation, after sign-off):** the `RoleMap` map change, corrected `content_editor` role, synchronized Group roles, and the `mandala_group_inheritance` bypass permission + hook wiring. Gated on the ADR because it's the concrete expression of a decision you don't want to build until it's ratified.

Splitting them means Yuji and Xiaoming are signing off on the *access model* cleanly, not reviewing YAML diffs at the same time — and nothing lands in code until they've agreed to the model.

Let me fold this delivery workflow into the plan and lay out the full execution, then I'll present it for your approval.

---

## Claude — 2026-07-30 15:04

The plan file is complete and reflects every decision. Let me load the exit tool and present it for approval.

---

## Claude — 2026-07-30 15:05

Plan approved. Starting PR 1 (the docs-only sign-off gate). Let me set up the branch and read the files I'll be updating.

---

## Claude — 2026-07-30 15:05

Now I have everything. Writing ADR 015 first.

---

## Claude — 2026-07-30 15:05

Now the README table row and `.pages` entry.

---

## Claude — 2026-07-30 15:06

Now the gate-2 deferred note — add a resolution pointer to ADR 015.

---

## Claude — 2026-07-30 15:07

Now add the `content_editor` grant to the per-site migration checklist in the legacy-nid convention note.

---

## Claude — 2026-07-30 15:07

All docs updated. Let me review the diff and check push auth before committing.

---

## Claude — 2026-07-30 15:07

SSH agent has keys. Committing and pushing.

---

## Claude — 2026-07-30 15:07

Branch pushed. Let me find the reviewer handles before opening the PR.

---

## Claude — 2026-07-30 15:08

Only two direct collaborators returned. Let me check how reviewers were assigned on the comparable PR #69.

---

## Claude — 2026-07-30 15:08

Handles confirmed: `Xiaoming` and `ys2n`. Opening the PR.

---

## Claude — 2026-07-30 15:08

PR #74 opened. Let me confirm the reviewers attached (they weren't in the direct-collaborators list, so worth verifying).

---

## Claude — 2026-07-30 15:09

Reviewers attached, not a draft — PR 1 is fully out for sign-off. Let me record this session's decision in memory.

---

## Claude — 2026-07-30 15:09

Done. **PR 1 (the sign-off gate) is out: [PR #74](https://github.com/uvalib/mandala-navina/pull/74).**

## What landed
- **ADR 015** (`Status: Proposed`) — the editorial access model, with all six Decision clauses we worked through, framed as a brokenness fix under ADR 008/010.
- README table row + `.pages` entry (so it renders in mkdocs).
- Gate-2 deferred note cross-linked and annotated — records that ADR 015 *narrows* its original "global for everyone" direction to "global, shanti_editor-only; per-group deferred."
- The per-site-migration checklist (in the legacy-nid convention note) gained the required "grant `content_editor` full CRUD on the site's content types" item.

Docs-only, normal (non-draft) PR, reviewers **Xiaoming** and **ys2n** attached and requested.

## What's next
- **Ratification:** when both approve, I flip the ADR's `Status: Proposed → Accepted` (+ ratification date + deciders) in a final commit, then merge.
- **PR 2 (implementation) is deliberately held** until the ADR is Accepted. It's fully scoped in the plan file (`~/.claude/plans/ok-so-what-i-jolly-turtle.md`): the `RoleMap` map narrowing in `d7_users.yml`, corrected `content_editor` role, the synchronized Group roles, and the `mandala_group_inheritance` bypass permission + hook — with the end-to-end DDEV verification (shanti_editor→content_editor; edit in a public *and* a private collection as a non-member; non-admin floor).

Memory's updated to reflect gate 2 as decided-pending-ratification. Want me to leave PR #74 with the team and stop here, or draft PR 2 now so it's ready to fire the moment the ADR is approved?

---

## User — 2026-07-30 15:10

what do you mean by PR 1?

---

## Claude — 2026-07-30 15:10

Just my shorthand from the plan — sorry, it's clearer to use the real number.

When we set up the sequencing, I split the work into two pull requests:

- **"PR 1"** = the docs-only ADR sign-off PR. That's the one I just opened: **[PR #74](https://github.com/uvalib/mandala-navina/pull/74)**. It contains only the decision record (ADR 015 as `Proposed`) plus the index/cross-link updates — no code. Its job is to get Yuji and Xiaoming to agree the access model.
- **"PR 2"** = the implementation PR (the `RoleMap` map change, the corrected role, the Group roles, the hook). Not opened yet — it's gated on ADR 015 being accepted.

So "PR 1" is just a label for PR #74. There's only one PR live right now. Going forward I'll call it PR #74 to avoid confusion.

---

## User — 2026-07-30 15:11

describe to me the issue behind the "PR 2"?

---

## Claude — 2026-07-30 15:11

"PR 2" is the implementation that actually makes ADR 015 real in the running site. The issue behind it is this: **once the user migration runs, every migrated editor lands on a D11 site where they cannot edit any Mandala content** — and fixing that touches four different places that all have to change together. Here's the problem in layers.

## The core defect
D11's committed `content_editor` role is unmodified Drupal stock config. Its ~23 permissions cover only `article`, `page`, and `tags` — Drupal's demo content types that *nothing in Mandala uses*. It has **zero** permission on `shanti_image`, which is the only real content type in the Images pilot. So a former editor who migrates and gets `content_editor` can log in and touch nothing they're supposed to manage. That's the headline bug PR 2 fixes.

## Why it's not just "add permissions to one YAML file"
Four independent things are broken/missing, and each is a separate edit:

**1. The migration role map grants the role to the wrong people.**
`RoleMap` in `d7_users.yml` currently maps D7 rids 4, 5, *and* 6 all → `content_editor`. Per ADR 015 that must narrow to `{3: administrator, 6: content_editor}` — only former `shanti editor`s get it. Left as-is, the migration would hand a *global* editor role (once we make it powerful) to former group-scoped editors too — a privilege escalation. So the map has to change in lockstep with making the role powerful.

**2. Node permissions alone can't reach the content, because the content lives inside Groups.**
`shanti_image` nodes aren't free-standing — they're *group content* (via `group_node:shanti_image` relationships in the collections/subcollections). The Group module (3.3.5) enforces its own access layer on top of core node permissions. So even if you gave `content_editor` "edit any shanti_image content" at the core level, Group would still gate it. The correct Group 3.x mechanism is a **synchronized group role** (`scope: outsider`/`insider`, `global_role: content_editor`) on each group type, granting `create` / `update any` / `delete any group_node:shanti_image entity`. Those config objects don't exist — PR 2 creates them.

**3. There's no ready-made "global" hammer — and a custom hook actively blocks the private case.**
I checked: `bypass group access` is referenced in the custom `mandala_group_inheritance` module but **doesn't actually exist** in Group 3.3.5 — it's dead code. Meanwhile that module's `hook_entity_access` explicitly **forbids non-members from *viewing* content in *private* collections** (`field_group_access == 1`). Since you can't edit a form you can't view, a global editor can't reach private-collection content at all. ADR 015 says the editor *must* reach private collections. So PR 2 has to **define a real bypass permission**, grant it to `content_editor`, and wire it into that hook (which conveniently already checks for the permission name — defining it activates the existing path). The alternative, core `bypass node access`, is rejected because it's too broad for the "no admin" constraint.

**4. The "no admin" floor has to be preserved.**
The corrected role must gain real editorial reach *without* picking up site-config, views, or user-administration permissions. So it's not "make it powerful" — it's "make it exactly as powerful as a global content editor and no more."

## Why PR 2 is separated and gated
All of the above is the *concrete expression* of an access-model decision that Yuji and Xiaoming haven't signed off on yet. If the model changes in review (say, they want public-only reach, or a different reviewer wants per-group roles built now instead of deferred), the four edits above change materially. So PR 2 is held until ADR 015 is Accepted — otherwise we'd be reviewing YAML diffs and re-litigating the model at the same time.

And there's the verification burden that comes with it: PR 2 can't be eyeballed from config: it has to be driven end-to-end in DDEV against the scrubbed DB — confirm a shanti_editor becomes `content_editor` while a plain editor becomes only authenticated, confirm the role can edit a `shanti_image` in both a public *and* a private collection as a non-member, and confirm it *can't* reach `/admin/config`. That's the proof the four moving parts actually compose correctly.

---

## User — 2026-07-30 15:15

draft PR 2 now so it's ready

---

## Claude — 2026-07-30 15:16

On it. This is real Drupal config, which is finicky, so let me read the actual file structures before editing rather than guess. Let me pull the templates and the migration configs.

---

## Claude — 2026-07-30 15:18

Now the two `d7_users.yml` migration configs and the current role config.

---

## Claude — 2026-07-30 15:19

I now have every file structure I need. Let me create the implementation branch off `main` and generate UUIDs for the new config.

---

## Claude — 2026-07-30 15:20

The docs changes correctly stayed on PR #74's branch; this branch is clean off `main` (PR 2 doesn't touch those docs). Now the implementation. First, correct the `content_editor` role.

---

## Claude — 2026-07-30 15:20

Now the role map in both `d7_users.yml` copies.

---

## Claude — 2026-07-30 15:21

Now the module: add the permission definition and wire it into the two bypass checks.

---

## Claude — 2026-07-30 15:22

Now the four synchronized Group roles (outsider + insider, per group type).

---

## Claude — 2026-07-30 15:22

All files written. Let me validate PHP syntax and YAML well-formedness before committing (DDEV is down, so this is the static check I can do now).

---

## Claude — 2026-07-30 15:23

PHP lint passed. Let me parse the YAML via the Symfony component through the vendor autoloader instead.

---

## Claude — 2026-07-30 15:23

All YAML valid, PHP lints clean. Let me review the full diff before committing.

---

## Claude — 2026-07-30 15:23

All nine files staged and validated. Committing and opening as a **draft** PR (gated on ADR #74 acceptance — unlike the ADR PR, this one must not merge yet).

---

## Claude — 2026-07-30 15:24

Done. **PR #75 is up as a draft**, based on `main`, gated on ADR #74.

Let me update memory to reflect that PR 2 now exists as a draft.

---

## Claude — 2026-07-30 15:25

PR 2 is drafted and ready. Summary:

## Two PRs now open
- **[#74](https://github.com/uvalib/mandala-navina/pull/74)** — ADR 015 (Proposed), docs-only, out for Yuji + Xiaoming sign-off.
- **[#75](https://github.com/uvalib/mandala-navina/pull/75)** — the implementation, **GitHub draft**, based on `main`, explicitly gated on #74.

## What's in PR #75 (9 files)
1. **Role map narrowed** — `d7_users.yml` (both copies) → `{3: administrator, 6: content_editor}`. Only former shanti_editors become `content_editor`; rid 4/5 → authenticated.
2. **`content_editor` corrected** — dropped the disconnected stock article/page/tags perms, added `bypass mandala group access`, still `is_admin: false`.
3. **Four synchronized Group roles** — `collection`/`subcollection` × `outsider`/`insider`, granting `create` / `update any` / `delete any group_node:shanti_image entity`. Both scopes so the grant holds whether or not the editor is also a member.
4. **Private-collection reach** — new restricted `bypass mandala group access` permission in `mandala_group_inheritance`, wired into the view-hook's two bypass checks (the hook already had the dead-code check shape, so this activates it cleanly).

## What I could and couldn't verify
- ✅ PHP lints clean; all changed/new YAML parses.
- ❌ **Not verified end-to-end** — DDEV is stopped, so I couldn't run the migration or exercise access. That's why it's a draft. The PR body carries the full pre-merge checklist: ADR accepted → run migration on the scrubbed DB → edit in a public *and* a private collection as a non-member → confirm the non-admin floor → `cim`/`cex` round-trip to normalize config ordering → check whether Group's create path also needs core `create shanti_image content`.

One design note worth flagging for the reviewers (it's in the PR): I built **both** outsider and insider scopes now, even though Phase A has no group memberships yet. The insider variant is dead weight until Phase B, but it prevents a latent bug where a content_editor who later becomes a group member would silently lose "edit any" in their own collections. If Yuji/Xiaoming would rather keep the config minimal, dropping the two insider files is a clean trim.

When you're ready to verify, I can bring DDEV up and drive the checklist — just say the word (ideally after the ADR gets its sign-off, so we're not verifying a model that might still shift).

---

## User — 2026-07-30 16:02

bring up DDEV and run the verification checklist

---

## Claude — 2026-07-30 16:02

Bringing DDEV up. This is a multi-step verification, so let me start the environment and check the baseline state.

---

## Claude — 2026-07-30 16:03

DDEV is up. Let me check the site state — whether Drupal is installed, config status, and whether the scrubbed user DB is available.

---

## Claude — 2026-07-30 16:03

Drupal is installed and clean. Let me rebuild caches (to pick up the module hook + new permission) and check what config import is pending.

---

## Claude — 2026-07-30 16:05

This local DDEV has significant pre-existing config drift (media types, footnote editor, etc. from spike work) between its DB and `config/sync`. A full `cim` would churn all of that and could delete uncommitted local spike config — wrong tool here. I'll do a **targeted partial import** of just my ADR-015 config objects from a temp source dir, which leaves everything else untouched.

---

## Claude — 2026-07-30 16:05

`--preview` isn't in this drush. Let me run the partial import directly (it only touches the 6 files in the source dir).

---

## Claude — 2026-07-30 16:05

Config imported cleanly — 4 group roles created, `content_editor` and the migration updated. Drupal accepted all the permission strings, scopes, and `global_role`. Let me verify the role/permission are active and check what content data exists for the access tests.

---

## Claude — 2026-07-30 16:06

All active and correct. Now let me survey the content data available for the access tests — shanti_image nodes and public/private collections.

---

## Claude — 2026-07-30 16:06

Real data present: 111,343 images, 39 public + 16 private collections. Now let me find a representative content node in a public collection and one in a private collection for the access tests.

---

## Claude — 2026-07-30 16:07

Now the core access test. Let me create two non-member test users — one `content_editor`, one plain authenticated — and check view+update access on the public node (nid 21) and private node (nid 1).

---

## Claude — 2026-07-30 16:07

That's exactly the ADR 015 matrix:

| user (non-member) | public node | private node |
|---|---|---|
| **content_editor** | view ✅ / edit ✅ | view ✅ / edit ✅ |
| plain authenticated | view ✅ / edit ❌ | view ❌ / edit ❌ |

The bypass reaches private content; the plain user is correctly still locked out of both editing and private view. Now the non-admin floor (item 5) and checking whether the scrubbed DB is available for a real migration run (item 2).

---

## Claude — 2026-07-30 16:08

Non-admin floor fully verified — `content_editor` has none of the administrative or bypass permissions. The `migrate_users` connection isn't wired up in this DDEV. Let me check whether the scrubbed dump is available to do a real migration run, versus verifying the map at the plugin level.

---

## Claude — 2026-07-30 16:08

The scrubbed dump exists (8.2M) and was used for gate-1's end-to-end run. Let me do a real migration run. First, how does `settings.php` wire the `migrate_users` connection, and let me load the dump to see the actual role data.

---

## Claude — 2026-07-30 16:09

The dump is here and used before. Let me load it into a `mandala_shared` database and inspect the actual role assignments so I know the expected outcomes.

---

## Claude — 2026-07-30 16:10

Important finding in the actual data. The scrubbed shared DB has this role distribution:

| rid | role | users |
|---|---|---|
| 3 | administrator | 23 |
| 4 | editor | 142 |
| 5 | workflow editor | 2 |
| **6** | **shanti editor** | **0** |

**There are zero shanti_editors in the scrubbed data.** Under PR #75's map (`6 → content_editor`), that means the migration would assign `content_editor` to *nobody* — and all 142 real editors become plain authenticated users. I'll come back to what that means for the decision, but first let me finish the mechanical verification: run the migration (with a synthetic rid-6 assignment injected so I can also positively confirm `6 → content_editor`).

---

## Claude — 2026-07-30 16:11

Empty result — the migration isn't discoverable (a known gate-1 gotcha). Let me check `externalauth` and rebuild the migration cache.

---

## Claude — 2026-07-30 16:11

`externalauth` isn't enabled — that's the gate-1 gotcha that aborts migration discovery (the sibling `d7_user_authmap` needs it). Let me enable it and retry.

---

## Claude — 2026-07-30 16:12

`externalauth` was already enabled, yet status is still empty — so discovery is failing for another reason. Let me get the actual error with verbose output.

---

## Claude — 2026-07-30 16:12

"No migrations found" — the config exists, so discovery is choking on the source plugin. The D7 `d7_user` source plugin validates the source DB schema. Let me check what tables the scrubbed dump actually contains.

---

## Claude — 2026-07-30 16:13

`system` table is present and it's a full D7 schema, so that's not it. The likely cause: migrate discovery instantiates *all* migrations including the `d7_images_*` ones, which need the `migrate` (per-site) connection that isn't set — one failure aborts discovery globally. Let me point both connection env vars at the loaded DB so discovery succeeds, then import only `d7_users`.

---

## Claude — 2026-07-30 16:13

Still nothing, even with a valid connection and `d7_images` present. Let me see what migrate discovery finds at all — plain status, no filter.

---

## Claude — 2026-07-30 16:13

Discovery does work (those errors are noisy auto-generated `migrate_drupal` migrations hitting the wrong source DB). Let me filter the status for our actual migration.

---

## Claude — 2026-07-30 16:14

migrate_plus config migrations aren't being discovered at all (while auto-generated ones are), which points to a load-time exception in one of them. Let me instantiate `d7_users` directly to get the exception.

---

## Claude — 2026-07-30 16:14

The nested quoting broke it. Let me write a script file instead.

---

## Claude — 2026-07-30 16:15

That's the missing piece — the source connection's host falls back to an unset `MYSQL_HOST` and tries a socket. Per the documented setup, I need `MIGRATE_SOURCE_HOST/USER/PASSWORD=db`. Let me set all connection vars and run the import.

---

## Claude — 2026-07-30 16:15

The migration was already run on this machine (gate-1's run — 1538 users already imported with the *old* map). Let me re-run with `--update` to reprocess all users through the new map, then check the resulting role distribution.

---

## Claude — 2026-07-30 16:16

The role map works end-to-end against real data. Let me spot-check a former rid-4 editor (should now be authenticated-only) and test the create-permission question (item 7).

---

## Claude — 2026-07-30 16:17

Clear results:
- **Former rid-4 editor (uid 8): roles = `[authenticated]`** — correctly *not* content_editor ✅
- **Synthetic rid-6 shanti_editor (uid 7): roles = `[authenticated, content_editor]`** ✅

The role map is verified end-to-end against real data. Now item 7's create nuance: the Group-layer create permission is granted (YES), but core `createAccess` is NO. Let me settle whether create actually works by checking the real group-content creation route access.

---

## Claude — 2026-07-30 16:18

**Create works** — the group-content create route is ALLOWED for content_editor (gated by the group permission, not core node create), so **no core `create shanti_image content` grant is needed** (item 7 resolved). The `add_form` (attaching an *existing* node) is correctly denied since we didn't grant relationship-create — consistent with "create content."

Now item 6 — the config round-trip. The site has heavy unrelated drift, so I'll check normalization only for *my* objects by exporting active config and diffing against the committed files.

---

## Claude — 2026-07-30 16:19

The data round-trips identically — but the diff surfaces one real cleanup: **my `user.role.content_editor.yml` carries an inline comment block, and Drupal strips comments from export-managed `config/sync` files** (that's why the repo convention is comments live in `config/install`, not `config/sync` — the committed `d7_users.yml` in sync has none). Those comments would vanish on the next `cim`/`cex` and show as drift. The rest (permissions, scope, dependencies) is byte-identical. Let me remove the comment block so the file matches what Drupal actually stores.

---

## Claude — 2026-07-30 16:20

Now clean up the verification artifacts (temp import dir, test users) and confirm the corrected file matches active config.

---

## Claude — 2026-07-30 16:20

Committed file now matches active config exactly. Let me commit the cleanup and push.

---

## Claude — 2026-07-30 16:20

Pushed. Now let me post the verification results as a comment on PR #75 (keeping it a draft, since the ADR gate remains).

---

## Claude — 2026-07-30 16:21

Verification is complete and documented on the PR. Let me update memory with the results and the key finding.

---

## Claude — 2026-07-30 16:21

Verification complete. Here's the rundown.

## The checklist — all technical items pass ✅

| Item | Result |
|---|---|
| **Role map** (real `migrate:import d7_users --update`, all 1,538 users) | rid 3 → 23 administrators; rid 6 → content_editor (proven via an injected synthetic rid-6 row); rid 4/5 → authenticated only (former editor spot-checked: `[authenticated]`) |
| **Edit in public collection** (non-member content_editor) | view ✅ / edit ✅ |
| **Edit in private collection** | content_editor view ✅ / edit ✅; plain authenticated fully blocked ✅ |
| **Create in group** (item 7) | `create_form` ALLOWED — group permission gates it, **no core `create shanti_image content` needed** |
| **Non-admin floor** | denied on all `administer *` + `bypass node/group access` ✅ |
| **Config hygiene** | all 6 objects imported cleanly; removed the inline comments from `content_editor.yml` (config/sync strips them → drift) and pushed the fix |

This was a real end-to-end run against the scrubbed DB, not a plugin unit test — the whole `mandala_role_map` → `entity:user` pipeline reprocessed 1,538 users.

## ⚠️ One data finding that needs a team decision

The scrubbed shared DB has **zero shanti_editors**:

| rid 3 admin | rid 4 editor | rid 5 workflow | rid 6 shanti editor |
|---|---|---|---|
| 23 | 142 | 2 | **0** |

So a real run of PR #75's map (`6 → content_editor`) assigns `content_editor` to **nobody**, and all 142 real editors migrate as plain authenticated users (their editing returns only in Phase B). That's *faithful to ADR 015 as written*, but it means **Phase A gives editorial capability to zero migrated users**. This is exactly the guardrail the ADR flagged ("confirm the shanti_editor population before running"). Before this un-drafts, the team should confirm whether dev-0's live `mandala_d7_shared` actually has shanti_editor holders, or reconsider whether shanti_editor-only is the right gate. I documented all of this in a comment on the PR and cc'd Yuji and Xiaoming.

## State
- **PR #75** is pushed (2 commits) and **stays a draft** — gated on ADR #74 acceptance, and now also flagging the data question.
- DDEV is left running with the migrated users in place (local, disposable). The `mandala_shared` source DB is loaded if you want to poke further.
- Memory updated with the verification results and the finding.

The honest headline: the implementation is **correct and proven** against the decision — but the verification surfaced that, on the data we have, the decision as written would grant the new role to no one. That's a decision question for the team, not a code bug.

---

## User — 2026-07-30 16:25

It's ok if the content editor role is granted to no one. Because it is powerful, I want to grant it by hand. Tell me are there any spikes left open for me?

---

## Claude — 2026-07-30 16:25

Good — that settles the data finding; hand-granting a powerful role is the right call, and the migration assigning it to no one is then exactly what you want.

Let me check the authoritative spike status rather than trust memory.

---

## Claude — 2026-07-30 16:26

Yes — you (Than) are lead on **three still-open spikes**:

| Spike | Title | Status | Where it stands |
|---|---|---|---|
| **4b** | CKEditor 5 footnotes (Texts-specific) | ○ Pending (most progressed) | Diagnostic findings + the Option 3 prototype are merged (PRs #31, #72). It's blocked on a **team decision** between Option 1 (footnotes module + a render-cache mitigation), Option 2 (plain hyperlinks), and Option 3 (Notes-list aggregation — the one you prototyped). Not concluded until the team picks a direction. |
| **5** | bibcite for Sources site | ○ Pending | Recently reassigned Xiaoming → you (PR #71). Not started. |
| **6** | API compatibility for the React app | ○ Pending | Not started. It's the **Phase 3 cutover gate** (Team-candidate mode). |

Your other two — **Spike 3** (Group collections) and **Spike 4a** (Tibetan Unicode round-trip) — are both ● Proven and closed.

So: 4b is waiting on a group decision rather than more work from you; 5 and 6 are genuinely unstarted. If you want to pick one up, 4b is the closest to closable (you've already built the leading option — it mainly needs the team to choose), while 6 is the highest-stakes since it gates the Phase 3 React cutover.

Want me to pull up the 4b spike doc and lay out the Option 1/2/3 tradeoff so you can drive that decision, or start scoping 5 or 6?

---

## User — 2026-07-30 16:32

Lay out the 4b Option 1/2/3 tradeoff

---

## Claude — 2026-07-30 16:33

Here's the 4b decision laid out. The doc frames it as "three options," but the important thing to see first is that **one of the three isn't really a peer of the other two** — so the real choice is narrower than it looks.

## The shared foundation (true regardless of which option)

- Every Texts book is a D7 Book-module outline: pages share a `bid`. Footnote **citations** (`nb{N}`) are scattered across content pages; their **definitions** (`n{N}`) are collected on a dedicated "Notes" page. This is a 100%-consistent site convention — across all 7,633 books, *zero* have a same-page citation+definition pair (552 refs / 579 defs / 29 books).
- `footnotes` 4.x is **single-field-scoped**: a citation tag must carry its own definition text (`<footnotes data-value="N" data-text="…">`). It has no cross-node capability. That's the decisive, confirmed finding.
- D7 never showed books page-by-page — it **concatenates the whole book at render time** (a Views query on `bid`), so readers never experienced a cross-page problem.
- The **per-citation transform** — resolve each `nb{N}`/`n{N}` pair within a book and inline the resolved text into that citation's own tag, written back **per-page** (no node merging) — is de-risked and works. **Footnote popovers work from this in every option**, because the text is baked into the tag, independent of caching.

So the popover UX is not where the options differ. **They differ on the end-of-book Notes list.**

## The three options

| | **Option 1 — full footnote fidelity** | **Option 2 — plain hyperlinks** | **Option 3 — dedicated Notes-list aggregation** |
|---|---|---|---|
| **What it is** | Transform + `footnotes` module: popovers **and** an aggregated end-of-book Notes list, matching D7 | Citation numbers become plain links to the Notes page/anchor | Not a display option — the **mechanism that makes Option 1's Notes list work** |
| **Reader UX** | Preserved (popover + notes list) | Downgraded (click through, no popover) | (enables Option 1's UX) |
| **Effort** | Transform + a Notes-list mitigation + tests | Lowest — no transform, no cross-node logic | Custom aggregation reading migration output directly; + integration/styling/tests |
| **Open risk** | **Was** the blocker → now retired by Option 3 | **Zero** open technical risk | Prototyped & confirmed working |

## The key insight

Option 1's *only* real blocker was the Notes list: the module's stock aggregation (`footnotes_footer_disable` + `FootnotesGroupBlock`) is **empirically confirmed broken under Drupal's default render cache** — it silently drops the footnote from any page that was ever rendered standalone (a direct `/node` visit, a crawler, a search result). Since the render cache is keyed on the entity, not the view context, and persists indefinitely, that's *near-certain in production*, not an edge case.

There were four ways to fix that. **Option 3 is one of them** — bypass the broken static accumulator entirely and build the Notes list by reading the migration's own resolved-footnote data directly. You **prototyped it** (`spike_footnotes_demo`) and it produces a correct, complete Notes list against the exact cache-HIT precondition that breaks the stock mechanism.

So Option 3 isn't a third destination — **it's the bridge that turned Option 1 from "has an unsolved caching problem" into "hard part is built and proven."**

## What the decision actually is

It's **Option 1 (using Option 3 as its Notes-list engine) vs. Option 2.**

- **Option 1 + 3** — preserves the footnote experience users have today. Cost is now *known and bounded*: the book-outline-aware transform + wiring your prototype to real migration output + styling/tests. No open unknowns remain.
- **Option 2** — cheapest to ship, but a permanent UX downgrade (numbers become links, no popover, no module-driven notes list).

**My read:** the doc already leaned Option 1, and your Option 3 prototype retired its last risk — what's left is implementation, not investigation. Since you built the original D7 footnotes and the popover *is* the reading experience, Option 1+3 is the defensible call unless the team wants to trade that fidelity for Option 2's lower effort. The honest question to put to the team is simply: **is the footnote-popover experience worth the (now-bounded) transform + aggregation work, or do we accept hyperlinks?**

Want me to turn this into a shareable decision doc/artifact for the team, or draft the recommendation as an update to the spike's "team sign-off" section so you can drive it to a decision?

---

## User — 2026-07-30 16:58

Yes, I'd like to go with 1+3. The team will agree since it is most faithful and there are no real blockers.

---

## Claude — 2026-07-30 17:00

Great — Option 1+3 it is. Let me record that decision in the spike doc and put it in front of the team for the sign-off. First a clean branch off `main`.

---

## Claude — 2026-07-30 17:00

Those notes just reflect that this branch (off `main`) doesn't carry the ADR-015 code — correct, it's docs-only. Now let me add the decision to the spike doc.

---

## Claude — 2026-07-30 17:00

Now update the doc's Status line and the spikes README to reflect the decision.

---

## Claude — 2026-07-30 17:02

PR #76 is up for the 4b sign-off. Let me update memory to record the decision.

---

## Claude — 2026-07-30 17:02

This memory is 17 days stale (pre-dates the Option 3 prototype and the decision). Let me add the decision to the top.

---

## Claude — 2026-07-30 17:03

Done — the 4b decision is recorded and in front of the team.

## What I did
- **Recorded the decision** in `docs/spikes/spike-04b-ckeditor5-footnotes.md`: a new **Decision (2026-07-30)** section (Option 1 + Option 3, with the reasoning and the downstream implementation list) and updated the status line.
- **Updated the spikes README** — 4b now reads *"◐ Direction chosen (2026-07-30): Option 1+3 — feasibility proven, transform impl downstream."*
- **Opened [PR #76](https://github.com/uvalib/mandala-navina/pull/76)** as the sign-off vehicle (docs-only, reviewers `ys2n` + `Xiaoming`). The PR body lays out the reframing — that Option 3 isn't a third destination but the mechanism that retired Option 1's only blocker — so the team can confirm quickly.
- **Memory updated** (spike-4b note + index) to supersede the old "3 options, decision needed" framing.

I recorded it honestly as **your call, expecting team agreement**, with PR #76 as where that agreement gets logged — I didn't claim ratification that hasn't happened yet.

## Your three open PRs from this session
| PR | What | State |
|---|---|---|
| [#74](https://github.com/uvalib/mandala-navina/pull/74) | ADR 015 (content_editor access model) | Proposed — awaiting Yuji + Xiaoming |
| [#75](https://github.com/uvalib/mandala-navina/pull/75) | ADR 015 implementation | Draft, **verified**, gated on #74 |
| [#76](https://github.com/uvalib/mandala-navina/pull/76) | Spike 4b decision (Option 1+3) | Awaiting Yuji + Xiaoming sign-off |

Once the team signs off on #76, Spike 4b is effectively closed (feasibility proven, direction set) and the footnote transform becomes downstream Texts-migration implementation work. That leaves **Spike 5** (bibcite for Sources) and **Spike 6** (React API compatibility) as your genuinely-unstarted ones whenever you want to pick the next thread.

---

## User — 2026-07-30 17:19

what is spike 6

---

## Claude — 2026-07-30 17:20

**Spike 6 — API Compatibility for the React Application.** It's your spike (you own the React app and the D7 API contracts), and it's the **Phase 3 cutover gate**: the React front-end can't move to the consolidated D11 instance until there's a proven strategy for keeping the APIs it consumes working.

## The core question
The current React app talks to **five separate D7 sites**, each at its own subdomain with its own API paths. D11 consolidates all of them into **one Drupal instance at one domain**. Spike 6 has to prove there's a clear strategy to preserve those API contracts so the React app doesn't break.

## Two things need answering

**1. URL strategy** (the main decision — a Drupal ↔ React team agreement):
- **Option A** — one domain, React app updated to use the new paths.
- **Option B** — keep the old subdomains as ALB aliases pointing at the single D11 instance → *no React changes* (needs Dave/Goldstein for ALB config).
- **Option C** — 301 redirects from old paths → risky, may break the React app depending on how it follows redirects.

**2. Per-endpoint feasibility** — document all the D7 API response formats and confirm each can be reproduced in D11, per endpoint.

## What's already known (de-risked by Spike 2)
Spike 2 did a lot of the groundwork, which narrows this spike:
- **Text search is NOT an API concern** — D7 search is entirely client-side; the React app queries the Solr proxy directly and will keep doing so. There's no search endpoint to replicate, and Spike 2 already proved D11's Solr query matches D7 exactly.
- The real Drupal APIs are of **two kinds**, and both need equivalents in D11:
  - **Browse-by-KMap** endpoints (e.g. `/services/subject/{kmap_id}`, `/general/api/subjectsimages/{kmap_id}`) — these just issue `fq=kmapid:{id}` Solr queries, which Spike 2 already proved. There's even a reusable reference controller (`spike_solr_demo`).
  - **Per-site node-detail** endpoints — the eight `/api/json/{nid}`-style URLs (one JSON + one AJAX per site). These are separate, handled by different D7 modules (shivanode/shivadata etc.), and **still need auditing** — that's the least-explored part.

## Pass criteria (when it's "proven")
- All eight per-site API response formats documented
- A URL strategy (A/B/C) agreed between the Drupal and React teams
- That strategy shown feasible in both D11 *and* the Terraform ALB config
- A clear per-endpoint implementation approach in D11

## Why it's the highest-stakes of your open spikes
It gates the Phase 3 React cutover, it's a **Team spike** (needs Dave for ALB and the React/Drupal teams to agree on URLs — not a solo call), and there's a real risk item baked in: if node IDs change during migration the APIs would need an old→new nid mapping (though the migration's `field_legacy_nid` convention is already laying groundwork for exactly that).

The lightest first step, if you pick it up, would be auditing those eight per-site node-detail endpoints (the one genuinely unmapped piece) and then getting the A/B/C URL decision in front of Dave and the React side. Want me to pull the D7 module source for those node endpoints so we can see what their responses actually look like?

---

## User — 2026-07-30 17:33

ok to get ready for spike 6. I suggest you look at the latest commits in the original mandala-om repo /Users/ndg8f/Sandbox/Mandala/Standalone/mandala-om and the mandala plug-in code at /Users/ndg8f/Sandbox/Mandala/Standalone/wp-kmaps We just had an issue with not being able to load the .json from mandala apis because of a strict WAF plus a separate issue with Sources.

---

## Claude — 2026-07-30 17:34

Let me look at both repos — recent history first, to find the WAF/JSON and Sources issues you mentioned.

---

## Claude — 2026-07-30 17:34

Let me find the API-consumption code and anything about the WAF/JSON and Sources issues.

---

## Claude — 2026-07-30 17:34

Shell reset — let me use absolute paths. There's an `ndg8f/api-proxy` branch and a "nodejson unavailable" commit that look directly relevant.

---

## Claude — 2026-07-30 17:34

The app lives in `kmaps-app/`. Let me explore that.

---

## Claude — 2026-07-30 17:34

zsh is globbing the `--include` patterns. Let me quote them.

---

## Claude — 2026-07-30 17:35

`src/logic/assetapi.js` is the heart of it — that's where the per-site node API URLs are built. Let me read it.

---

## Claude — 2026-07-30 17:35

JSONP is the key mechanism here — the app injects `<script>` tags with `json_wrf`/`json.wrf` callbacks rather than doing CORS fetches, and the per-app URL switch only implements `texts` so far (everything else is a TODO). That's directly germane to both the WAF block and Spike 6's scope. Let me see the dispatch hook and how Sources is handled.

---

## Claude — 2026-07-30 17:35

`useMandala` pulls the node-JSON URL from the Solr record's `url_json` field — so the endpoint is data-driven from the kmassets index. Let me read that hook and the Sources path.

---

## Claude — 2026-07-30 17:36

The architecture is clear now: node JSON is fetched via **JSONP** using a `url_json` field carried on each Solr record — which is exactly why a strict WAF breaks it (JSONP is a cross-origin `<script>` fetch of a `.json` URL with a callback param). Let me check the Sources path and the wp-kmaps side.

---

## Claude — 2026-07-30 17:36

The full per-site host map is the key Spike-6 artifact — in production each app is its own subdomain (`av.`, `images.`, `sources.`, `texts.`, `visuals.mandala.library.virginia.edu`, plus `mandala.library.virginia.edu` for home/places/subjects/terms). Let me look at the Sources viewer and the wp-kmaps plugin.

---

## Claude — 2026-07-30 17:36

Sources uses the same `useKmap` + `useMandala` JSONP path — so its node JSON also flows through `url_json`. Let me look at the wp-kmaps plugin's role and the recent WAF-adjacent changes.

---

## Claude — 2026-07-30 17:38

I've now got a clear picture of how the React app consumes the mandala APIs, and it explains both issues you hit. Here's the Spike-6 readiness synthesis.

## How the React app actually consumes the mandala APIs

It's a **two-step, per-asset** flow — and the second step is the fragile one:

1. **Solr first** (`useKmap`/`useAsset`) — query the kmassets index (via the solr-proxy) for the asset's record.
2. **Node JSON second** (`useMandala`) — read the **`url_json` field baked into that Solr record** and fetch it for the full Drupal node JSON. AV special-cases `.json` → `.jsonp`.

The single most important finding for Spike 6:

> **The node-JSON endpoint URL is not hardcoded in the app — it's stored per-record in the Solr `url_json` field.** (`src/hooks/useMandala.js`.) The app just follows whatever URL each Solr doc carries.

That reframes the whole URL-strategy question: the client's API URL is controlled by **what the kmassets sync writes into `url_json`**, which ties Spike 6 directly to the kmassets write path (1a.8 / reindeer_x, ADR 006/007). Change what the sync writes, and the app follows — no app redeploy needed.

## Everything is JSONP — which is exactly why the WAF broke it

The app fetches node JSON via **JSONP** — cross-origin `<script>` injection with a callback param (`callback`, `json_wrf`, `json.wrf`, `mdldata`, `pfunc` show up across `assetapi.js`, `useMandala.js`, `legacy/texts.js`). JSONP exists here purely to dodge CORS, because the React app (embedded by **wp-kmaps** in a WordPress page at `mandala.library.virginia.edu/mandala`) fetches from **six different D7 subdomains**:

| App | Production host |
|---|---|
| Home / places / subjects / terms | `mandala.library.virginia.edu` |
| AV | `av.mandala.library.virginia.edu` |
| Images | `images.mandala.library.virginia.edu` |
| Sources | `sources.mandala.library.virginia.edu` |
| Texts | `texts.mandala.library.virginia.edu` |
| Visuals | `visuals.mandala.library.virginia.edu` |

A strict WAF blocks JSONP readily — it's a `.json` URL served as a script with an odd callback query param, which trips bot/scraper and content-type heuristics. (Corroborated in the code: recent commits *"adding error message when nodejson unavailable"* and *"adding console message to JSONp network error"* are the app hardening against exactly this failure.)

**Spike 6 implication:** the D11 consolidation is the moment to **kill JSONP**. Once assets live behind one D11 origin (or ALB-routed origins), the app can move to plain `fetch` + proper CORS headers — and the **WAF ruleset for the API paths becomes an explicit deliverable of the URL-strategy decision (Option A/B/C)**, not an afterthought. This is a concrete new fail-criterion the spike should carry.

## The wp-kmaps role

`mandala.php` shows wp-kmaps is the **embedding + hosting layer**: it serves the built React app at `/mandala` (via `asset-manifest.json`) and injects `window.mandala_wp` settings. It also owns the **Tibetan/Newar translation-tool REST endpoints** (`mandala/v1/splittib|parsetib|parsenewar`, also JSONP via `json_wrf`) — that's what all the recent commits and the new `nocache_headers` are about. It is **not** an asset-API proxy — the node JSON still comes straight from the D7 subdomains. (Worth noting: a wp-kmaps-side proxy *could* be one answer to the WAF problem — route asset JSON through the same origin as the app.)

## Sources as a separate concern

`SourcesViewer.js` uses the same `useKmap` + `useMandala` JSONP path, so it fetches `sources.mandala.library.virginia.edu/sources-api/json/{nid}` the same way. But Sources is doubly special: its record structure/display needed heavy recent refactoring, **and** it's the **Spike 5 (bibcite) target** — so Sources' API has both a citation-data-model angle (Spike 5) and an API-compatibility angle (Spike 6). If Sources' Solr records carry a stale/odd `url_json`, that's a kmassets-index data issue, not an app bug.

---

**One gap:** I found the architecture that *explains* both incidents, but I didn't find explicit commits recording the specific WAF fix or the specific Sources failure in these two repos — they may have been infra-side (WAF config), in the D7 backend, or not yet committed. So I can lay out *why* they happen and what they mean for Spike 6, but I don't want to assert the exact incident details I couldn't see.

Two things would sharpen this before we start Spike 6 proper:
1. Can you tell me the specifics of the two incidents — what the WAF was blocking (which host/path, what response) and what exactly failed with Sources? I'll fold the accurate details in.
2. Want me to capture this as a **"Pre-spike findings"** section in `spike-06-api-compatibility.md` (the way Spike 2's findings are already recorded there), so Spike 6 starts from this map — including the JSONP→CORS/WAF angle and the `url_json`-comes-from-Solr insight as new fail-criteria?

---

## User — 2026-07-30 17:42

Ok now I switched it to the current release branch of the react app in mandala-om. Review the latest commits there to see issue that may affect this API spike.

---

## Claude — 2026-07-30 17:42

This is exactly it — the top commits on `release/v1.1.0-rc` are the WAF/Sources fixes. Let me read them in detail, starting with the proxy fix and the README.

---

## Claude — 2026-07-30 17:43

That's the smoking gun. Let me read the env-rename commit and the new architecture README, which likely documents the proxy fully.

---

## Claude — 2026-07-30 17:43

The proxy is a WordPress-served `/proxy/json?url=` on the THL/`thlib.org` deployment (the `tibet` project fork), separate from the geoserver var it was accidentally overloading. Let me read the new architecture README.

---

## Claude — 2026-07-30 17:43

Let me read the key README sections — the data layer, the proxy, and known issues.

---

## Claude — 2026-07-30 17:44

I read the three release-branch fix commits (yours, 2026-07-28→30) plus the new architecture README. This is much more concrete than the abstract A/B/C in the spike doc — the WAF problem is **already happening in production**, and there's a **working mitigation pattern** to build Spike 6 around. Here's what matters.

## The confirmed incident (commit `6a2ef22b`)

`sources.mandala.library.virginia.edu` returns **HTTP 503 to cross-origin browser JSONP requests** — an edge/WAF block that **curl and the WordPress server itself do *not* hit**. So Sources detail pages rendered the **title but a blank body** (the Solr record gave the title; the `url_json` body fetch got 503'd).

The tell is important: the block targets **browser cross-origin requests specifically** (not server-to-server). That's a WAF rule keyed on `Origin`/`Referer`/`Sec-Fetch-*`/bot heuristics — exactly the kind of rule the new D11 AWS WAF will also have, probably stricter.

## The fix — a same-origin server-side proxy (commits `6a2ef22b`, `27a21c63`)

In `useMandala.js`, Sources body fetches now route through a **WordPress proxy**: `{REACT_APP_WP_PROXY}/json/?url=<encoded target>` via a plain `axios.get` (not JSONP). The browser makes a **same-origin** request to the WP site (`thlib.org/proxy` in prod); the proxy does the cross-origin fetch server-side, sidestepping the browser-targeted WAF block. Verified: `#/sources/127668` → `/proxy/json/?url=…/sources-api/json/127668` → 200.

Two caveats baked into the commits:
- **Scoped to the Sources host only.** Images/AV/Texts/Visuals still use direct JSONP — one WAF-config change away from the same 503.
- The env var was initially overloaded onto `REACT_APP_GOSERVER_URL` (which is actually the **geoserver/map** base), then split into its own `REACT_APP_WP_PROXY` — plus there's a `REACT_APP_JSON_PROXY=/proxy/json?url=` relative same-origin rule and a local-dev `setupProxy.js` that proxies `/proxy/*` to DDEV WordPress. So the proxy is already an infrastructural pattern, currently on the THL/`tibet` deployment.

## What this changes for Spike 6

1. **JSONP-direct is failing *now*, in production — not a hypothetical D11 risk.** The spike's "React app cannot be changed / WAF" fail-criteria are live. The D11 consolidation + a new AWS WAF makes *every* app's JSONP fetch a candidate for the same 503, not just Sources.

2. **The same-origin proxy is the proven answer, and Spike 6 should evaluate generalizing it to all asset JSON.** Instead of the browser JSONP-ing five D7 subdomains, it makes one same-origin call to `/proxy/json?url=<D11 endpoint>` and the proxy fetches server-side. This sidesteps CORS + JSONP + WAF in one move, and it's already working for Sources. This is a more concrete direction than Option A/B/C — effectively "Option B, but at the API layer via a proxy" rather than ALB-aliasing whole subdomains.

3. **The `url_json`-from-Solr coupling compounds it.** The proxy just wraps whatever `url_json` the kmassets record carries. So D11 has two independent levers — *what URL the sync writes into `url_json`* and *whether the app proxies it* — and the cleanest cutover writes the D11 endpoint into `url_json` and lets the proxy handle reachability. Spike 6 is therefore entangled with the kmassets write path (1a.8 / reindeer_x), not just ALB config.

4. **A server-to-server fetch path must be explicitly allowed in the D11 WAF.** The whole fix works because server-side requests bypass the browser-targeted rule. D11's Terraform/ALB/WAF config has to (a) permit the proxy's server-side fetches and (b) decide whether to allow the app origin directly (true CORS) — that's a concrete addition to the spike's "feasible in D11 + Terraform ALB config" pass criterion.

5. **Sources is the canary; don't fix it five times.** It broke first and got a targeted patch. Spike 6 should decide whether to generalize the proxy before the D11 cutover so Images/AV/Texts/Visuals don't each hit the wall — and note that Sources is *also* the Spike 5 (bibcite) target, so it carries both threads.

6. **Context caveat:** the app is aging (React 16, react-scripts 3.4.3, Node 14 in CI, a Dependabot backlog). Any change to the data layer — proxy-everywhere or JSONP→CORS — lands in old code, which is a cost input for the Phase 3 cutover.

## Suggested next step

I'd capture all of this as a **"Pre-spike findings — client API architecture & the WAF/proxy problem"** section in `spike-06-api-compatibility.md` (mirroring how Spike 2's findings are already recorded there), with: the JSONP→503 mechanism, the `/proxy/json?url=` mitigation, the `url_json`-from-Solr coupling, and the "generalize the proxy vs. move to CORS" decision as an explicit new fail-criterion. That gives Spike 6 a running start instead of re-deriving it.

Want me to write that section (docs-only, on a branch → PR like the others)? And if you can tell me whether the proxy is meant to be the long-term D11 answer or a THL-only stopgap, I'll frame the recommendation accordingly.

---

## User — 2026-07-30 17:47

yes, write it up as a pre-spike finding, create a separate branch for the spike, commit it and push it, but let's wait on the PR until we do the spike.

---

## Claude — 2026-07-30 17:47

On it. Let me create the spike branch off `main` and write the pre-spike findings.

---

## Claude — 2026-07-30 17:48

Branch created. (Spike 4b's status change stays on its own PR branch — correct, this branch is spike-6 only.) Now let me update the spike-6 doc's header and add the pre-spike findings section.

---

## Claude — 2026-07-30 17:49

Good call — let me make that decision explicit and prominent rather than a sub-bullet.

---

## Claude — 2026-07-30 17:49

Now commit and push (no PR, per your call).

---

## Claude — 2026-07-30 17:50

Pushed to `spike/6-api-compatibility`, no PR (as you asked). Let me record the spike-6 prep and the WAF finding in memory so it's not stale next session.

---

## Claude — 2026-07-30 17:50

Done. The pre-spike finding is written, committed, and pushed — **no PR yet**, as you asked.

## What landed
- **Branch `spike/6-api-compatibility`** (off `main`, pushed to origin). This becomes the spike's working branch; the PR waits until you run the spike.
- **New "Pre-spike findings (2026-07-30)" section** in `docs/spikes/spike-06-api-compatibility.md`, plus a status-line note pointing to it. It captures:
  - The two-step fetch and the **`url_json`-is-data** insight (couples Spike 6 to the kmassets write path, not just ALB).
  - The JSONP-across-six-subdomains architecture and the production host map.
  - The **confirmed WAF 503 incident** on the Sources host and the same-origin `/proxy/json` mitigation (with the exact commits `6a2ef22b` / `27a21c63`).
  - Seven implications reframing the spike's Option A/B/C into concrete, live terms.
  - A dedicated **"Key decision this spike must make: is `/proxy/json` the final solution?"** subsection (per your mid-turn note) — weighing generalize-the-proxy vs. native CORS, same-origin serving, ALB aliases, or a dedicated proxy service, with a required deliverable of a recommended reachability architecture.
- **Memory updated** so next session starts with the WAF/proxy finding and the branch state.

When you're ready to actually run Spike 6, the obvious first moves are: (1) pin down whether `/proxy/json` is the intended long-term answer or a THL stopgap, (2) audit the eight per-site node-detail endpoints, and (3) get the reachability decision (proxy vs CORS vs ALB) in front of Dave for the AWS WAF/ALB side. Then we open the PR off this branch with the spike's conclusions.

---

## User — 2026-07-30 17:52

that's it for now. End the session.

---

## Claude — 2026-07-30 17:52

Let me verify nothing's left uncommitted before we close out.

---

## Claude — 2026-07-30 17:53

Everything's committed and pushed — clean tree, all four branches on origin. Session wrapped up.

## What we shipped this session

**Gate 2 of the user migration — decided, built, and verified:**
- **[PR #74](https://github.com/uvalib/mandala-navina/pull/74)** — ADR 015 (global non-admin `content_editor` = shanti_editor equivalent), `Proposed`, awaiting Yuji + Xiaoming.
- **[PR #75](https://github.com/uvalib/mandala-navina/pull/75)** — the implementation, **draft, verified end-to-end in DDEV** (role map, public/private edit, non-admin floor all pass), gated on #74.

**Spike 4b — decided:**
- **[PR #76](https://github.com/uvalib/mandala-navina/pull/76)** — Option 1+3 for Texts footnotes, awaiting sign-off.

**Spike 6 — started:**
- Branch **`spike/6-api-compatibility`** with the pre-spike findings (the live WAF/JSONP incident, the `/proxy/json` mitigation, and the "is proxy the final answer?" decision). **No PR yet**, per your call.

## Two things to remember for next time
- **DDEV is left running** with the 1,538 migrated users and the `mandala_shared` source DB loaded (local, disposable) — from the PR #75 verification. `externalauth` was enabled there.
- **Three PRs await Yuji/Xiaoming sign-off** (#74, #76, and #75 which unblocks once #74 is accepted).
- Memory (`project-mandala-state`, `project-spike-4b`, index) is refreshed with all of the above.

One optional loose end: I didn't run `scripts/save-session-log.py` — this was a substantial session, so if you want a committed session log for the team record, say the word next time and I'll generate one on its own branch. Good session — talk soon.

---

## User — 2026-07-30 17:56

yes committed session log always

---

## Claude — 2026-07-30 17:56

Understood — committed session log at session end, every time. Let me note that preference and run the script.

---
