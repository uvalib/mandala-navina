# D7 alias preservation covers only `shanti_image` — the other 68% needs a decision

**Area:** migration / Images / URLs / ADR 016
**Raised during:** Session 2026-08-25, DDEV verification of the `d7_images_url_alias` migration
**Jira:** (add when available)
**Priority:** Medium — collection aliases are done; the live item is a one-query check on dev-0

[ADR 016](../adr/016-public-url-structure-single-host.md) decision 7 makes preserving D7's
pathauto paths a **requirement**. The `d7_images_url_alias` migration delivers that for
`shanti_image` nodes. It is worth being explicit that this is **32% of the D7 alias table**,
and that the remainder was scoped out by deliberate choice, not covered by accident.

Measured against the 2026-06-11 production Images dump (`d7_images`, 350,921 alias rows):

| D7 source | Alias rows | D11 destination | Status |
|---|---:|---|---|
| `shanti_image` nodes | **111,304** | `path_alias` → `/node/{nid}` | ✅ migrated |
| `image_agent` nodes | 103,521 | became **paragraphs** — no URL | ❌ no destination exists |
| `image_descriptions` nodes | 70,909 | became **paragraphs** — no URL | ❌ no destination exists |
| `file/*` | 64,933 | file entities | ⚠ unassessed |
| `subcollection` nodes | 119 | `path_alias` → `/group/{id}` | ✅ migrated |
| `collection` nodes | 55 | `path_alias` → `/group/{id}` | ✅ migrated |
| `external_classification` | 13 | taxonomy / paragraph | ⚠ unassessed |
| `asset_link` | 13 | — | ⚠ unassessed |
| `page` | 6 | — | ⚠ unassessed |
| `user/*` | 46 | users | ⚠ unassessed |
| `external_classification_scheme` | 2 | taxonomy terms | ⚠ unassessed |

## The satellite aliases are probably not worth preserving — but say so deliberately

`image_agent` and `image_descriptions` account for 174,430 rows, half the table. Two reasons
they are likely droppable, and one reason to record the decision rather than let it happen
silently:

1. **They are machine-generated derivatives, not shared URLs.** Sampled aliases read
   `agent/natasha-judson-985`, `-986`, `-987` — the *same agent*, fanned out one node per
   image reference, which is exactly the D7 modelling artefact [1a.7](../sprints/sprint-01-images-implementation.md)
   collapsed into owned paragraphs. `image_descriptions` is the same shape
   (`image/desc/{slug}-1`).
2. **They have no D11 destination at all.** Those entities are now paragraphs, and
   [1b.4](../sprints/sprint-01-images-implementation.md) established that the `paragraph`
   entity type declares no canonical route — there is no URL to redirect *to*, and inventing
   one would undo 1b.4's access guarantee.

So the likely answer is "drop them", but per the Spike 6 convention on the AJAX endpoints,
**the default answer should be a recorded decision, not an omission.**

## Collection aliases migrate, and the pages work

`d7_images_collection_url_alias` was built and verified 2026-08-25: 174 created, 0 failed,
**0 mismatches** against the D7 source, 55 collection + 119 subcollection matching the group
counts exactly. `/group/1` → `/collection/poor-peoples-campaign`.

### ⚠ Correction — an earlier version of this note was wrong

That version claimed the preserved URLs were 403 for everyone because **"no group role grants
`view group`"**, and framed it as an access-model gap needing to be designed and wired.

**Both claims were wrong.** The permission *is* granted — all ten `group.role.*` configs carry
`'view group'` — and the enforcement *is* built: `_mandala_group_inheritance_group_access()`
has always handled group entities, denying only `field_group_access = 1` for non-members, with
the bypass permissions honoured. Nothing needed wiring.

The false finding came from a bad grep: the pattern `view group$` never matches the config
line `  - 'view group'`, which ends in a quote. A zero count was read as "not granted" rather
than "pattern didn't match". **Absence of a grep hit is not evidence of absence.**

### The real cause: 174 stale anonymous memberships

Anonymous was denied because **anonymous was recorded as a *member* of every group** — 174
`group_membership` rows with `entity_id = 0`, one per group. Group's
`GroupPermissionChecker::hasPermissionInGroup()` branches on membership: a member gets the
**insider** item, a non-member the **outsider** one. Anonymous holds only the `anonymous`
global role, and the insider roles are scoped to `authenticated`, so the insider lookup
returned nothing and access fell through to neutral — which Drupal denies. Public and private
collections alike, which is exactly why the symptom looked like a blanket permission gap.

Diagnosis chain, each step measured: role config grants it → permission is registered and
`allowed for: anonymous,outsider,member` → the calculator **does** emit `view group` for
anonymous in the outsider scope → but the *checker* returns false → because the membership
loader reports anonymous as a member.

**This is stale data, not a code defect.** The rows are dated `2026-07-10 10:45:16` — the
original 1b.2 migration run, before the `uid: default_value: 1` fix landed. Both committed
migrations now set it correctly, so a fresh import does not reproduce it.

Removing the 174 rows locally gives the correct behaviour, verified with all three cases:

| Request (anonymous) | Result |
|---|---|
| `/collection/{slug}`, **public** | **200** |
| `/collection/{slug}`, **private** | **403** |
| `/collection/{bogus}` | **404** |

### What still needs doing

- **Check dev-0 for the same 174 rows.** Not verifiable from this session. dev-0's migration
  ran 2026-07-17, *after* the fix, so it is probably clean — but "probably" is what produced
  the error above. The query is
  `SELECT COUNT(*) FROM group_relationship_field_data WHERE plugin_id='group_membership' AND entity_id=0;`
  and it should return 0.
- Note the 1a.9 acceptance cycle self-heals this anyway: `rollback` deletes the groups and
  `import` recreates them with `uid: 1`.

## Why collection aliases were worth doing

`collection/poor-peoples-campaign`, `collection/women-natural-history-illustrators` — 174
rows, and unlike the satellites these are exactly the sort of URL a curator puts in a syllabus
or an email. They point at D7 nodes that became **D11 Groups**, whose canonical path is `/group/{id}`, so
`d7_images_url_alias` deliberately excludes them (it writes `/node/{nid}` paths only) and
`d7_images_collection_url_alias` handles them separately. Both share one source plugin, which
takes a `node_types` list; only the destination path construction differs.

## Open questions

1. **Confirm dev-0 has no stale `uid=0` group memberships** (see the correction above). One
   query; it should return 0. ~~Should any group role grant `view group`~~ — moot, it already
   does, and `field_group_access` is already respected.
2. **Satellite aliases: confirm the drop.** No D11 destination exists; the recommendation is
   to drop, recorded rather than assumed.
3. **`file/*` aliases (64,933): unassessed.** Note they share the `image/{slug}` namespace
   with node aliases (e.g. `file/1` → `image/morven-landscape-ecology-1937`), so whatever is
   decided must not collide with the 111,304 node aliases now occupying that namespace.
4. **The long tail** (`user`, `page`, `asset_link`, `external_classification*`): 80 rows
   total. Cheap to decide, easy to forget.

Each of these repeats per site as Texts, Sources and AV migrate — see the per-site checklist
in [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md).

## Update 2026-08-27: dev-0 check resolved — clean on the from-scratch rebuild

The "confirm dev-0 has no stale `uid=0` group memberships" follow-up (added when
`d7-alias-preservation-scope-beyond-shanti-image.md`'s sibling correction retracted the
"no group role grants `view group`" misdiagnosis) is now moot rather than merely re-checked:
dev-0 was rebuilt from scratch on 2026-08-26/27, so there is no rollback-era membership data
left to be stale. Collection aliases (171) and node aliases (111,301) both reproduced exactly
on the clean run. Group access is confirmed correct on the fresh build: public group ALLOWED,
private group denied, for a real anonymous session.
