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

**2026-10-06, second follow-up check** (same method, after the English/Chinese/
Nepali/Dzongkha/Wylie split below), tested against **real sample rows pulled directly
from `d7_av.tcu_tier`**, not synthetic text:

- Chinese (`好。琼结藏王墓附近有三座石碑...`): `ts_content_zho:石碑` ("stele") matches —
  `HMMChineseTokenizerFactory` correctly segments the real 2-character word out of
  the unsegmented sentence.
- Nepali (`मेरो नाम छिमि याङ्चे (हो)।`): `ts_content_nep:नाम` ("name") matches.
- Dzongkha (`ད་འ་ནཱི་བྱ་ཅིག་... རྒྱལ་པོ་ཟེར་མི་འདི་`): `dzo_bod:རྒྱལ` matches, and
  `analysis/field` confirms the ICU tokenizer correctly produces per-syllable
  tokens tagged `script: Tibetan`.
- Wylie (`...cig_yum chen khyed... g.yu mtsho 'dra/_mtsho la`): `ts_content_wylie:yum`
  matches (confirms the underscore-to-space char filter correctly splits
  `cig_yum` into independently searchable `cig` and `yum`), and
  `ts_content_wylie:'phel` matches with the apostrophe intact (confirms it is
  preserved, not stripped as punctuation).
- **One real error caught by this check, not assumed-correct from research alone**:
  the initial draft of the Chinese fieldType used
  `solr.SmartChineseSentenceTokenizerFactory` + `solr.SmartChineseWordTokenFilterFactory`
  — both `ClassNotFoundException` in this Lucene/Solr version. Inspecting
  `lucene-analyzers-smartcn-7.7.3.jar` directly showed it registers exactly one
  factory, `solr.HMMChineseTokenizerFactory`, which does sentence + HMM word
  segmentation as a single tokenizer. Fixed before merging; see the schema
  comment and the deferred note for detail.

## Tier analyzers

**2026-10-06, Than: decided and built, in two passes.** `ts_*` tiers use the ICU
tokenizer plus ICU folding by default — confirmed, not just assumed, to be the right
choice for the ~11 remaining smaller-volume tiers (mostly Himalayan minority languages
with no dedicated Lucene/Solr support). Five tiers now have their own dedicated
fieldType instead:

| Tier | fieldType | Mechanism |
|---|---|---|
| `ts_content_eng` | `text_tier_en` | `StandardTokenizer`, bundled `lang/stopwords_en.txt`, lowercasing, `EnglishPossessiveFilterFactory`, `PorterStemFilterFactory` — restores real English stemming (`chant` matches `chanting`) |
| `ts_content_zho` | `text_tier_zho` | `HMMChineseTokenizerFactory` — dictionary-based HMM word segmentation for Simplified Chinese |
| `ts_content_nep` | `text_tier_nep` | `StandardTokenizer` + `IndicNormalizationFilterFactory` + `HindiNormalizationFilterFactory` — Devanagari script normalization only, deliberately no Hindi-specific stemmer (no dedicated Nepali analyzer exists in Lucene) |
| `dzo_bod` | `text_dzo` | `ICUTokenizerFactory` only — same mechanism as Tibetan's `text_bod`, split into its own fieldType purely for independent future tuning |
| `ts_content_wylie` | `text_tier_wylie` | Underscore-to-space char filter + `WhitespaceTokenizerFactory` — no lowercasing (EWTS case is phonemically meaningful) and no diacritic folding (EWTS has none by design) |

No synonyms filter on any tier yet — whether D7's synonyms file is even populated is
still unconfirmed. Separate per-language fields for the remaining ~11 smaller tiers
remain deferred, lower priority now that the confirmed real gaps (English stemming,
Chinese/Nepali/Dzongkha/Wylie each sharing one generic analyzer) are closed:
[deferred note](../../docs/deferred/transcript-tier-analyzers-and-language-fields.md).
