# Session Log: Spike 3 — Group Module Collections Architecture

**Date:** 2026-06-12  
**Participants:** Than Grove, Claude Sonnet 4.6  
**Outcome:** Partial pass — see [docs/spikes/spike-03-group-collections.md](../spikes/spike-03-group-collections.md)

---

*This is the raw conversation transcript. Tool calls and raw code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-06-12 (session open)

Please tell me what we have planned for Spike 3

---

## Claude — 2026-06-12

Spike 3 is pending and assigned to you (Than Grove). Here's the summary:

**Goal:** Prove that the Drupal 11 Group module can replace Organic Groups from D7 to model Mandala's collection/subcollection hierarchy with one level of nesting and appropriate access control.

**Pass criteria:**
- Collection/subcollection nesting works with a hard one-level limit
- Content added to groups inherits group access rules
- Group roles cover current access patterns
- No critical open issues in the Group module queue affecting this model

**Fail criteria and planned responses:**

| Failure | Response |
|---|---|
| Nesting broken/incomplete | Evaluate workarounds or flat model with parent field |
| Can't enforce one-level constraint | Custom validation hook |
| Access control insufficient | Supplement with `group_permissions` module |
| Breaking API changes expected | Pin current version, plan upgrade sprint post-stable |

The spike hasn't been run yet — demo, findings, and deferred notes are all blank. Want to start running it?

---

## User — 2026-06-12

let's run spike 3

---

## Claude — 2026-06-12

Read session startup docs (ADRs, deferred notes, spikes plan) then checked DDEV status. DDEV was stopped. Started it — first run, pulling Docker images and installing 216 Composer packages. `drupal/group 3.3.5` was already in `composer.json`. Waiting for startup.

Also noted: `composer.json` has `drupal/core-recommended: ^10` — the repo is currently Drupal 10 (not D11 yet). Group 3.3.5 supports both D10 and D11; no issue for the spike.

---

## User — 2026-06-12

keep going, wait for ddev to finish

---

## Claude — 2026-06-12

DDEV finished — all 216 packages installed, `drupal/group 3.3.5` confirmed. Fresh Drupal site installed. Proceeding through the 10-step spike work plan.

**Step 1 — Enable Group module:**

```
ddev drush en group gnode -y
```

Enabled: `group`, `gnode`, `entity`, `flexible_permissions`.

**Step 2 — Create `collection` and `subcollection` group types:**

Used `\Drupal\group\Entity\GroupType::create()`. Both created successfully.

**Step 3 — Register content type as group content:**

Discovered the Group 3.x API: `GroupRelationshipTypeStorage::createFromPlugin()` (not `GroupType::installPlugin()` which doesn't exist in 3.x). Available plugins: `group_node:article`, `group_node:page`, `group_membership`. Registered `group_node:page` on both group types via `createFromPlugin()`.

**Step 4 — Create groups and test nesting:**

Created "Buddhist Texts Collection" (collection, id=1).

**Key finding:** Group 3.3.5 has **no built-in group-in-group plugin**. All three available plugins are `group_membership`, `group_node:article`, `group_node:page`. No `subgroup`, `ggroup`, or equivalent. This is the most significant architectural finding from the spike.

Two external modules exist — `ggroup` and `subgroup` — but they are mutually incompatible and were not evaluated.

**Implemented alternative:** Added a `parent_collection` entity reference field on the `subcollection` group type, with `target_bundles` restricted to `['collection']`. Created "Tibetan Sutras" subcollection (id=2) referencing the collection.

**Step 5 — Test one-level constraint:**

Attempted to create a subcollection (id=2) referencing another subcollection (id=2) as parent. Drupal entity validation returned:

```
parent_collection.0.target_id: This entity (group: 2) cannot be referenced.
```

**One-level constraint is enforced by the type system automatically** — no custom validation hook needed.

**Step 6 — Add content to groups:**

Created a Page node "The Diamond Sutra" and added it to both collection (id=1) and subcollection (id=2) via `Group::addRelationship($node, 'group_node:page')`. Works correctly.

**Step 7 — Configure group roles:**

Used `PermissionScopeInterface` constants (`OUTSIDER_ID`, `INSIDER_ID`, `INDIVIDUAL_ID`). Created four roles for the collection group type:

- `collection-anonymous` — scope=outsider, global_role=anonymous
- `collection-outsider` — scope=outsider, global_role=authenticated
- `collection-member` — scope=individual
- `collection-admin` — scope=individual

**Steps 8–9 — Access control testing:**

Initial tests (before granting `view group_node:page entity` permission): both anonymous and member got NO. Cause: member role had only `view group` permission, not the node-specific `view group_node:page entity`.

After granting `view group_node:page entity` to member role and `view group` + `view group_node:page entity` to anonymous role:

| Scenario | Anonymous | Member | Authenticated Non-member |
|---|---|---|---|
| Public collection | YES | YES | NO |
| Restricted collection | NO | YES | NO |

Important: Group module permission results are **heavily cached** — stale YES results appeared until a full `ddev drush cr` was run. Cache rebuild required between permission changes during testing.

**Key technical note:** Group 3.x uses `hook_entity_access` (not `hook_node_access_records`) for content access. The `node_access` table only has the generic fallback record; node access is handled at the entity access handler level by the group_node plugin.

**Step 10 — Group module issue queue review:**

Searched drupal.org. Findings:
- Group 3.3.5 is already stable — no pending "1.0" breaking changes
- `ggroup` and `subgroup` modules exist for nesting but are mutually incompatible
- Four known access control issues in the queue (not blockers for Mandala's patterns but require monitoring): `#3162511`, `#2955656`, `#3134058`, `#3263349`

---

## Claude — 2026-06-12

Writing up findings.

**Overall: PARTIAL PASS.** The core collection model works. Key open decision: entity reference approach (proven, simpler, no access inheritance) vs. `ggroup`/`subgroup` module (unproven, adds inheritance but adds a dependency). This decision gates Phase 3 design.

Two deferred notes created:
- `docs/deferred/group-subgroup-nesting-approach.md` — nesting approach decision
- `docs/deferred/group-access-inheritance-subcollections.md` — access inheritance from collection → subcollection

The most significant architectural implication: **access is NOT inherited from collection to subcollection** in the entity reference model. A restricted collection does not automatically restrict content in its subcollections.

---

## User — 2026-06-12

commit this

---

## Claude — 2026-06-12

Committed as `49314ef`:

```
Record Spike 3 results: Group module collections architecture (partial pass)
```

Four files: `docs/spikes/spike-03-group-collections.md`, `docs/deferred/README.md`, `docs/deferred/group-subgroup-nesting-approach.md`, `docs/deferred/group-access-inheritance-subcollections.md`.

---

## User — 2026-06-12

push it

---

## Claude — 2026-06-12

Push failed — SSH key not available in the shell environment. Suggested `! git push`.

---

## User — 2026-06-12

`! git push`

---

## Claude — 2026-06-12

Acknowledged — push should work with local SSH keys. Waiting for confirmation.

---

## User — 2026-06-12

create a pull request for mandala-navina

---

## Claude — 2026-06-12

`gh` not installed. Provided the `gh pr create` command for the user to run manually.

---

## User — 2026-06-12

gh is now installed. try again.

---

## Claude — 2026-06-12

`gh` installed but not authenticated. Directed to `! gh auth login`.

---

## User — 2026-06-12

`! gh auth login`

---

## Claude — 2026-06-12

Waiting for login to complete.

---

## User — 2026-06-12

instead of doing the PR do a push

---

## Claude — 2026-06-12

Tried `git push`. Remote is SSH (`git@github.com:uvalib/mandala-navina.git`) — SSH key not available. Switched remote to HTTPS, tried again — `gh auth setup-git` ran but GitHub returned 403: `ThanGrove` doesn't have push access to `uvalib/mandala-navina`. Restored remote to SSH. Explained the issue: need to be added as a collaborator in the `uvalib` org, or load SSH key into `ssh-agent`.

---

## User — 2026-06-12

do a pull request first

---

## Claude — 2026-06-12

Since `main` is the default branch and the commit was on `main`, moved the commit to a feature branch `spike/03-group-collections`, reset local `main` back to `origin/main`. Attempted `gh pr create` — failed because the branch wasn't pushed yet.

---

## User — 2026-06-12

`! git push -u origin spike/03-group-collections`

---

## Claude — 2026-06-12

User pushing branch with their SSH credentials. Waiting for confirmation.

---

## User — 2026-06-12

has the mkdocs directory been updated in that branch

---

## Claude — 2026-06-12

No changes to `mkdocs/` in this branch — only `docs/spikes/` and `docs/deferred/` were touched. The mkdocs site pulls from `docs/` directly, so the spike doc content is there, but the session log hadn't been written yet.

---

## User — 2026-06-12

please record this spike in mkdocs including the session history

---

## Claude — 2026-06-12

Writing this session log at `docs/session-logs/2026-06-12-spike-3-group-collections.md`.

---

## User — 2026-06-12

One feature we had in D7 with Organic Groups was for subcollections to inherit the access permission of the parent collection so that if a parent collection was private the subcollection by default would be private, but a subcollection could also be set to public on its own. Could you investigate whether ggroups or subgroups provides that functionality?

---

## Claude — 2026-06-12

Investigated both modules against the specific requirement: private parent → private subcollection by default; subcollection can independently override to public.

**`ggroup`:** Handles membership/role inheritance (parent members can access child groups) but does NOT propagate group-level visibility (public/private) from parent to child. No stable release; D11 compatibility work still in progress.

**`subgroup`:** Same limitation — membership inheritance only, not visibility inheritance. D10-stable (2.0.1+), D11 unconfirmed.

Neither module implements the required pattern. Recommended path: entity reference approach (proven in Spike 3) + custom `hook_group_presave` / `hook_group_update` logic with a `visibility_overridden` flag on subcollection to track independent overrides. ~1–2 days of custom module work. Updated deferred notes.

---

## User — 2026-06-12

Further requirements are the subcollections inherit the users associated with the parent but can have specific users added as well.

---

## Claude — 2026-06-12

Confirmed full D7 inheritance model:

1. **Visibility inheritance** — private parent → private subcollection by default; subcollection can override independently
2. **Membership inheritance** — subcollection inherits all parent collection members; additional members can be added to subcollection independently

`ggroup` and `subgroup` both handle membership inheritance (partially addressing requirement 2) but neither handles visibility inheritance (requirement 1). `ggroup` also not D11-ready.

Recommended approach updated to **Option D**: entity reference + custom hooks covering both requirements:

- Membership: `hook_group_relationship_insert` on parent membership changes to sync members to subcollections; tracks members added directly to subcollection to prevent accidental removal
- Visibility: `hook_group_presave` / `hook_group_update` with `visibility_overridden` flag

Estimated ~2–3 days of custom module work. Key edge case: member removed from parent collection but added directly to subcollection — should remain on subcollection.

Updated both deferred notes and deferred `.pages` nav entry.
