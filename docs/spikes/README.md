# Spikes

| # | Title | Lead | Mode | Status |
|---|-------|------|------|--------|
| [Spike 1](spike-01-kmaps-field.md) | KMaps field type on Drupal 11 | Yuji | — | ✓ Proven |
| [Spike 2](spike-02-solr-integration.md) | Solr index read-only integration | Yuji | Team candidate | ✓ Proven |
| [Spike 3](spike-03-group-collections.md) | Group module collections architecture | Than | Team candidate | Pending |
| [Spike 4](spike-04-ckeditor5-footnotes.md) | CKEditor 5 footnotes + Tibetan Unicode | Than | Individual | Pending |
| [Spike 5](spike-05-bibcite-sources.md) | bibcite for Sources site | Xiaoming | Individual | Pending |
| [Spike 6](spike-06-api-compatibility.md) | API compatibility for React application | Than | Team candidate | Pending |

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
