# Transcript tier analyzers, and separate language fields

**Area:** Solr / transcripts / multilingual search
**Raised during:** Spike 11, schema draft 2026-10-02; deferred the same day (Yuji)
**Jira:** (add when available)
**Priority:** Medium. **Question 1 (analyzer choice) DECIDED and BUILT 2026-10-06** (Than).
Question 2 (separate per-language fields) remains open, lower urgency now that the
immediate regression is fixed.

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

## Still open

1. **Separate per-language fields** (question 2 from the original note) — not done; the
   explicit-field-override approach above works per tier name but doesn't restructure
   the schema into dedicated language fields. Lower urgency now that the one confirmed
   real regression (English stemming) is fixed.
2. **Synonyms file**: still unconfirmed whether D7's synonyms file is populated or
   empty/unused. If confirmed populated and in real use, add a
   `SynonymGraphFilterFactory` to `text_tier_en`'s analyzer.
3. Related, not part of this: Tibetan tokenization in kmassets is a separate topic (ADR
   004, Spike 4a).

**Owner:** Than (built); unassigned for the two still-open items above.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, schema
- [Schema and README](../../solr/mandala-av-transcripts/README.md)
