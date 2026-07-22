<?php

/**
 * @file
 * Seeds demo content for the Spike 4b Option 3 prototype.
 *
 * Run via: ddev drush php:script modules/custom/spike_footnotes_demo/scripts/seed-demo.php
 * (path relative to the Drupal web root, or use an absolute path).
 *
 * Creates two 'page' nodes sharing book id (bid) 999, each with an inline
 * <footnotes data-value data-text> citation tag already resolved (matching
 * what the real migration-time transform would produce). Node A is
 * rendered standalone FIRST, seeding its entity render cache — reproducing
 * the exact scenario the CONFIRMED bug depends on (a page viewed
 * independently before ever appearing in a composite book view). Then both
 * pages' resolved footnote data is written to spike_footnotes_resolved.
 *
 * Visit /spike/footnotes-book-demo/999 afterward to see the Option 3
 * aggregation succeed regardless of node A's cache state.
 */

use Drupal\node\Entity\Node;

$bid = 999;

$node_a = Node::create([
  'type' => 'page',
  'title' => 'Spike 4b demo: Nangchu Doring (citing page, will be cache-seeded)',
  'body' => [
    'value' => '<p>The temple came from Sog sde of the Nag chu kha region'
      . '<footnotes data-value="1" data-text="At the beginning, this temple was known as dPon tshang lha khang.">'
      . '</footnotes>.</p>',
    'format' => 'spike_footnotes_format',
  ],
]);
$node_a->save();
$nid_a = (int) $node_a->id();

$node_b = Node::create([
  'type' => 'page',
  'title' => 'Spike 4b demo: Lo rgyus (citing page, fresh — never rendered before)',
  'body' => [
    'value' => '<p>The approach to the bKa\' \'gyur canon developed over centuries'
      . '<footnotes data-value="2" data-text="See also the Tibetan Literature volume on canon formation.">'
      . '</footnotes>.</p>',
    'format' => 'spike_footnotes_format',
  ],
]);
$node_b->save();
$nid_b = (int) $node_b->id();

// Reproduce the CONFIRMED bug's precondition: render node A standalone,
// as if a reader (or crawler, or a prior book view) had visited it
// directly. This populates Drupal's entity render cache for node A.
$view_builder = \Drupal::entityTypeManager()->getViewBuilder('node');
$build = $view_builder->view($node_a, 'full');
\Drupal::service('renderer')->renderInIsolation($build);
echo "Rendered node {$nid_a} standalone (entity render cache now seeded for it).\n";

// Populate the dedicated resolved-footnote table (Option 3's data source),
// completely independent of the render pass above.
$db = \Drupal\Core\Database\Database::getConnection();
$db->delete('spike_footnotes_resolved')->condition('bid', $bid)->execute();
$db->insert('spike_footnotes_resolved')
  ->fields(['bid', 'nid', 'page_weight', 'number', 'text'])
  ->values([$bid, $nid_a, 1, 1, 'At the beginning, this temple was known as dPon tshang lha khang.'])
  ->execute();
$db->insert('spike_footnotes_resolved')
  ->fields(['bid', 'nid', 'page_weight', 'number', 'text'])
  ->values([$bid, $nid_b, 2, 2, 'See also the Tibetan Literature volume on canon formation.'])
  ->execute();

echo "Seeded book bid={$bid}: nid {$nid_a} (cache-seeded) + nid {$nid_b} (fresh).\n";
echo "Visit /spike/footnotes-book-demo/{$bid} to see the Option 3 aggregation.\n";
