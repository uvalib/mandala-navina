# 1a.8 Open Decisions — next-session prep

**Created:** 2026-06-29 (end of the 1a.8 doc-builder session, before a 1–2 day break;
pickup ~2026-06-30 / 07-01).
**Purpose:** the single resume point for Sprint 1 task **1a.8 (Solr write/sync)**.
Each decision links to its detailed deferred note; this page is the queue + status.

## Where 1a.8 stands

- ✅ **Doc-builder built and validated.** `mandala_kmassets_sync` produces the flat
  kmassets "golden doc"; diffed against the 3 golden fixtures → **114 fields match,
  0 builder-logic defects** (PR #18). The builder is the bulk of 1a.8 and is done.
- ⏳ **Sinks not started** — direct-to-master + file→S3 (gated by decisions B1/B2).
- ⏳ **Track A (Dave coordination)** — Yuji owns the comms; gates B2.

Nothing below is a builder defect. Every item is either an external dependency,
a migration-data gap, or a deliberate fidelity choice.

---

## Blocking decisions

These gate completing 1a.8 (writing migrated content to the real index, and faithful
KMaps faceting). Resolve before/alongside the sinks.

### B1 — kmassets `uid` identity across the D7→D11 nid reassignment  ·  High
**Owner:** architecture (Yuji) · **Blocks:** any write to the existing index ·
**Detail:** [`deferred/kmassets-uid-identity-across-migration`](../deferred/kmassets-uid-identity-across-migration.md)

D11 reassigns node IDs (`1028396` → `5`), so `uid = images-{nid}` no longer matches
the ~111k live docs or external references (reindeer_x, React app). Single-site nid
collisions across the five former sites make blanket nid-preservation impossible.

- **Options:** (a) carry the D7 nid on the node (e.g. `field_legacy_nid` / via
  `field_other_ids`) and compose `id`/`uid` from it — *recommended*; (b) preserve
  nids per-asset-type where ranges don't collide — fragile; (c) accept new identity +
  full reindex + consumer migration — high blast radius.
- **This is the most consequential decision** — until it's made, the write path can't
  target the existing index.

### B2 — Direct writer to the Solr master: allowed, and how does Drupal reach it?  ·  High
**Owner:** Dave (Cloud Infra) · **Blocks:** the direct-to-master sink ·
**Detail:** [`project-staging-solr-write-path` memory] + doc contract §3

The staging master (`mandala-solr-master-staging-private:8080/solr`) takes plain HTTP
gated by security-group + source-IP — **no credentials**. So this is a *network-access*
question, not a creds question.

- **Options:** (a) open the SG path from the Drupal subnet → master `:8080` so the
  direct sink works; (b) route everything through the S3 → batch pipeline and drop the
  direct sink. (a) keeps the fast diagnostic loop; (b) is simpler but loses incremental
  immediacy.
- Already confirmed from `terraform-infrastructure`: batch sizing `BLOCK_COUNT=25`,
  the `-unprocessed`/`-email-notify` failure path, and the existing `json-transform`
  stage — so only the access question is truly open. (Yuji handling the Dave comms.)

### B3 — kmapid ancestor-ID resolution  ·  High
**Owner:** architecture + migration · **Blocks:** faithful `kmapid` faceting/discovery ·
**Detail:** [`deferred/kmassets-kmapid-ancestor-id-resolution`](../deferred/kmassets-kmapid-ancestor-id-resolution.md)

The migrated KMaps `path` column holds breadcrumb **names** (`{{Earth}}{{Asia}}…`),
not numeric ancestor **IDs**, so the builder's ancestor expansion (`kmapid`,
`kmapid_is`) is starved. Directly-tagged `kmapid_strict` is correct.

- **Options:** (a) resolve at migration time — populate `path` with `ancestor_id_path`
  via the existing `KmapsPathResolver` — *recommended, no build-time Solr dependency*;
  (b) resolve at build time (live kmterms-Solr call per term, cacheable but slower).
- **Plus:** fix KMaps **field ordering** so `kmapid_strict`/`_strict_ss`/idfacets match
  D7's order (goldens are places → subjects → terms).

---

## Non-blocking decisions

The builder works without these; decide before the write path is finalized.

| # | Decision | Pri | Detail |
|---|----------|-----|--------|
| N1 | **Description text-format fidelity** — match D7's auto-`<p>` filtering vs. accept the markup diff (text content already matches). | Med | [deferred note](../deferred/images-description-text-format-fidelity.md) |
| N2 | **`url_html`/`url_ajax`/`url_json` single-site URL scheme** — currently config-templated to the D7 multisite shape; the canonical D11 scheme is undecided (affects React API-compat). | Med | doc contract §7 |
| N3 | **`projects_ss` producer field** — trace the D7 source of "tibet" first, then map-or-drop. | Med | [deferred note](../deferred/images-projects-ss-producer-field.md) |
| N4 | **IIIF thumb `^!` vs `!`** — bug-for-bug (edit shared `IiifUrlBuilder`) vs. canonical. | Low | [deferred note](../deferred/images-iiif-thumb-size-format.md) |

---

## Suggested next-session sequence

1. **Land B1 + B3 decisions** (architecture) — they shape what the builder emits and
   whether re-migration is needed.
2. **B2 outcome from Dave** → choose the sink topology.
3. **Build the sinks:** direct-to-master first (fast validation loop) if B2 allows,
   else file→S3 first.
4. Fold in N1–N4 as the write path firms up.
5. Then **1a.9** (rollback story) closes Step 1a → **1b** auth increment.

Reference: PR #18, [doc contract](kmasset-solr-doc-contract.md),
[deferred index](../deferred/README.md), `project-1a8-doc-builder-status` memory.
