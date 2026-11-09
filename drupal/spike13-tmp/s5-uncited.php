<?php

/**
 * @file
 * Spike 13 S5 (throwaway): 15582's uncited definitions, grouped by Notes page.
 */

require_once __DIR__ . '/s5-lib.php';

$pages = s5_pages(15582);
$r = s5_resolve($pages, 'next');
$allCites = [];
foreach ($r['cites'] as $nid => $cs) {
  foreach ($cs as $c) {
    $allCites[$c['key']][] = $pages[$nid]['d7'];
  }
}
foreach ($r['defs'] as $nid => $defs) {
  $un = array_filter($defs, fn($d) => !$d['cited']);
  if ($un) {
    $keys = array_column($un, 'key');
    printf("notes d7=%d defs=%d uncited=%d keys=%s\n", $pages[$nid]['d7'], count($defs), count($un), implode(' ', array_slice($keys, 0, 12)));
    $k = $keys[0];
    echo "   first uncited text: " . mb_substr(strip_tags($un[array_key_first($un)]['text']), 0, 100) . "\n";
    echo "   any citation with key '$k' anywhere: " . (isset($allCites[$k]) ? implode(',', $allCites[$k]) : 'none') . "\n";
    // Is the key cited in raw HTML in some other form?
    $kk = preg_quote($k, '/');
    foreach ($pages as $p) {
      if (preg_match('/href="#n' . $kk . '"/', $p['value'])) {
        echo "   href=#n$k found on d7=" . $p['d7'] . "\n";
      }
    }
  }
}
