# D7 alias preservation covers only `shanti_image` — the other 68% needs a decision

**Area:** migration / Images / URLs / ADR 016
**Raised during:** Session 2026-08-25, DDEV verification of the `d7_images_url_alias` migration
**Jira:** (add when available)
**Priority:** Medium — one part (collection aliases) is High and user-facing

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
| `subcollection` nodes | 119 | became **Groups** (`/group/{id}`) | ⚠ **real gap** |
| `collection` nodes | 55 | became **Groups** (`/group/{id}`) | ⚠ **real gap** |
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

## Collection aliases ARE a real gap

`collection/poor-peoples-campaign`, `collection/women-natural-history-illustrators` — 174
rows, and unlike the satellites these are exactly the sort of URL a curator puts in a syllabus
or an email. They point at D7 nodes that became **D11 Groups**, whose canonical path is
`/group/{id}`, so `d7_images_url_alias` deliberately excludes them (it writes `/node/{nid}`
paths only).

Preserving them needs a second migration writing `path_alias` rows against the Group
canonical path, keyed on the Groups' own `field_legacy_nid` (already migrated on both group
types). Small — 174 rows — and independent of the node alias work.

## Open questions

1. **Collection/subcollection aliases: preserve or drop?** Recommend preserve; it is 174 rows
   and they are genuinely user-facing.
2. **Satellite aliases: confirm the drop.** No D11 destination exists; the recommendation is
   to drop, recorded rather than assumed.
3. **`file/*` aliases (64,933): unassessed.** Note they share the `image/{slug}` namespace
   with node aliases (e.g. `file/1` → `image/morven-landscape-ecology-1937`), so whatever is
   decided must not collide with the 111,304 node aliases now occupying that namespace.
4. **The long tail** (`user`, `page`, `asset_link`, `external_classification*`): 80 rows
   total. Cheap to decide, easy to forget.

Each of these repeats per site as Texts, Sources and AV migrate — see the per-site checklist
in [migration-legacy-nid-required-convention.md](migration-legacy-nid-required-convention.md).
