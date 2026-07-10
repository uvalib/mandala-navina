<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 OG memberships: images (node) → collections/subcollections.
 *
 * Yields one row per og_membership record where the member is a shanti_image
 * node and the group is a collection or subcollection node.
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

    // Restrict to shanti_image members.
    $query->join('node', 'member', 'member.nid = ogm.etid');
    $query->condition('member.type', 'shanti_image');

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
      'etid' => 'D7 member node NID (shanti_image)',
      'group_type' => 'Group node type (collection or subcollection)',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['id' => ['type' => 'integer', 'alias' => 'ogm']];
  }

}
