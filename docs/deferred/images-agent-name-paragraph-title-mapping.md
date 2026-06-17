# Migration mapping: D7 image_agent node title → field_agent_name

**Area:** migration / Images / paragraphs
**Raised during:** Sprint 1 (Step 1a — content-model build session, 2026-06-17)
**Jira:** (add when available)
**Priority:** Medium — must be resolved before the 1a.7 migration is written
**Owner:** mob (1a.7 is the pattern-setting migration)

## Context

The Images audit collapsed the D7 satellite nodes to **Paragraphs** (see
[Images Content-Model Audit](../planning/images-content-model-audit.md)). One
consequence surfaced while building the content model: in D7, an `image_agent`'s
**name was the node title**, not a field — the bundle's five `field_base` fields
are `field_agent_role`, `field_agent_place`, `field_agent_dates`,
`field_agent_dates_approx`, `field_agent_notes`. **Paragraphs have no title.**

To avoid silently dropping the agent's name on migration, a new field
**`field_agent_name`** (string, required) was added to the `image_agent`
paragraph type. It has **no D7 `field_base` counterpart** — it exists solely to
carry the legacy node title. Approved by Yuji 2026-06-17.

## What the 1a.7 migration must do

- Map the source `image_agent` **node title** → `field_agent_name` (not a field
  source). Required, so every migrated agent must have a non-empty title; spot-check
  the production dump for empty/auto-generated agent titles before the run.
- The same node→paragraph "title has no home" question applies to
  `image_descriptions` and `external_classification`. The build **did not** add a
  title field to those, on the assumption their D7 titles are auto-generated /
  non-meaningful (description content lives in `field_description` / `field_summary`;
  classification identity is scheme + external id). **Verify this against the dump**
  before the migration — if those titles carry real content, add equivalent fields.

## Relates to

- Sprint 1 task 1a.2 (paragraph types) and 1a.7 (migration)
- ADR 010 (remodeling permitted) / ADR 008 (faithful-migration floor — the title
  must round-trip, hence this field)
