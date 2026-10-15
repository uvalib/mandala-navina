<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Transcript;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;

/**
 * Resolves `trid` from the D7-derived node-to-trid table.
 *
 * Interim source until the T2 transcript entity exists. Keyed by the ADR 017
 * composite (legacy site, legacy nid), never by a D11 node id: each
 * environment's D11 ids come from its own migration history.
 * Loaded by `drush kmassets:load-trid-map`.
 */
class LegacyTridMapResolver implements TridResolverInterface {

  public const TABLE = 'mandala_kmassets_trid_map';

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(NodeInterface $node): ?int {
    if (!$node->hasField('field_legacy_site') || !$node->hasField('field_legacy_nid')
      || $node->get('field_legacy_site')->isEmpty() || $node->get('field_legacy_nid')->isEmpty()) {
      return NULL;
    }
    $trid = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['trid'])
      ->condition('legacy_site', (string) $node->get('field_legacy_site')->value)
      ->condition('legacy_nid', (int) $node->get('field_legacy_nid')->value)
      ->execute()
      ->fetchField();
    return $trid === FALSE ? NULL : (int) $trid;
  }

}
