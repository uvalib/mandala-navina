<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * D7 subcollection nodes with parent collection nid resolved via og_membership.
 *
 * @MigrateSource(
 *   id = "d7_image_subcollection",
 *   source_module = "og"
 * )
 */
class D7ImageSubcollection extends Node {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();

    // Subcollections belong to exactly one parent collection in D7 OG.
    // og_membership.etid = subcollection nid, og_membership.gid = collection nid.
    $query->leftJoin(
      'og_membership',
      'parent_og',
      "parent_og.etid = n.nid AND parent_og.entity_type = 'node' AND parent_og.group_type = 'node'"
    );
    $query->addField('parent_og', 'gid', 'parent_collection_nid');

    return $query;
  }

}
