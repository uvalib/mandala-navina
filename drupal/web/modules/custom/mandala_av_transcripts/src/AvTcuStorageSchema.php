<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Indexes for reading one transcript's units in order.
 *
 * (transcript, sequence) serves the display read; (transcript, start) serves
 * "which unit is playing at time t". Neither is unique: the migration carries
 * D7 data as found, and editing can briefly hold equal values.
 */
class AvTcuStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base = $this->storage->getBaseTable();
    $schema[$base]['indexes']['av_tcu__transcript_sequence'] = ['transcript', 'sequence'];
    $schema[$base]['indexes']['av_tcu__transcript_start'] = ['transcript', 'start'];
    return $schema;
  }

  /**
   * {@inheritdoc}
   *
   * The soft-delete flags are NOT NULL DEFAULT 0, so a plain `deleted = 0`
   * filter is correct for every row, including rows that existed before the
   * column was added and rows written by a bulk insert that skips the entity.
   */
  protected function getSharedTableFieldSchema(FieldStorageDefinitionInterface $storage_definition, $table_name, array $column_mapping) {
    $schema = parent::getSharedTableFieldSchema($storage_definition, $table_name, $column_mapping);
    if (in_array($storage_definition->getName(), ['deleted', 'deleted_with_transcript'], TRUE)) {
      foreach ($schema['fields'] as &$spec) {
        $spec['not null'] = TRUE;
        $spec['default'] = 0;
      }
    }
    return $schema;
  }

}
