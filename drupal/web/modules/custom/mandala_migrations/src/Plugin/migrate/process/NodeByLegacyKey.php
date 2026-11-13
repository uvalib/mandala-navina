<?php

namespace Drupal\mandala_migrations\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds the D11 node for a D7 node id by the composite legacy key (ADR 017).
 *
 * The key is `(field_legacy_site, field_legacy_nid)`. It is used instead of
 * migration_lookup on purpose: a lookup reads this environment's id map, which
 * a rollback empties, while the legacy key is on the node itself and survives
 * any number of rollback and re-run cycles. A D11 node id is never written
 * anywhere (CLAUDE.md, "Content identity across environments").
 *
 * A missing node, or two nodes with the same key, FAILS the row with a message
 * (it shows in `migrate:messages`). It does not skip it silently: a transcript
 * without its node is a finding, not a no-op.
 *
 * Configuration:
 * - site: required. The `field_legacy_site` value, `audio-video` for AV.
 *
 * @code
 * node:
 *   plugin: mandala_node_by_legacy_key
 *   source: nid
 *   site: audio-video
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "mandala_node_by_legacy_key"
 * )
 */
class NodeByLegacyKey extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
    $site = $this->configuration['site'] ?? '';
    if ($site === '') {
      throw new \InvalidArgumentException('mandala_node_by_legacy_key requires a `site`.');
    }
    if ($value === NULL || $value === '') {
      throw new MigrateException('The source has no D7 node id.');
    }
    // accessCheck(FALSE): this is plumbing, and an unpublished node is still
    // the node.
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_legacy_site', $site)
      ->condition('field_legacy_nid', (int) $value)
      ->execute();
    if (count($ids) !== 1) {
      throw new MigrateException(sprintf('Expected one D11 node for legacy key (%s, %d), found %d.', $site, (int) $value, count($ids)));
    }
    return (int) reset($ids);
  }

}
