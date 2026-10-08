# Audit AV node fields D7 vs D11, starting from the missing technical-metadata fields

**Area:** migration / AV / content model / data fidelity
**Raised during:** Group session 2026-10-02 (Than)
**Jira:** (add when available)
**Priority:** High — raised as a direct observation against the live site, not a hypothetical

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

## What closes it

1. **First step (Than's proposal):** rework the AV node **edit form** display
   (`core.entity_form_display.node.{audio,video}.default`) so every field currently `hidden` is
   shown in the `content` region, organized/grouped to match the old D7 site's form layout. This
   doesn't touch the view/render side — it's about what an editor sees when editing a node — but
   makes the rest of the audit tractable by inspection.
2. Enumerate D7's full technical-metadata field set for AV (PBCore + any AV-specific fields),
   same approach as the Images field-by-field work.
3. For each field: confirm it migrated (data present on D11 nodes), confirm it's wired into the
   current *view* display config (`core.entity_view_display.node.{audio,video}.*`), and confirm
   it renders — three separate failure modes, don't assume "migrated" implies "visible," and
   don't conflate the form-display fix in step 1 with the view-display question here.
4. Compare against what AV15 (PR #222) actually covered, to explain the discrepancy rather than
   re-doing undifferentiated work.
5. Record findings here or split into per-field deferred notes if multiple distinct gaps turn up
   (matching the existing `av4-*`/`av15-*` note pattern).

**Owner:** Than. **Go-ahead given by the team, 2026-10-07.** Step 1 (the edit-form display) is in progress as PR #284, which Than closed on 2026-10-07 as not yet ready and is reworking; steps 2–5 are not started.

## Related

- [AV15 session log](../session-logs/2026-09-17-av15-details-technical-parity-two-migration-bugfixes-and-deploy.md) — the prior parity pass this audit needs to reconcile against
- [av-content-model-audit.md](../planning/av-content-model-audit.md) — the original field inventory (C1)
- [endpoint-field-inventories-are-lower-bounds.md](endpoint-field-inventories-are-lower-bounds.md) — D7 JSON endpoints omit empty fields, so any inventory derived from sampled API responses is a lower bound, not a complete contract; relevant if this audit uses that approach
- [Spike 11 corpus audit](../spikes/spike-11-av-transcript-replication.md) — the audit methodology (real DB counts, not doc claims) this should follow
