# D7 collection membership must be read from `og_membership`, not `field_data_field_og_collection_ref` — confirmed on 3 of 3 sites checked; Images assumed the same

**Area:** migration / OG / collections — cross-site
**Raised during:** Sprint 2 Workstream C (content-model audits), Session 2026-09-01
**Jira:** (add when available)
**Priority:** High — will silently break any migration built the obvious way

**Status (2026-09-01, Than):** `field_og_collection_ref` is a **vestigial field left
over from an OG module version upgrade** — not a data-corruption bug, an artifact of
how OG itself evolved (an older version stored membership on the field; the version
this platform runs on stores it in `og_membership` instead, and the old field was never
cleaned up). Treat the field as **irrelevant on every site**, including Images — assume
Images has the identical empty-field/`og_membership`-authoritative shape without
re-verifying it site-by-site, the same way it's now confirmed on AV/Sources/Texts.
`og_membership` is the correct and only source to read for collection membership,
platform-wide.

## The problem

Every D7 site checked so far attaches a real, `field_sql_storage`-backed
`field_og_collection_ref` field to its primary content type(s), to record membership in
a `collection`/`subcollection` (via the shared `shanti_collections` module). The
natural migration approach is to read that field's value table
(`field_data_field_og_collection_ref`). **That table is empty (0 rows) in production on
every site checked:**

| Site | Bundles checked | `field_data_field_og_collection_ref` rows | Real membership count (via `og_membership`) |
|---|---|---:|---:|
| AV | `audio`/`video` | 0 | 11,587 (`field_og_collection_ref`) + 85 (`field_og_parent_collection_ref`) |
| Sources | `biblio`/`asset_link` | 0 | 26,710 (`field_og_collection_ref`) + 52 (`field_og_parent_collection_ref`) |
| Texts | `book`/`asset_link` | 0 | 7,419 (`field_og_collection_ref`) + 57 (`field_og_parent_collection_ref`) |

Images was not re-checked for this specific gap (its own audit predates this finding).
**Than's call: assume Images has the same shape without re-verifying** — the field is a
known vestige of an OG version upgrade (see Status above), not a per-site anomaly.

The real membership relationship lives entirely in the **`og_membership`** table,
keyed by `field_name` (a label matching the field's machine name, not a mirror of its
storage). A migration source plugin that reads only the Field API value table — the
obvious first approach — would see **zero collection memberships** for every one of
these sites and nobody would notice until content appeared un-collected in D11.

**Independent corroborating evidence, found in the Texts codebase itself (not just data
observation):** `shanti_texts_splitter`'s page-generation code explicitly resets
`field_og_collection_ref` to empty on every machine-generated child page, with the code
comment *"Without reseting collection for pages, site crashes"* — suggesting even the
D7 codebase's own authors treated this field's storage as unreliable/unnecessary,
independent of any migration concern.

## Where this was found

- [`av-content-model-audit.md`](../planning/av-content-model-audit.md) (Workstream C1)
- [`sources-content-model-audit.md`](../planning/sources-content-model-audit.md)
  (Workstream C2) — found first, here
- [`texts-content-model-audit.md`](../planning/texts-content-model-audit.md)
  (Workstream C3)

## What's needed

1. **Build the D11 collection-membership migration source against `og_membership`**,
   filtered by `entity_type='node'` and `field_name='field_og_collection_ref'` (or
   `field_og_parent_collection_ref` for subcollection→collection nesting) — not against
   any `field_data_field_og_collection_ref`-style table, on any site.
2. ~~Verify Images too.~~ **Decided (Than, 2026-09-01): not needed** — assume parity,
   it's a known vestigial OG-upgrade artifact, not a site-specific risk.
3. Treat this as **one shared migration-tooling fix**, not four separate per-site
   workarounds — the root cause and remedy are identical across every site.
4. Fold into whatever OG → D11 Group mapping design work covers each site's access
   model (already an open item on each site's own audit).
