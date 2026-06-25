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
   the Western sense; the existing index does not apply *Tibetan-aware* tokenization
   (it falls back to generic ICU segmentation). See the schema-grounded detail in
   "How tokenization actually works today" below.
4. **Carryover from Spike 2 findings.** `language_field` must stay disabled or KMaps
   taxonomy silently drops; `title`/`names_txt` are string fields requiring prefix
   wildcards. Any future analysis work must account for these existing constraints.

## How tokenization actually works today (schema-grounded, 2026-06-25)

Traced in `solr-shanti-configsets/solr7.3.x/production/kmassets/conf/schema.xml`
(deployed Solr 7.7.3). **Byte-identical in the `solr9.10.11` configset**, so none of
this changes on that migration path — it is a standing state, not a version artifact.
Recorded here for the eventual search-quality spike; **no change is proposed now**
(ADR 004 — existing Solr is source of truth).

There are **two parallel Tibetan field families** with *different* analysis:

- **Legacy `*_tibt` → `text_kw`** (schema ~L148 / type ~L1208): chain is
  `MappingCharFilter(mapping-Tibetan.txt)` → **`KeywordTokenizerFactory`** →
  `ASCIIFoldingFilter(preserveOriginal)` → `LowerCaseFilter`. Because the tokenizer
  is `KeywordTokenizer`, **`*_tibt` fields are not tokenized at all** — the whole
  value is a single token (whole-value / prefix matching only). This is the same
  family as `title` / `names_txt`, and is *why* consumers must use prefix wildcards
  (`title:Buddhism*`) — cross-references the Spike 2 string-field pitfall (item 4).
- **Newer `*_bo` → `text_bo`** (schema ~L152 / type ~L788): chain is
  `MappingCharFilter(mapping-Tibetan.txt)` → **`ICUTokenizerFactory`**. These fields
  *are* tokenized — into generic Unicode (UAX-29) syllable-ish units broken at the
  tsek (`་`). No lowercase/stemming/stopwords (n/a for Tibetan script).

**The key finding — the purpose-built Tibetan tokenizer is disabled.** In `text_bo`,
`io.bdrc.lucene.bo.TibSyllableTokenizerFactory` (BDRC `lucene-bo`, proper Tibetan
syllable/word segmentation) is **present but commented out** (schema L791), with the
generic `ICUTokenizerFactory` as the live fallback. So "Tibetan-aware tokenization"
is wired-but-off. Re-enabling it requires the `lucene-bo` jar on the Solr classpath —
an infra/classpath change, deferred with the rest of this note.

**Shared normalization — `mapping-Tibetan.txt`.** Both types use this charFilter; the
entire file is one rule: `"།" => "་"` — fold the **shad** (`།`, phrase
terminator) into a **tsek** (`་`, syllable dot), so segmentation is uniform whether
or not the input ended in a shad. No NFC or other normalization happens here.

**Copy-field mixing.** `titles_bo` (`text_bo`) aggregates from *both* families —
`title_bo` / `caption_bo` (already `*_bo`) **and** `title_alt_tibt` /
`title_corpus_tibt` / `title_short_tibt` (legacy `*_tibt`). So keyword-authored
`*_tibt` values get ICU-re-analyzed once they land in the `*_bo` aggregate; the two
families are not cleanly separated.

**1a.8 writer implication (fidelity only, no schema change):** all analysis is
server-side schema.xml. The D11 writer just emits field *values*; its obligation is
(a) NFC-correct Tibetan input and (b) writing each value to the *correct suffix* —
`*_bo` (tokenized/searchable) vs `*_tibt` (keyword/exact). Choosing the wrong suffix
silently changes search behavior — same bug class as the `language_field` and
string-field pitfalls.

### Latent decisions to settle in the eventual spike
1. **Enable the BDRC `TibSyllableTokenizerFactory`?** (proper Tibetan segmentation,
   needs the `lucene-bo` jar) vs. stay on generic ICU.
2. **Retire the legacy `*_tibt` keyword family**, or must the writer keep populating
   both `*_tibt` and `*_bo` indefinitely?

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
