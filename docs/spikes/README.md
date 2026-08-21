# Spikes

| # | Title | Lead | Mode | Status |
|---|-------|------|------|--------|
| [Spike 1](spike-01-kmaps-field.md) | KMaps field type on Drupal 11 | Yuji | — | ● Proven |
| [Spike 2](spike-02-solr-integration.md) | Solr index read-only integration | Yuji | Team candidate | ● Proven |
| [Spike 3](spike-03-group-collections.md) | Group module collections architecture | Than | Team candidate | ● Proven |
| [Spike 4](spike-04-ckeditor5-footnotes.md) | ~~CKEditor 5 footnotes + Tibetan Unicode~~ | Than | Individual | ◇ Split (2026-07-10) — see 4a/4b |
| [Spike 4a](spike-04a-tibetan-unicode-roundtrip.md) | Tibetan Unicode round-trip (NFC/NFD fidelity, cross-cutting) | Than | Individual | ● Proven (2026-07-22) |
| [Spike 4b](spike-04b-ckeditor5-footnotes.md) | CKEditor 5 footnotes (Texts-specific) | Than | Individual | ● Complete (2026-08-07) — Option 1+3, feasibility proven + prototype; production transform tracked for the Texts migration ([deferred note](../deferred/texts-footnotes-production-transform.md)) |
| [Spike 5](spike-05-bibcite-sources.md) | bibcite for Sources site | Than | Individual | ○ Pending |
| [Spike 6](spike-06-api-compatibility.md) | API compatibility for React application | Than | Team candidate | ● **Proven (closed 2026-08-21)** — Option A (generalized same-origin proxy) decided 2026-08-12 and browser-verified; D11 endpoint live for Images; all 8 D7 response formats live-verified (AJAX side is 6 routes, not 4). Per-site controllers handed to the [per-site migration checklist](../deferred/migration-legacy-nid-required-convention.md); 3 deferred notes carried forward |
| [Spike 7](spike-07-kaltura-av-integration.md) | Kaltura AV integration on Drupal 11 | — | Individual | ○ Pending |
| [Spike 8](spike-08-reindeer-x-consolidation.md) | reindeer_x consolidation as managed sync subsystem | Yuji | Individual | ◐ Partial |
| [Spike 9](spike-09-docs-hosting-confluence.md) | Documentation hosting & access control (mkdocs → public + Confluence) | Yuji | Individual | ○ Pending (low priority) — **partially superseded 2026-08-13**: two private docs repos now exist (`uvalib/mandala-legacy-docs`, `uvalib/mandala-navina-docs`), scoped to sensitive material only; submodule + Confluence sync still open |
| [Spike 10](spike-10-saml-oauth2-coexistence.md) | SAML + OAuth2 coexistence on D11 (`simplesamlphp_auth` + `simple_oauth`) | Yuji | Individual | ● Proven — **1b.1 unblocked** (2026-07-09) |
| [Spike 11](spike-11-av-transcript-replication.md) | AV time-synced transcript replication on D11 (data model + sync + search + migration) | Than | Individual | ○ Pending — backlog (AV / Phase 4); relates to Spikes 7, 4a, 6 |

**Status key:** ● Proven · ◐ Partial / in progress · ○ Pending · ◇ Split into sub-spikes

See [docs/planning/spikes-plan.md](../planning/spikes-plan.md) for full spike definitions and pass/fail criteria.

---

Each file records the outcome of a time-boxed technical spike. The purpose is to
document what was proven (or disproven), what the demo shows, and what the spike
explicitly does *not* establish — so downstream decisions are made on accurate evidence.

## Template

```
# Spike N: Title
**Status:** Proven / Not proven / Partial
**Date:** YYYY-MM
**Branch/commit:** (link or SHA)

## Theory
One sentence: what we were trying to prove.

## Demo
How to see the result. URLs, drush commands, screenshots if needed.

## Findings
What was demonstrated. Be precise about scope.

## What this does NOT establish
Explicit statement of boundaries — what future work still needs to prove.

## Deferred notes
Links to docs/deferred/ items generated during this spike.
```
