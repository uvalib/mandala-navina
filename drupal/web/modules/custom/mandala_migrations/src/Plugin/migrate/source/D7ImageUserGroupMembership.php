<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * D7 OG user memberships in collection/subcollection groups.
 *
 * ONE ROW PER (user, group) PAIR, not per og_membership row. D7 can record the
 * same user in the same group twice under different OG field names -- AV's
 * dump carries user memberships under both `og_group_ref` (1,207 rows) and
 * `og_user_node` (30), and two of those pairs overlap. D11's Group module
 * treats a membership as unique per (group, user), so migrating both rows would
 * attempt a duplicate GroupRelationship. Grouping on (gid, etid) and keying the
 * map on the lowest og_membership id collapses them.
 *
 * Verified behaviour-preserving for the Sprint 1 Images migration: its source
 * has 249 rows across 249 distinct (uid, gid) pairs, so nothing collapses.
 *
 * Both OG field names are kept deliberately. `og_user_node` is OG's user-side
 * reference field rather than a different KIND of relationship -- it still
 * means "this user is a member of this group" -- so filtering to `og_group_ref`
 * alone would silently drop 28 real AV memberships.
 *
 * ("Image" in the plugin name is historical — it predates the AV track; the
 * query is site-agnostic and AV reuses it unchanged apart from its source key.)
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
      ->fields('ogm', ['gid', 'etid'])
      ->condition('ogm.entity_type', 'user')
      ->condition('ogm.group_type', 'node');

    $query->join('node', 'grp', 'grp.nid = ogm.gid');
    $query->condition('grp.type', ['collection', 'subcollection'], 'IN');
    $query->addField('grp', 'type', 'group_type');

    $query->groupBy('ogm.gid');
    $query->groupBy('ogm.etid');
    $query->groupBy('grp.type');
    $query->addExpression('MIN(ogm.id)', 'id');
    // Active (1) wins over pending/blocked if the same pair appears twice.
    $query->addExpression('MIN(ogm.state)', 'state');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => 'Lowest OG membership ID for this (user, group) pair',
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
    return ['id' => ['type' => 'integer']];
  }

}
