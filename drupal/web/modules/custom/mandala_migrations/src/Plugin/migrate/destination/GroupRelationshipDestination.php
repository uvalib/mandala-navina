<?php

namespace Drupal\mandala_migrations\Plugin\migrate\destination;

use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * Destination plugin: creates group_relationship entities via Group API.
 *
 * Accepts rows with:
 *   gid        - D11 Group entity ID
 *   entity_id  - D11 entity ID (e.g. node nid)
 *   plugin_id  - Relation plugin ID (e.g. 'group_node:shanti_image')
 *
 * Uses Group::addRelationship() so the correct GroupRelationshipType is
 * resolved automatically — no need to hardcode hashed bundle IDs.
 *
 * @MigrateDestination(
 *   id = "mandala_group_relationship"
 * )
 */
class GroupRelationshipDestination extends DestinationBase {

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'id' => ['type' => 'integer'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    return [
      'gid' => 'D11 Group entity ID',
      'entity_id' => 'D11 entity ID to add to the group',
      'plugin_id' => 'Group relation plugin ID (e.g. group_node:shanti_image)',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $gid = $row->getDestinationProperty('gid');
    $entity_id = $row->getDestinationProperty('entity_id');
    $plugin_id = $row->getDestinationProperty('plugin_id');

    if (!$gid || !$entity_id) {
      return FALSE;
    }

    $entity_type_manager = \Drupal::entityTypeManager();

    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $entity_type_manager->getStorage('group')->load($gid);
    if (!$group) {
      throw new MigrateException("Group {$gid} not found.");
    }

    $entity_type_id = $this->entityTypeFromPlugin($plugin_id);
    $entity = $entity_type_manager->getStorage($entity_type_id)->load($entity_id);
    if (!$entity) {
      throw new MigrateException("Entity {$entity_type_id}:{$entity_id} not found.");
    }

    // Skip if the relationship already exists (idempotent).
    $existing = $group->getRelationshipsByEntity($entity, $plugin_id);
    if (!empty($existing)) {
      $rel = reset($existing);
      return [$rel->id()];
    }

    $relationship = $group->addRelationship($entity, $plugin_id);
    return [$relationship->id()];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $id = reset($destination_identifier);
    $rel = \Drupal::entityTypeManager()->getStorage('group_relationship')->load($id);
    if ($rel) {
      $rel->delete();
    }
  }

  /**
   * Returns the entity type ID for a given plugin ID.
   */
  protected function entityTypeFromPlugin(string $plugin_id): string {
    if (str_starts_with($plugin_id, 'group_node:')) {
      return 'node';
    }
    if ($plugin_id === 'group_membership') {
      return 'user';
    }
    throw new MigrateException("Unknown plugin_id: {$plugin_id}");
  }

}
