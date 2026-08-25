# `field_legacy_nid` is mandatory on every content-entity migration

**Area:** migration / process / DX
**Raised during:** Session 2026-07-10 (post-1b.2, config-sync drift incident)
**Jira:** (add when available)
**Priority:** High — every future content migration (Texts, Sources, AV, Mandala Home) must follow this or repeat the incident below

## The convention

Any migration whose destination is a top-level content entity (`node`, `group`,
and any future bundle that gets its own identity — not paragraphs, not
relationship/membership entities) **must**:

1. Have a `field_legacy_nid` (integer, optional) field on the destination
   bundle, added via CMI.
2. Map it in the migration's `process` pipeline: `field_legacy_nid: nid`
   (or the appropriate source ID property if not `nid`).
3. Get verified post-import: row count in `{entity_type}__field_legacy_nid`
   must equal the migrated entity count, with 0 mismatches against the
   migration's own `migrate_map_*` table.

This is already established for Images (`shanti_image` nodes, `collection`/
`subcollection` groups). It must be repeated for every future site migration.

**Why this matters beyond audit trail:** `field_legacy_nid` is the source for
the planned `uid_legacy_s` kmassets Solr field (old→new uid compatibility
shim at cutover — see
[kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md)),
and it's the only durable D7→D11 identity mapping that survives rollback/
reimport (the `migrate_map_*` tables are migration-run-scoped and get wiped
on rollback).

## The incident (2026-07-10)

`field_legacy_nid: nid` was correctly added to the `mandala_migrations`
module's `config/install/*.yml` for `d7_images_shanti_image` (2026-07-07,
commit `a6c9e78`) and for the new `d7_images_collections` /
`d7_images_subcollections` migrations (1b.2, PR #27). But **`config/install`
is a module's default configuration — Drupal only reads it when the config
doesn't already exist in active storage** (fresh module install, or a
brand-new config object). It is never read by `drush cim`, which syncs from
`drupal/config/sync/` only.

Because `d7_images_shanti_image` was already active *before* the
`field_legacy_nid` line was added, the fix never reached that migration's
active config or `config/sync` on any already-provisioned machine — the
line only took effect on a machine where the module was freshly reinstalled
after the edit. Result: `shanti_image` migrations run on affected machines
produced 111,343 nodes with an **empty** `field_legacy_nid` field, silently.

The collections/subcollections migrations happened to pick up the fix
because those config objects were newly created during 1b.2 (so Drupal read
them straight from the already-corrected `config/install`) — but their
`uid: default_value: 1` fix (preventing Group's uid=0 auto-membership bug —
see [[project-1b2-group-collections]] Group 3.x API notes) had the identical
drift problem: correct in `config/install`, never exported to `config/sync`.
Discovered when a machine that had *not* freshly reinstalled the module
showed all 174 migrated groups owned by `uid=0`.

**Fixed in this session** (branch `fix/1b-legacy-nid-migration-config-sync`):
applied the `config/install` process definitions to active config, ran
`drush cex` to write them into `config/sync`, backfilled the empty
`shanti_image.field_legacy_nid` data, and corrected the 174 groups' `uid`
from 0 → 1. `drush config:status` confirmed no other config had drifted the
same way.

## Process guardrail (applies beyond migrations)

**Editing a module's `config/install/*.yml` after the module is already
enabled somewhere does nothing on its own.** It only takes effect for a
config object that doesn't yet exist in active storage. To actually deploy
the change:

1. Apply it to active config (`drush config:set`, or a one-off script that
   merges the corrected keys and saves).
2. Run `drush cex` and confirm `git diff` only touches the files you intended
   — `drush config:status` should be empty on a clean environment before you
   start, so you can trust the post-`cex` diff is exactly your change.
3. Commit the `config/sync/*.yml` change in the same PR as the
   `config/install` edit. Never let them land in separate commits/PRs.

This isn't migration-specific — it applies to any CMI config shipped in a
custom module's `config/install/`. Worth a general callout in module
development going forward, not just migrations.

## Checklist for future content migrations (Texts, Sources, AV, Mandala Home)

- [ ] `field_legacy_nid` field added to CMI for every top-level destination
      bundle
- [ ] `field_legacy_nid: nid` (or equivalent) mapped in the migration process
- [ ] Config change applied to active config **and** exported via `drush cex`
      in the same commit — verify with `drush config:status` before and after
- [ ] Post-import verification: row count + 0 mismatches against
      `migrate_map_*`
- [ ] **Node-JSON endpoint built for the site's asset bundle(s)** — the D11 equivalent of
      D7's per-site detail endpoint, following `mandala_node_api`'s `GET /api/json/{nid}`
      (Images, the proven pattern). **Handed to this checklist when [Spike 6](../spikes/spike-06-api-compatibility.md)
      closed 2026-08-21:** the spike proved the approach and documented every D7 response
      contract, but the controllers cannot be built before their site migrates. Each site's D7
      shape differs — see the endpoint table and the live-verification sections in that spike,
      and note especially:
      - **Sources** — augmentations are conditional by node type (`description` only when `body`
        is non-empty, `subcollections` only on `collection`, `parent` only on `subcollection`).
      - **Texts** — D7 collapses any page nid to its **book root**, and bakes rendered HTML from
        four `views_embed_view()` panes, so the D11 equivalent depends on those Views existing.
      - **AV** — a Services-module route returning an augmented raw node; **not** Solr-derived,
        and no ALB/server rewrite is needed. Blocked until a `video`-equivalent bundle exists.
      - Field inventories in the spike are **lower bounds, not complete contracts** — see
        [endpoint-field-inventories-are-lower-bounds.md](endpoint-field-inventories-are-lower-bounds.md).
- [ ] **Endpoint enforces node access — public-only by default.** Gate on the real
      `node->access('view')` check, as `mandala_node_api` does; no endpoint exempt, no per-site
      variation. See
      [d11-asset-endpoints-uniform-access-and-authenticated-fetch.md](d11-asset-endpoints-uniform-access-and-authenticated-fetch.md)
      — this is a **required property**, not a nice-to-have, and the D7 endpoints are **not** a
      safe model to copy here.
- [ ] **Decide whether the site needs an AJAX/embed equivalent** — D7 has one per site (six routes
      across the four sites), all returning HTML fragments rather than JSON. Only Texts'
      `node_embed` has an identified consumer (`legacy/texts.js`); the rest have none. Per Than
      (2026-08-21) these are low-importance and same-origin, so **the default answer is "no"** —
      but it should be a recorded decision per site, not an omission. Contracts documented in
      Spike 6's AJAX audit.
- [ ] `content_editor` granted full create/edit/delete on the site's new
      content types, on the same footing Images has for `shanti_image` — per
      [ADR 015](../adr/015-editorial-access-model-global-content-editor.md).
      For Group content types this is a synchronized `group.role.*-content_editor`
      config (`global_role: content_editor`) granting `create` / `update any` /
      `delete any group_node:<type> entity`. Omitting it leaves editors unable
      to manage that site's content.

## ⚠ Gap in the convention: `field_legacy_nid` alone is not unique across sites

**Raised 2026-08-25 (Yuji), during the [ADR 016](../adr/016-public-url-structure-single-host.md)
URL-structure work. Must be resolved BEFORE the next collection migrates.**

The convention above says to map `field_legacy_nid: nid`. That is correct but
insufficient, because **D7 nids are unique per domain, not globally.** Each legacy
site had its own database and its own nid sequence, so `node/1631632` on
`images.mandala.library.virginia.edu` and `node/1631632` on
`av.mandala.library.virginia.edu` are *different assets*.

`field.storage.node.field_legacy_nid` is a bare unsigned integer on `entity_type: node`,
shared by every bundle, with a single-column index and **no companion field recording the
source site**. So a lookup by `field_legacy_nid` alone can match several nodes once more
than one collection has migrated.

**This is latent, not broken, today** — Images is the only migrated collection, so every
value is currently unambiguous. It becomes a live defect the moment Texts, Sources or AV
lands, and it will not announce itself: the lookup returns *a* node, just not reliably the
right one.

Two consumers already depend on this mapping being unique:

1. **Legacy URL redirects** ([ADR 016](../adr/016-public-url-structure-single-host.md)
   decision 6) — resolving an old `/node/{d7nid}` to its D11 node.
2. **The `uid_legacy_s` kmassets compatibility shim**
   ([kmassets-uid-identity-across-migration.md](kmassets-uid-identity-across-migration.md)).
   Note the kmassets uid contract already solved this problem the same way — `images-1631632`
   vs `av-1631632` carry a **service prefix**, making service+nid the real composite key.
   That is precedent for the options below, not a coincidence.

**Two ways to close it — pick one before the next migration:**

- **Scope lookups by bundle**, mapping legacy site → candidate D11 bundles
  (`images…` → `shanti_image`; `av…` → the audio *and* video bundles). Sound, because one
  D7 site was one database with one nid sequence, so site-plus-nid stays unique even where
  a site carries two bundles. No schema change; the mapping lives in code.
- **Add a `field_legacy_site`** companion field, populated per migration, so the pair is
  self-describing and no consumer has to know the host→bundle mapping.

Retrofitting a discriminator across already-migrated rows is materially harder than
populating it in the migration that creates them — which is why this belongs in the
convention, before Texts/Sources/AV, rather than in whatever later work first trips over it.
