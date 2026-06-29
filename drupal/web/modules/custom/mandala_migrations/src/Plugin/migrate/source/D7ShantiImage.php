<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * Sources D7 shanti_image nodes, enriched with IIIF identity + paragraph keys.
 *
 * Two things the stock d7_node source cannot provide:
 *
 *   1. IIIF identity. The i3fid / mmsid / pixel dimensions live in the custom
 *      `shanti_images` sidecar table (a registry keyed by nid), not in Field
 *      API storage. prepareRow() joins it and exposes i3fid, mmsid, iiif_width,
 *      iiif_height. ~36 images have no sidecar row (imageless metadata records)
 *      and are migrated without IIIF linkage (field_iiif_id is optional).
 *
 *   2. Paragraph lookup keys. The satellites are migrated per-reference and
 *      keyed on (host_nid, delta) by D7ImageSatellite. So that the host node
 *      can migration_lookup each fanned-out paragraph, prepareRow() emits a
 *      [{nid, delta}, …] list per satellite reference field.
 *
 * @MigrateSource(
 *   id = "d7_shanti_image",
 *   source_module = "node"
 * )
 */
class D7ShantiImage extends Node {

  /**
   * Satellite reference field => source property holding its lookup keys.
   */
  protected const SATELLITE_KEYS = [
    'field_image_agents' => 'agent_keys',
    'field_image_descriptions' => 'description_keys',
    'field_external_classification' => 'classification_keys',
  ];

  /**
   * {@inheritdoc}
   *
   * Honours an optional `nids` source-config list to restrict the source query
   * to specific node IDs. Used for fast, scoped imports (e.g. the 1a.8 golden
   * fixtures) — unlike migrate_tools `--idlist`, which still calls prepareRow()
   * on every source row. No-op when unset (the normal full-migration path).
   */
  public function query() {
    $query = parent::query();
    $nids = $this->configuration['nids'] ?? [];
    if (!empty($nids)) {
      $query->condition('n.nid', $nids, 'IN');
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
    $nid = $row->getSourceProperty('nid');

    // IIIF identity from the shanti_images sidecar registry. A node can have
    // more than one historical row; take the lowest siid deterministically.
    $sidecar = $this->select('shanti_images', 'si')
      ->fields('si', ['i3fid', 'mmsid', 'width', 'height'])
      ->condition('si.nid', $nid)
      ->orderBy('si.siid')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if ($sidecar) {
      $row->setSourceProperty('i3fid', $sidecar['i3fid'] ?: NULL);
      $row->setSourceProperty('mmsid', !empty($sidecar['mmsid']) ? (string) $sidecar['mmsid'] : NULL);
      $row->setSourceProperty('iiif_width', !empty($sidecar['width']) ? (int) $sidecar['width'] : NULL);
      $row->setSourceProperty('iiif_height', !empty($sidecar['height']) ? (int) $sidecar['height'] : NULL);
    }

    // Build [{nid, delta}, …] lists so the host can look up each paragraph.
    foreach (static::SATELLITE_KEYS as $field => $property) {
      $items = $row->getSourceProperty($field) ?: [];
      $keys = [];
      foreach (array_keys($items) as $delta) {
        $keys[] = ['nid' => (int) $nid, 'delta' => (int) $delta];
      }
      $row->setSourceProperty($property, $keys);
    }

    return TRUE;
  }

}
