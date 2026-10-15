<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

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

}
