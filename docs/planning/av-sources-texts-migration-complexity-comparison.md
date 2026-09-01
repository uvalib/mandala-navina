# AV / Sources / Texts: Migration Complexity Comparison

**Audience:** Developers, sequencing/resourcing decisions for the Phase 2/3 migration
tracks
**Date:** 2026-09-01
**Source:** Synthesis across the three Sprint 2 Workstream C content-model audits —
[AV](av-content-model-audit.md), [Sources](sources-content-model-audit.md),
[Texts](texts-content-model-audit.md) — no new research, no migration code.
**Relates to:** [ADR 009](../adr/009-migration-sequencing-strategy.md) (Texts/Sources
fork after Images, AV sequenced last as "hardest, last" — this doc's result validates
that call rather than revising it), [Sprint 2 backlog](../sprints/sprint-02-theme-images-ui-and-endpoint-access.md#workstream-c--content-model-audits-av-sources-texts--audit-only)

> **Scope.** This is a comparative synthesis, not a new modeling decision. It scores
> the three sites against each other using facts already established by their
> individual audits, to inform sequencing/resourcing conversations — it does not
> commit anyone to a migration approach for any one site.

---

## Why this comparison

With all three Sprint 2 Workstream C audits complete and each independently
data-validated against a real production dump, a natural next question is how the
three sites actually compare in migration difficulty — not just what each one's shape
is individually. This doc answers that with an explicit, transparent scoring rubric
rather than a gut call, so the reasoning (and any disagreement with the weights) is
visible and revisable.

## Method

Five dimensions, each scored 1 (simplest) to 5 (hardest) per site, based on facts
already recorded in the three audits. The final score is an unweighted average of the
five — deliberately simple so the reasoning stays legible; if the group wants to weight
any dimension more heavily (e.g. functional fidelity risk mattering more than access
complexity), the table below has everything needed to recompute that.

One deliberately excluded factor: the collection-membership storage gap
([`og-collection-ref-storage-empty-use-og-membership.md`](../deferred/og-collection-ref-storage-empty-use-og-membership.md))
is **not scored** here — it affects all three sites (and Images) identically and is
already a single shared fix, so it doesn't differentiate them.

## Scoring

| Dimension | AV | Sources | Texts | Basis |
|---|---:|---:|---:|---|
| **Storage/schema shape** | 3 | 5 | 2 | AV's PBCore/workflow metadata is `field_collection` — non-standard but Migrate has an established source-plugin pattern for it. Sources' `biblio` is a genuine **hybrid**: ~50 flat legacy columns in a custom table need a bespoke, non-standard source plugin, *plus* a normal Field-API layer on top for KMaps/Zotero/access — two different migration strategies for one content type. Texts' `book` is D7 **core's own Book content type** with ordinary Field-API fields — the most standard shape of the three. |
| **Entity graph complexity** | 4 | 3 | 2 | AV nests `field_collection` two levels deep (`field_workflow` → `field_transcript_workflow_notes`/`field_catalog_workflow_notes`). Sources has real many-to-many relations (contributors, keywords) — a well-understood pattern, and the contributor-dedup graph is used on only 5 of 36,151 records, so it likely flattens cheaply. Texts' page hierarchy is core `bid`/`plid`/`weight` — a solved problem, independent of any custom entity graph. |
| **Functional/rendering fidelity risk** | 4 | 4 | 3 | AV: Kaltura is an externally hosted service with an **unresolved re-provisioning question** (Spike 7, still ○ Pending) — not just a data-shape question but an operational/account dependency. Sources: D7's PHP-plugin `cse` citation style vs. bibcite's CSL-only rendering is **confirmed not byte-identical** (Spike 5) — a stakeholder acceptability call, not purely an engineering task. Texts: the cross-page footnote pattern is structurally the most novel requirement of the three, but it is the **only one of the three risks in this row that is already fully spiked and de-risked** (Spike 4b, CLOSED, working prototype, team sign-off merged) — real implementation work remains, but zero open technical uncertainty. |
| **Access-model complexity** | 4 | 1 | 1 | AV alone carries **two custom OG grant realms** (`group_access_uva_member`, `mb_collection_admin`) beyond the baseline OG/Visibility pattern every site shares. Sources and Texts both use the plain shared pattern with no extra realms found in either audit. |
| **Unresolved unknowns** | 4 | 3 | 2 | AV has three distinct unchased items: 68 `MISSING_TYPE` corrupted-bundle nodes, the uninventoried `transcripts_apachesolr_transcript` timing-table schema, and the Kaltura profile/player ID re-validation (`METADATA_PROFILE_ID`, `MB_MAIN_PLAYER_ID`). Sources has two smaller ones: 17.7% of `biblio` nodes have no collection membership at all (unexplained), and `group_content_access` values `1`/`2` (19 rows total) have unconfirmed semantics. Texts has the fewest/mildest: three overlapping, unreconciled language fields, and one unchased vestigial field-base pair. |
| **Total (average of the five)** | **3.8 / 5** | **3.2 / 5** | **2.0 / 5** | — |

**Result: AV is hardest, Sources is second, Texts is easiest — roughly half AV's
difficulty by this rubric.**

## Reading the result

- **This validates ADR 009's existing sequencing** (Texts/Sources fork after Images,
  AV last as "hardest, last") rather than revising it — AV scoring highest isn't a new
  finding, it's evidence behind a call the team had already made on instinct.
- **Sources' hardest single dimension (schema shape) is a different kind of hard than
  AV's overall profile.** A hybrid flat-table-plus-fields shape is architecturally
  unusual but bounded: one bespoke source plugin, then a known Migrate pattern for
  everything else. AV's total is higher not because any one dimension is off the
  charts, but because it **compounds** four separate real risks (nested entities,
  external-service dependency, extra access realms, more open unknowns) rather than
  concentrating risk in one place.
- **Volume is a separate axis, not folded into this score.** Sources has by far the
  most primary-content rows (25,627 `biblio` nodes vs. AV's 11,583 audio/video vs.
  Texts' 7,633 books) — this affects migration runtime and QA sampling effort, not
  architectural difficulty, and is worth factoring in separately when scheduling.
- **This is a snapshot, not a commitment.** It reflects what each site's own audit
  established as of 2026-09-01; if any open question above gets resolved in a way that
  changes its severity (e.g. Spike 7 lands and Kaltura re-provisioning turns out
  trivial), this comparison should be revisited rather than treated as fixed.

## What this doc does NOT establish

- No migration approach, timeline, or resourcing decision for any site — this is
  comparative context for those conversations, not a plan.
- No new facts beyond what the three source audits already established — every score
  traces back to a specific finding in one of those docs.
- No weighting judgment beyond a simple unweighted average — if the group wants to
  weight, say, functional fidelity risk above schema shape, or vice versa, the
  per-dimension scores above support recomputing without new research.
