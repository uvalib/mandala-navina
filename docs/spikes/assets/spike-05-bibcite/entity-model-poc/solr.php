<?php

/**
 * Spike 5 throwaway (LOCAL DDEV ONLY): kmassets doc for a bibcite_reference.
 * BUILDS ONLY. Never posts to Solr (effective solr_master_url is empty locally,
 * and this script has no sink call). Run access.php first.
 */
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\mandala_group_inheritance\Access\CollectionVisibility;

$etm = \Drupal::entityTypeManager();
$refs = $etm->getStorage('bibcite_reference');

// 1. The existing builder takes a NodeInterface.
try {
  \Drupal::service('mandala_kmassets_sync.doc_builder')->build($refs->load(47));
  echo "existing builder: accepted a reference (unexpected)\n";
}
catch (\Throwable $e) {
  echo "existing builder on a reference: " . get_class($e) . "\n  " . substr($e->getMessage(), 0, 150) . "\n";
}

// 2. PoC builder: base + collection fields for a reference.
function ref_doc($ref): array {
  $group = CollectionVisibility::owningGroup($ref);
  $doc = [
    'id' => 'sources-11-' . $ref->id(),
    'uid' => 'sources-11-' . $ref->id(),
    'title' => $ref->label(),
    'service' => 'sources',
    'asset_type' => 'sources',
    'asset_subtype' => $ref->bundle(),
    'url_html' => '/sources/' . $ref->id(),
    'visibility_i' => 1,
    'visibility_s' => 'public',
    'solrdoc_ts_i' => time(),
  ];
  if ($ref->hasField('field_legacy_nid') && !$ref->get('field_legacy_nid')->isEmpty()) {
    $doc['uid_legacy_s'] = 'sources-' . $ref->get('field_legacy_nid')->value;
  }
  if ($group) {
    $v = CollectionVisibility::forNode($ref, $group);
    $doc['visibility_i'] = CollectionVisibility::code($v);
    $doc['visibility_s'] = $v;
    $doc['collection_uid_s'] = ($group->get('field_legacy_site')->value ?: 'images') . '-11-' . $group->id();
    $doc['collection_title'] = $group->label();
  }
  return $doc;
}
echo "\nsample doc for reference 47 (private collection):\n" . json_encode(ref_doc($refs->load(47)), JSON_PRETTY_PRINT) . "\n";

// 3. Does the real reader-side filter agree with Drupal access?
//    Evaluate the member's fq clauses the way the proxy would: anonymous gets
//    visibility_i:1; authenticated adds (1 3) and their private collection uids.
$u = fn($n) => current($etm->getStorage('user')->loadByProperties(['name' => $n]));
$who = ['anonymous' => new AnonymousUserSession(), 'outsider' => $u('spike5_outsider'), 'member of private' => $u('spike5_member')];
$builder = \Drupal::service('mandala_solr_visibility.token_builder');
printf("\n%-18s %-14s %-14s %-14s %-14s\n", 'viewer: solr | drupal', '47 private', '46 public', '45 uva', '48 none');
$bad = 0;
foreach ($who as $label => $acct) {
  $fq = $builder->build($acct);
  preg_match('/collection_uid_s:\(([^)]*)\)/', (string) $fq, $m);
  $uids = isset($m[1]) ? explode('%20', $m[1]) : [];
  $cells = [];
  foreach ([47, 46, 45, 48] as $id) {
    $ref = $refs->load($id);
    $d = ref_doc($ref);
    $solr = $acct->isAnonymous() ? $d['visibility_i'] === 1 : (in_array($d['visibility_i'], [1, 3], TRUE) || in_array($d['collection_uid_s'] ?? '', $uids, TRUE));
    $dru = $ref->access('view', $acct);
    $bad += $solr !== $dru;
    $cells[] = ($solr ? 'ALLOW' : 'deny') . '|' . ($dru ? 'ALLOW' : 'deny') . ($solr === $dru ? '' : ' MISMATCH');
  }
  printf("%-18s %-14s %-14s %-14s %-14s\n", $label, ...$cells);
}
echo $bad ? "\nMISMATCHES: $bad\n" : "\nSolr-side and Drupal-side agree for all 12 cases\n";
