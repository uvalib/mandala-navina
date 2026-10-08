# Audit AV node fields D7 vs D11, starting from the missing technical-metadata fields

**Area:** migration / AV / content model / data fidelity
**Raised during:** Group session 2026-10-02 (Than)
**Issue:** #292
**Priority:** High — raised as a direct observation against the live site, not a hypothetical.
**Step 1 (edit form) DONE 2026-10-06 (Than).** Steps 2-5 (the full data/view-display audit)
remain open and unstarted — see "Still open" below.

## What is deferred

Than noticed the technical-metadata fields he expects from the old AV site are not showing up
on the D11 AV node pages. This has not been investigated yet — filed here to track it, not to
record a diagnosis.

**Than's own diagnosis of the first-order cause:** on the AV node *edit form*, many fields are
currently set to `hidden`/disabled, not wired into the `content` region — this is a form-display
config state (`core.entity_form_display.node.{audio,video}.default`), separate from the *view*
display that renders the node page. His proposed first step: get the AV node **form** to show all
its fields, organized to match the old D7 site's form layout/grouping, before auditing which
fields have data or render on the view side. That reordering makes the rest of the audit possible
to do visually (an editor can see what's on the form) rather than purely by diffing YAML.

## Why this needs a full audit, not just a fix

AV15 (PR #222, 2026-09-17/18) already did one round of "Details/Technical/Availability parity
with D7" work and found two real migration bugs along the way (a `field_pbcore_instantiation`
single-valued-winner scoring defect affecting 307 nodes, and the `field_relation_identifier`
migration-ordering bug). That work was believed to close the technical-metadata gap at the time.
Than's observation now contradicts that, which means either:
- a regression was introduced since 09-17 (config drift, a later migration re-run, or a display
  change), or
- the AV15 pass covered a different field set than what Than is looking for, or
- the fields are migrated but not wired into the current view mode/display config.

Any of these is plausible and none should be assumed without checking. This should be a
**systematic field-by-field audit** (every `field_pbcore_*`/`field_av_*` field against its D7
source, not just the ones AV15 touched), not a point fix for the specific fields Than noticed
missing — the same kind of corpus-wide audit [Spike 11's corpus audit](../spikes/spike-11-av-transcript-replication.md)
did for transcripts, and the content-model audit did at [C1](../planning/av-content-model-audit.md).

## Step 1 — DONE 2026-10-06 (Than)

Reworked `core.entity_form_display.node.{audio,video}.default` so every genuinely
D7-equivalent editorial field is shown in the `content` region, using Drupal's own
default widget per field type (via `WidgetPluginManager::prepareConfiguration()`, the
same resolution the Field UI admin screen uses — not hand-picked widgets). **Not** a
literal "flip every hidden field" — three fields were deliberately kept hidden because
they aren't D7-equivalent editable fields at all:
- `field_legacy_nid` / `field_legacy_site` — D11-only migration bookkeeping (ADR 017's
  identity-resolution composite key); D7 has no such field, and exposing them as
  editable risks an editor breaking content-identity resolution.
- `field_kaltura_duration` — a technical value synced from Kaltura at upload time; D7's
  own UI only ever displayed this (never let editors type it), per the earlier
  `av15-pbcore-duration-vs-kaltura-duration.md` finding.
- `promote`/`sticky` — generic Drupal node fields, no D7 equivalent, not AV-specific.

22 fields now visible on both bundles (23 on audio, which also gets
`field_thumbnail_image`): `field_available_from`, `field_copyright_owner`,
`field_group_content_access`, `field_kmap_annotation`, `field_license`, all 11
`field_pbcore_*` fields, `field_rating`, `field_tags`, `field_transcript`,
`field_workflow`, `field_year_published`. No grouping/fieldsets added — Than decided a
flat field list was enough for this pass, D7's exact layout/grouping was never
documented anywhere to match against precisely anyway (see "What closes it" below,
unchanged).

**Verified live** (not just config diffed): loaded a real video node's edit form over
HTTP, confirmed all 12 spot-checked fields present (`field_pbcore_title`,
`field_pbcore_description`, `field_workflow`, `field_rating`, `field_tags`,
`field_license`, `field_available_from`, `field_copyright_owner`,
`field_year_published`, `field_group_content_access`, `field_kmap_annotation`,
`field_pbcore_contributor`) and all 3 deliberately-excluded fields correctly absent;
confirmed no new watchdog errors from rendering the form; existing
`mandala_group_inheritance` kernel suite still 7/7.

**This step explicitly does NOT answer**: whether each field's data actually migrated,
or whether each field is wired into the *view* display — those are steps 2-5 below,
still open. A field showing (empty) on the edit form proves nothing about whether D11
has real migrated data for it.

**Browser-tested 2026-10-08.** Loaded a real video node's edit form in an actual
browser (not just `curl`/HTTP). Found and fixed a real bug along the way: none of the
11 `av_pbcore_*` paragraph bundles had their own `core.entity_form_display` config, so
the inline Paragraphs widget rendered an empty summary row with no inputs for every
nested field (contributor name/role, title, creator, relation identifiers, the
24-field instantiation bundle, etc.). Built each bundle's form display live in DDEV
and exported (12 new config files). Re-verified live: all field groups expand
correctly and every nested PBCore field now shows its real data. PR #284 is ready to
merge.

## Still open (steps 2-5, unchanged from the original note)

2. Enumerate D7's full technical-metadata field set for AV (PBCore + any AV-specific fields),
   same approach as the Images field-by-field work.
3. For each field: confirm it migrated (data present on D11 nodes), confirm it's wired into the
   current *view* display config (`core.entity_view_display.node.{audio,video}.*`), and confirm
   it renders — three separate failure modes, don't assume "migrated" implies "visible."
4. Compare against what AV15 (PR #222) actually covered, to explain the discrepancy rather than
   re-doing undifferentiated work.
5. Record findings here or split into per-field deferred notes if multiple distinct gaps turn up
   (matching the existing `av4-*`/`av15-*` note pattern).

**Owner:** Than. **Go-ahead given by the team, 2026-10-07.** Step 1 (the edit-form display) is
done and browser-verified 2026-10-08 — PR #284 is ready to merge; steps 2-5 are not started.

## Related

- [AV15 session log](../session-logs/2026-09-17-av15-details-technical-parity-two-migration-bugfixes-and-deploy.md) — the prior parity pass this audit needs to reconcile against
- [av-content-model-audit.md](../planning/av-content-model-audit.md) — the original field inventory (C1)
- [endpoint-field-inventories-are-lower-bounds.md](endpoint-field-inventories-are-lower-bounds.md) — D7 JSON endpoints omit empty fields, so any inventory derived from sampled API responses is a lower bound, not a complete contract; relevant if this audit uses that approach
- [Spike 11 corpus audit](../spikes/spike-11-av-transcript-replication.md) — the audit methodology (real DB counts, not doc claims) this should follow
