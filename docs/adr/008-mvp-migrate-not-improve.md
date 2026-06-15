# ADR 008: MVP scope is migrate, not improve

**Status:** Accepted  
**Date:** 2026-06-15  
**Deciders:** Yuji Shinozaki (Lead Architect), Xiaoming Wang, Carla Arton, Dave Goldstein, David Germano, Than Grove, Andres Montano  
**Relates to:** ADR 004 (Solr as source of truth)

## Context

The Drupal 11 rebuild consolidates five legacy D7 sites (AV, Images, Sources, Texts,
Mandala Home) into a single Drupal instance. The platform carries several long-standing
quality gaps that the team would like to improve — most visibly the quality of search
over Tibetan-bearing content (mixed Latin/Tibetan fields, transliteration diacritic
folding, Tibetan-aware tokenization).

Improving those things means changing the Solr schema and analysis chain — precisely
the refactor that [ADR 004](004-solr-source-of-truth.md) defers by treating the
existing Solr infrastructure as source of truth. Attempting migration and quality
improvement at the same time would couple an already-large consolidation to an
open-ended search-quality initiative, blurring success criteria and inflating risk.

The team needs an explicit, shared guardrail for what the MVP is — and, just as
importantly, what it is not — so that quality work is not accidentally pulled into
the migration effort.

## Decision

**The MVP goal is faithful migration, not improvement.** The five sites are
consolidated into one D11 instance with content migrated faithfully; quality
improvements are explicitly deferred.

The scope boundary:

| In scope (MVP) | Out of scope (deferred) |
|---|---|
| Faithful migration: Tibetan in = identical Tibetan out | Improving Tibetan **search** quality |
| Transliteration + diacritics preserved at the correct Unicode normalization form | Mixed Latin/Tibetan field search handling |
| Content indexes and is **retrievable via existing query patterns** | Transliteration diacritic folding; Tibetan tokenization/analysis |

The operative distinction: **"docs land in Solr and are retrievable via existing query
patterns" is the MVP bar; "Tibetan search works well" is deferred work.** Any pilot or
migration success criterion touching Solr must be written narrowly to that bar.

## Consequences

- Faithful migration *is* a hard requirement: Tibetan Unicode script must round-trip
  identically, and transliteration diacritics must be preserved at the correct Unicode
  normalization form (NFC/NFD fidelity through Migrate API → MySQL collation → Solr).
  This is subtle and silent and must be explicitly verified — it is migration
  faithfulness, not quality improvement.
- The Solr schema and analysis chain are not changed during the MVP, consistent with
  ADR 004.
- Deferred search-quality work is tracked in
  [deferred/tibetan-search-quality.md](../deferred/tibetan-search-quality.md) and
  revisited only after the five sites are migrated and the consolidated index is
  stable, as its own initiative (likely its own spike).
- Existing query-layer workarounds remain in force during the MVP — notably the
  Spike 2 constraints that `language_field` stays disabled (or KMaps taxonomy silently
  drops) and that `title`/`names_txt` are string fields requiring prefix wildcards. Any
  later analysis work must account for these.
