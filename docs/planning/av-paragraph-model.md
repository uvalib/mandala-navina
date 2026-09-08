# AV3: The D11 paragraph model for AV's PBCore and workflow metadata

**Decision date:** 2026-09-08
**Decided by:** Yuji Shinozaki (Sprint 3 lead, per [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md))
**Backlog item:** [Sprint 3](../sprints/sprint-03-av-core-implementation.md) AV3
**Status:** Decided **and built** — 15 paragraph types, 84 new field storages, 87 field
instances live in `config/sync`
**Relates to:** [ADR 008](../adr/008-mvp-migrate-not-improve.md) / [ADR 010](../adr/010-adr-008-scope-clarification.md)
(internal remodeling is permitted where it reduces risk), [AV2 scope note](av-content-type-decision.md)
(the "one shared definition" constraint this follows), [AV Content-Model Audit](av-content-model-audit.md)
(whose open question #2 this closes), [Sprint 1](../sprints/sprint-01-images-implementation.md)
(Images' node→Paragraph precedent)

---

## Decision

**Every D7 `field_collection` becomes a Paragraph type — 1:1 — with one exception.**

The three structurally-identical workflow-note collections consolidate into a **single
`av_workflow_note` type, referenced by three separate fields** on `av_workflow`. That
preserves all three note streams exactly as D7 keeps them, while defining the shape once.

**15 paragraph types**, not 17: 16 live field_collections minus the two collapsed by
consolidation, plus the vestigial one dropped.

```
node (audio | video)                    ← AV4's job; bundles do not exist yet
 ├─ av_pbcore_title            (3)     ├─ av_pbcore_identifier   (2)
 ├─ av_pbcore_description      (3)     ├─ av_pbcore_extension    (2)
 ├─ av_pbcore_creator          (2)     ├─ av_kmap_annotation     (6)
 ├─ av_pbcore_contributor      (2)     ├─ av_pbcore_coverage     (1)
 ├─ av_pbcore_relation         (2)     ├─ av_pbcore_publisher    (2)
 ├─ av_pbcore_sponsor          (2)
 │
 ├─ av_pbcore_instantiation   (25)
 │    └─ field_pbcore_format_id ──→ av_pbcore_format_id  (2)
 │
 └─ av_workflow               (29)
      ├─ field_workflow_notes            ──┐
      ├─ field_catalog_workflow_notes    ──┼──→ av_workflow_note  (4)
      └─ field_transcript_workflow_notes ──┘
```

## Why consolidate the notes but nothing else

The three note collections carry the same four fields — author, date, importance, and a
body. The only difference is the body's *name*: `field_catalog_workflow_notes` calls it
`field_description`, the other two call it `field_workflow_note`. That the *content* is
genuinely the same kind of thing was confirmed against the production data, not assumed —
see [Field naming](#field-naming-keep-d7s-names-with-three-exceptions) below. Three near-identical
4-field types that can drift apart independently is precisely the failure mode
[AV2](av-content-type-decision.md) found in D7's two AV bundles, and the same "one shared
definition" reasoning applies. Consolidating the *type* costs nothing, because the three
streams stay distinct at the **field** level — `av_workflow` still has three separate
reference fields, so a catalog note is never confused with a transcript note.

Nothing else consolidates. The other collections have genuinely different shapes.

## Why not flatten the two card-1 groups

`field_workflow` (29 sub-fields) and `field_pbcore_instantiation` (25) are cardinality 1
on the node, so they could have been flattened into ~54 plain node fields instead of
Paragraph types. Rejected, primarily because of **[AV2](av-content-type-decision.md)**:
AV2 kept `audio` and `video` as two node bundles, and in D11 a *node* field needs
per-bundle instance config — so flattening would mean configuring 54 fields **twice**,
which is exactly the duplication AV2's "one shared definition" constraint exists to
contain. Paragraph types are shared across both node bundles for free.

Secondary reasons: Paragraphs preserve D7's grouped editing affordance, and ADR 008's
floor is faithful migration, which flattening is not.

## Field naming: keep D7's names, with three exceptions

**Rule: D11 field names are D7's field names verbatim**, so the AV4 migration mapping is
1:1 and needs no lookup table. Three exceptions:

| D7 name | D11 name | Why |
|---|---|---|
| `field_language` | **`field_pbcore_language`** | **Forced — type collision.** `field_language` already exists on the `paragraph` entity type from Images, as a **`shanti_kmaps_fields_default`** field (cardinality -1). D7 AV's is a `list_text`. Field storage is per *entity type*, so one name cannot carry two types — the rename is unavoidable. |
| `kmap_id` | **`field_kmap_id`** | The only D7 sub-field without a `field_` prefix. |
| `field_description` **(on `field_catalog_workflow_notes` only)** | **`field_workflow_note`** | **Semantic, and evidence-backed — see below.** Applies *only* to the catalog-note body; `av_pbcore_description` keeps `field_description` and reuses Images' storage. |

### Why the catalog-note body is renamed (checked against the data, 2026-09-08)

Consolidating the three note collections into one type means the type has one body field,
and the catalog stream's body had a different D7 name. The merge was originally justified
on structure alone — identical author/date/importance fields — which was **inference, not
evidence**. Checked afterwards against the production dump, and the evidence is stronger
than the inference was:

`field_description` in D7 is **not** exclusive to catalog notes. It is attached to **two**
field_collection bundles that mean different things:

| Bundle carrying `field_description` | Rows | Content | Median length |
|---|---|---|---|
| `field_pbcore_description` | 15,669 | Public descriptive metadata — HTML, multilingual | 197 |
| `field_catalog_workflow_notes` | 2,049 | Internal cataloguing/QA notes | 88 |

Real catalog-note values read *"Needs an English description. Needs a referenced place
name. Needs Tibetan description and caption"* — the same register as `field_workflow_note`
values like *"Needs title slates in Tibetan and Chinese"* (median 47). D7 also treats the
two differently downstream: `mb_metadata` strips the whole `field_workflow` group from the
Solr index as internal state, while PBCore descriptions are public content.

**So `field_description` is a D7 modeling accident — one field name carrying two unrelated
meanings.** Keeping it on the note type would have put *three* unrelated meanings on a
single `paragraph` storage: Images' `image_descriptions`, AV's public PBCore description,
and AV's internal QA notes. The rename separates them and puts the catalog note body with
the other two note streams, where it belongs.

**AV4 consequence:** the migration must map `field_catalog_workflow_notes.field_description`
→ `av_workflow_note.field_workflow_note`. This is the one place the otherwise-1:1 field
mapping does not hold, and it is easy to miss because the source field name still exists in
D11 (on `av_pbcore_description`) meaning something else entirely.

Deliberately **not** renamed: `field_sponser_role` keeps D7's typo. Fixing it would be a
judgment call, and the value of "D7 name verbatim" as a single mechanical rule outweighs
the cosmetics. AV4's mapping stays trivial.

**One storage is reused rather than created:** `field_description` (`text_long`,
cardinality 1) already exists on `paragraph` from Images' `image_descriptions`, with an
identical type — so `av_pbcore_description` shares it. That is how Drupal field storage is
meant to work; there is no alternative for a shared name, and the types match exactly.

## Type mapping

| D7 | D11 | Notes |
|---|---|---|
| `text` | `string` | |
| `text_long` | `text_long` | |
| `list_text` | `list_string` | 46 fields; all `allowed_values` carried across verbatim (e.g. `field_title_type` has 73) |
| `datetime` | `datetime` | `datetime_type: datetime` — keeps time, avoids silent truncation |
| `number_integer` | `integer` | |
| `entityreference` | `entity_reference` | `field_relation_identifier` only |
| `field_collection` | `entity_reference_revisions` | the two nested cases |

## How this was built (and why it matters)

The config was **generated by Drupal and then exported**, not hand-authored as YAML. A
script created the 15 types and 87 field instances through the entity API, then
`drush config:export` produced the canonical files. This sidesteps two known traps: the
UUID conflicts that come from hand-writing config entities, and the hand-edited-YAML
validation question still open in
[`config-export-drift-hand-edited-yaml.md`](../deferred/config-export-drift-hand-edited-yaml.md).

> ⚠ **That deferred item is real, and this build hit it.** `drush config:export`
> round-trips *every* file through Drupal's YAML dumper, and it stripped the explanatory
> comments from three unrelated hand-edited files
> (`migrate_plus.migration.d7_images_collections`, `…_subcollections`,
> `views.view.collection_gallery`) and reformatted their sequence style. Those three were
> **reverted and not committed** — comments are not config data, so `config:status` stays
> clean with the commented versions in place. **Anyone running `config:export` on this
> repo must check for and revert this collateral damage**, until that deferred item is
> resolved.

### Verified, not assumed

Beyond a clean export, the model was exercised: an `av_workflow` paragraph with a nested
`av_workflow_note`, and an `av_pbcore_instantiation` with a nested `av_pbcore_format_id`,
were created, saved, reloaded and read back through both levels of nesting. A deliberately
invalid `list_string` value was rejected with exactly one validation violation, confirming
the `allowed_values` actually carried across and are enforced. Test entities were deleted
afterwards.

## What this does NOT do

- **No node-level fields.** The `audio`/`video` bundles do not exist yet, so the
  entity-reference-revisions fields that attach these paragraphs to a node are **AV4's**
  work, not this task's. The paragraph types stand alone and are independently valid.
- **`field_relation_identifier` has no bundle restriction yet.** D7 restricts it to
  `audio`/`video` nodes. Those bundles don't exist, so the field is currently an
  unrestricted node reference. **AV4 must narrow it** once the bundles are created.
  It is also an intra-AV node reference, so AV4 needs a second pass or stub-and-backfill
  to resolve it.
- **No form or view displays.** Drupal falls back to defaults, and the editing/rendering
  surface belongs to **AV9**.
- **No migration.** Mapping D7 field_collection items onto these types is AV4.

## Counts

| | |
|---|---|
| D7 live `field_collection` bundles | 16 (+1 vestigial `field_pbcore_genre`, excluded) |
| D11 paragraph types created | **15** |
| Field storages created | **84** (+1 reused: `field_description`) |
| Field instances created | **87** |
| New files in `config/sync` | **186** |
| D7 field_collection items awaiting migration (AV4) | **125,528** |
