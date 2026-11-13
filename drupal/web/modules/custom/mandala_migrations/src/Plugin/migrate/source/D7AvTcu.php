<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 time-coded units (`tcu`), one row per unit, for `av_tcu`.
 *
 * Plan: docs/planning/t5-transcript-migration-plan.md.
 *
 * ONLY UNITS OF TRACKED TRANSCRIPTS. The inner join to the tracking table is
 * deliberate (ADR 019's existence rule): the 19 orphan trids hold 2,153 units
 * and no transcript, so those units are not yielded here. They are counted by
 * the verification script, not dropped silently, and their storage is Than's
 * open decision.
 *
 * SEQUENCE AND NEXT START COME FROM THE QUERY. D7 has no sequence column. Its
 * viewer reads units from Solr sorted by `fts_start` ascending (confirmed in
 * the module source and on a live page, node 761), so readers see time order.
 * `sequence` is therefore ROW_NUMBER() over (start, tcuid) within the
 * transcript, from 0; `tcuid` breaks ties because D7's own tie order is
 * undefined. In 201 transcripts (377 units) this differs from `tcuid` order.
 * `next_start_s` is the start of the next unit in that order, which is what
 * TimecodeClassifier needs, so the classifier and the stored sequence cannot
 * disagree.
 *
 * THE WINDOWS LIVE IN A DERIVED TABLE, AND THAT IS NOT OPTIONAL. SQL evaluates
 * WHERE before window functions, and migrate adds its own WHERE to every
 * query (`map.sourceid1 IS NULL OR needs-update`) so a resumed run skips what
 * is already imported. With the windows in the outer query, a run resumed
 * part-way through a transcript would number only its *remaining* units from 0
 * and give each the wrong `next_start_s`. In the derived table the windows
 * see every tracked unit; migrate's filter only picks which rows come out.
 * (AvTranscriptSourcesTest::testSequenceSurvivesAnInterruptedRun.) The
 * `trids` option scopes whole transcripts, never a subset of one, so it is
 * safe inside the derived table.
 *
 * TIMECODES ARE EXACT DECIMALS, NOT PHP FLOATS. D7 stores single-precision
 * floats; the column in D11 is DECIMAL(10,3). The query casts to DECIMAL(10,3)
 * (whole milliseconds), so PHP receives exact strings such as `157.267` and
 * never rounds a float. Ordering uses the same cast, so noise below a
 * millisecond cannot decide the order. Values are carried as found: no repair,
 * no end rewrite.
 *
 * TIERS AND SPEAKERS ARE AGGREGATED IN SQL as JSON objects (JSON_OBJECTAGG, one
 * grouped pass over `tcu_tier` / `tcu_speaker`), because per-unit queries would
 * be about half a million round trips and GROUP_CONCAT truncates at 1,024
 * bytes. A unit with no tier rows (908 in D7) yields `{}`. Empty values are
 * carried as empty strings. Orphan tier and speaker rows (18 and 95) have no
 * unit to join to and are not yielded.
 *
 * NFC. Values are normalised to NFC (Spike 4a). 3,526 of the 355,536 tier
 * values and 136 speaker values are not NFC in the corpus, so this changes
 * stored text; `nfc_changed` counts the values changed in each row so the
 * verification can total them. Text that is not valid UTF-8 fails the row
 * rather than being stored as FALSE.
 *
 * @code
 * source:
 *   plugin: d7_av_tcu
 *   key: migrate_av
 *   trids: [8181]        # optional: scope a run to these transcripts
 * @endcode
 *
 * @MigrateSource(
 *   id = "d7_av_tcu",
 *   source_module = "node"
 * )
 */
class D7AvTcu extends SqlBase {

  /**
   * The whole-millisecond cast used for both ordering and the stored value.
   */
  protected const MS = 'CAST(%s AS DECIMAL(10,3))';

  /**
   * {@inheritdoc}
   */
  public function query() {
    $start = sprintf(static::MS, 'c.start');
    $end = sprintf(static::MS, 'c.end');
    $window = "PARTITION BY c.trid ORDER BY $start, c.tcuid";

    // Every tracked unit, numbered within its transcript. See the class docs:
    // this must not be the outer query.
    $numbered = $this->select('tcu', 'c');
    $numbered->innerJoin('transcripts_apachesolr_transcript', 't', "t.trid = c.trid AND t.module = 'transcripts_node' AND t.type = 'node'");
    $numbered->addField('c', 'tcuid', 'tcuid');
    $numbered->addExpression($start, 'start_s');
    $numbered->addExpression($end, 'end_s');
    $numbered->addExpression("ROW_NUMBER() OVER ($window) - 1", 'sequence');
    $numbered->addExpression("LEAD($start) OVER ($window)", 'next_start_s');
    $trids = $this->configuration['trids'] ?? [];
    if (!empty($trids)) {
      $numbered->condition('c.trid', $trids, 'IN');
    }

    $query = $this->select('tcu', 'c');
    $query->fields('c', ['tcuid', 'trid', 'created', 'changed']);
    $query->innerJoin($numbered, 'w', 'w.tcuid = c.tcuid');
    foreach (['start_s', 'end_s', 'sequence', 'next_start_s'] as $name) {
      $query->addField('w', $name, $name);
    }

    $tiers = $this->select('tcu_tier', 'x');
    $tiers->addField('x', 'tcuid', 'tcuid');
    $tiers->addExpression('JSON_OBJECTAGG(x.tier, x.value)', 'tier_map');
    $tiers->groupBy('x.tcuid');
    $query->leftJoin($tiers, 'tt', 'tt.tcuid = c.tcuid');
    $query->addField('tt', 'tier_map', 'tier_map');

    $speakers = $this->select('tcu_speaker', 'y');
    $speakers->addField('y', 'tcuid', 'tcuid');
    $speakers->addExpression('JSON_OBJECTAGG(y.tier, y.value)', 'speaker_map');
    $speakers->groupBy('y.tcuid');
    $query->leftJoin($speakers, 'sp', 'sp.tcuid = c.tcuid');
    $query->addField('sp', 'speaker_map', 'speaker_map');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }
    if (!class_exists(\Normalizer::class)) {
      throw new \RuntimeException('d7_av_tcu needs the PHP intl extension (Normalizer) to normalise text to NFC.');
    }
    $tcuid = (int) $row->getSourceProperty('tcuid');

    $changed = 0;
    foreach (['tier_map' => 'tiers_json', 'speaker_map' => 'speakers_json'] as $from => $to) {
      $raw = $row->getSourceProperty($from);
      $map = ($raw === NULL || $raw === '') ? [] : json_decode((string) $raw, TRUE, 512, JSON_THROW_ON_ERROR);
      foreach ($map as $name => $value) {
        $value = (string) $value;
        $normalised = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if ($normalised === FALSE) {
          throw new \RuntimeException("Unit $tcuid, `$name`: text is not valid UTF-8 and cannot be normalised.");
        }
        if ($normalised !== $value) {
          $changed++;
        }
        $map[$name] = $normalised;
      }
      // The same encoding AvTcu::setTiers() / setSpeakers() write.
      $row->setSourceProperty($to, json_encode((object) $map, JSON_UNESCAPED_UNICODE));
    }
    $row->setSourceProperty('nfc_changed', $changed);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'tcuid' => $this->t('D7 unit id; becomes the av_tcu id.'),
      'trid' => $this->t('D7 transcript id; becomes the av_tcu transcript.'),
      'created' => $this->t('D7 unit created timestamp.'),
      'changed' => $this->t('D7 unit changed timestamp.'),
      'start_s' => $this->t('Start in seconds, exact DECIMAL(10,3) string.'),
      'end_s' => $this->t('End in seconds, exact DECIMAL(10,3) string, as found.'),
      'sequence' => $this->t('Position within the transcript in (start, tcuid) order, from 0.'),
      'next_start_s' => $this->t('Start of the next unit in that order, NULL for the last.'),
      'tier_map' => $this->t('Raw JSON object tier => text (before NFC).'),
      'speaker_map' => $this->t('Raw JSON object speaker tier => value (before NFC).'),
      'tiers_json' => $this->t('NFC-normalised tier JSON as AvTcu::setTiers() writes it.'),
      'speakers_json' => $this->t('NFC-normalised speaker JSON.'),
      'nfc_changed' => $this->t('How many tier and speaker values NFC changed in this row.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      // The alias matters: migrate joins its id map on this column, and the
      // derived tables in query() expose a `tcuid` of their own.
      'tcuid' => ['type' => 'integer', 'alias' => 'c'],
    ];
  }

}
