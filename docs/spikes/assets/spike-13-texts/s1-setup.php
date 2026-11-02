<?php

/**
 * Spike 13 S1 setup (throwaway). Run: ddev drush php:script <this file>.
 *
 * Creates a `book` node type, allows it in Book's settings, and creates
 * stand-in nodes (same nid/title/status as D7) for the sampled books, so
 * the shipped d7_book migration has nodes to attach outline rows to.
 */

use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

const S1_BIDS = [53536, 15641, 53126, 14679];

if (!NodeType::load('book')) {
  NodeType::create(['type' => 'book', 'name' => 'Book page'])->save();
  echo "created node type book\n";
}
\Drupal::configFactory()->getEditable('book.settings')
  ->set('allowed_types', ['book'])
  ->set('child_type', 'book')
  ->save();

$src = Database::getConnection('default', 'migrate');
$rows = $src->query(
  'SELECT n.nid, n.title, n.status FROM {node} n JOIN {book} b ON b.nid = n.nid WHERE b.bid IN (:bids[]) ORDER BY n.nid',
  [':bids[]' => S1_BIDS]
)->fetchAll();

$created = 0;
foreach ($rows as $r) {
  if (Node::load($r->nid)) {
    continue;
  }
  Node::create([
    'nid' => (int) $r->nid,
    'type' => 'book',
    'title' => $r->title,
    'status' => (int) $r->status,
    'uid' => 1,
  ])->save();
  $created++;
}
echo "stand-in nodes: source rows=" . count($rows) . " created=$created\n";

$mlids = $src->query(
  'SELECT b.mlid FROM {book} b WHERE b.bid IN (:bids[]) ORDER BY b.mlid',
  [':bids[]' => S1_BIDS]
)->fetchCol();
file_put_contents('/tmp/s1-mlids.txt', implode(',', $mlids));
echo "mlids for idlist: " . count($mlids) . "\n";
