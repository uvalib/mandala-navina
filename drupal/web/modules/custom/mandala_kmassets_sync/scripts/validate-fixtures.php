<?php

/**
 * @file
 * Acceptance gate for the kmassets doc-builder (Sprint 1, 1a.8).
 *
 * Builds the kmasset doc for each migrated fixture node and diffs it against the
 * captured golden fixture (docs/planning/kmasset-fixtures/*.json), reporting per
 * field: MATCH / MISMATCH / MISSING (in fixture, not built) / NEW (built, not in
 * fixture).
 *
 * Run with:
 *   ddev drush scr drupal/web/modules/custom/mandala_kmassets_sync/scripts/validate-fixtures.php
 *
 * The diff excludes fields that legitimately diverge from the D7 production
 * golden (see the builder docblock + the validation task):
 *   - Table D copyField aggregates — Solr fills these at index time.
 *   - Volatile index-set fields.
 *   - Table B collection/access fields — Group-based, owned by 1b.
 *   - User fields — D11 user migration deferred (owner = uid 0).
 *   - url_html/ajax/json — D11 single-site URL scheme is a deferred decision.
 */

use Drupal\node\Entity\Node;

$fixtures = [
  1028396 => 'sample-image-doc-1.json',
  1087551 => 'sample-image-multidesc.json',
  1243616 => 'sample-image-tibetan.json',
];

// Fields excluded from the diff, with the reason bucket.
$excluded = [
  // Table D — Solr copyField aggregates.
  'ids' => 'tableD', 'str' => 'tableD', 'titles' => 'tableD', 'names' => 'tableD',
  'creators' => 'tableD', 'descriptions' => 'tableD',
  // Volatile.
  'solrdoc_ts_i' => 'volatile', 'timestamp' => 'volatile', '_version_' => 'volatile',
  // Table B — collection/access (1b).
  'collection_idfacet' => 'tableB', 'collection_uid_s' => 'tableB',
  'collection_nid' => 'tableB', 'collection_title' => 'tableB',
  'collection_uid_path_ss' => 'tableB', 'collection_title_path_ss' => 'tableB',
  'collection_nid_path_is' => 'tableB',
  // User — uid not migrated.
  'node_user' => 'user', 'node_user_i' => 'user', 'node_user_full_s' => 'user',
  // URL scheme — deferred.
  'url_html' => 'url', 'url_ajax' => 'url', 'url_json' => 'url',
  // Identity — D11 reassigns nids (single-site nid collisions make D7-nid
  // preservation impossible), so id/uid differ. How uid survives the nid
  // reassignment is an open migration-architecture question (1a.8 finding).
  'id' => 'identity', 'uid' => 'identity',
];

$base = \Drupal::root() . '/../config/sync';
$fixture_dir = \Drupal::root() . '/../../docs/planning/kmasset-fixtures';
$builder = \Drupal::service('mandala_kmassets_sync.doc_builder');

$norm = function ($v) {
  // Normalise scalars vs single-element arrays for comparison; Solr stores many
  // single values as 1-element arrays.
  if (is_array($v) && count($v) === 1) {
    $v = $v[0];
  }
  return is_scalar($v) ? (string) $v : json_encode($v);
};

$grand = ['match' => 0, 'mismatch' => 0, 'missing' => 0, 'new' => 0];

foreach ($fixtures as $nid => $file) {
  print "\n========================================================\n";
  print "  nid $nid — $file\n";
  print "========================================================\n";

  // D11 reassigns nids, so resolve the dest id via the migration map.
  $destid = \Drupal::database()->select('migrate_map_d7_images_shanti_image', 'm')
    ->fields('m', ['destid1'])
    ->condition('sourceid1', $nid)
    ->execute()
    ->fetchField();
  $node = $destid ? Node::load($destid) : NULL;
  if (!$node) {
    print "  !! node not migrated — skipping\n";
    continue;
  }
  print "  (D7 nid $nid → D11 nid {$node->id()})\n";
  $golden = json_decode(file_get_contents("$fixture_dir/$file"), TRUE);
  $built = $builder->build($node);
  if ($built === NULL) {
    print "  !! builder returned NULL (unpublished or unconfigured bundle)\n";
    continue;
  }

  $all_keys = array_unique(array_merge(array_keys($golden), array_keys($built)));
  sort($all_keys);

  $mismatches = [];
  foreach ($all_keys as $key) {
    if (isset($excluded[$key])) {
      continue;
    }
    $in_g = array_key_exists($key, $golden);
    $in_b = array_key_exists($key, $built);
    if ($in_g && $in_b) {
      if ($norm($golden[$key]) === $norm($built[$key])) {
        $grand['match']++;
      }
      else {
        $grand['mismatch']++;
        $mismatches[] = sprintf("  ~ MISMATCH %s\n      golden: %s\n      built : %s",
          $key, $norm($golden[$key]), $norm($built[$key]));
      }
    }
    elseif ($in_g) {
      $grand['missing']++;
      $mismatches[] = sprintf("  - MISSING  %s (in golden, not built): %s", $key, $norm($golden[$key]));
    }
    else {
      $grand['new']++;
      $mismatches[] = sprintf("  + NEW      %s (built, not in golden): %s", $key, $norm($built[$key]));
    }
  }

  if (empty($mismatches)) {
    print "  ✓ all compared fields match\n";
  }
  else {
    print implode("\n", $mismatches) . "\n";
  }
}

print "\n========================================================\n";
printf("  TOTALS: %d match, %d mismatch, %d missing, %d new\n",
  $grand['match'], $grand['mismatch'], $grand['missing'], $grand['new']);
print "========================================================\n";
