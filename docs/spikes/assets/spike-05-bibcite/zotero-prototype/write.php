<?php

/**
 * Spike 5 prototype writer (LOCAL DDEV ONLY, throwaway). Run with drush:
 *
 *   MODE=upsert|mutate|render|clean ZTYPE=user ZID=475425 ZMAX=40 \
 *     ddev drush php:script docs/spikes/assets/spike-05-bibcite/zotero-prototype/write.php
 *
 * Dedup key: PROTOTYPE ONLY stores "zotero:<type>/<id>:<itemKey>" in
 * bibcite_remote_db_name. D7 populated remote_db_* on real rows, so production
 * needs its own dedicated field; this reuse is just to avoid a config change.
 */
require __DIR__ . '/ZoteroClient.php';
require __DIR__ . '/ZoteroMapper.php';

$mode = getenv('MODE') ?: 'upsert';
$ztype = getenv('ZTYPE') ?: 'user';
$zid = (int) (getenv('ZID') ?: 475425);
$max = (int) (getenv('ZMAX') ?: 40);
$etm = \Drupal::entityTypeManager();
$refs = $etm->getStorage('bibcite_reference');
$contribs = $etm->getStorage('bibcite_contributor');
$kws = $etm->getStorage('bibcite_keyword');
$prefix = "zotero:$ztype/$zid:";

function find_ref($refs, $dedup) {
  $ids = $refs->getQuery()->accessCheck(FALSE)->condition('bibcite_remote_db_name', $dedup)->execute();
  return $ids ? $refs->load(reset($ids)) : NULL;
}

function contributor($contribs, $c) {
  $q = $contribs->getQuery()->accessCheck(FALSE)->condition('last_name', $c['last']);
  $c['first'] !== '' ? $q->condition('first_name', $c['first']) : $q->notExists('first_name');
  $ids = $q->execute();
  if ($ids) {
    return reset($ids);
  }
  $e = $contribs->create(['first_name' => $c['first'], 'last_name' => $c['last']]);
  $e->save();
  return $e->id();
}

function keyword($kws, $name) {
  $ids = $kws->getQuery()->accessCheck(FALSE)->condition('name', $name)->execute();
  if ($ids) {
    return reset($ids);
  }
  $e = $kws->create(['name' => $name]);
  $e->save();
  return $e->id();
}

if ($mode === 'clean') {
  $ids = $refs->getQuery()->accessCheck(FALSE)->condition('bibcite_remote_db_name', 'zotero:', 'STARTS_WITH')->execute();
  $other = $refs->getQuery()->accessCheck(FALSE)->count()->execute() - count($ids);
  if ($other > 0) {
    exit("Refusing to clean: $other non-Zotero references exist.\n");
  }
  $refs->delete($refs->loadMultiple($ids));
  $contribs->delete($contribs->loadMultiple());
  $kws->delete($kws->loadMultiple());
  echo "deleted " . count($ids) . " references and all contributors/keywords\n";
  return;
}

if ($mode === 'mutate') {
  $ids = $refs->getQuery()->accessCheck(FALSE)->condition('bibcite_remote_db_name', $prefix, 'STARTS_WITH')->range(0, 1)->execute();
  $r = $refs->load(reset($ids));
  $r->set('title', 'LOCALLY EDITED TITLE')->save();
  echo "mutated " . $r->id() . " (" . $r->get('bibcite_remote_db_name')->value . ")\n";
  return;
}

if ($mode === 'render') {
  $styler = \Drupal::service('bibcite.citation_styler');
  $ids = $refs->getQuery()->accessCheck(FALSE)->condition('bibcite_remote_db_name', $prefix, 'STARTS_WITH')->sort('id')->range(0, (int) (getenv('NRENDER') ?: 6))->execute();
  $styler->setStyleById(getenv('STYLE') ?: 'cse_name_year_8th_edition');
  foreach ($refs->loadMultiple($ids) as $r) {
    $data = \Drupal::service('serializer')->normalize($r, 'csl');
    echo '[' . $r->bundle() . '] ' . trim(strip_tags($styler->render($data))) . "\n";
  }
  return;
}

// MODE=upsert.
$client = new ZoteroClient($ztype, $zid, getenv('ZKEY') ?: NULL);
$mapper = new ZoteroMapper();
$stat = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
foreach ($client->items(NULL, NULL, $max) as $item) {
  if (!($m = $mapper->map($item))) {
    $stat['skipped']++;
    continue;
  }
  $dedup = $prefix . $m['key'];
  $ref = find_ref($refs, $dedup);
  $want = ['title' => $m['title']] + $m['fields'] + ['bibcite_remote_db_name' => $dedup, 'bibcite_remote_db_provider' => 'zotero'];
  $wantAuthors = [];
  foreach ($m['contributors'] as $c) {
    $wantAuthors[] = [contributor($contribs, $c), $c['category'], $c['role']];
  }
  $wantKw = array_map(fn($t) => keyword($kws, $t), $m['tags']);
  if (!$ref) {
    $ref = $refs->create(['type' => $m['type']]);
    $verb = 'created';
  }
  else {
    $verb = 'unchanged';
    $haveAuthors = array_map(fn($i) => [(int) $i['target_id'], $i['category'], $i['role']], $ref->get('author')->getValue());
    $haveKw = array_column($ref->get('keywords')->getValue(), 'target_id');
    sort($haveKw);
    $sw = $wantKw;
    sort($sw);
    if ($haveAuthors != array_map(fn($a) => [(int) $a[0], $a[1], $a[2]], $wantAuthors) || array_map('intval', $haveKw) != array_map('intval', $sw)) {
      $verb = 'updated';
    }
    foreach ($want as $f => $v) {
      if ((string) $ref->get($f)->value !== (string) $v) {
        $verb = 'updated';
      }
    }
  }
  if ($verb !== 'unchanged') {
    foreach ($want as $f => $v) {
      $ref->set($f, $v);
    }
    $ref->set('author', array_map(fn($a) => ['target_id' => $a[0], 'category' => $a[1], 'role' => $a[2]], $wantAuthors));
    $ref->set('keywords', array_map(fn($k) => ['target_id' => $k], $wantKw));
    $ref->save();
  }
  $stat[$verb]++;
}
echo json_encode($stat) . "  total references now: " . $refs->getQuery()->accessCheck(FALSE)->count()->execute() . "\n";
