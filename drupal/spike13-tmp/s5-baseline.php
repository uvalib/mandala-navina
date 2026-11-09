<?php

/**
 * @file
 * Spike 13 S5 (throwaway): D7 footnote baseline and resolution rules per book.
 *
 * Run: drush scr drupal/spike13-tmp/s5-baseline.php
 */

require_once __DIR__ . '/s5-lib.php';

$books = [15256, 15582, 15988, 16110, 14158, 14679, 15641];
echo "d7bid\trule\tcites\tdefs\tdefs_nokey\tunresolved\tambiguous_keys\tdefs_uncited\tdefs_cited_twice\n";
foreach ($books as $d7bid) {
  $pages = s5_pages($d7bid);
  foreach (['book', 'next'] as $rule) {
    $r = s5_resolve($pages, $rule);
    $cites = array_merge([], ...array_values($r['cites']));
    $defs = array_merge([], ...array_values($r['defs']));
    $hits = [];
    foreach ($cites as $c) {
      if ($c['def_nid']) {
        $hits[$c['def_nid'] . '|' . $c['key']] = ($hits[$c['def_nid'] . '|' . $c['key']] ?? 0) + 1;
      }
    }
    $unres = array_filter($cites, fn($c) => !$c['def_nid']);
    $uncited = array_filter($defs, fn($d) => !$d['cited']);
    printf("%d\t%s\t%d\t%d\t%d\t%d\t%d\t%d\t%d\n", $d7bid, $rule, count($cites), count($defs),
      count(array_filter($defs, fn($d) => $d['key'] === NULL)), count($unres),
      count(array_unique(array_column(array_filter($cites, fn($c) => $c['ambiguous']), 'key'))),
      count($uncited) . ($uncited && count($uncited) < 6 ? '' : ''), count(array_filter($hits, fn($n) => $n > 1)));
    if ($rule === 'next' && $uncited && count($uncited) < 6) {
      foreach ($uncited as $d) {
        echo "    uncited key={$d['key']}: " . mb_substr(strip_tags($d['text']), 0, 90) . "\n";
      }
    }
  }
}
