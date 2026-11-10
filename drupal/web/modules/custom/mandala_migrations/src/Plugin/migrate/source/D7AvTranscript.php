<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 transcripts: one row per tracking-table row, for `av_transcript`.
 *
 * Plan: docs/planning/t5-transcript-migration-plan.md. Read that for the
 * decisions; this class records only what is specific to reading D7.
 *
 * ONE ROW PER TRACKING ROW (ADR 019's existence rule). A transcript exists
 * exactly when `transcripts_apachesolr_transcript` has a row for it. The 19
 * orphan trids that have units but no tracking row are therefore not yielded
 * here, and D7AvTcu skips their units for the same reason.
 *
 * THE ID IS D7'S `trid`. The migration sets it explicitly; D11 allocates its
 * own ids from 100,000 up, clear of D7's range.
 *
 * EDITS FLAG. D7's editor never touches `tcu.changed`; it flags the *node*
 * with `keep_transcript_edits`. The flag is joined here on the node id and
 * collapsed with MAX(timestamp), so a node flagged twice still yields one row.
 * `has_edits` is TRUE exactly when a flagging row exists.
 *
 * SOURCE FILE. The current `field_transcript` attachment is the original of
 * record (decided 2026-10-02): the 68 transcripts whose tracking row names a
 * different, now-deleted file have no trace of it in any revision. Only delta 0
 * is read; the corpus has one row per node (5,380 rows, 5,380 nodes), and the
 * verification script asserts the row count, because a second row per node
 * would silently duplicate a transcript here. `source_format` comes from the
 * file's extension and is NULL for anything but vtt, xml, txt and srt.
 *
 * TIER ORDER IS SITE CONFIGURATION, NOT PER TRANSCRIPT. D7 displays
 * `transcripts_ui_tiers()` (the `transcripts_ui_tiers` variable followed by
 * `transcripts_ui_hidden_tiers`) intersected with the transcript's tiers. The
 * tracking column only says which tiers the transcript has (space-delimited).
 * Where it is empty (9 of 5,343) the set is taken from the transcript's units.
 * A tier that is not in the configured list would be invisible in D7; it is
 * kept, appended in name order, because dropping data is the one thing this
 * migration must not do silently. The variable is required: reading it as
 * "empty" would reorder every transcript without a sound.
 *
 * `created` is the earliest unit `created`. The tracking table has no date and
 * the node's is no substitute (571 transcripts have units older than their
 * node).
 *
 * PERFORMANCE. `tcu` has no index on `trid` on D7. The `created` map is built
 * with one grouped scan the first time it is needed, not one query per row.
 *
 * @code
 * source:
 *   plugin: d7_av_transcript
 *   key: migrate_av
 *   trids: [8181]        # optional: scope a run to these transcripts
 * @endcode
 *
 * @MigrateSource(
 *   id = "d7_av_transcript",
 *   source_module = "node"
 * )
 */
class D7AvTranscript extends SqlBase {

  /**
   * The Flag module flag D7's transcript editor sets on a node.
   */
  protected const EDITS_FLAG = 'keep_transcript_edits';

  /**
   * File extensions `source_format` accepts.
   */
  protected const FORMATS = ['vtt', 'xml', 'txt', 'srt'];

  /**
   * Configured tier names in display order, read once.
   *
   * @var string[]|null
   */
  protected ?array $configuredTiers = NULL;

  /**
   * [trid => earliest unit created], read once.
   *
   * @var int[]|null
   */
  protected ?array $firstCreated = NULL;

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('transcripts_apachesolr_transcript', 't')
      ->fields('t', ['trid', 'fid', 'tiers']);
    $query->addField('t', 'id', 'nid');
    $query->condition('t.module', 'transcripts_node');
    $query->condition('t.type', 'node');

    // One row per flagged node, however many flaggings it has.
    $flags = $this->select('flagging', 'g');
    $flags->innerJoin('flag', 'f', 'f.fid = g.fid');
    $flags->condition('f.name', static::EDITS_FLAG);
    $flags->condition('g.entity_type', 'node');
    $flags->addField('g', 'entity_id', 'entity_id');
    $flags->addExpression('MAX(g.timestamp)', 'flagged_at');
    $flags->groupBy('g.entity_id');
    $query->leftJoin($flags, 'e', 'e.entity_id = t.id');
    $query->addField('e', 'flagged_at', 'edits_flagged_at');

    $query->leftJoin('field_data_field_transcript', 'ft', "ft.entity_id = t.id AND ft.entity_type = 'node' AND ft.deleted = 0 AND ft.delta = 0");
    $query->addField('ft', 'field_transcript_fid', 'current_fid');
    $query->leftJoin('file_managed', 'fm', 'fm.fid = ft.field_transcript_fid');
    $query->addField('fm', 'filename', 'current_filename');

    // Scoping by transcript, never by unit: the optional escape hatch the other
    // AV sources offer (`nids`), here for a sample run.
    $trids = $this->configuration['trids'] ?? [];
    if (!empty($trids)) {
      $query->condition('t.trid', $trids, 'IN');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }
    $trid = (int) $row->getSourceProperty('trid');

    $extension = strtolower(pathinfo((string) $row->getSourceProperty('current_filename'), PATHINFO_EXTENSION));
    $row->setSourceProperty('source_format', in_array($extension, static::FORMATS, TRUE) ? $extension : NULL);

    $row->setSourceProperty('has_edits', $row->getSourceProperty('edits_flagged_at') !== NULL);

    $listed = preg_split('/\s+/', trim((string) $row->getSourceProperty('tiers')), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($listed)) {
      $listed = $this->tiersOnUnits($trid);
    }
    $ordered = $this->inDisplayOrder($listed);
    $row->setSourceProperty('tier_list', $ordered);
    // The same encoding AvTranscript::setTiers() writes.
    $row->setSourceProperty('tiers_json', json_encode($ordered, JSON_UNESCAPED_UNICODE));

    $created = $this->firstCreatedMap()[$trid] ?? NULL;
    $row->setSourceProperty('created', $created);

    return TRUE;
  }

  /**
   * Tier names in display order, then any unknown ones by name.
   */
  protected function inDisplayOrder(array $tiers): array {
    $tiers = array_values(array_unique($tiers));
    $configured = $this->configuredTierOrder();
    $known = array_values(array_filter($configured, fn ($name) => in_array($name, $tiers, TRUE)));
    $unknown = array_values(array_diff($tiers, $configured));
    sort($unknown);
    return array_merge($known, $unknown);
  }

  /**
   * The configured tier names, visible first and the hidden ones after.
   *
   * @return string[]
   */
  protected function configuredTierOrder(): array {
    if ($this->configuredTiers !== NULL) {
      return $this->configuredTiers;
    }
    $read = function (string $name, bool $required): array {
      $value = $this->select('variable', 'v')
        ->fields('v', ['value'])
        ->condition('v.name', $name)
        ->execute()
        ->fetchField();
      if ($value === FALSE) {
        if ($required) {
          throw new \RuntimeException("D7 variable `$name` is missing, so the tier display order is unknown; refusing to guess it.");
        }
        return [];
      }
      $decoded = @unserialize((string) $value, ['allowed_classes' => FALSE]);
      if (!is_array($decoded)) {
        throw new \RuntimeException("D7 variable `$name` is not a serialized array.");
      }
      return $decoded;
    };
    // D7's own `transcripts_ui_tiers()` is an array union: keys of the first,
    // then the hidden tiers that are not already in it.
    $this->configuredTiers = array_map('strval', array_keys($read('transcripts_ui_tiers', TRUE) + $read('transcripts_ui_hidden_tiers', FALSE)));
    return $this->configuredTiers;
  }

  /**
   * The distinct tier names on one transcript's units.
   *
   * Only reached for the few tracking rows whose `tiers` column is empty, so a
   * scan of `tcu` (no `trid` index) per call is acceptable.
   */
  protected function tiersOnUnits(int $trid): array {
    $query = $this->select('tcu', 'c');
    $query->innerJoin('tcu_tier', 'tt', 'tt.tcuid = c.tcuid');
    $query->condition('c.trid', $trid);
    $query->fields('tt', ['tier'])->distinct();
    return array_map('strval', $query->execute()->fetchCol());
  }

  /**
   * [trid => earliest unit created], one grouped scan.
   *
   * @return int[]
   */
  protected function firstCreatedMap(): array {
    if ($this->firstCreated === NULL) {
      $query = $this->select('tcu', 'c');
      $query->addField('c', 'trid', 'trid');
      $query->addExpression('MIN(c.created)', 'first_created');
      $query->groupBy('c.trid');
      $this->firstCreated = array_map('intval', $query->execute()->fetchAllKeyed(0, 1));
    }
    return $this->firstCreated;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'trid' => $this->t('D7 transcript id; becomes the av_transcript id.'),
      'nid' => $this->t('D7 node id of the audio or video node.'),
      'fid' => $this->t('File id the tracking row names (71 have no file_managed row).'),
      'tiers' => $this->t('Raw space-delimited tier set from the tracking row.'),
      'edits_flagged_at' => $this->t('When keep_transcript_edits was flagged (latest), or NULL.'),
      'current_fid' => $this->t('fid of the node\'s current field_transcript attachment.'),
      'current_filename' => $this->t('Filename of that attachment.'),
      'source_format' => $this->t('vtt, xml, txt or srt from the extension, else NULL.'),
      'has_edits' => $this->t('TRUE when the edits flag is set.'),
      'tier_list' => $this->t('Tier names in D7 display order, unknown tiers last.'),
      'tiers_json' => $this->t('tier_list encoded as AvTranscript::setTiers() does.'),
      'created' => $this->t('Earliest unit created timestamp.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'trid' => ['type' => 'integer', 'alias' => 't'],
    ];
  }

}
