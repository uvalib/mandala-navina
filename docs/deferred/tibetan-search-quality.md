# Tibetan Search Quality (mixed-script, transliteration folding, analysis)

**Area:** solr / search / i18n
**Raised during:** Session 2026-06-15 (roadmap planning)
**Jira:** (add when available)
**Priority:** Low — explicitly post-MVP
**Owner:** (unassigned)

## Context

The MVP scope is *migrate, don't improve*. Faithful migration of Tibetan content —
both Unicode script and Latin transliteration — is in scope. **Improving how that
content is searched is not.** This note records the deferred search-quality work so
it is not lost and is not accidentally pulled into the migration effort.

This sits behind [ADR 004](../adr/004-solr-source-of-truth.md): treat the existing
Solr infrastructure as source of truth and defer the Solr refactor. See the
[migration roadmap](../roadmap.md) scope-boundary table.

## The distinction that matters

- **In scope (MVP):** content round-trips faithfully (Tibetan in = identical Tibetan
  out; transliteration diacritics preserved at the correct Unicode normalization
  form) and is retrievable via the *existing* query patterns.
- **Out of scope (this note):** improving search *quality* over Tibetan-bearing
  content.

"Docs land in Solr and are retrievable" is the MVP bar. "Tibetan search works well"
is this deferred work.

## Known complications to address later

1. **Mixed Latin + Tibetan script in a single field.** How should a field containing
   both scripts be analyzed and queried? Current behavior relies on workarounds.
2. **Transliteration diacritic folding.** EWTS/Wylie uses Latin letters plus
   diacritics (ḍ, ṇ, ś, ṭ, …). Should a search for `nalanda` match `nālandā`?
   Folding/normalization at query and index time is unaddressed.
3. **Tibetan tokenization/analysis.** Tibetan script has no spaces between words in
   the Western sense; the existing index does not apply Tibetan-aware tokenization.
4. **Carryover from Spike 2 findings.** `language_field` must stay disabled or KMaps
   taxonomy silently drops; `title`/`names_txt` are string fields requiring prefix
   wildcards. Any future analysis work must account for these existing constraints.

## Why deferred

- Workarounds exist and are acceptable for MVP.
- Improving analysis means changing the Solr schema/analysis chain — exactly the
  refactor ADR 004 defers.
- Search quality is a *quality* improvement, not a *migration faithfulness*
  requirement.

## When to revisit

After the five sites are migrated and the consolidated index is stable — as a
dedicated search-quality initiative, likely requiring its own spike to evaluate
Tibetan-aware Solr analysis options against the existing infrastructure.
