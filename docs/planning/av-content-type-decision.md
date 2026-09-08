# AV2 Scope Note: `audio` and `video` stay two D11 content types, built from one shared definition

**Decision date:** 2026-09-08
**Decided by:** Yuji Shinozaki (Sprint 3 lead, per [ADR 018](../adr/018-av-track-starts-in-parallel-not-strictly-last.md))
**Backlog item:** [Sprint 3](../sprints/sprint-03-av-core-implementation.md) AV2
**Status:** Decided — AV3 and AV4 unblocked
**Relates to:** [ADR 008](../adr/008-mvp-migrate-not-improve.md) / [ADR 010](../adr/010-adr-008-scope-clarification.md)
(migrate-not-improve, and the latitude for internal remodeling),
[ADR 016](../adr/016-public-url-structure-single-host.md) (URL grammar keys on the D11
content type), [ADR 017](../adr/017-legacy-identity-composite-key.md) (legacy identity
discriminator is the D7 *site*, so audio and video already share one nid space),
[AV Content-Model Audit](av-content-model-audit.md) (whose open question #1 this closes)

This is a scope note in the manner of [ADR 010](../adr/010-adr-008-scope-clarification.md),
not a new ADR: the decision **conforms to** ADRs 008/010/016 rather than changing or
superseding any of them. It follows the precedent Images set, where the equivalent
content-model decision was recorded as a dated decision alongside the audit rather than
as an ADR of its own.

---

## Decision

**Keep `audio` and `video` as two separate D11 content types.** Do not collapse them into
a single bundle with a media-kind field.

**Build both from a single shared field definition.** The ~29 fields the two bundles
share must be generated or derived from one source of truth, not hand-maintained twice.
The mechanism is left to AV3/AV4 (a shared field-definition array consumed by both
bundles' config generation is sufficient; nothing exotic is required), but the constraint
is binding: **it must not be possible to change a shared field on one AV bundle and not
the other without that being a deliberate, visible act.**

## Why

**1. It is the faithful migration, which is ADR 008's floor.** Two D7 bundles become two
D11 bundles. No remodeling risk, nothing to reconcile at cutover. ADR 010 permits internal
remodeling where it *reduces* risk; here collapsing would add risk rather than remove it,
so the latitude does not apply.

**2. ADR 016 already reasoned this and said not to disturb it.** Clause 3 establishes
`/audio/{nid}` and `/video/{nid}` as deliberately separate, states that "the URL grammar
keys on the **D11 content type**, not on the kmassets facet" — where AV is a single
`audio-video` value across 11,537 production docs — and explicitly instructs readers not
to "align" the two. Keeping two bundles costs no ADR churn. Collapsing would have required
amending a clause whose whole purpose is to stop exactly that reconciliation.

**3. `field_thumbnail_image` is a genuine functional difference, not drift.** It exists
only on `audio`, and it is actively used: **2,844 of 4,187 audio nodes (68%) carry one;
video has zero.** This reads as deliberate — Kaltura generates a poster frame for video,
audio has no frame, so editors upload cover art. The bundles are not interchangeable.
Collapsing would expose the field on video, where D7 forbids it.

**4. Bundles are already this platform's asset-type axis.** `shanti_image` is its own
bundle. Keeping `audio` and `video` as bundles preserves a 1:1 correspondence between
content type and asset type across the whole platform, and keeps AV from becoming the one
site whose asset type has to be read out of a field.

**5. The config-surface argument for collapsing is weaker than it appears.** In D11 field
*storage* is per entity type, shared across bundles — `field.storage.node.field_pbcore_title`
is defined once. Only the per-bundle `field.field.*`, form-display and view-display config
duplicates, and that duplication is mechanical. The genuine cost is that AV3, AV4, AV6,
AV7, AV8, AV9 and AV13 each configure twice; requirement (2) above is what keeps that cost
mechanical rather than a maintenance liability.

## Why "one shared definition" is part of the decision, not a nicety

D7 ran these two bundles as independent copies, and **they drifted.** Measured against the
2026-09-01 production dump, all 29 shared fields differ in their stored settings. Almost
all of it is cosmetic — 28 differ only in form-widget weight, 21 and 19 in teaser and
default display weight — but the substantive residue is real:

| Divergence | `audio` | `video` |
|---|---|---|
| `field_language_kmap` KMaps search root | `301` | `6403/301` |
| `field_pbcore_identifier` label | Identifier | Identifier**s** |
| `field_tags` label | Tags | **Tags Old** |
| `field_tags` default display | `taxonomy_term_reference_link` | **hidden** |
| `field_pbcore_instantiation` default label | hidden | above |
| `group_content_access` default label | inline | above |
| `field_rating` `allow_clear` | `0` | `1` |
| Help text | diverged on 4 PBCore fields | |

None of this is catastrophic and none of it blocks migration. But it is what a decade of
two hand-maintained near-identical bundles produces, and it is the specific failure mode
this decision has to avoid repeating. `field_tags` being labelled "Tags Old" and hidden on
video but live and labelled "Tags" on audio is the clearest example: the same field, two
different apparent intentions, with no record of which is current.

**Migration consequence:** these divergences migrate as-is. This scope note does *not*
authorise harmonising labels or help text — that is a content decision for AV staff, and
under ADR 008 it is an improvement, not a migration. Carry D7's values across per bundle,
and raise the `field_tags` "Tags Old" question with AV staff separately.

## Evidence

All figures below are from `mandala-prod-av-db_2026-09-01.sql.gz` (`gzip -t` verified
before use, per Spike 7's dump-hygiene rule), read by streaming the dump.

**Bundle structure** — `field_config_instance`, `entity_type = node`, `deleted = 0`:

| | `audio` | `video` |
|---|---|---|
| Field instances | 31 | 30 |
| Shared with the other bundle | 29 | 29 |
| Exclusive | `field_audio`, `field_thumbnail_image` | `field_video` |

**Node counts** — matching Sprint 3's acceptance criteria exactly:

| Bundle | Count | Published | Unpublished |
|---|---|---|---|
| `video` | 7,396 | 7,395 | 1 |
| `audio` | 4,187 | 4,187 | 0 |
| `collection` | 152 | 131 | 21 |
| `subcollection` | 85 | 85 | 0 |
| `MISSING_TYPE` | 68 | 68 | 0 |
| `page` | 4 | 4 | 0 |
| `source` | 1 | 1 | 0 |

**Media and thumbnail coverage:**

| | Rows | Bundles present |
|---|---|---|
| `field_data_field_audio` | 4,186 | `audio` only |
| `field_data_field_video` | 7,379 | `video` only |
| `field_data_field_thumbnail_image` | 2,844 | **`audio` only** |

`node_type` carries **no `MISSING_TYPE` row** — it was never a content type, only an
invalid string written into `node.type`.

## Corrections this makes to the AV content-model audit

1. The audit states the two bundles "differ only in which single field holds the media
   reference (`field_audio` vs. `field_video`); every other field is shared," and its
   entity graph and field inventory both list `field_thumbnail_image` as shared. **It is
   audio-only**, and used on 68% of audio nodes. Corrected in place.
2. The audit's open question #1 (collapse or keep two) is **closed by this note**.

## What this note does NOT decide

- **The PBCore/workflow `field_collection` → Paragraphs question** (audit open question
  #2, Sprint 3 AV3). Unaffected either way — the target model is the same whether it
  attaches to one bundle or two.
- **Whether the two bundles share view modes or displays.** Requirement (2) governs field
  definitions; display configuration is AV9's and AV13's to settle, and AV13 already
  expects per-view-mode player configuration that may legitimately differ between audio
  and video.
- **Anything about `MISSING_TYPE` disposition** (AV5) or the 18 AV nodes with no Kaltura
  entry — both are separate dispositions, tracked in Sprint 3.
- **Label and help-text harmonisation.** Explicitly out of scope, as above.
