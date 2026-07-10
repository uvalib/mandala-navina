<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * D7 OG user memberships in collection/subcollection groups.
 *
 * @MigrateSource(
 *   id = "d7_image_user_group_membership",
 *   source_module = "og"
 * )
 */
class D7ImageUserGroupMembership extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('og_membership', 'ogm')
      ->fields('ogm', ['id', 'gid', 'etid', 'state'])
      ->condition('ogm.entity_type', 'user')
      ->condition('ogm.group_type', 'node');

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
      'etid' => 'D7 user UID',
      'state' => 'Membership state (1=active)',
      'group_type' => 'Group node type',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['id' => ['type' => 'integer', 'alias' => 'ogm']];
  }

}
