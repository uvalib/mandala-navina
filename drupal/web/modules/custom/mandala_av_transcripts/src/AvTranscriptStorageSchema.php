<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Adds a database-level unique key so one node can have only one transcript.
 *
 * The UniqueField constraint on the node field covers entity validation; this
 * covers writes that skip validation (migrations, direct saves), so the
 * one-to-one guarantee does not depend on every caller validating.
 */
class AvTranscriptStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base = $this->storage->getBaseTable();
    $schema[$base]['unique keys']['av_transcript__node'] = ['node'];
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
    if (in_array($storage_definition->getName(), ['deleted'], TRUE)) {
      foreach ($schema['fields'] as &$spec) {
        $spec['not null'] = TRUE;
        $spec['default'] = 0;
      }
    }
    return $schema;
  }

}
