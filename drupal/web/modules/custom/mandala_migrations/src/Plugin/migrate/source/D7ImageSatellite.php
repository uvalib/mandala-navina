<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * Sources a D7 image satellite node once per *reference* from a parent image.
 *
 * The Images audit (ADR 010) collapses the D7 satellite graph (image_agent,
 * image_descriptions, external_classification) to Paragraphs owned by the
 * shanti_image node. A paragraph revision is owned by exactly one host, so a
 * D7 satellite node shared across N images must become N separate paragraphs —
 * "fan-out". This source therefore inner-joins the parent image's reference
 * field and yields one row per (host image, delta):
 *
 *   - Fan-out: shared agents (e.g. the "Unknown" default on 3,219 images)
 *     produce one paragraph per referencing image, as required.
 *   - Orphan skip: the inner join excludes satellite nodes that no image
 *     references (~12k agents, ~17k descriptions), per the audit.
 *
 * The migration map is keyed on (host_nid, delta) so the host node migration
 * can migration_lookup each fanned-out paragraph by [image_nid, delta].
 *
 * Configuration:
 *   - node_type: the satellite bundle (image_agent, image_descriptions, …).
 *   - reference_field: the D7 field on shanti_image that references it
 *     (field_image_agents, field_image_descriptions, field_external_classification).
 *
 * @MigrateSource(
 *   id = "d7_image_satellite",
 *   source_module = "node"
 * )
 */
class D7ImageSatellite extends Node {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $ref = $this->configuration['reference_field'];
    // One row per (referencing image, delta); inner join drops orphans.
    $query->innerJoin("field_data_$ref", 'r', "r.{$ref}_target_id = n.nid");
    $query->addField('r', 'entity_id', 'host_nid');
    $query->addField('r', 'delta', 'host_delta');
    $query->orderBy('r.entity_id');
    $query->orderBy('r.delta');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    $fields['host_nid'] = $this->t('Parent shanti_image node ID');
    $fields['host_delta'] = $this->t('Reference delta on the parent image');
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'host_nid' => ['type' => 'integer'],
      'host_delta' => ['type' => 'integer'],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The migration keys (host_nid, host_delta) are aliases of the joined
   * reference table's columns, which MySQL cannot reference in the map JOIN's
   * ON clause. Disable the SQL map join so migrate checks the map in PHP.
   */
  public function mapJoinable() {
    return FALSE;
  }

}
