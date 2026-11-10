<?php

namespace Drupal\mandala_migrations\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The first `target_id` of an entity reference or file field on an entity.
 *
 * The source is the entity id. Returns NULL when the entity or the value is
 * missing, so the destination field is simply empty (4 tracked AV nodes have
 * no transcript file). migrate_plus's `entity_value` returns the field's whole
 * value structure, which is not what a reference property wants.
 *
 * Configuration:
 * - entity_type: required, e.g. `node`.
 * - field_name: required, e.g. `field_transcript`.
 *
 * @code
 * source_file:
 *   plugin: mandala_entity_field_target
 *   source: '@node'
 *   entity_type: node
 *   field_name: field_transcript
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "mandala_entity_field_target"
 * )
 */
class EntityFieldTarget extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    foreach (['entity_type', 'field_name'] as $key) {
      if (empty($this->configuration[$key])) {
        throw new \InvalidArgumentException("mandala_entity_field_target requires `$key`.");
      }
    }
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($this->configuration['entity_type'])->load($value);
    $field = $this->configuration['field_name'];
    if ($entity === NULL || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    return (int) $entity->get($field)->first()->get('target_id')->getValue();
  }

}
