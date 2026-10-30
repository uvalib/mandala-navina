<?php

/**
 * Spike 5 throwaway: give three local references the title, year, first
 * author and legacy key of REAL D7 Sources nodes (tab-separated rows in
 * real.tsv beside this file: nid, title, year, first, last), so a legacy URL can be
 * checked against the real D7 record.
 */
$etm = \Drupal::entityTypeManager();
$refs = $etm->getStorage('bibcite_reference');
$contribs = $etm->getStorage('bibcite_contributor');
$targets = [47, 46, 48];
foreach (file(__DIR__ . '/real.tsv', FILE_IGNORE_NEW_LINES) as $i => $line) {
  [$nid, $title, $yr, $first, $last] = explode("\t", $line);
  $c = $contribs->create(['first_name' => $first, 'last_name' => $last]);
  $c->save();
  $r = $refs->load($targets[$i]);
  $r->set('title', $title)->set('bibcite_year', $yr ?: NULL)
    ->set('author', [['target_id' => $c->id(), 'category' => 'primary', 'role' => 'author']])
    ->set('field_legacy_site', 'sources')->set('field_legacy_nid', $nid)->save();
  echo "ref {$r->id()} <= D7 nid $nid: $title\n";
}
