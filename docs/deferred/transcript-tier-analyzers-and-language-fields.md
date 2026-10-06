# Transcript tier analyzers, and separate language fields

**Area:** Solr / transcripts / multilingual search
**Raised during:** Spike 11, schema draft 2026-10-02; deferred the same day (Yuji)
**Jira:** (add when available)
**Priority:** Low. **Question 1 (analyzer choice) DECIDED and BUILT 2026-10-06** (Than),
**in two passes**: English first, then Chinese/Nepali/Dzongkha/Wylie the same day.
**Question 2 (separate per-language fields for the ~11 remaining smaller tiers) DECIDED
2026-10-07 (Than): not doing it.** None of those tiers have dedicated Lucene/Solr
language support to gain from a split, so there's nothing to build even if split out.
This item is now fully resolved.

## What is deferred

The `mandala-av-transcripts` schema (`solr/mandala-av-transcripts/conf/schema.xml`) indexes the
`ts_*` language tiers (everything except `content_bod` and `dzo_bod`, about 145k of the D7 tier
values: English 71,873, Wylie 30,497, Nepali 13,625, Chinese 5,191, gloss 3,491, plus about ten
smaller languages) with the **ICU tokenizer plus ICU folding**. This is the **default for now**,
not a settled choice. `content_bod` and `dzo_bod` keep D7's ICU tokenizer unchanged.

Compared with D7 (read from the `mandala-av` schema, not tested live), the default means:
- **No stemming:** `chant` no longer matches `chanting`. Confirmed in a throwaway Solr 7.7.3.
- **No English stop words, no synonyms, no Latin-1 accent mapping.**
- **Better tokenizing** of languages written without spaces between words (Chinese) and of
  Nepali and Wylie than D7's whitespace tokenizer, which likely treated a run of Chinese as one
  token (not tested live).

Two separate things are deferred:
1. **Which analyzer each tier should use.** Options discussed: exact D7 parity (copy D7's
   analyzer and its stop word, protected word, synonym and accent-mapping files); ICU for all
   `ts_*` tiers (the current default); or a mix, with English keeping the legacy analyzer and
   the other languages on ICU (an explicit field overrides the `ts_*` pattern, so this is cheap).
2. **Breaking the tiers into separate language-specific fields**, each with an analyzer suited to
   its language, instead of one analyzer per naming pattern. Yuji's view is that they should
   eventually be separate language fields; the discussion is deferred.

## Decision and build (2026-10-06, Than)

Answers to the closing questions above:
1. **Yes, users rely on English stemming.** "chant" needs to match "chanting".
2. **Yes, other-language and Wylie transcripts are actively searched.**
3. Synonyms file usage: **still unconfirmed** — not answered, not blocking.

Hit-count comparison against real `d7_av` data (the note's old step 2) was explicitly
**skipped** — not needed to act on the answers above.

**Built the "mix" option** the note already named as the cheap path: `schema.xml` keeps
the ICU-tokenizer-plus-folding default (`text_tier`) for every `ts_*` tier **except**
English, and adds an explicit `ts_content_eng` field override (`text_tier_en`) with
standard Solr English analysis — `StandardTokenizer`, `StopFilterFactory` (Solr's
bundled `lang/stopwords_en.txt`, no local wordlist file needed), lowercasing,
`EnglishPossessiveFilterFactory`, `PorterStemFilterFactory`. No synonyms filter added —
question 3 is still open. An explicit `<field>` always wins over a `dynamicField`
pattern match in Solr regardless of declaration order, so this doesn't disturb the
`ts_*` pattern for the other 16 tier names.

**Verified live** (Solr 7.7.3, techproducts-based throwaway core, same setup Spike 11
used): schema reloads cleanly with no errors; `ts_content_eng:chant` matches a document
containing "chanting" (stemming confirmed working); `ts_content_wylie:grwa` matches
tokenized Wylie text (confirms the ICU default for non-English tiers is untouched by
this change). Not tested: real D7 transcript data, a live comparison against D7's exact
analyzer output, Solr 9.x.

## Second pass (2026-10-06, same day): Chinese, Nepali, Dzongkha, and Wylie split out too

After the English fix above, Than asked for Chinese, Nepali, and Dzongkha to each get
their own tuned analyzer too (all three are among the four largest non-English tiers by
volume), plus a recommendation for Wylie given it's a transliteration scheme, not a
natural language.

Researched (web research, cross-checked against real sample rows from `d7_av.tcu_tier`
and, for Wylie, THL's own published EWTS specification), then built and verified live
in a throwaway Solr 7.7.3 core against **real** D7 sample content (not synthetic text)
for all four:

| Tier | fieldType | Mechanism | Verified |
|---|---|---|---|
| `ts_content_zho` | `text_tier_zho` | `HMMChineseTokenizerFactory` (dictionary-based HMM word segmentation) | `石碑` ("stele") correctly segmented out of an unsegmented real sentence |
| `ts_content_nep` | `text_tier_nep` | `StandardTokenizer` + `IndicNormalizationFilterFactory` + `HindiNormalizationFilterFactory`, deliberately no Hindi stemmer | `नाम` ("name") matches a real sample sentence |
| `dzo_bod` | `text_dzo` | `ICUTokenizerFactory` only, same mechanism as Tibetan's `text_bod` but its own fieldType | real sample correctly tokenizes per-syllable, tagged `script: Tibetan` |
| `ts_content_wylie` | `text_tier_wylie` | underscore→space char filter + `WhitespaceTokenizerFactory`, no lowercasing, no diacritic folding | `cig_yum` → independently searchable `yum`; apostrophe (`'phel`) preserved, not stripped |

**A real error was caught by live verification, not assumed correct from research
alone**: the research's initial Chinese recommendation named
`solr.SmartChineseSentenceTokenizerFactory` + `solr.SmartChineseWordTokenFilterFactory`
— neither class exists in this Lucene/Solr version (`ClassNotFoundException` on core
reload). Inspecting `lucene-analyzers-smartcn-7.7.3.jar` directly showed it registers
exactly one factory, `solr.HMMChineseTokenizerFactory`, which does sentence + HMM word
segmentation as a single tokenizer. Fixed before merging.

Why these three languages specifically, and why Nepali has no stemmer added: no
dedicated Nepali analyzer/stemmer exists anywhere in Lucene — `IndicNormalizationFilter`
and `HindiNormalizationFilter` are script-level Devanagari normalization (safe for any
Devanagari language per Lucene's own docs), but `HindiStemFilter` applies real Hindi
morphology, which risks incorrect stemming if applied to Nepali, so it was deliberately
left out. Wylie's recommendation corrected two of Than's/Claude's own starting
assumptions: EWTS case **is** phonemically meaningful (capitals encode Sanskrit-derived
sounds — retroflexes, long vowels), so there's no case-folding; EWTS has **no**
diacritics by design (capitals substitute for them specifically because diacritics are
hard to type), so there's nothing for an accent-folding filter to do.

## Decided not to do (2026-10-07, Than)

**Separate per-language fields for the ~11 remaining smaller tiers** (`ts_content_und`,
`ts_content_gyal`, `ts_content_gloss`, `ts_content_nmm`, `ts_content_xkf`,
`ts_content_tsum`, `ts_content_kjz`, `ts_content_npa`, `ts_content_gvr`,
`ts_content_tsj`, `ts_content_kte`) — staying on the generic `text_tier` (ICU)
default, permanently, not just for now. None of these have dedicated Lucene/Solr
language support, so splitting them into their own fields would just be N copies of
the same analyzer under different names — no actual behavior to gain.

## Still open

1. **Synonyms file**: still unconfirmed whether D7's synonyms file is populated or
   empty/unused. If confirmed populated and in real use, add a
   `SynonymGraphFilterFactory` to `text_tier_en`'s analyzer.
2. Related, not part of this: Tibetan tokenization in kmassets is a separate topic (ADR
   004, Spike 4a).

**Owner:** Than (built, both passes; decided not to split the remaining tiers).
Unassigned for the synonyms-file question.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, schema
- [Schema and README](../../solr/mandala-av-transcripts/README.md)
