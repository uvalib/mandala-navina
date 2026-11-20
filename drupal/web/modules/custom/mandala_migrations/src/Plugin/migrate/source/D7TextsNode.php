<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * D7 Texts `book` nodes, with field_book_content as D7 actually displays it.
 *
 * The stock d7_node source reads every field from its revision table at
 * node.vid. For 43 Texts books, field_data_field_book_content carries a
 * revision_id that exists in no node_revision row (some past script wrote the
 * current value under an invented revision id), so the revision table at
 * node.vid holds an OLDER text than the one D7 renders. D7 renders the current
 * value from field_data, and that is what has to migrate. In 6 of the 43 the
 * text actually differs (2026-11-20 production dump: five yoga practice pages
 * two characters apart, and test page 47461 whose shown body is empty).
 *
 * No other book field has this mismatch (checked over every book field
 * instance on the same dump), so only field_book_content is re-read.
 *
 * Deleted nodes need no handling here: 1,441 field_book_content rows belong to
 * nodes that no longer exist, and the parent only reads fields for rows of the
 * node table.
 *
 * @MigrateSource(
 *   id = "d7_texts_node",
 *   source_module = "node"
 * )
 */
class D7TextsNode extends Node {

  /**
   * {@inheritdoc}
   *
   * Honours an optional `nids` source-config list, like D7AvNode.
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

    // FETCH_ASSOC explicitly, as in D7AvNode: the default fetch mode of a
    // migrate SqlBase query is not guaranteed.
    $current = $this->select('field_data_field_book_content', 'c')
      ->fields('c', ['delta', 'field_book_content_value', 'field_book_content_format'])
      ->condition('c.entity_type', 'node')
      ->condition('c.entity_id', $row->getSourceProperty('nid'))
      ->condition('c.deleted', 0)
      ->orderBy('c.delta')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $values = [];
    foreach ($current as $record) {
      $values[] = [
        'value' => $record['field_book_content_value'],
        'format' => $record['field_book_content_format'],
      ];
    }
    $row->setSourceProperty('field_book_content', $values);

    return TRUE;
  }

}
