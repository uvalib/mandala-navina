# mandala-av-transcripts (Solr core definition)

One Solr document per time-coded unit (TCU) of an AV transcript, for the D11 rebuild.
Decisions and reasoning: [Spike 11](../../docs/spikes/spike-11-av-transcript-replication.md),
question 6. This directory is **not** part of the Drupal image, so changing it does not
trigger a deploy.

## What is here

- `conf/schema.xml` — minimal schema, keeping the legacy field names the React client reads
  (`is_trid`, `fts_start`, `fts_end`, `fts_duration`, `ss_speaker_bod`, `ss_language`, and the
  language tiers `content_bod`, `dzo_bod`, `ts_content_*`), plus `id`, `entity_id`, `nid` and a
  `sm_*` dynamic field for the language facet.

## What is NOT here yet

- **`solrconfig.xml`** and the other conf files. Base it on the `kmassets` config, which is
  known to work with this instance's master/replica setup, and add the two lines the schema
  needs for ICU analysis:

  ```xml
  <lib dir="${solr.install.dir:../../../..}/contrib/analysis-extras/lib" regex=".*\.jar" />
  <lib dir="${solr.install.dir:../../../..}/contrib/analysis-extras/lucene-libs" regex=".*\.jar" />
  ```
- The cross-core join access filter (a `solr-proxy` change; prototype first).

## How the schema was checked (2026-10-02)

Loaded into a throwaway Solr 7.7.3 container (core created from the techproducts starter,
schema swapped in, the two `<lib>` lines above added), then exercised with **synthetic** units
(no real transcript text):

- the React client's query (`is_trid:N`, rows 1000, sorted by `fts_start`) returns the expected
  fields, with float values printing exactly (`10.033`, `81807.234`);
- phrase search per tier with D7-style `<mark>` highlighting, on English, Chinese and Tibetan
  tiers (the ICU tokenizer splits at the tsheg, so Tibetan phrases match);
- cross-transcript edismax search across tiers; the `sm_has_tier` facet; delete by `is_trid`
  (the write path's whole-transcript operation).

**Not checked:** Solr 9.x, real data, the production `solrconfig.xml`, replication, the proxy.

**2026-10-06 follow-up check** (same throwaway-core method, after the tier-analyzer
decision below): schema reloads cleanly with the `text_tier_en` override added;
`ts_content_eng:chant` matches a document containing "chanting" (stemming confirmed);
`ts_content_wylie:grwa` still matches tokenized Wylie text (confirms the ICU default
for other tiers is unaffected).

## Tier analyzers (2026-10-06, Than: decided and built)

`ts_*` tiers use the ICU tokenizer plus ICU folding by default — confirmed, not just
assumed, to be the right choice for non-English tiers (Wylie, Chinese, Nepali, etc. are
actively searched, and no stemmer exists for Wylie anyway). **English
(`ts_content_eng`) is the one exception**: an explicit field override
(`text_tier_en`) restores real stemming (`chant` now matches `chanting`, confirmed
live in a throwaway Solr 7.7.3 core) via standard Solr English analysis —
`StandardTokenizer`, bundled `lang/stopwords_en.txt`, lowercasing,
`EnglishPossessiveFilterFactory`, `PorterStemFilterFactory`. No synonyms filter yet —
whether D7's synonyms file is even populated is still unconfirmed. Splitting every tier
into its own dedicated language field (rather than one analyzer per naming pattern)
remains deferred, lower urgency now that the one confirmed regression is fixed:
[deferred note](../../docs/deferred/transcript-tier-analyzers-and-language-fields.md).
