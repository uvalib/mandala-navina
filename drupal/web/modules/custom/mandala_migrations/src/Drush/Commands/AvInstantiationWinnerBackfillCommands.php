<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Second-pass backfill for the `field_pbcore_instantiation` wrong-winner bug.
 *
 * `field_pbcore_instantiation` is the only cardinality-1 PBCore host field,
 * so a host with both an `en` and `und` layer link needs the migration to
 * pick ONE of the two D7 field_collection items
 * (AvLanguageLayerTrait::resolveSingleValued()). That picker scored each
 * candidate via `populatedSubFieldCounts()`, which counted `COUNT(*)` rows
 * per sub-field table -- but a D7 field_collection item with NOTHING
 * entered still gets one row per configured sub-field, value column NULL.
 * COUNT(*) counted those as "populated", so a completely-empty item could
 * out-score (or tie and then win the `en`-preference tiebreak against) a
 * genuinely populated one. Fixed in AvLanguageLayerTrait (counts the real
 * value column now, which SQL's COUNT(column) already excludes NULLs for)
 * -- this command corrects the paragraphs that already migrated wrong
 * before that fix, same reasoning as the AV4 relation-identifier backfill:
 * the paragraph and the host's reference to it are already correct, only
 * the paragraph's OWN field values are wrong.
 */
class AvInstantiationWinnerBackfillCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * D11 field name => D7 sub-field name, only where they differ.
   */
  private const FIELD_NAME_OVERRIDES = [
    'field_pbcore_language' => 'field_language',
  ];

  /**
   * D11 field name => Drupal field type, for the sub-fields needing
   * non-trivial value shaping (everything else is a plain scalar copy).
   */
  private const DATETIME_FIELDS = ['field_date_created', 'field_date_issued', 'field_pbcore_date_available'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'av:backfill-instantiation-winner')]
  #[CLI\Option(name: 'dry-run', description: 'Report affected hosts without saving any paragraph.')]
  #[CLI\Usage(name: 'drush av:backfill-instantiation-winner --dry-run', description: 'Report what would change.')]
  #[CLI\Usage(name: 'drush av:backfill-instantiation-winner', description: 'Backfill for real.')]
  public function backfill(array $options = ['dry-run' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];
    $db = Database::getConnection();
    $sourceDb = Database::getConnectionInfo('migrate_av')['default']['database'];
    $source = Database::getConnection('default', 'migrate_av');

    $subFields = $this->subFieldList($source);

    // Contested hosts: two link rows on field_pbcore_instantiation.
    $hosts = $db->query(
      "SELECT entity_id, GROUP_CONCAT(field_pbcore_instantiation_value) AS item_ids
       FROM $sourceDb.field_data_field_pbcore_instantiation
       GROUP BY entity_id
       HAVING COUNT(*) > 1"
    )->fetchAllKeyed();

    $updated = 0;
    $alreadyCorrect = 0;
    $noParagraph = 0;
    foreach ($hosts as $hostNid => $itemIdList) {
      // GROUP_CONCAT includes a row per (host, language) link, so a host
      // whose `en` and `und` rows point at the SAME item_id (the common
      // case, not a real conflict) shows up as e.g. "291041,291041" -- dedup
      // before counting, or every such host would be miscounted as a
      // genuine two-way conflict.
      $itemIds = array_unique(array_map('intval', explode(',', (string) $itemIdList)));
      if (count($itemIds) !== 2) {
        continue;
      }
      $itemIds = array_values($itemIds);

      $scores = $this->scoreItems($source, $subFields, $itemIds);
      $correctItem = $scores[$itemIds[0]] >= $scores[$itemIds[1]] ? $itemIds[0] : $itemIds[1];

      $mapRow = $db->query(
        'SELECT sourceid1, destid1 FROM migrate_map_d7_av_pbcore_instantiation WHERE sourceid1 IN (:ids[])',
        [':ids[]' => $itemIds]
      )->fetchObject();
      if (!$mapRow) {
        continue;
      }
      if ((int) $mapRow->sourceid1 === $correctItem) {
        $alreadyCorrect++;
        continue;
      }

      $paragraph = $this->entityTypeManager->getStorage('paragraph')->load($mapRow->destid1);
      if (!$paragraph) {
        $noParagraph++;
        continue;
      }

      $this->applySubFields($source, $db, $paragraph, $subFields, $correctItem);
      if (!$dryRun) {
        // No setNewRevision(TRUE): updates the current revision in place,
        // the one the host node's entity_reference_revisions field already
        // points at -- same reasoning as the relation-identifier backfill.
        $paragraph->save();
      }
      $updated++;
    }

    $this->logger()->success('{action} {count} av_pbcore_instantiation paragraph(s).', [
      'action' => $dryRun ? 'Would correct' : 'Corrected',
      'count' => $updated,
    ]);
    $this->logger()->info('{count} contested host(s) already had the correct winner.', ['count' => $alreadyCorrect]);
    if ($noParagraph) {
      $this->logger()->warning('{count} contested host(s) had a migrate-map row but no loadable paragraph.', ['count' => $noParagraph]);
    }
  }

  /**
   * The sub-field list, discovered the same way the source plugin does.
   */
  private function subFieldList(Connection $source): array {
    return $source->select('field_config_instance', 'fci')
      ->fields('fci', ['field_name'])
      ->condition('fci.entity_type', 'field_collection_item')
      ->condition('fci.bundle', 'field_pbcore_instantiation')
      ->condition('fci.deleted', 0)
      ->execute()
      ->fetchCol();
  }

  /**
   * [item_id => populated-value count], using the FIXED scoring logic.
   */
  private function scoreItems(Connection $source, array $subFields, array $itemIds): array {
    $scores = array_fill_keys($itemIds, 0);
    foreach ($subFields as $subField) {
      [$valueColumn] = $this->resolveColumns($source, $subField);
      if ($valueColumn === NULL) {
        continue;
      }
      // Neither NULL nor '' -- D7 saves '' rather than leaving the column
      // NULL for plain-text sub-fields left empty (see
      // AvLanguageLayerTrait::populatedSubFieldCounts(), the same scoring
      // logic this mirrors). Skip the '' check for native DATETIME columns
      // -- MySQL errors trying to parse '' as a date, and D7's date module
      // never stores '' there anyway (NULL or a real value).
      $table = 'field_data_' . $subField;
      $query = $source->select($table, 't')
        ->fields('t', ['entity_id'])
        ->condition('t.entity_id', $itemIds, 'IN')
        ->condition('t.deleted', 0)
        ->isNotNull("t.$valueColumn");
      if (!$this->isDatetimeColumn($source, $table, $valueColumn)) {
        $query->condition("t.$valueColumn", '', '<>');
      }
      foreach ($query->execute()->fetchCol() as $itemId) {
        $scores[(int) $itemId]++;
      }
    }
    return $scores;
  }

  /**
   * Resolves [value_column, format_column|NULL] for a D7 sub-field, or
   * [NULL, NULL] if the table/column doesn't exist.
   */
  private function resolveColumns(Connection $source, string $subField): array {
    $table = 'field_data_' . $subField;
    if (!$source->schema()->tableExists($table)) {
      return [NULL, NULL];
    }
    $columns = $source->query("SHOW COLUMNS FROM {$table}")->fetchCol();
    foreach (['_value', '_target_id', '_fid', '_tid', '_rating'] as $suffix) {
      if (in_array($subField . $suffix, $columns, TRUE)) {
        $format = in_array($subField . '_format', $columns, TRUE) ? $subField . '_format' : NULL;
        return [$subField . $suffix, $format];
      }
    }
    return [NULL, NULL];
  }

  /**
   * Whether a column is a native DATETIME/DATE type.
   */
  private function isDatetimeColumn(Connection $source, string $table, string $column): bool {
    $type = $source->query("SHOW COLUMNS FROM {$table} LIKE :col", [':col' => $column])->fetchField(1);
    return str_contains((string) $type, 'date');
  }

  /**
   * Copies every sub-field's value from the correct D7 item onto the
   * paragraph, replacing whatever wrong-winner data it currently holds.
   */
  private function applySubFields(Connection $source, $db, $paragraph, array $subFields, int $correctItem): void {
    foreach ($subFields as $subField) {
      $d11FieldName = array_search($subField, self::FIELD_NAME_OVERRIDES, TRUE) ?: $subField;
      if (!$paragraph->hasField($d11FieldName)) {
        continue;
      }

      // The nested field_collection reference: look up the child paragraph
      // through its own (independently-run, unaffected by this bug)
      // migration, keyed by the D7 sub-item id linked under the correct
      // parent item.
      if ($subField === 'field_pbcore_format_id') {
        $childItemIds = $source->select('field_data_field_pbcore_format_id', 't')
          ->fields('t', ['field_pbcore_format_id_value'])
          ->condition('t.entity_id', $correctItem)
          ->condition('t.deleted', 0)
          ->orderBy('t.delta')
          ->execute()
          ->fetchCol();
        $refs = [];
        foreach ($childItemIds as $childItemId) {
          $map = $db->query(
            'SELECT destid1, destid2 FROM migrate_map_d7_av_pbcore_format_id WHERE sourceid1 = :id',
            [':id' => $childItemId]
          )->fetchObject();
          if ($map) {
            $refs[] = ['target_id' => $map->destid1, 'target_revision_id' => $map->destid2];
          }
        }
        $paragraph->set($d11FieldName, $refs);
        continue;
      }

      [$valueColumn, $formatColumn] = $this->resolveColumns($source, $subField);
      if ($valueColumn === NULL) {
        continue;
      }
      $table = 'field_data_' . $subField;
      $select = $source->select($table, 't')
        ->condition('t.entity_id', $correctItem)
        ->condition('t.deleted', 0)
        ->orderBy('t.delta');
      $select->addField('t', $valueColumn, 'v');
      if ($formatColumn) {
        $select->addField('t', $formatColumn, 'f');
      }
      $rows = $select->execute()->fetchAll();

      if (in_array($d11FieldName, self::DATETIME_FIELDS, TRUE)) {
        $values = array_filter(array_map(
          static fn ($r) => $r->v ? str_replace(' ', 'T', (string) $r->v) : NULL,
          $rows
        ));
        $paragraph->set($d11FieldName, array_values($values));
        continue;
      }
      if ($formatColumn) {
        // field_pbcore_annotation: the only text_long sub-field here.
        $values = array_map(
          static fn ($r) => ['value' => $r->v, 'format' => $r->f ?: 'basic_html'],
          array_filter($rows, static fn ($r) => $r->v !== NULL)
        );
        $paragraph->set($d11FieldName, array_values($values));
        continue;
      }

      $values = array_values(array_filter(array_map(static fn ($r) => $r->v, $rows), static fn ($v) => $v !== NULL));
      $paragraph->set($d11FieldName, $values);
    }
  }

}
