# Transcript tier analyzers, and separate language fields

**Area:** Solr / transcripts / multilingual search
**Raised during:** Spike 11, schema draft 2026-10-02; deferred the same day (Yuji)
**Jira:** (add when available)
**Priority:** Medium. A working default is in place; revisit before search is judged ready.

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

## What closes it

1. Ask Than: do users rely on English stemming? Do they search Chinese, Nepali or Wylie
   transcripts? Is D7's synonyms file in use or empty (not read)?
2. Optionally compare hit counts for sample queries under the candidate analyzers on a sample of
   real units from the `d7_av` dump, in a local throwaway Solr, so the choice rests on evidence.
3. Decide the per-language field design, including how the React client's tier names map onto
   it (the client reads the legacy tier names, so any new fields must stay compatible).
4. Related, not part of this: Tibetan tokenization in kmassets is a separate topic (ADR 004,
   Spike 4a).

**Owner:** unassigned.

## Related

- [Spike 11](../spikes/spike-11-av-transcript-replication.md): question 6, schema
- [Schema and README](../../solr/mandala-av-transcripts/README.md)
