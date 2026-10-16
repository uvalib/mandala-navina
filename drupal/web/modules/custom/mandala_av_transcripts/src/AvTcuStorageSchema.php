<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

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

}
