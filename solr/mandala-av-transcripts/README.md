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

## Open decision (Than)

`ts_*` tiers use the ICU tokenizer plus ICU folding, **not** D7's English analyzer. Result:
no stemming (`chant` does not match `chanting`, as it did in D7) but sensible tokenization of
Chinese, Nepali, Wylie and other languages. Switch back to the legacy analyzer for exact parity.
