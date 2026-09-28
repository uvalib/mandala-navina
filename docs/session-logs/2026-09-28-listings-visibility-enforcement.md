# Session Log: Listings Visibility Enforcement

**Date:** 2026-09-28  
**Participants:** Than Grove (driving), Yuji Shinozaki (joined mid-session), Xiaoming Wang, Claude Code  
**Outcome:** Closed the listings-visibility gap [raised 2026-09-25](../deferred/collection-visibility-not-enforced-in-listings.md) and left "awaiting a team decision" — both surfaces, in [PR #259](https://github.com/uvalib/mandala-navina/pull/259). Anonymous now sees 9,555 / 111,269 / 238 rows across `av_gallery` / `image_gallery` / `collections` instead of 11,582 / 111,339 / 387, each matching `hook_entity_access()` exactly. **Decided against node grants** in favour of query-time filtering keyed on the `node_access` / `group_access` tags: the existing rule is a veto that composes with Group's permission grid, grants would have fixed only the node half, and they would have added an unmeasured `node_access_rebuild()` over 122,923 nodes to cutover. The options in the note had framed this as grants vs. *per-view* alters and missed the tag-based shape — and it turned out **Group 3.3.5 already ships access-aware Views SQL rewriting that was running on all three views**, filtering nothing because it keys on Group permissions, which `collection-anonymous` grants freely. Brought the first test suite under `modules/custom` (10 tests, 324 assertions), each suite verified to actually fail by injecting a bug. Two spin-off PRs: [#260](https://github.com/uvalib/mandala-navina/pull/260) confirms the orphan review-group direction with newly-quantified AV evidence (18 restricted `video` nodes outside any collection), and [#261](https://github.com/uvalib/mandala-navina/pull/261) fixes a separate live Solr gap found while verifying — `CollectionFieldContributor` ignored the node-level override, mislabelling 376 documents, 35 of them confirmed served to anonymous search today.

---

*This is the raw conversation transcript. Tool calls and code output are omitted; only*
*the text exchanges are recorded.*

---

## User — 2026-09-28 17:07

pull latest code and refreshe memory and get ready for a session with me, Yuji, and Xiaoming.

---

## Claude — 2026-09-28 17:07

I'll run the session-start check, which covers the git sync and DB parity steps.

---

## Claude — 2026-09-28 17:08

Git is current and local config is clean. dev-0 was unreachable (VPN). Now orienting.

---

## Claude — 2026-09-28 17:08

Now the most recent session log and open PRs/branches.

---

## Claude — 2026-09-28 17:09

API Error: Connection lost mid-response. The response above may be incomplete.

---

## Claude — 2026-09-28 17:09

VPN's up — re-running the dev-0 checks that were skipped.

---

## Claude — 2026-09-28 17:10

All checks clean, full parity with dev-0. Now refreshing memory.

---

## Claude — 2026-09-28 17:10

Memory is current through 09-25; I'll add a short 09-28 entry and demote the old header.

---

## Claude — 2026-09-28 17:11

Ready. Here's where things stand.

## Session-start checks — all clean
- **Git:** `main`, up to date, clean tree. No open PRs, no unmerged branches.
- **Config:** `config:status` reports no differences; `drupal/config/sync` matches `origin/main`.
- **dev-0 parity (VPN on):** exact match on all seven counts — 1,385 authmap / 125,455 group_relationship / 408 groups / 4,187 audio / 111,340 shanti_image / 7,396 video / 1,543 users. 25 sampled legacy-identity pairs resolve to the same D11 nid, so no id drift on this machine.

## What landed since last session
Only the 09-25 session's own work: **PR #255** (AV7 private/UVA access enforcement — the fix for 2,027 anonymously-viewable AV nodes) and **PR #256** (session-start script surfacing SSH stderr), both merged. Nothing else moved.

## Where the project is
Sprint 3 (AV core) is the active track; Texts and Sources paused under ADR 018. ADR 016 (public URL structure) is still **Proposed** — the only non-accepted ADR. Spikes 5, 7, 8 are Partial; 11 pending.

## Agenda candidates for the three of you
These are the items explicitly blocked on a group conversation rather than on implementation — worth taking while all three drivers are present:

1. **Collection visibility not enforced in listings — High.** The gap found while verifying the AV7 fix: `hook_entity_access()` doesn't govern Views, so `av_gallery` returns all 11,582 and `image_gallery` all 111,339 published nodes to anonymous, with restricted titles rendered. Two independent surfaces — node grants would fix the galleries but *not* the `collections` view (125 private + 24 UVA collections listed). Pre-existing, not a regression. Decided 09-25: no stopgap. The open unknown is the unmeasured cost of a `node_access_rebuild()` over 122,923 nodes.
2. **Local dev files provisioning — Medium-High.** No mechanism populates `sites/default/files` on any DDEV; confirmed missing on two machines. Open: on-demand vs. automatic trigger, and full-parity vs. a narrower first pass.
3. **Config-export drift on hand-edited YAML — Medium.** How to validate hand-edited config before `main`, not just at deploy time.

Memory refreshed with a 09-28 entry and the index updated. Where do you want to start?

---

## User — 2026-09-28 17:11

WHat is the status of this sprint?

---

## Claude — 2026-09-28 17:12

## Sprint 3 — AV core implementation

**Status: ◐ In progress**, started 2026-09-08. Lead is Yuji per ADR 018, individual mode.

Two caveats on the docs before the substance:

- `docs/sprints/README.md` still lists Sprint 3 as **○ Planned** — stale since 09-08. The sprint file itself is the accurate one.
- **AV7's row still opens with ◐**, but its body records the work built and merged 09-25 (PR #255). Its named remaining scope is closed; what's left was reclassified as a separate deferred item, not AV7.

### Done (13 of 16)
AV2, AV3, AV4, AV5, AV6, AV8, AV9, AV13, AV14, AV15, AV16 — plus AV7 in substance. The full AV corpus migrates and is verified (dev-0 run 09-10, 24/24 checks, 5h05m), kmassets/Solr fully in sync across all 122,921 published nodes, both galleries render, players and the PBCore metadata accordion match D7's production shape.

### Partial
- **AV1** (Spike 7) — ◐ module survey and playback prototype done; the migration-source-plugin half is satisfied by AV4, so what's actually open is upload/ingest, which lives in AV10–AV12.
- **AV10** (Kaltura config layer) — ◐ scaffolded and verified 09-15: the `mandala_kaltura` module, a schema-validated settings object and a read-only resolver service. That's the whole surface AV9 and AV12 need.

### Paused — and this is the part that concerns you
- **AV11** (Kaltura Session minting service) — ⏸ deferred 09-15 by Yuji, explicitly *"paused until Than is back (Wednesday 2026-09-24)."* Not a technical block; AV10's config layer and the secret-delivery pattern are both already proven. A capacity call.
- **AV12** (browser-direct chunked upload widget) — ⏸ deferred alongside AV11, depends on it directly.

You're back, and that date has passed. **The AV11 → AV12 upload track is the sprint's remaining work and it's waiting on your availability, not on anything technical.** AV7's other half — the access realms — was the same kind of pause, and got resolved on 09-24/25; this is the last one outstanding.

### Acceptance criteria
None of the 14 boxes are checked, though several are clearly satisfiable now (migration count match, collection membership, KMaps indexing, the 68 `MISSING_TYPE` and 18 media-less dispositions, the transcript-as-inert-field criterion, gallery UI). Four genuinely can't close until AV11/AV12 ship: end-to-end upload, browser-direct/no-secret-in-browser, all-players-render, and the per-view-mode config carry.

One criterion reads oddly now: *"AV's two custom OG access realms are mapped and enforced"* — but `mb_collection_admin` was **dropped from scope** on 09-24 as dead code. That criterion needs rewording to match the decision, or it can never be honestly ticked.

Want me to reconcile the three stale spots (README row, AV7 marker, that acceptance criterion) and tick the criteria the evidence already supports?

---

## User — 2026-09-28 17:16

So pull the latest code again, Yuji said this, "I need to join later.  I’ve merged last Friday’s work this morning and run a reindex.  You can check the progress and look at what the next steps are.  I’ll try to join around 1:45.   I think if you ask Claude it will tell you about what’s next there are a few access control clean ups needed." Can you describe the control clean ups he is speaking of.

---

## Claude — 2026-09-28 17:17

Now let me look at the access-control items specifically, and check my local state against what Yuji did.

---

## Claude — 2026-09-28 17:18

Pulled — two new commits since we started, both Yuji's from this morning. Here's what he did and what he means.

## What Yuji did this morning
He reviewed and merged **PR #255** (your AV7 access fix) at 16:51Z, watched the webhook deploy through (`a321e6c5…`, succeeded 13:01:40), then ran that PR's two unchecked post-deploy steps against dev-0:

- **Three-bundle spot-check, both tiers** — private video 116898 and audio 111738 denied to anonymous; UVA `shanti_image` 29035 and video 116905 denied to anonymous but viewable to an authenticated non-member. All correct.
- **The reindex he mentions** — scoped, via `indexNode()` rather than `kmassets:index-all` (which would have rewritten all 111,340 image docs). Found **511** published nodes in UVA-only collections — 453 video, 53 `shanti_image`, 5 audio, so broader than AV alone — and re-indexed them **511/511, 0 errors**, flipping `visibility_i` from `private` to `3`/`uva` on the write master.

Recorded in PRs #257 and #258. `main` is at `f5f0101`, no open PRs. Nothing is still running on dev-0.

## The access-control cleanups

He named one explicitly in his own log; the rest are what the deferred notes have queued behind it. Flagging which is which, since he said "a few" without enumerating.

**1. Listings visibility — the one he certainly means.** High, and the direct residue of the PR he just merged. `hook_entity_access()` governs entity *pages* but not Views, so node and collection *listings* still expose restricted titles and metadata to anonymous. **Two independent surfaces:**
- Galleries filter on the node **grants** table, and nothing implements `hook_node_access_records()`/`hook_node_grants()` — `node_access` holds a single `realm = all` row. So `av_gallery` returns all 11,582 and `image_gallery` all 111,339 published nodes.
- The `collections` view separately lists **125 private + 24 UVA collections** to anonymous — and node grants would *not* fix that half.

The gating unknown is the unmeasured cost of a `node_access_rebuild()` over 122,923 nodes. Decided 09-25: no stopgap, because dev-0 is VPN-internal and no D11 production exists. **This is explicitly gated as "awaiting a team decision — do not start implementing."** Which is presumably why he wants to be here for it at 1:45.

**2. Default-loosens on value-less collections.** A D7 collection with no `group_access` value migrates to D11 as **public** — the field default. Harmless today (the only three cases are empty, unpublished D7 test collections), but it's the same shape as the subcollection defect PR #248 repaired, and at production cutover a value-less collection might actually hold content. Wants a deliberate choice — default private? — before the real migration.

**3. Staging, and any other environment migrated under the old inheritance hook.** The `group_presave` fix makes a *fresh* migration correct by construction, but environments already migrated under the old hook need the same dry-run → run → scoped re-index that dev-0 got on 09-24. Nobody has checked whether staging is affected.

**4. Three D7 test collections** (D11 groups 174, 176, 177 — "Twm4g Test Collection", "Chris's Test Collection", "Gingko Art"). Migrate or drop? Nobody has said. Low, not blocking, but it's an open access-adjacent question sitting in the notes.

**5. A standing post-migration D7-vs-D11 access comparison** as a cutover check, rather than the ad-hoc export used on 09-24 — which itself had a bug that produced a false discrepancy on nid 3.

### Two things that sound like cleanups but aren't open any more
- The **`field_group_access = 2` mis-enumeration** in `CollectionFieldContributor` (2 documented as "subscribable", mapped to private) — that was fixed *in* PR #255, and Yuji's re-index this morning is what completed it. Closed.
- The **search reader showing 0 of those 511 docs** — real, but it's the separately-tracked reader-lags-master gap, already assigned to him. His re-index neither caused it nor could fix it.

### Worth raising while all three of you are here
Two larger access items are queued that aren't AV7 residue but are the same area, and both are **High** and pre-cutover: the **contributor tier is unwired** (D7's authenticated users have CRUD-own across all asset types; D11's `authenticated` role grants none of it — affects all ~1,538 migrated users, and is what makes ADR 015's Q1(b) decision non-destructive), and **orphaned collection-less content** needs a temporary review group or it drops silently on cutover.

Want me to pull the listings note's written-up options into a decision brief for 1:45, so the three of you can settle it rather than re-derive it?

---

## User — 2026-09-28 17:23

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-09-28 17:23

<command-name>/model</command-name>
            <command-message>model</command-message>
            <command-args></command-args>

---

## User — 2026-09-28 17:23

<local-command-stdout>Set model to `Sonnet 5` and saved as your default for new sessions</local-command-stdout>

---

## User — 2026-09-28 17:23

<local-command-caveat>Caveat: The messages below were generated by the user while running local commands. DO NOT respond to these messages or otherwise consider them in your response unless the user explicitly asks you to.</local-command-caveat>

---

## User — 2026-09-28 17:23

<command-name>/model</command-name>
            <command-message>model</command-message>
            <command-args></command-args>

---

## User — 2026-09-28 17:23

<local-command-stdout>Set model to `Opus 5` and saved as your default for new sessions</local-command-stdout>

---

## User — 2026-09-28 17:23

Pull the listing notes and let's come up with a plan on how we will implement the Listings visibility

---

## Claude — 2026-09-28 17:24

I'll read the listings note in full, then explore the code that would need to change.

---

## Claude — 2026-09-28 17:24

The note is well-developed. Let me explore the actual code that would implement this.

---

## Claude — 2026-09-28 17:24

While those run, let me read the governing ADR.

---

## Claude — 2026-09-28 17:25

Here's `docs/deferred/collection-visibility-not-enforced-in-listings.md` in full.

---

# Collection visibility is not enforced in listings (nodes or collections)

**Area:** access / Group / Views / node grants / ADR 011 / ADR 013 / ADR 015
**Raised during:** Sprint 3 AV7 (session 2026-09-25, verifying the AV access fix in PR #255)
**Priority:** **High — real anonymous exposure of restricted titles and metadata in Drupal listings. AWAITING A TEAM DECISION (2026-09-25): do not start implementing; the approach for both halves below is an open group question.**

## The gap

Mandala enforces collection visibility through `hook_entity_access()` (`mandala_group_inheritance_entity_access()`). That hook governs **individual entity access checks** — a node or collection page, `$entity->access()`, `EntityQuery` with `accessCheck(TRUE)`. It does **not** govern Views listings.

There are two independent listing surfaces, and neither is covered:

| Surface | Filtered by | Leaking to anonymous |
|---|---|---|
| Node listings (`/av`, `/images`) | the node **grants** table | 2,027 AV nodes + 70 private images |
| Collection listing (`collections` view) | nothing — groups are not nodes | 125 private + 24 UVA collections |

**Node grants would fix the first and do nothing for the second.** Worth knowing before this is scoped as one job.

### Measured, as anonymous, after PR #255

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

With SQL rewriting enabled (`disable_sql_rewrite: false` in both gallery views, which is correct), the node-access query alter therefore filters nothing.

Collection listing — `views.view.collections`, `page_1`:

```
387 rows to anonymous | by field_group_access: {"0":238, "1":125, "2":24}
(0=public, 1=private, 2=UVA)
```

## What is and is not exposed

- **Exposed:** titles and whatever else the teaser/card/row renders, for restricted content and restricted collections, to anonymous users.
- **Not exposed:** the entity pages themselves — correctly denied as of PR #255, so links 403.
- **Not exposed:** search. The Solr path is independent and fails closed — the proxy applies `visibility_i:1` to anyone without a token.
- **Reachability:** dev-0 is VPN-internal and there is no D11 production yet, so this is not a live public exposure today. **It is a cutover blocker.**

## Why it was not caught earlier

The Images implementation has always had both gaps — the 70 private images have always been listed in `/images`. Earlier verification checked entity-page access (`public 200 / private 403`) and the Solr `fq` path, both correct, and never asserted on what a listing returns for anonymous. Sprint 3's AV work inherited the same shape.

## Half 1: node listings — options, none chosen

Nothing in the codebase implements node grants today, and Group 3.x does not either (hence the single `all` row). This would be the codebase's first grants implementation — an architectural step, not a patch.

1. **Node grants, dependency-encoded realms.** Write records that encode what the decision *depends on* rather than its resolved answer: node value 1 → `mandala_public`/gid 0; value 2 → `mandala_member`/gid = collection id; value 3 → `mandala_uva`/gid 0; value 0 or absent → `mandala_group_default`/gid = collection id. `hook_node_grants()` then returns `mandala_public => [0]` always, `mandala_uva => [0]` when authenticated, `mandala_member =>` the user's membership gids, and `mandala_group_default =>` the gids whose effective access the user satisfies (244 public + 30 UVA if authenticated + memberships — a few hundred ids, a fine `IN()` list). **Changing a collection's visibility then needs zero node writes**, only grant-cache invalidation.
2. **Node grants, resolved at save.** Simpler realms storing the resolved visibility, at the cost of re-saving every member node when a collection's `field_group_access` changes — up to 26,130 images for group 31, so realistically queued. This is D7's own staleness trap.
3. **Per-view query alters.** Cheap for the two known galleries, but fails open on every future view, block, REST resource or access-checked query nobody audits.
4. **Denormalised `effective_visibility` field + views filter.** Considered and looks worse than 1: same staleness problem as 2, opt-in per view like 3, and no protection for EntityQuery or REST.

**Unmeasured and gating:** a one-time `node_access_rebuild()` across **122,923 nodes**. Nobody has timed this on dev-0, and it becomes a mandatory cutover step. Every node in the corpus is asset content (111,340 `shanti_image` + 7,396 `video` + 4,187 `audio`), so there is no article/page case to special-case.

**Risk to respect:** grants fail closed hard. If the hook returns no records for a node it becomes invisible to *everyone* — exactly PR #201's failure mode, where every grouped AV node was invisible including to a site administrator. Verification must assert through a view, not `$node->access()`; the 21-combination matrix from PR #255 should be re-run against `av_gallery`/`image_gallery` output.

## Half 2: the collections listing — options, none chosen

1. **Check whether the Group module already ships an access-aware Views filter** and use it, before writing anything custom.
2. **Filter `views.view.collections` on group entity access**, via a query alter or an existing filter handler. Small and self-contained; closes the collection-name leak independently of the node-grants question.
3. **Bundle it with the node work** so the whole access story lands coherently, accepting that this leak stays open longer.

## Decisions taken so far

- **2026-09-25 — no stopgap.** Explicitly decided not to add interim gating while the real fix is designed. Rationale: dev-0 is VPN-internal, there is no D11 production, nothing is publicly exposed — worth doing once, properly.
- **2026-09-25 — approach deferred to the group.** Both halves raised with Than, Yuji and Xiaoming present and deliberately left open.

## Open questions for the group

- Node grants or per-view alters — and if grants, dependency-encoded or resolved-at-save?
- What does `node_access_rebuild()` actually cost on the real 122,923-node corpus, and who measures it?
- Does the cutover plan need an explicit `node_access` rebuild step?
- Is the collections-view leak fixed now as its own small change, or held with the node work?

---

Two Explore agents are still running over the access-resolution code and the Views/Group integration — I'll fold their findings in before drafting the plan. The four questions at the bottom are the ones you three need to answer; I'll bring you a recommendation on each rather than re-asking them cold.

---

## User — 2026-09-28 17:26

<task-notification>
<task-id>a73596b32ed3d82a4</task-id>
<tool-use-id>toolu_01WEwSnAWy8iThpePnnZpEWs</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/40801de8-57d1-4b15-9fb6-54ddeb9f66e5/tasks/a73596b32ed3d82a4.output</output-file>
<status>completed</status>
<summary>Agent "Explore access resolution code" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>Findings below. Nothing was modified.

## 1. `mandala_group_inheritance_entity_access()` — exact logic

File: `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_group_inheritance/mandala_group_inheritance.module`

**Entry point (lines 62–86)** — only `view` is handled; groups and nodes branch separately:

```php
function mandala_group_inheritance_entity_access(EntityInterface $entity, $operation, AccountInterface $account) {
  if ($operation !== 'view') {
    return AccessResult::neutral();
  }
  if ($entity-&gt;getEntityTypeId() === 'group' &amp;&amp; in_array($entity-&gt;bundle(), ['collection', 'subcollection'])) {
    return _mandala_group_inheritance_group_access($entity, $account);
  }
  if ($entity-&gt;getEntityTypeId() === 'node'
    &amp;&amp; in_array($entity-&gt;bundle(), _mandala_group_inheritance_group_node_bundles(), TRUE)) {
    return _mandala_group_inheritance_node_access($entity, $account);
  }
  return AccessResult::neutral();
}
```

Note the hook only ever returns `forbidden` or `neutral` — never `allowed`. It is a *veto layer* on top of Group's own permissions. A node-grants implementation must reproduce that: grants are positive, so the realm design must make the grant set equal "everything Group would allow, minus what this hook forbids".

**(a) Bundle discovery from config (PR #255) — lines 95–111:**

```php
function _mandala_group_inheritance_group_node_bundles(): array {
  $bundles = &amp;drupal_static(__FUNCTION__);
  if ($bundles === NULL) {
    $bundles = [];
    $storage = \Drupal::entityTypeManager()-&gt;getStorage('group_relationship_type');
    foreach ($storage-&gt;loadMultiple() as $relationship_type) {
      $plugin_id = $relationship_type-&gt;getPluginId();
      if (str_starts_with($plugin_id, 'group_node:')) {
        $bundles[] = substr($plugin_id, strlen('group_node:'));
      }
    }
    $bundles = array_values(array_unique($bundles));
  }
  return $bundles;
}
```

Currently installed (`/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/config/sync/group.relationship_type.*.yml`, key `content_plugin`): `shanti_image`, `audio`, `video` — on both `collection` and `subcollection`. So the effective bundle set today is `[shanti_image, audio, video]`, but it grows automatically as plugins are installed. `hook_node_access_records` is invoked for every node of every type, so it must apply the same discovery (and return nothing for out-of-scope bundles, leaving them to core's default "no records = visible if published").

**(b) Owning group lookup — lines 266–278:**

```php
function _mandala_group_inheritance_owning_group(EntityInterface $node): ?GroupInterface {
  $rel_storage = \Drupal::entityTypeManager()-&gt;getStorage('group_relationship');
  foreach ($rel_storage-&gt;loadByEntity($node) as $relationship) {
    $group = $relationship-&gt;getGroup();
    if (in_array($group-&gt;bundle(), ['collection', 'subcollection'], TRUE)) {
      return $group;
    }
  }
  return NULL;
}
```

First matching relationship wins; bundles limited to `collection`/`subcollection`. If NULL → hook returns neutral (no enforcement at all).

**(c)+(d) Field read and resolution order — lines 232–254 (`_mandala_group_inheritance_effective_visibility()`):**

```php
  if ($node-&gt;hasField('field_group_content_access') &amp;&amp; !$node-&gt;get('field_group_content_access')-&gt;isEmpty()) {
    switch ((int) $node-&gt;get('field_group_content_access')-&gt;value) {
      case 1: return 'public';
      case 2: return 'private';
      case 3: return 'uva';
    }
    // Value 0 is "use group defaults" — fall through to the collection.
  }

  return match ((int) $group-&gt;get('field_group_access')-&gt;value) {
    1 =&gt; 'private',
    2 =&gt; 'uva',
    default =&gt; 'public',
  };
```

Exact order: node field present AND non-empty AND value in {1,2,3} → node wins. Otherwise (field absent, empty, value 0, or any unrecognised value) → group's `field_group_access`, where anything other than 1/2 (including NULL/empty) is public. Allowed values confirmed in `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/config/sync/field.storage.node.field_group_content_access.yml` (0 use group defaults, 1 public, 2 private, 3 UVA only); `field_group_access` is a plain unsigned integer on group (`field.storage.group.field_group_access.yml`), no allowed-values list.

**(e)+(f) Enforcement — lines 183–227 (`_mandala_group_inheritance_node_access()`):**

Order of checks:
1. Bypass first (lines 186–188): `bypass group access` OR `bypass node access` OR `bypass mandala group access` → `neutral()-&gt;cachePerPermissions()`.
2. No owning group → neutral.
3. `private` → `if (!$group-&gt;getMember($account))` → forbidden, `cachePerUser()`. Membership is Group membership on the *owning* group only (cascaded subcollection memberships are real relationships by then, per the cascade hooks, so no parent walk is done here).
4. `uva` → `if (!$account-&gt;isAuthenticated())` → forbidden, cache context `user.roles:authenticated`. **UVA = any authenticated user**, no NetBadge/role check.
5. `public` → neutral.

Group-entity side (lines 121–155) mirrors this: value 0 or unrecognised → neutral; bypass check; `2` → authenticated-only; `1` → members only.

Relevant for grants: since the hook returns *neutral* for allowed cases and relies on Group's own per-plugin `view group_node:&lt;bundle&gt; entity` permissions to actually grant, node grants must not accidentally become more permissive than Group's permission grid (see the audit command in §4 — those grants are config, per (group type, plugin, role)).

## 2. Other hooks in the module

Same file:

- `mandala_group_inheritance_entity_base_field_info()` (line 39) — adds boolean `mandala_inherited` base field on `group_relationship` to distinguish cascaded from direct memberships.
- `mandala_group_inheritance_entity_access()` (line 62) — the access veto described above.
- `mandala_group_inheritance_group_presave()` (line 298) — on new `subcollection` with `field_parent_collection`: copies parent's `field_group_access`; if `isSyncing()` (migration) keeps its own value and sets `field_visibility_overridden` when it differs from the parent.
- `mandala_group_inheritance_group_update()` (line 331) — on `collection` whose `field_group_access` changed, propagates the new value to non-overridden subcollections (`_mandala_group_inheritance_propagate_visibility()`, line 348).
- `mandala_group_inheritance_group_relationship_insert()` (line 376) — on `group_membership` insert into a `collection`, cascade-adds the user to every subcollection with `mandala_inherited =&gt; TRUE`.
- `mandala_group_inheritance_group_relationship_delete()` (line 399) — on `group_membership` delete from a `collection`, removes the user from subcollections *except* where `mandala_inherited` is falsy (direct/sub-only members retained).
- `mandala_group_inheritance_group_insert()` (line 479) — on new `subcollection` with a parent, backfills all existing parent members as inherited memberships.
- `mandala_group_inheritance_update_9001()` in `.install` — installs the `mandala_inherited` field storage.

Note for grants: `group_update` and `group_presave` mutate visibility for whole collections/subcollections, and the membership hooks mutate membership. With node grants, each of these becomes a point where grant records for potentially thousands of nodes go stale — today they are safe because access resolves live.

## 3. Node grants / query alter — none present

`grep` for `node_access_records`, `node_grants`, `hook_query_alter`, `views_query_alter`, `_query_alter` across `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/` returns **zero hits**. Contrib `group`/`gnode` also implements none (only two cache-context references to `user.node_grants:view` in `modules/contrib/group/modules/gnode/config/optional/views.view.group_nodes.yml`). So the node access table is currently empty of custom realms, and listings/views are unfiltered by grants — confirming your expectation.

Also, the module has **no tests directory** (`src/Drush/Commands/` is the only subtree).

## 4. Drush commands

`/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_group_inheritance/src/Drush/Commands/GroupContentPermissionAuditCommands.php`

- `mandala:group-permission-audit` (line 63, method `audit()`, `--fix` option). For each group type it reads `group.relationship_type.*` config to find installed `group_node:*` plugins, then for each `group.role.*` of that group type derives a canonical permission "shape" by templating bundle names out of existing permission strings (`PERMISSION_PATTERN`, line 46: `/^(.*\bgroup_node:)([a-zA-Z0-9_]+)(\b.*)$/`), and reports/repairs any bundle missing part of that shape. It exists because Group's per-plugin view permissions do **not** defer to `bypass node access` / `bypass group access`.

Other custom Drush commands elsewhere (not this module): `mandala:home-carousel-seed`, `mandala:missing-file-audit`.

## 5. Solr visibility mapping

**Writer** — `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_kmassets_sync/src/Contributor/CollectionFieldContributor.php` lines 54–58:

```php
  protected const ACCESS_TO_VISIBILITY = [
    0 =&gt; [1, 'public'],
    1 =&gt; [2, 'private'],
    2 =&gt; [3, 'uva'],
  ];
```

applied at lines 86–92 using **only the group's `field_group_access`**, with fallback `[1,'public']`, plus `collection_uid_s = {service}-11-{gid}` and `collection_title`. Base default `visibility_i =&gt; 1` is set in `KmassetDocBuilder.php:129` for nodes with no owning group.

**Important divergence to flag for the grants work:** the Solr writer ignores the node-level `field_group_content_access` entirely — it maps the *collection* value only. `_mandala_group_inheritance_effective_visibility()` lets the node override. So a public node inside a private collection (or vice versa) is already indexed differently from how Drupal resolves it. If node grants are meant to be "consistent with this existing mapping", one of the two has to move; the module's own comments (lines 157–182) treat the node override as authoritative.

**Reader** — `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_solr_visibility/src/VisibilityTokenBuilder.php`:

- `BYPASS_PERMISSIONS` (lines 65–69) is the exact same triple as the node-access hook, documented as required to stay in lockstep.
- `build()` (lines 95–121): anonymous → NULL (proxy applies public-only `visibility_i:1`); bypass → `(*:*)`; otherwise OR of `(visibility_i:(1%203))` — i.e. **public + uva for any authenticated user, the direct analogue of "UVA = authenticated"** — plus `asset_type:(places subjects terms)`, own-node `node_user_i`, `members_uid_ss:user-{uid}`, and `collection_uid_s:(...)` for private collections the user belongs to.
- `restrictedCollectionUids()` (line 154) only includes groups where `field_group_access === 1` (private); UVA groups are covered by the base clause.
- `groupKmassetUid()` (line 183) duplicates the writer's `{field_legacy_site}-11-{group id}`, fallback `images`.

Natural realm mapping if you mirror this: realm `mandala_visibility` gid 1 = public (granted to everyone), gid 3 = uva (granted to authenticated), and realm `mandala_collection` gid = group id for private (granted per membership), plus an all-access realm for the three bypass permissions — which `hook_node_grants` can express since it receives the account.</result>
<usage><subagent_tokens>51512</subagent_tokens><tool_uses>20</tool_uses><duration_ms>123271</duration_ms></usage>
</task-notification>

---

## Claude — 2026-09-28 17:26

The first agent returned a significant finding. Let me note it while the second runs.

---

## Claude — 2026-09-28 17:27

Both agents are back, and the second one found something that changes the options materially. Let me verify a few things directly.

---

## Claude — 2026-09-28 17:28

Every node is in exactly one group, and the image numbers reproduce the note's figures exactly. Let me verify the key technical assumption.

---

## Claude — 2026-09-28 17:28

The technical picture is now complete. Let me pressure-test the design before I write it up.

---

## Claude — 2026-09-28 17:29

Both agents are in and I've verified the key facts directly against the local DB. One finding materially reframes the decision the note asks you to make — worth putting in front of you before the plan lands.

## The note's option list is missing the option that probably wins

The note frames Half 1 as **node grants** (options 1–2) versus **per-view query alters** (option 3), and correctly dismisses option 3 because it "fails open on every future view, block, REST resource or access-checked query nobody audits."

But there's a third shape it doesn't consider, and it's the one Drupal actually gives you: **a tag-based query alter**, which is centralized rather than per-view.

- `core/modules/node/src/NodeViewsData.php:77` sets `'access query tag' => 'node_access'` on the node base table, so *every* SQL-rewriting node query — Views, EntityQuery with `accessCheck(TRUE)`, REST — carries that tag.
- `core/modules/views/src/EntityViewsData.php:185` gives group queries the tag `group_access` by the same mechanism, which covers Half 2.

So a `hook_query_node_access_alter()` in `mandala_group_inheritance` reaches everything option 3 would miss, without the grants table.

## And Group is already doing exactly this — it just can't see Mandala's fields

This is the part that surprised me. Group 3.3.5 **already ships access-aware Views SQL rewriting** (`group_views_query_alter()` → `EntityQueryAlter`/`GroupQueryAlter`), gnode opts into it with `entity_access: TRUE`, and all three views have `disable_sql_rewrite: false`. It is running on `/av`, `/images` and `/collections` right now.

It filters nothing because it filters by **Group permissions**, and both `group.role.collection-anonymous` and `collection-outsider` grant `view group` plus `view group_node:{audio,video,shanti_image} entity`. Mandala's visibility lives in `field_group_access` / `field_group_content_access`, which Group's permission system knows nothing about.

So the listing layer isn't missing enforcement infrastructure — the enforcement that exists is keyed on the wrong thing.

## Why this argues against grants

The existing hook is a **veto**: `mandala_group_inheritance_entity_access()` only ever returns `forbidden()` or `neutral()`, never `allowed()`. It sits on top of Group's permission grid and subtracts from it.

Node grants are **positive** — you must enumerate what everyone *can* see. To be correct, the grant set would have to equal "everything Group's permission grid allows, minus what the veto forbids," which means reimplementing Group's permission logic in a second place. Get it wrong and nodes become invisible to everyone including admins — precisely PR #201's failure mode. Group's maintainers chose query access over grants for this reason, and the codebase has now had four separate incidents of duplicated access logic drifting.

A query alter keeps the veto shape, and can share one resolution helper with the existing hook.

## Measured, on the real local corpus

The candidate join runs in about a second against all 111k images:

| View | Total published | Anonymous should see | Hidden |
|---|---|---|---|
| `image_gallery` | 111,339 | 111,269 | **70** — exactly the note's private-image figure |
| `av_gallery` | 11,582 | 9,537 | 2,045 |

Two things fell out worth knowing: **every node belongs to exactly one group** (122,821 with one, zero with two or more), so the join can't multiply rows and needs no `DISTINCT`. And the AV figure is 2,045 against the note's 2,027 — an 18-node gap that I'd want reconciled during implementation rather than hand-waved; my read is that "restricted" and "was viewable by anonymous" are slightly different sets, but that's a guess, not a finding.

## A separate defect this turned up

`CollectionFieldContributor::ACCESS_TO_VISIBILITY` maps **only the collection's** `field_group_access` into Solr, ignoring the node-level `field_group_content_access` override that Drupal treats as authoritative. **2,525 nodes carry a non-zero override** — 1,977 public, 443 private, 105 UVA. For the 443 marked private inside a public collection, Solr would index them as public while Drupal 403s the page. That's search-side, independent of listings, and I'd treat it as its own note rather than growing this job.

The Plan agent is pressure-testing the query-alter design now — ordering, cache contexts, the bypass permissions, and how to stop the two copies of the logic drifting. Once it's back I'll bring you the four decisions the note asks for, with a recommendation on each, and write it up as a plan.

---

## User — 2026-09-28 17:35

<task-notification>
<task-id>a62e542964444da37</task-id>
<tool-use-id>toolu_01PZ97XqXd8K9MPx1hmgBKMq</tool-use-id>
<output-file>/private/tmp/claude-503/-Users-ndg8f-Sandbox-Mandala-mandala-navina/40801de8-57d1-4b15-9fb6-54ddeb9f66e5/tasks/a62e542964444da37.output</output-file>
<status>completed</status>
<summary>Agent "Design listings visibility enforcement" finished</summary>
<note>A task-notification fires each time this agent stops with no live background children of its own. The user can send it another message and resume it, so the same task-id may notify more than once.</note>
<result>## Recommendation: **B — tag-based query alter.** Not close.

### Why B, plainly

1. **A only fixes half the problem.** Node grants do nothing for `views.view.collections` (base table `groups_field_data`, confirmed in `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/config/sync/views.view.collections.yml`). You would write the grants system *and then still* write a `hook_query_group_access_alter()`. B is one mechanism for both halves.
2. **A inverts the semantics of the thing you already shipped.** `mandala_group_inheritance_entity_access()` is a veto that composes with Group's permission grid. Grants are positive and *replace* it — the grant set must reproduce "whatever `group.role.collection-anonymous` / `collection-outsider` allow, minus the veto". That is a second, divergent copy of Group's permission model inside your module, which is precisely the failure class this codebase has hit four times (bundle hardcodes in PR #201/#199, `SiblingCarouselService`, PR #255). The consequence of getting it wrong is PR #201's regression: nodes invisible to everyone including uid 1.
3. **A has a staleness surface that exactly matches your existing hook set.** `mandala_group_inheritance_group_update()` propagates visibility to subcollections, `_mandala_group_inheritance_cascade_member_add/remove()` mutate memberships, `hook_group_insert()` backfills. Every one of those would need a grants-invalidation companion. Dependency-encoded realms reduce but do not eliminate this (membership changes still move a user between `mandala_member` gids — that's `hook_node_grants()` side, fine — but a node moving between collections, or a bundle gaining a `group_node` plugin, still needs record rewrites).
4. **A adds a mandatory, unmeasured cutover step** (`node_access_rebuild()` over 122,923 nodes). B adds nothing to the cutover.
5. **B covers surfaces A doesn't**, because `Drupal\Core\Entity\Query\Sql\Query::prepare()` (line 162) adds `{entity_type}_access` whenever `accessCheck(TRUE)`. One implementation covers Views *and* EntityQuery *and* anything REST/JSON:API builds on them. The deferred note's option 3 ("per-view query alters") is not this — per-view alters fail open on every new view; the *tag* alter is what makes B general.

### How B can fail — including things not on your list

**Fails open (the structural risk):**

- **Untagged/opted-out queries.** `disable_sql_rewrite: true` on any view silently disables it. All 24 views in `config/sync` are `false` today — add a CI grep asserting that.
- **`accessCheck(FALSE)` callers already in the tree**, which B will *not* fix and A would not either:
  - `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/shanti_images_carousel/src/Service/SiblingCarouselService.php:189` — carousel siblings, cached by `node_list:*` tags. Under B this becomes a one-line fix (`accessCheck(TRUE)`), but note the cache entry would then need a per-user cid or you leak across users. Flagging as genuinely risky and worth its own follow-up, not a drive-by.
  - `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/shanti_collections_view/shanti_collections_view.module:215` — item count on the collection card. Counts restricted items. Cosmetic leak (a number), decide explicitly.
  - Search API indexing uses `accessCheck(FALSE)` (`modules/contrib/search_api/src/Plugin/search_api/datasource/ContentEntity.php:845,1177`) — **good**, indexing is unaffected by B. Verify this explicitly after the change; if indexing ran access-checked as an anon-ish CLI user, B would silently empty the index. It doesn't, but assert it.
- **Raw `Database::select()`** anywhere in future code. Unavoidable with B; document it in the module header.

**Correctness traps in the SQL itself:**

- **Row multiplication via langcode.** `group_relationship_field_data`, `node__field_group_content_access` and `group__field_group_access` all carry `langcode`/`delta`/`deleted`. Fact 6 says one relationship per node, but that's per *entity*, not per *row*. Every join must carry `deleted = 0`, `delta = 0`, and a langcode/`default_langcode = 1` guard. Verify with `SELECT entity_id, COUNT(*) ... GROUP BY entity_id HAVING COUNT(*)&gt;1` on each of the three tables before trusting "no DISTINCT needed". Alternatively sidestep entirely by using `EXISTS`/`NOT EXISTS` subqueries instead of LEFT JOINs — cardinality-proof by construction, at some cost. My preference: LEFT JOINs with the guards, plus the duplicate check as a one-time verification.
- **Base table resolution.** Copy core's approach in `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/core/modules/node/src/Hook/NodeDatabaseHooks.php:52-79`: read `$query-&gt;getMetaData('base_table')`, else scan `$query-&gt;getTables()` preferring `node`/`node_field_data`. Unlike core, **do not throw** when no node table is found — core throws because it is core; you should `return` and log, so a third-party query tagged `node_access` against `taxonomy_index` without metadata doesn't WSOD the site.
- **Tag co-tenancy.** `node_query_node_access_alter()` is the only other `node_access` implementation and it currently short-circuits at `hasImplementations('node_grants')` (line 47) — a no-op today. Ordering does not matter: both implementations add their own condition group via `$query-&gt;condition()`, which ANDs at the top level. This stays true even if someone later adds grants. Group's alters run off a *different* tag (`entity_query` / `views_entity_query`, see `group.module:297-335`), so no interaction at all.
- **`op` metadata.** Only alter when `$query-&gt;getMetaData('op') ?: 'view'` is `view`, mirroring the hook's `if ($operation !== 'view')`.
- **Count query / pager.** Handled for free: `core/modules/views/src/Plugin/views/query/Sql.php:1516-1519` adds the access tag to *both* `$query` and `$count_query`. So the pager total is consistent. Assert it anyway (`$view-&gt;total_rows`).

**Caching:**

- Grants give you `user.node_grants:view` automatically; B does not, and **`user.group_permissions` is the wrong context** — it varies by calculated permissions, not membership identity, so two users with identical permissions but different collection memberships would collide and serve each other's listings. That is the one place B can produce a real cross-user leak.
- Correct set to bubble: `user.permissions` (bypass perms), `user.roles:authenticated` (UVA tier), and a membership-identity context. Use `user` as the safe default, or — better, and with direct prior art in Group's `GroupPermissionsHashGenerator` — define `Drupal\mandala_group_inheritance\Cache\Context\CollectionMembershipsCacheContext` (`user.mandala_collection_memberships`) hashing the sorted list of the account's collection/subcollection gids. That collapses anonymous + all non-members (the overwhelming majority) into one bucket while staying correct.
- Bubble via the renderer exactly as Group does — `QueryAlterBase::applyCacheability()` in `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/contrib/group/src/QueryAccess/QueryAlterBase.php` (guarded by `$renderer-&gt;hasRenderContext()`, so drush/cron is safe). Also add the `group_relationship_list` / `group_list` / `node_list` cache tags so a visibility edit invalidates rendered listings — the current entity_access hook adds `addCacheableDependency($group)` per entity, which a *listing* cannot do for rows it filtered out. **This is the subtle one:** if collection 31 flips public→private, the cached anonymous `/images` page must invalidate even though the now-hidden nodes were never in it. Bubbling `group_list` (or `config:views.view.image_gallery` + `group_list`) covers that.

---

## Implementation shape

### New files (all in `drupal/web/modules/custom/mandala_group_inheritance/`)

| File | Role |
|---|---|
| `src/Access/CollectionVisibility.php` | **The single source of truth.** Constants, PHP resolver, SQL expression, account-allows predicate, bundle discovery, bypass check. |
| `src/QueryAccess/NodeQueryAlter.php` | Applies the veto to `node_access`-tagged selects. |
| `src/QueryAccess/GroupQueryAlter.php` | Applies it to `group_access`-tagged selects. |
| `src/Cache/Context/CollectionMembershipsCacheContext.php` | `user.mandala_collection_memberships`. |
| `mandala_group_inheritance.services.yml` | Register the above (cache context tagged `cache.context`). |
| `tests/src/Kernel/…`, `tests/src/Functional/…` | See verification. |

`mandala_group_inheritance.module` gains two thin hooks (`hook_query_node_access_alter`, `hook_query_group_access_alter`) that instantiate the alter class via `class_resolver`, mirroring `group_query_entity_query_alter()`.

### The shared resolver — the anti-drift core

`CollectionVisibility` exposes, and nothing else may reimplement:

- `PUBLIC = 'public' | PRIVATE = 'private' | UVA = 'uva'`
- `forNode(NodeInterface $node, GroupInterface $group): string` — the body currently in `_mandala_group_inheritance_effective_visibility()` (lines 232-254), moved verbatim.
- `forGroup(GroupInterface $group): string` — the `match` currently inlined in `_mandala_group_inheritance_group_access()`.
- `allows(string $visibility, AccountInterface $account, ?int $gid): bool` — private ⇒ member of `$gid`; uva ⇒ `isAuthenticated()`; public ⇒ TRUE.
- `hasBypass(AccountInterface $account): bool` — the three-permission check, in **one** place (today it is duplicated between `_mandala_group_inheritance_group_access()` and `_mandala_group_inheritance_node_access()`, and they differ: the group one omits `bypass node access`. Decide which is right; I'd unify on all three).
- `groupNodeBundles(): array` — the existing `_mandala_group_inheritance_group_node_bundles()`.
- `nodeVisibilityExpression(string $nca_alias, string $gfa_alias): string` and `groupVisibilityExpression(string $gfa_alias): string` — returning the CASE SQL.

The three existing procedural helpers become one-line wrappers (keep them; external callers and the doc comments reference them), or are deleted and call sites updated. Either way there is exactly one implementation of the rule per language.

### SQL condition structure (node half)

Sketch, after `ensureBaseTable()`:

```
LEFT JOIN group_relationship_field_data grel
  ON grel.entity_id = base.nid AND grel.plugin_id IN (:group_node_plugin_ids)
     AND grel.default_langcode = 1
LEFT JOIN group__field_group_access gfa
  ON gfa.entity_id = grel.gid AND gfa.deleted = 0 AND gfa.delta = 0
LEFT JOIN node__field_group_content_access nca
  ON nca.entity_id = base.nid AND nca.deleted = 0 AND nca.delta = 0
```

then one top-level OR group ANDed onto the query:

```
OR(
  base.type NOT IN (:bundles)          -- out of scope bundles untouched
  grel.gid IS NULL                     -- not in a collection: nothing to enforce
  &lt;visibility-expr&gt; = 'public'
  &lt;visibility-expr&gt; = 'uva'     AND :is_authenticated = 1
  &lt;visibility-expr&gt; = 'private' AND grel.gid IN (:member_gids)
)
```

`&lt;visibility-expr&gt;` is the nested CASE from your fact-7 query, emitted by `CollectionVisibility::nodeVisibilityExpression()` — node override wins when in {1,2,3}, else the group mapping `1→private, 2→uva, else public`, with `COALESCE(gfa.value, 0)`. Note the `NOT IN (:bundles)` disjunct is what leaves article/page/future types alone; it is cheap and future-proof, and it uses the *discovered* bundle list so Texts/Sources get enforcement the moment their plugin is enabled — same property the entity_access hook already has.

Two shaping decisions:

- **Inline `:member_gids` rather than joining memberships.** Compute the account's collection/subcollection gids in PHP (same list the cache context hashes) and pass as an `IN()`. Anonymous ⇒ list empty ⇒ drop the private disjunct entirely, so the anonymous query (the hot one) never touches `group_relationship` for memberships. Group's own `addIndividualConditions()` inlines ids the same way. If a user ever has &gt;~1000 memberships, fall back to `ensureMembershipJoin()`-style LEFT JOIN; measure before bothering.
- **Bypass**: `if (CollectionVisibility::hasBypass($account)) return;` before touching the query — plus bubble `user.permissions`.

### Group half

Tag `group_access`, base `groups_field_data`, one LEFT JOIN to `group__field_group_access`:

```
OR(
  base.type NOT IN ('collection','subcollection')
  COALESCE(gfa.value,0) NOT IN (1,2)
  COALESCE(gfa.value,0) = 2 AND :is_authenticated = 1
  base.id IN (:member_gids)
)
```

Differences from the node half: no override field, no relationship join, membership is against the row's own id, and the bypass set is the two-permission set the group hook uses today (unify per above). Also note `views.view.my_collections` and `views.view.group_members` are based on `group_relationship_field_data` (tag `group_relationship_access`) — deliberately **not** covered; `my_collections` lists the viewer's own memberships so there's nothing to hide. Record that as a decision, not an oversight.

---

## Keeping it in lockstep with `hook_entity_access()`

Three mechanisms, in order of strength:

1. **Structural**: the hook and both alters call `CollectionVisibility`. The hook's `_mandala_group_inheritance_node_access()` keeps its shape (veto, cacheable metadata) but its *decisions* come from `allows()`. There is no second copy of the rule to go stale.
2. **The SQL/PHP agreement test** (the one that actually catches drift, because SQL and PHP are unavoidably two expressions of one rule): a kernel test that builds every combination of `field_group_content_access ∈ {absent, empty, 0, 1, 2, 3, 99}` × `field_group_access ∈ {NULL, 0, 1, 2, 9}` as real nodes/groups, then asserts for each of 5 account types that **`$node-&gt;access('view', $account)` and "nid appears in the view result" agree**. That is 35 × 5 assertions and it makes the two paths physically unable to diverge. Extend the PR #255 21-combination matrix into this.
3. **Cross-references in comments**, the convention this codebase already uses (`CollectionFieldContributor::getOwningGroup()` ↔ `_mandala_group_inheritance_owning_group()`). Add reciprocal pointers, but treat them as documentation, not enforcement — they are exactly what failed four times.

**Fourth consumer — Solr (your point 9).** `CollectionFieldContributor::ACCESS_TO_VISIBILITY` at `/Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_kmassets_sync/src/Contributor/CollectionFieldContributor.php:54-89` maps only the group value and ignores the node override, so Solr disagrees with Drupal for 2,525 nodes — including **443 node-level private and 105 UVA inside public collections, which Solr currently labels `visibility_i:1` and serves to anonymous search**. That is a *live* leak of the same class, arguably worse than the listings one because search is the primary surface. **Out of scope for this PR, into its own — and it should be raised now, not filed.** It requires a re-index, and the fix is to have `contribute()` call `CollectionVisibility::forNode()` (making `mandala_kmassets_sync` depend on `mandala_group_inheritance`, which only depends on group/gnode — cheap; the session log's objection to sharing applied to `mandala_solr_visibility`, which is on the login path, not to `kmassets_sync`).

---

## Verification

**Yes, this should finally create `tests/`.** Three reasons specific to this change: the failure mode is invisible content (silent, not an error); the correctness claim is a 35-cell matrix no one will re-run by hand; and B's whole value proposition is "applies everywhere the tag applies", which is a claim only a test suite can hold. Start with `modules/custom/mandala_group_inheritance/tests/src/{Kernel,Functional}/` and a `phpunit.xml` runnable under ddev.

**Assert through the view, never `$node-&gt;access()`** (the note is right and PR #201 is the proof):

```php
$view = Views::getView('av_gallery');
$view-&gt;setDisplay('page_1');
$view-&gt;execute();
$nids = array_map(fn($r) =&gt; $r-&gt;nid, $view-&gt;result);
```
with the account set via `$this-&gt;setCurrentUser($account)` / `AccountSwitcher` — and critically **rebuild the view per account** (`$view-&gt;destroy()`), because the query is built once. Assert three things per case: membership of `$nids`, `$view-&gt;total_rows` (proves the count query was altered too), and for the functional layer that the restricted **title string is absent from the rendered HTML** of an anonymous `GET /av` — that is the actual exposure being fixed.

Layers:

- **Kernel** — the 35×5 matrix, against `av_gallery`, `image_gallery`, `collection_gallery`, `collection_gallery_av` and `collections`. Include the entity_access oracle comparison (mechanism 2 above).
- **Kernel, regression-specific** — a node type *without* a `group_node` plugin must be completely unfiltered (the `NOT IN (:bundles)` disjunct); a node with no group relationship must be visible; a group with `field_group_access` NULL must be visible.
- **Functional** — anonymous and authenticated `GET /av`, `/images`, `/collections`, plus a private collection page as a member (contents must still render — this is the PR #201 guard) and as `content_editor` with `bypass mandala group access`.
- **Live, this PR, on the real corpus** — the drush-eval pattern the team already uses, capturing before/after: `av_gallery` 11,582 → 9,537; `image_gallery` 111,339 → 111,269; `collections` 387 → 238; and non-zero, unchanged counts for uid 600 (real private-collection member) and a bypass account.

**The 18-node discrepancy** (2,045 vs 2,027): reconcile *before* implementing, with a cross-tab of the delta set grouped by `(nca.value, gfa.value, status, group bundle)`. Most likely candidates: nodes whose owning group is a subcollection whose `field_group_access` the 2026-09-25 UVA fix changed, or nodes with an override value outside {0,1,2,3}. Do not start until the number is explained — an unexplained 18 is exactly how a wrong CASE branch hides.

---

## Performance

Capture, anonymous and as a member, before and after, on the real local corpus:

1. `EXPLAIN` (and `EXPLAIN ANALYZE`) for the built `$view-&gt;build_info['query']` and `count_query` of `av_gallery`, `image_gallery`, `collections`.
2. Wall time of `$view-&gt;execute()` cold and warm, 5 runs, median — separately for the result query and the count query. The count query is the one at risk: it has no `LIMIT` and now carries three joins over 111k rows plus a `CASE` in the `WHERE`, which is unindexable.
3. Rendered page TTFB for `/av`, `/images`, `/collections` with the render cache cold.
4. `SHOW INDEX` on `node__field_group_content_access`, `group__field_group_access`, `group_relationship_field_data`. Confirm `group_relationship_field_data` has a usable index on `entity_id` (and ideally `(entity_id, plugin_id)`); if not, that join over 122k rows is the cost centre and is worth an index.

Acceptance: if the count query regresses past ~1s, the mitigations in order are (a) the index above, (b) swap the pager to a "quantity/estimate" mode or `views_pager` with skipped total, (c) reconsider a denormalised `effective_visibility` *computed column or view* rather than reconsidering the whole approach. Your fact-7 measurement (~1s including ddev overhead for the full 111k aggregate) suggests this lands fine.

---

## Rollout / sequencing

**One PR, three commits.** (1) pure refactor extracting `CollectionVisibility`, no behaviour change, config:status clean; (2) node half + cache context + tests; (3) group half + tests. The two halves share the resolver, the cache context and the test harness — splitting them means building that plumbing twice or landing half of it dead. The collections leak is small enough that it does not justify its own release train, and landing them together is what makes "the whole access story lands coherently" true.

**Separate follow-up PRs**, deliberately: the Solr `ACCESS_TO_VISIBILITY` node-override bug (needs re-index), and the two `accessCheck(FALSE)` callers (carousel cache keying is a real design question).

**Verify on dev-0 after deploy**: the three anonymous counts; uid 600 member sees private content; `content_editor` bypass unaffected; a private collection page *as a member* still renders its embedded `collection_gallery` / `collection_gallery_av` galleries (the fail-closed guard); page timings from step 3 above; and that Search API indexing still tracks/indexes all 122,923 items (it uses `accessCheck(FALSE)`, so it should — assert rather than assume). Remember merging to `main` auto-triggers the webhook deploy.

**Cutover implications: none added**, which is the headline advantage over A. No `node_access_rebuild()` step, no grants table to warm, no ordering constraint against the migration. The only new cutover-adjacent item is a standing check that no view ships `disable_sql_rewrite: true`.

**Under-specified / genuinely risky, flagged:**
- The membership cache context is the single place B can leak across users. It needs its own test (two users, same roles, different memberships, same page URL, distinct results).
- The 18-node discrepancy is unexplained and gates the work.
- The Solr node-override gap is a live anonymous exposure of 443 private + 105 UVA nodes through search, independent of everything above — it deserves a decision this week, not a deferred note.
- `mandala_group_inheritance` has no `.services.yml` today and no test infrastructure exists anywhere under `modules/custom`; this PR creates both. Budget for that, it is not free.

### Critical Files for Implementation
- /Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_group_inheritance/mandala_group_inheritance.module
- /Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/contrib/group/src/QueryAccess/QueryAlterBase.php
- /Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/core/modules/node/src/Hook/NodeDatabaseHooks.php
- /Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/config/sync/views.view.collections.yml
- /Users/ndg8f/Sandbox/Mandala/mandala-navina/drupal/web/modules/custom/mandala_kmassets_sync/src/Contributor/CollectionFieldContributor.php</result>
<usage><subagent_tokens>67728</subagent_tokens><tool_uses>30</tool_uses><duration_ms>346931</duration_ms></usage>
</task-notification>

---

## Claude — 2026-09-28 17:35

Two claims there are serious enough that I want to verify them myself before putting them in front of you.

---

## Claude — 2026-09-28 17:35

The agent over-extrapolated there — let me pin the real number and the 18-node gap.

---

## Claude — 2026-09-28 17:36

Verified. Now the decisions the note asks you to make — I have a recommendation on each.

---

## Claude — 2026-09-28 17:38

Before I reformulate — what would you like to clarify?

A few things I can expand on if they're what's in question:

- **The Solr finding**, since it's new and I only measured it this session. The 126 breaks down as 93 audio + 29 video with a node-level `private` override, plus 4 video with `uva`, all sitting inside collections whose own `field_group_access` is public. That's the same 126 the AV7 note attributes to "`field_group_content_access` being read by nothing" — PR #255 fixed Drupal's side for them, but `CollectionFieldContributor` still maps only the collection value, so Solr labels them `visibility_i:1`. I haven't confirmed against the live index, only the DB, so "served to anonymous search" is an inference from the mapping, not something I've queried Solr for. I can check that before you decide.

- **The query-alter recommendation**, if the concern is performance or whether it really covers everything. The ~1s figure is an aggregate `COUNT(*)` over the full 111k corpus including ddev overhead, not a rendered page load — the count query behind the pager is the one I'd actually want measured before committing, and I have a plan for that rather than a number.

- **The 18-node discrepancy**, which I did *not* resolve. My query says 2,045 AV nodes should be hidden; the note says 2,027 were viewable. I confirmed one component exactly (the 126) but the overall partition doesn't reconcile, and I'd want it explained before anyone writes the CASE expression — an unexplained 18 is how a wrong branch hides.

- **Whether these are even the right four questions.** Yuji isn't here until 1:45, and two of these — the approach and the Solr one — are arguably his call as lead architect rather than something to settle before he joins.

What's on your mind?

---

## User — 2026-09-28 17:38

Can you describe the difference between tag based and query alter?

---

## Claude — 2026-09-28 17:38

Good catch — my phrasing conflated two things. They're not alternatives. "Query alter" is the mechanism; "tag-based" describes *which* queries it fires on. Let me check the real signatures rather than describe from memory.

---

## Claude — 2026-09-28 17:39

They're not two options — I was sloppy. **Query alter is the mechanism. "Tag-based" describes which queries it fires on.** The real choice is *what you key the hook to*, and that's where the note's option 3 differs from what I'm recommending.

## Three places you can hook the same mechanism

| Hook | Fires on | Scope |
|---|---|---|
| `hook_views_query_alter($view, $query)` | Every view; you branch on `$view->id()` | Views only, and only views you named |
| `hook_query_alter($query)` | Every alterable DB query in Drupal | Everything, including queries with no relation to nodes |
| `hook_query_TAG_alter($query)` | Only queries carrying `TAG` | Whatever declares that tag |

All three are "query alters." The note's option 3 — "per-view query alters" — is the first row. Its weakness isn't the mechanism, it's that the enforcement is keyed to a **list of view IDs somebody has to maintain**. Write a new gallery next sprint, forget to add it, it ships unfiltered.

## Tags are just labels — so which tag matters

A Views query on `node_field_data` carries several tags at once. From `core/modules/views/src/Plugin/views/query/Sql.php`:

```php
1359:  ->addTag('views')
1360:  ->addTag('views_' . $this->view->storage->id());   // e.g. views_av_gallery
...
1516:  if (isset($base_table_data['table']['base']['access query tag'])) {
1517:    $access_tag = $base_table_data['table']['base']['access query tag'];
1518:    $query->addTag($access_tag);        // 'node_access'
1519:    $count_query->addTag($access_tag);  // and the pager count too
```

So you could write `hook_query_views_av_gallery_alter()` — tag-based, but still per-view, with exactly option 3's problem. **Being tag-based buys you nothing by itself.** What buys you generality is keying on `node_access` specifically, because that tag isn't attached by Views as a convenience — it's attached by anything declaring *"this query should be access-checked"*:

- Views, from the entity's views data (`NodeViewsData.php:77`)
- `EntityQuery` whenever `accessCheck(TRUE)` is set
- consequently REST/JSON:API and anything else built on those

So the coverage claim isn't "tags are magic." It's that `node_access` is the existing, core-declared meeting point for access-checked node queries, and hooking it puts the enforcement where every such query already looks — including views nobody has written yet.

Line 1519 is a free bonus worth naming: the tag goes on the **count query** as well, so the pager total stays consistent with the filtered rows. Under a per-view approach you'd have to remember that yourself.

## One detail that makes this concrete

Core already implements `hook_query_node_access_alter()` — and it currently does nothing, deliberately:

```php
if (!\Drupal::moduleHandler()->hasImplementations('node_grants')) {
  return;
}
```

No module implements `node_grants`, so core bails immediately. That is *exactly* why the note found `node_access` holding one `realm = all` row and the tag filtering nothing.

Ours would be a **second, independent implementation of the same tag hook**. Both run, each adds its own condition group, and they AND together at the top level — so ordering doesn't matter, and it stays correct even if someone later adds grants. We'd get the veto we want without touching the grants system at all.

## So the actual decision

Not "tag-based vs query alter." It's:

- **Key on `node_access` / `group_access`** — enforcement lives at the access-check boundary, covers Views + EntityQuery + REST, fails closed on surfaces nobody has built yet, and
- **vs. key on view IDs** (option 3) — cheap for the two galleries we know about, fails open on everything else, and
- **vs. node grants** — precompute into a table instead of filtering at query time, which is a different mechanism entirely and brings the rebuild and the veto-to-positive inversion.

Should I redo the question with that framing — `node_access` tag vs node grants, dropping the per-view option since I don't think it's a serious contender?

---

## User — 2026-09-28 17:44

Yes, redo the question with that framing

---

## Claude — 2026-09-28 17:45

What would you like to clarify?

If it helps narrow it down, the things I'd guess are unclear:

- **Whether these are the right questions at all.** Yuji joins at 1:45 and the mechanism choice is arguably his call as lead architect. I could hold questions 1 and 3 for him and only settle packaging and tests now — or drop the questions entirely and write the plan with my recommendations as stated assumptions, flagged for him to overturn.

- **The Solr question**, which is the weakest of the four. I offered "confirm against live Solr first" as an option, but that's really a task I should just go do rather than ask you to choose — it's read-only and takes a minute.

- **The cost side of the recommendation.** I've given you the benefits concretely and the cost vaguely ("three joins"). The honest position is that I measured an aggregate `COUNT(*)` at roughly a second on the full corpus, which is not the same as the pager count query under a real page load. If that number is what you want before choosing, I should measure it rather than ask.

- **Something in the framing itself** — if "query at read time vs precompute into a table" still isn't the distinction you're after, tell me what it's missing and I'll reframe again.

Which of those, or something else?

---

## User — 2026-09-28 17:45

Can you explain what you mean by tags?

---

## Claude — 2026-09-28 17:45

A tag is just a **string label stuck on a database query object** — nothing more. It exists so that code which didn't build the query can find it later and modify it. Let me show you the actual machinery rather than describe it.

---

## Claude — 2026-09-28 17:46

That's the entire mechanism, and it's simpler than the name suggests.

## A tag is a string in an array

```php
public function addTag($tag) {
  $this->alterTags[$tag] = 1;
  return $this;
}
```

That's it. No registry, no validation, no type. Any code holding a query object can stick any string on it.

## Tags become hook names by concatenation

When the query is about to run, `preExecute()` turns each tag into a hook name and invokes it:

```php
$hooks = ['query'];
foreach ($this->alterTags as $tag => $value) {
  $hooks[] = 'query_' . $tag;
}
\Drupal::moduleHandler()->alter($hooks, $query);
```

So for the `/av` gallery query, which carries `views`, `views_av_gallery` and `node_access`, Drupal calls every module's:

- `hook_query_alter()`
- `hook_query_views_alter()`
- `hook_query_views_av_gallery_alter()`
- `hook_query_node_access_alter()`

The query is passed by reference, and this happens **before** the SQL is generated — so a hook can still add joins, conditions, anything.

## Which means a tag is a convention, not a guarantee

Nothing in Drupal enforces that `node_access` means "check node access." It means that only because core attaches it in one place and core's own alter reads it in another, and everyone agreed to follow suit. You could tag a query `bananas` and write `hook_query_bananas_alter()`; it would work identically.

That's worth being clear-eyed about, because the whole coverage argument rests on it: hooking `node_access` reaches everything **that chose to declare itself access-checked**. The declaration is made by whoever builds the query, not by us:

- Views attaches it from the entity's views data — `NodeViewsData.php:77` — onto both the result query and the pager count query
- `EntityQuery` attaches `{entity_type}_access` whenever `accessCheck(TRUE)`

So we inherit their coverage, including views nobody has written yet. We don't have to enumerate anything.

## And the same fact is exactly where it fails open

If a query never adds the tag, our hook never fires. Three ways that happens here:

- `accessCheck(FALSE)` on an EntityQuery — and there are two such callers in this codebase today, in `SiblingCarouselService` and `shanti_collections_view`
- `disable_sql_rewrite: true` on a view — all 24 views in `config/sync` are currently `false`, which is why I'd want a CI check asserting that stays true
- a raw `Database::select()` that never opts in

Node grants have the identical blind spot, incidentally — core's grants alter is itself just a `hook_query_node_access_alter()` implementation. Neither approach protects a query that declined to be access-checked.

One useful detail from that core snippet: core's own implementation currently bails out immediately —

```php
if (!\Drupal::moduleHandler()->hasImplementations('node_grants')) {
  return;
}
```

— because nothing implements `node_grants`. So the tag is already on our gallery queries and already being dispatched; the only subscriber just declines to act. Adding our own subscriber to that same tag is a small step, not new infrastructure.

Does that clear it up, or is there a part of how this would land in the module that you want to see?

---

## User — 2026-09-28 17:49

What is the tag we are going to use here and where does it get added?

---

## Claude — 2026-09-28 17:49

Two tags — one per half. Let me show you exactly where each gets attached.

---

## Claude — 2026-09-28 17:49

All three confirmed. Let me verify empirically rather than leave it as inference.

---

## Claude — 2026-09-28 17:49

`node_access` isn't there. That contradicts what I told you — let me find out why.

---

## Claude — 2026-09-28 17:49

My test was wrong, not the mechanism — the tag is added in `execute()`, not `build()`. Re-running.

---

## Claude — 2026-09-28 17:50

Confirmed empirically, on the real views, as the user Drush runs as:

```
av_gallery     (node_field_data,   total_rows 11582)   result + count: … node_access
image_gallery  (node_field_data,   total_rows 111339)  result + count: … node_access
collections    (groups_field_data, total_rows 387)     result + count: … group_access
```

Those totals — 11,582 / 111,339 / 387 — match the note's measured figures exactly, so this is the same unfiltered behaviour it documented.

## The two tags

**`node_access`** for the galleries, **`group_access`** for the collections view. So two hooks:

```php
mandala_group_inheritance_query_node_access_alter(AlterableInterface $query)
mandala_group_inheritance_query_group_access_alter(AlterableInterface $query)
```

## Where each one gets attached

**`node_access`** is declared once, by core's node module, as a property on the views data for the node base table:

```php
core/modules/node/src/NodeViewsData.php:77
  $data['node_field_data']['table']['base']['access query tag'] = 'node_access';
```

**`group_access`** is never declared explicitly by anyone. Group ships no `GroupViewsData` class, so it inherits core's default, which builds the tag from the entity type id:

```php
core/modules/views/src/EntityViewsData.php:185
  'access query tag' => $this->entityType->id() . '_access',
```

`group` + `_access` → `group_access`. Same mechanism, same shape, no special handling needed on our side.

**Views reads that property and attaches the tag** — and this is the bit I got wrong a moment ago. It happens in `execute()`, not `build()`:

```php
core/modules/views/src/Plugin/views/query/Sql.php:1514-1520
  if (empty($this->options['disable_sql_rewrite'])) {
    $base_table_data = Views::viewsData()->get(...base_table...);
    if (isset($base_table_data['table']['base']['access query tag'])) {
      $access_tag = $base_table_data['table']['base']['access query tag'];
      $query->addTag($access_tag);
      $count_query->addTag($access_tag);
    }
```

Three things follow from those six lines. The tag goes on the **count query** as well, so the pager total gets filtered in step with the rows — no extra work for us. The whole block is gated on `disable_sql_rewrite` being false, which is what makes that a setting worth a CI guard. And because it lands at execute time, any test that only calls `build()` will see no tag and prove nothing — which is exactly the trap I just fell into, and worth writing into the test plan so nobody repeats it.

**`EntityQuery` attaches the same tags independently**, which is where the non-Views coverage comes from:

```php
core/lib/Drupal/Core/Entity/Query/Sql/Query.php:161-162
  if ($this->accessCheck) {
    $this->sqlQuery->addTag($this->entityTypeId . '_access');
  }
```

`node` → `node_access`, `group` → `group_access`. Same two tags, so the same two hooks cover EntityQuery and anything built on it, with nothing extra to write.

The flip side is visible in that same `if`: a query with `accessCheck(FALSE)` never gets the tag, so neither hook fires. That's the fail-open surface, and it's why the two existing `accessCheck(FALSE)` callers — `SiblingCarouselService:189` and `shanti_collections_view.module:215` — need a deliberate decision rather than being assumed covered.

Want me to write the plan up now, or keep digging on something?

---

## User — 2026-09-28 17:50

Ask the questions again please

---

## Claude — 2026-09-28 18:03

All four recommended. Let me do the Solr verification you asked for, then write the plan.

---

## Claude — 2026-09-28 18:03

My local points at the shared staging master — I'll read only, no writes.

---

## Claude — 2026-09-28 18:03

The `fl` filter was ignored. Let me query it directly as a count.

---

## Claude — 2026-09-28 18:03

Confirmed on samples. Let me get the exact aggregate rather than extrapolate from three.

---

## Claude — 2026-09-28 18:04

Syntax works — the full list was just too long for one URL. Batching.

---

## Claude — 2026-09-28 18:04

Spaces in the URL break curl — needs `+`.

---

## Claude — 2026-09-28 18:04

That aggregate is unreliable — batches after the first returned nothing, which is a bug in my loop, not a finding. Let me query each node individually instead.

---

## Claude — 2026-09-28 18:05

Classic stdin-consumption bug — `ddev exec` ate the loop input. Fixing.

---

## Claude — 2026-09-28 18:06

Now a trustworthy result — and it corrects what I told you earlier.

---

## Claude — 2026-09-28 18:07

Plan is written. One correction to what I told you earlier, because the live check changed the number.

**The Solr gap is real, but smaller than I said — and it grows.** I claimed the mapping bug would mean all 126 nodes are served to anonymous search. Querying each of the 126 individually against the live staging master:

- All 126 are present in the index
- **35 are labelled `visibility_i:1`** — public, served to anonymous search today
- 91 read `visibility_i:2` (private), but only because those documents predate recent collection-access repairs

So the live exposure is 35, not 126. The catch is that the 91 are correct by staleness, not by design: the mapping derives visibility from the collection, and all 126 sit in *public* collections, so **a fresh `index-all` would relabel all 126 as public.** The exposure gets worse the next time anyone reindexes, which makes it worth fixing before that happens rather than after.

Two other things worth flagging from the plan:

**I found a gate, not just a task.** The resolution query says 2,045 AV nodes should be hidden; the AV7 note says 2,027 were viewable. One component reconciles exactly — the 126 — but the overall partition doesn't, and I could not explain the remaining 18. That needs cross-tabbing before anyone writes the CASE expression, because an unexplained 18 is precisely how a wrong branch hides.

**An asymmetry in the existing code that needs Yuji.** The node bypass check (`.module:186`) tests three permissions; the group one (`.module:132`) omits `bypass node access`. That may well be deliberate — `bypass node access` is a node permission — but the shared resolver has to pick one, so it wants a decision rather than my guess.

The mechanism choice is in the plan as decided, but it's the kind of call Yuji may want to weigh in on at 1:45; nothing in the plan is hard to revisit if he prefers grants.

---

## Claude — 2026-09-28 18:15

Plan approved. The plan's own gate comes first: reconcile the 18 before any code. The definitive way is to run the actual access hook over the corpus and compare it to the SQL — which is also the oracle the plan calls for.

---

## Claude — 2026-09-28 18:16

The hook says **2,027** — the note is right and my SQL over-restricts by 18. Let me find exactly which nodes disagree.

---

## Claude — 2026-09-28 18:17

Found it. Let me confirm by running the plan's actual condition — the one with the `gid IS NULL` disjunct my quick query omitted.

---

## Claude — 2026-09-28 18:18

The gate is cleared, and the answer is cleaner than expected.

## The 18 are explained — and the plan's SQL was already right

Running the real access hook over all 11,582 published AV nodes as anonymous returns **exactly 2,027 denied**, matching the note. My earlier 2,045 was the error, not the note.

The 18 are all **collection-less nodes carrying a node-level restriction**:

```
nid       type   node_val   gid
119949    video     2       NULL
119966    video     3       NULL
...       video   2 or 3    NULL
```

`_mandala_group_inheritance_node_access()` bails to `neutral()` when there's no owning group, before ever reading the node's own override. My quick measurement query read the override first, so it hid them. The plan's SQL sketch already carries `grel.gid IS NULL` as a disjunct in the OR — and running the plan's full condition returns **exactly 2,027**. So the design reproduces the hook precisely; my throwaway query was just missing a clause the plan already had.

That's the reconciliation the plan gated on, done. Nothing in the plan needs to change.

## But it surfaced a real question for the group

Those 18 nodes are published, explicitly marked private (6) or UVA (12) at node level, have no collection, and **are visible to anonymous today** — on entity pages, not just listings. That's current behaviour, unchanged by this work, and faithfully reproducing it is what the plan now does.

Whether it's *correct* is a different question. A node someone marked private arguably shouldn't be public merely because it has no collection. This is ADR 015 Q2 territory — D11 forbids collection-less asset content, but migrated orphans exist — and it connects directly to `orphaned-content-temp-group-on-migration.md`. Worth 5 minutes with Yuji at 1:45 rather than a decision I make inside this PR.

Two things narrow it usefully: it's **18 nodes, all `video`**, none in Images or audio; and if the orphan note's "migrate into a temporary review group" direction lands, they get a collection and the question dissolves on its own.

## Where that leaves us

The plan is approved and the gate is passed, so implementation can start. I'd suggest the first commit — the pure `CollectionVisibility` refactor with no behaviour change — since it's mechanical, independently verifiable (`config:status` clean, access behaviour identical), and the thing both halves build on.

Want me to start on that, or hold until Yuji's here given he may want to revisit the mechanism choice?

---

## User — 2026-09-28 18:20

Go ahead and start. The orphaned nodes should be migrated to a temp group. Then start as you suggest. Yuji is here and agrees.

---

## Claude — 2026-09-28 18:20

Yuji's agreement on the orphan direction is a decision worth recording before I start on code. Let me set up the branch and read the module in full.

---

## Claude — 2026-09-28 18:21

Now the refactor. Creating the shared resolver first.

---

## Claude — 2026-09-28 18:22

Now rewiring the module's access section to delegate to it.

---

## Claude — 2026-09-28 18:22

Now the `use` statement, then verify behaviour is genuinely unchanged.

---

## Claude — 2026-09-28 18:24

Now the real test — behaviour must be identical. Running the access oracle across all three bundles and both tiers.

---

## Claude — 2026-09-28 18:30

The authenticated numbers are wrong — my probe user was never saved, so it had no uid and behaved as anonymous. Let me use a real one.

---

## Claude — 2026-09-28 18:35

Numbers are coherent now (24 UVA collections become visible to authenticated — matching the note). But I need a genuine before/after, not just plausibility. Stashing to get the baseline.

---

## Claude — 2026-09-28 18:46

**Identical on every cell.** The refactor is behaviour-preserving:

| | anon | auth13 | u105 | u69 |
|---|---|---|---|---|
| audio/video | 2027 | 1690 | 0 | 0 |
| shanti_image | 70 | 70 | 0 | 0 |
| groups | 170 | 146 | 21 | 21 |

Before and after match exactly. Restoring my work.

---

## Claude — 2026-09-28 18:47

Refactor verified. Committing it.

---

## Claude — 2026-09-28 18:47

Commit 1 is in. Here's where things stand before I start commit 2.

## Done: `CollectionVisibility` extracted, behaviour-identical

New `src/Access/CollectionVisibility.php` owns the rule; the procedural helpers in the module are now thin wrappers so any external caller keeps working. Verified the hard way rather than by inspection — ran the real access hook over the full 122,921-node corpus for four accounts, stashed the change, ran it again, compared:

| | anon | auth13 | u105 | u69 |
|---|---|---|---|---|
| audio/video | 2027 | 1690 | 0 | 0 |
| shanti_image | 70 | 70 | 0 | 0 |
| groups | 170 | 146 | 21 | 21 |

Every cell identical. `config:status` clean, since nothing but code changed. Two of those numbers independently corroborate the note: **70** private images, and **24** UVA collections becoming visible once authenticated (170 → 146).

## The orphan decision — I'd like to put it in its own PR

You and Yuji decided the 18 collection-less restricted nodes get migrated into a temp group. I've recorded that in the code comment where the early return lives, so the next person reading it understands why it isn't handled there.

But I haven't touched `orphaned-content-temp-group-on-migration.md` yet, deliberately. That note is its own deferred item with its own scope, and bundling a decision-resolution into a feature PR is the pattern we've agreed to avoid before. I'd rather land it as a small separate PR — the note plus the deferred README row, with the newly-quantified 18 video nodes as evidence. Say the word and I'll do that now; it's a few minutes.

## Two things I want to flag before commit 2

**The bypass asymmetry is still unresolved and it's Yuji's call.** The node path honours `bypass node access`; the group path doesn't. I preserved both verbatim as `hasNodeBypass()` / `hasGroupBypass()` rather than unify them, because unifying changes who sees what. Worth 30 seconds with him now that he's here — the query alters in commit 2 will call these, so whichever answer he gives gets baked into two more places.

**`u105` and `u69` both showed 0 denials across 122k nodes**, which means they have a bypass permission rather than being plain collection members. So the matrix above verifies the bypass path well, but it does *not* yet exercise "private content, visible to an actual member, not via bypass." That's a real gap in the verification, and commit 2's test matrix needs a genuine member account — I'll find one rather than reuse these.

Want me to do the orphan-note PR first, or go straight into commit 2 (the node-half query alter)?

---

## User — 2026-09-28 18:52

descreibe the The bypass asymmetry

---

## Claude — 2026-09-28 18:52

Let me ground it in who actually holds these permissions here, since that determines whether it matters in practice.

---

## Claude — 2026-09-28 18:53

Here it is concretely, and the practical answer is clearer than I expected.

## The two checks

Both live in `mandala_group_inheritance.module`. The node path:

```php
// line 186 — three permissions
if ($account->hasPermission('bypass group access')
 || $account->hasPermission('bypass node access')
 || $account->hasPermission('bypass mandala group access')) {
```

The group path:

```php
// line 132 — two permissions
if ($account->hasPermission('bypass group access')
 || $account->hasPermission('bypass mandala group access')) {
```

`bypass node access` appears in one and not the other. That's the whole asymmetry. I preserved it as `hasNodeBypass()` / `hasGroupBypass()` rather than collapsing it, because collapsing changes who sees what.

## Where the three permissions come from

- **`bypass group access`** — Group module's own.
- **`bypass mandala group access`** — ours, defined in `mandala_group_inheritance.permissions.yml`, added for ADR 015's global `content_editor` so it can reach private collections without per-group membership. Explicitly `restrict access: true`.
- **`bypass node access`** — core's node permission: *"View, edit and delete all content regardless of permission restrictions."* Note it says **content**. It has nothing to say about group entities.

## It is currently unobservable

Checked live rather than from config, since `is_admin` roles hold everything implicitly:

```
bypass node access          : administrator(admin)
bypass group access         : administrator(admin)
bypass mandala group access : content_editor, administrator(admin)
```

No role holds `bypass node access` except `administrator`, and `administrator` is `is_admin: true` — so it holds `bypass mandala group access` too, which **both** paths honour. The extra permission can therefore never be the deciding factor today. `content_editor`, the only non-admin bypass holder, has just the Mandala one, so it takes the same branch on both paths.

The asymmetry only becomes visible if someone grants `bypass node access` to a role lacking `bypass mandala group access`. That role would then see private *nodes* but get 403 on the private *collection page* containing them — a half-open state that would read as a bug.

Incidentally this also explains the gap I flagged earlier: `u105` and `u69` are both `administrator` (105 is your own account), which is why they showed zero denials. They tested the bypass path, not membership.

## My recommendation: keep them different, but deliberately

The current split is principled, not accidental. `bypass node access` is a *node* permission; a collection is a group entity, not a node. Honouring it on the node path and ignoring it on the group path is the semantically correct reading, and unifying would mean a node permission silently granting access to group entities.

So: keep two methods, document why, and cover it in commit 2's test matrix so the distinction is asserted rather than assumed. That's what I've already written into `CollectionVisibility` — I just don't want it standing on my reading alone.

The reason it's worth Yuji's 30 seconds now rather than later: commit 2 adds two more call sites (the node and group query alters), so whichever answer he gives gets baked into four places instead of two. And if he'd rather unify, that's a behaviour change that belongs in its own commit with its own before/after, not folded into a query-alter commit.

Want me to put it to him as "keep, and we'll test it" — or hold commit 2 until he's weighed in?

---

## User — 2026-09-28 19:03

In what situations would this asymmetry make any difference?

---

## Claude — 2026-09-28 19:03

Precisely one situation. Let me state it and then check whether anyone is actually in it.

---

## Claude — 2026-09-28 19:04

The asymmetry makes a difference in exactly one state, and **no account on the site is in it** — 0 of 1,543 checked.

## The one state

Write the three permissions as N (`bypass node access`), G (`bypass group access`), M (`bypass mandala group access`):

- Node path bypasses if **N or G or M**
- Group path bypasses if **G or M**

They differ only when **N is true and both G and M are false.** Any other combination sends both paths down the same branch.

## What that account would experience

| | Node pages / listings | Collection pages / listings |
|---|---|---|
| Restricted content | Sees everything | Normal rules apply |

Concretely: they could open every private and UVA-only node, but hitting the collection page those nodes live in would 403 unless they were a member. After commit 2, the same split shows up in listings — galleries unfiltered, `/collections` filtered. Odd enough to be reported as a bug.

## The reassuring part

The group path's permission set is a **strict subset** of the node path's. So the asymmetry can only ever make collections *more* restricted than nodes, never the reverse. It cannot cause exposure — only the inverse-looking oddity above. That makes this a correctness-and-consistency question, not a security one, which is the right frame for deciding it quickly.

## How someone could land in that state

Realistically one way: granting `bypass node access` to a role for an unrelated editorial reason. It's a common permission to reach for when a role needs to see or edit content regardless of restrictions — a QA role, a migration role, an editor tier that isn't `content_editor`. Nobody would think of it as touching collections, which is exactly why the split would surprise them.

The two adjacent things in flight both *could* go there: the contributor-tier work (`authenticated-contributor-crud-not-wired-in-d11`, High, unbuilt) and the group-editor tier deferred to Group roles under ADR 015. Neither needs `bypass node access` as currently scoped, but both are about giving non-admin roles broader reach over content, which is the neighbourhood.

## So: does it matter?

Today, no — demonstrably. Which is the useful part of the answer, because it means **either choice is free to make right now.** Unifying would change no one's access; keeping it would change no one's access. The cost only appears later, once a role exists that trips the divergence, and at that point changing it *is* a behaviour change on a live system.

My recommendation stands on the semantics rather than the numbers: keep them separate, because `bypass node access` is core's *node* permission and a collection isn't a node — but pin it with a test so it's a decision rather than an accident. That's a 30-second confirmation from Yuji, not a discussion.

Shall I take that to him and then start commit 2?

---

## User — 2026-09-28 19:05

Yes, Yuji says keep them separate and start commit 2

---

## Claude — 2026-09-28 19:06

Yuji's call recorded. Let me update the docblock and look at Group's own alter for the cacheability pattern before writing mine.

---

## Claude — 2026-09-28 19:06

Let me verify two things before writing: the condition-group API, and whether those field tables can produce duplicate rows.

---

## Claude — 2026-09-28 19:06

Max 1 row per entity on all three — no `DISTINCT` needed, as the plan assumed. Adding the SQL expression to the resolver.

---

## Claude — 2026-09-28 19:07

Now the query alter itself.

---

## Claude — 2026-09-28 19:07

Now the cache context — the one place this could leak across users.

---

## Claude — 2026-09-28 19:08

Now wiring the hook into the module.

---

## Claude — 2026-09-28 19:08

Now the real test — do the views actually filter, and do the numbers match the access hook?

---

## Claude — 2026-09-28 19:09

Every number matches the access hook exactly:

| | view rows | hook allowed | |
|---|---|---|---|
| anon AV | 9,555 | 11,582 − 2,027 = 9,555 | ✓ |
| anon images | 111,269 | 111,339 − 70 = 111,269 | ✓ |
| auth13 AV | 9,892 | 11,582 − 1,690 = 9,892 | ✓ |
| admin105 | 11,582 / 111,339 | 0 denied (bypass) | ✓ |

And since `total_rows` *is* the count query result, the pager is filtering too. Now performance and the untouched collections view.

---

## Claude — 2026-09-28 19:09

Those are too fast — views results caching. Let me time the actual SQL instead.

---

## Claude — 2026-09-28 19:09

That's the un-executed original — the alters fire in `preExecute()`, and Views executes a clone. Let me force it.

---

## Claude — 2026-09-28 19:10

Those are Group's joins (`gcfd`), not mine (`mgi_`). But the counts changed — I need to resolve that contradiction before trusting anything.

---

## Claude — 2026-09-28 19:10

`build_info['query']` is a pre-alter snapshot, so it's the wrong instrument. Let me use a query I control end-to-end.

---

## Claude — 2026-09-28 19:10

Confirmed end to end. Now the index question the plan flagged.

---

## Claude — 2026-09-28 19:11

Good — `group_relationship__load_by_entity` already covers `(entity_id, plugin_id)`, and the field tables are indexed on `entity_id` via PRIMARY. Confirming they're used:

---

## Claude — 2026-09-28 19:11

Indexes are all being used (`node__status_type`, `group_relationship__load_by_entity`, `PRIMARY` ×2). 353ms on 111k rows is well inside the plan's 1s threshold.

Now tests. Let me check what infrastructure exists to run them.

---

## Claude — 2026-09-28 19:11

phpunit and core-dev are both available. Writing the oracle test — the one that structurally prevents the PHP and SQL paths from drifting.

---

## Claude — 2026-09-28 19:12

Typo in that last method, and I need the user-creation trait. Fixing both.

---

## Claude — 2026-09-28 19:12

Now let's see if it runs.

---

## Claude — 2026-09-28 19:14

`flexible_permissions` is a Group dependency I missed. Adding it and the required attribute.

---

## Claude — 2026-09-28 19:16

`getRole()` isn't Group 3.x API — the roles are separate config entities.

---

## Claude — 2026-09-28 19:16

Group 3.x doesn't auto-create those roles — the site's config does. Let me copy the real shape.

---

## Claude — 2026-09-28 19:17

`createUser()` takes permissions, not roles. Simplifying.

---

## Claude — 2026-09-28 19:18

The module's own inheritance hooks need the subcollection bundle. Adding it, which also makes the fixture more faithful to production.

---

## Claude — 2026-09-28 19:19

`User::load(0)` is NULL in a kernel test — the anonymous user row doesn't exist. Creating it.

---

## Claude — 2026-09-28 19:19

168 assertions passing. But a test that can't fail is worthless — let me prove it catches drift by deliberately breaking the SQL.

---

## Claude — 2026-09-28 19:19

The test passed *with* the bug — so the oracle isn't working. Let me check the injection actually applied.

---

## Claude — 2026-09-28 19:20

The sed pattern didn't match (double quotes, not single). Injecting properly.

---

## Claude — 2026-09-28 19:20

The oracle works — it catches the drift precisely, naming the exact cell and both verdicts. Restoring.

---

## Claude — 2026-09-28 19:20

Restored and green. Final live check, then committing.

---

## Claude — 2026-09-28 19:21

Commit 2 is in. The node half works.

## What landed

`hook_query_node_access_alter()` in `mandala_group_inheritance`, keyed on the `node_access` tag, plus the membership cache context and the first test suite in `modules/custom`.

**Live, against the real corpus:**

| | av_gallery | image_gallery |
|---|---|---|
| anon | 9,555 *(was 11,582)* | 111,269 *(was 111,339)* |
| auth13 | 9,892 | 111,269 |
| admin105 | 11,582 | 111,339 *(bypass, unfiltered)* |

Every figure matches the entity-access hook exactly. `total_rows` is the count query result, so the pager filters in step — that came free from core tagging both queries.

**Performance:** 353ms for a tagged `COUNT` over all 111k images, 99ms for AV, against 22ms/9ms for a bypass account. All three joins are index-driven — `group_relationship__load_by_entity` already existed and covers `(entity_id, plugin_id)`, and the field tables use PRIMARY. Comfortably inside the 1s threshold, no new index needed.

## The verification worth flagging

Two things nearly slipped past me, and both are the kind that make a test look fine while proving nothing.

**`build_info['query']` is a pre-alter snapshot.** My first attempt to inspect the generated SQL showed no joins at all, even though the counts had clearly changed. That instrument was simply wrong — Views executes a different object. I switched to a query I built and tagged myself, which showed the real SQL with `mgi_rel`/`mgi_gfa`/`mgi_nca` and the CASE expression.

**The oracle test passed with a bug injected.** I broke the SQL mapping to prove the test catches drift, and it still went green — because my `sed` pattern didn't match and the bug was never applied. When I injected it properly, the test failed and named the exact cell:

> `collection field_group_access=1, node field_group_content_access='absent': entity access says DENIED, the tagged query says ALLOWED`

So the oracle genuinely works, but only because I checked. A test asserting agreement between two implementations passes trivially if neither is exercised, and I'd have had no way to tell the difference from a green run.

## Remaining

`collections` is still 387 rows for everyone — that's commit 3, the group half, which reuses the same resolver and cache context via `hook_query_group_access_alter()`. Expected to drop to 238 for anonymous and 262 for authenticated.

Still outstanding from earlier, neither started: the **orphan-note PR** (your and Yuji's temp-group decision — I've recorded it in code comments and the test, but not in `orphaned-content-temp-group-on-migration.md`), and the **Solr node-override PR** for the 35 confirmed-exposed nodes.

Want commit 3 next, or one of those two?

---

## User — 2026-09-28 19:25

Yes do commit 3 next.

---

## Claude — 2026-09-28 19:25

Commit 3 — the group half. Same shape, simpler, sharing the resolver.

---

## Claude — 2026-09-28 19:26

Numbers match exactly — 238 anonymous (the note's predicted 387 − 125 − 24), 262 authenticated, 387 for bypass. The "MISMATCH" label is my own `===` comparing an int to a string; the values are identical in every row.

Now group-side test coverage.

---

## Claude — 2026-09-28 19:27

Both green. Now proving the group oracle can fail too — same check I ran for the node half.

---

## Claude — 2026-09-28 19:27

The group oracle catches drift too. Final verification and commit.

---

## Claude — 2026-09-28 19:28

All three commits are in. The listings gap is closed on both surfaces.

## Final state, all three views

| | av_gallery | image_gallery | collections |
|---|---|---|---|
| **anon** | 9,555 *(was 11,582)* | 111,269 *(was 111,339)* | **238** *(was 387)* |
| auth13 | 9,892 | 111,269 | 262 |
| admin105 | 11,582 | 111,339 | 387 *(bypass)* |

Every cell matches what the entity-access hook independently returns. And 238 is exactly 387 − 125 private − 24 UVA — the number the deferred note predicted before any of this was written, which is a good sign the note's analysis and this implementation agree about what the data means.

## The three commits

1. **`4d2aa1e`** — extract `CollectionVisibility`, pure refactor, verified behaviour-identical by stashing and re-running the full-corpus oracle.
2. **`4501b3c`** — node half via `hook_query_node_access_alter()`, plus the membership cache context and the first test suite under `modules/custom`.
3. **`35b18f9`** — group half via `hook_query_group_access_alter()`.

Test suite: 2 tests, 208 assertions. I injected a deliberate bug into *each* alter to confirm the oracles actually fail — worth the extra minutes, since an agreement test passes trivially when neither side is exercised and a green run looks the same either way. The first time I tried this, the test passed because my `sed` never applied; I'd have wrongly concluded the oracle was sound.

`config:status` clean throughout — no config touched.

## Not done, and worth being explicit

**Nothing is pushed and there's no PR yet.** The branch is local at `feat/listings-visibility-enforcement`.

**The plan called for functional tests** — anonymous `GET /av` asserting a restricted title is absent from rendered HTML, and a private collection page rendering its galleries for a member. I built the kernel layer only. The kernel oracle asserts the query returns the right rows; it does not assert the rendered page omits the title. That's a real gap between plan and delivery, not a decision I made quietly.

**The cache context has no test.** I flagged it as the one place this can leak across users — two accounts, same roles, different memberships, same URL — and then didn't write that test. It's the highest-value thing still missing.

**Two follow-ups still untouched:** the orphan-note PR recording your and Yuji's temp-group decision, and the Solr node-override PR for the 35 confirmed-exposed nodes.

My suggestion: close the cache-context test and the functional tests before opening the PR, since both are load-bearing for the claims the commit messages make. Or push now and treat those as review follow-ups — your call.

---

## User — 2026-09-28 19:30

Go ahead and do the two tests.

---

## Claude — 2026-09-28 19:30

Two tests. First I'll extract the shared fixture so both can use it.

---

## Claude — 2026-09-28 19:31

Refactor clean, same 208 assertions. Now the cache-context test — the cross-user leak scenario.

---

## Claude — 2026-09-28 19:32

4 tests passing. Verifying they'd catch the actual mistake — simulating the `user.group_permissions` collision.

---

## Claude — 2026-09-28 19:33

The cache-context test catches the exact mistake it exists to prevent. Now the functional test — asserting the restricted title is absent from rendered HTML.

---

## Claude — 2026-09-28 19:34

The test needs a view to request. Adding a minimal test module with one.

---

## Claude — 2026-09-28 19:35

All 4 pass. Let me add the attribute Drupal 12 will require, then verify these catch the bug too.

---

## Claude — 2026-09-28 19:36

Now the decisive check — do the functional tests actually catch the leak? Disabling the node alter entirely.

---

## Claude — 2026-09-28 19:37

Exactly the right signature: 3 of 4 fail, and `testMemberSeesOwnPrivateContent` correctly still passes — it's the fail-closed guard, which shouldn't depend on filtering at all. Running the full suite.

---

## Claude — 2026-09-28 19:39

Both tests are done and committed. Tree is clean.

## Full suite: 10 tests, 324 assertions

**Cache context (4 kernel tests)** — the leak I'd flagged and then not covered. The load-bearing one asserts two accounts with *identical roles* but different memberships hash differently; that's precisely the assertion `user.group_permissions` would fail. Plus non-members all collapsing into one bucket (the reason for hashing rather than using `user`), and order-independence.

`testMembersWithIdenticalRolesDiverge()` is what gives the others teeth — it proves those two accounts genuinely see different content, so a collision would be a real leak rather than a theoretical one.

**Functional (4 tests)** — requests through the full stack, asserting the restricted *title* is absent from rendered HTML, which is the actual exposure. Needed a small test module supplying a view; `disable_sql_rewrite: false` is the fixture's load-bearing detail, since that's what attaches the tag.

Also refactored the shared fixture into `CollectionVisibilityKernelTestBase` rather than duplicating setup.

## Both verified to fail when they should

- Making the cache context return a constant → `testContextVariesByMembership` fails with *"hashed the same, so cached listings would be served across them."*
- Disabling `NodeQueryAlter::alter()` → **exactly 3 of 4** functional tests fail. `testMemberSeesOwnPrivateContent` correctly still passes, because a member should see that content whether or not filtering runs. That asymmetric signature is what tells you the suite measures the right thing rather than just being sensitive to any change.

Also added `#[RunTestsInSeparateProcesses]` to all four classes — Drupal 12 throws without it.

One cleanup worth mentioning: the functional run wrote 53 HTML files to `drupal/web/sites/simpletest/browser_output/`, untracked and not gitignored. I removed them and kept them out of the commit, but **anyone running functional tests will regenerate them** — that path probably wants a `.gitignore` entry. Small, and I didn't add it since it's outside this PR's scope; say the word if you'd like it folded in.

## Where the branch stands

Four commits on `feat/listings-visibility-enforcement`, nothing pushed, no PR open:

```
a6179b1  test: cover the cache context and the rendered listings
35b18f9  feat: enforce collection visibility in the collections listing
4501b3c  feat: enforce collection visibility in node listings
4d2aa1e  refactor: extract CollectionVisibility as the single resolver
```

Still outstanding, both untouched: the **orphan-note PR** recording your and Yuji's temp-group decision, and the **Solr node-override PR** for the 35 confirmed-exposed nodes.

Push and open the PR, or take one of those two first?

---

## User — 2026-09-28 19:40

Push and open a PR and also do separate PRs for the orphan-note and Solr override.

---

## Claude — 2026-09-28 19:40

Three PRs. First, PR 1 needs its own deferred note updated — it's the note this work resolves.

---

## Claude — 2026-09-28 19:41

Now moving the README row from "awaiting a team decision" to resolved.

---

## Claude — 2026-09-28 19:41

That table is two columns, not four. Fixing the row format.

---

## Claude — 2026-09-28 19:41

Three pipes matching the neighbouring rows. Committing the docs and pushing PR 1.

---

## Claude — 2026-09-28 19:42

PR #259 is open. Now the orphan-note PR.

---

## Claude — 2026-09-28 19:43

That's just my own uncommitted work. Committing and opening PR 2.

---

## Claude — 2026-09-28 19:44

PR #260 open. Now the Solr fix — which depends on `CollectionVisibility` from #259, so it branches off that.

---

## Claude — 2026-09-28 19:44

Now verifying the fix produces the right labels for the affected nodes.

---

## Claude — 2026-09-28 19:45

The fix works. But there's a second population I should check — the override also works in the *loosening* direction.

---

## Claude — 2026-09-28 19:46

85 nodes move the *other* way. Let me verify both directions agree with Drupal's own access decision.

---

## Claude — 2026-09-28 19:46

All 376 changed documents now agree with Drupal's own access decision, zero disagreements. Committing and opening PR 3.

---

## Claude — 2026-09-28 19:47

All three PRs are open and pushed.

## [#259](https://github.com/uvalib/mandala-navina/pull/259) — Enforce collection visibility in Views listings → `main`

Five commits. Closes both listing surfaces and the deferred note that gated them.

| Account | `av_gallery` | `image_gallery` | `collections` |
|---|---|---|---|
| anonymous | 9,555 *(was 11,582)* | 111,269 *(was 111,339)* | 238 *(was 387)* |
| authenticated | 9,892 | 111,269 | 262 |
| bypass | 11,582 | 111,339 | 387 |

10 tests, 324 assertions — the first suite under `modules/custom`.

## [#260](https://github.com/uvalib/mandala-navina/pull/260) — Orphan review-group direction → `main`

Docs only, independent of the others. Records your and Yuji's decision plus the 18 `video` nodes that gave it fresh evidence, and why it's handled in migration rather than the access layer.

## [#261](https://github.com/uvalib/mandala-navina/pull/261) — Solr node-level override → **stacked on #259**

Based on the feature branch because it uses `CollectionVisibility`; GitHub will retarget it to `main` when #259 merges. Flagged at the top of the PR body so nobody reviews it out of order.

**This one grew when I measured it properly.** I'd been describing it as 126 nodes; the real figure is **376** — because 126 was only the subset whose collection was public. The full set is every node whose override disagrees with its collection, and it moves in both directions:

- **291 tighten** (260 private, 31 uva) — including the 35 confirmed live-exposed in the index today
- **85 loosen** — nodes marked public inside a restricted collection, indexed as restricted, so missing from anonymous search despite having public pages

All 376 now agree with what anonymous entity access returns, zero disagreements.

## Three things to carry into review

**#261 needs a scoped re-index after deploy** — `indexNode()` over the 376, never `kmassets:index-all`, which would rewrite all 111,340 image docs. Without it the code change does nothing to existing documents and the 35 stay exposed.

**#259 and #261 both touch deploy-sensitive paths** under `drupal/**`, so per the standing note, check dev-0 for active detached jobs before merging — a deploy restarts the container.

**The `simpletest/browser_output` directory isn't gitignored.** I kept 53 generated files out of the commits, but anyone running the functional tests will regenerate them. I left it out as out of scope for these PRs; it's a one-line fix whenever you want it.

---

## User — 2026-09-28 19:47

Let's wrap up this session and write a session log and memory etc.

---
