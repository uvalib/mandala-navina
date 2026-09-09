<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 OG memberships: content nodes → collections/subcollections.
 *
 * Yields one row per og_membership record where the member is a node of one of
 * the configured types and the group is a collection or subcollection node.
 *
 * SCOPE — configurable via `member_types`, defaulting to `['shanti_image']` so
 * the Sprint 1 Images migration keeps its previous behaviour exactly. AV passes
 * `['audio', 'video']`, which is also what excludes the 68 `MISSING_TYPE`
 * nodes' memberships (AV5 disposition: exclude) at the source rather than
 * leaving them to fail a migration_lookup downstream.
 *
 * ("Image" in the plugin name is historical — it predates the AV track. The
 * shape is site-agnostic; only the member type list differs.)
 *
 * @MigrateSource(
 *   id = "d7_image_group_membership",
 *   source_module = "og"
 * )
 */
class D7ImageGroupMembership extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('og_membership', 'ogm')
      ->fields('ogm', ['id', 'gid', 'etid'])
      ->condition('ogm.entity_type', 'node')
      ->condition('ogm.group_type', 'node');

    // Restrict to the configured member node types. The member's type is also
    // exposed: with more than one member bundle in play (AV has audio AND
    // video), the Group relation plugin id has to be derived per row rather
    // than defaulted, and `group_node:{bundle}` needs the bundle.
    $query->join('node', 'member', 'member.nid = ogm.etid');
    $query->condition('member.type', $this->memberTypes(), 'IN');
    $query->addField('member', 'type', 'member_type');

    // Restrict to collection/subcollection groups.
    $query->join('node', 'grp', 'grp.nid = ogm.gid');
    $query->condition('grp.type', ['collection', 'subcollection'], 'IN');
    $query->addField('grp', 'type', 'group_type');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => 'OG membership ID',
      'gid' => 'D7 group node NID (collection or subcollection)',
      'etid' => 'D7 member node NID',
      'group_type' => 'Group node type (collection or subcollection)',
    ];
  }

  /**
   * Node types whose memberships this instance should yield.
   *
   * @return string[]
   *   D7 node type machine names.
   */
  protected function memberTypes() {
    $types = $this->configuration['member_types'] ?? ['shanti_image'];
    return is_array($types) ? $types : [$types];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['id' => ['type' => 'integer', 'alias' => 'ogm']];
  }

}
