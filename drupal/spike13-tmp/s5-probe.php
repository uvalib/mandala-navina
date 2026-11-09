<?php

/**
 * @file
 * Spike 13 S5 (throwaway): where 15582's cites/defs live, and samples.
 */

require_once __DIR__ . '/s5-lib.php';

foreach ([15582, 15641] as $d7bid) {
  echo "== $d7bid\n";
  $i = 0;
  $shown = 0;
  foreach (s5_pages($d7bid) as $nid => $p) {
    $i++;
    [$c, $d] = s5_parse($p['value']);
    if (!$c && !$d) {
      continue;
    }
    $num = array_filter(array_column($d, 0), fn($n) => $n !== NULL);
    printf("#%d d7=%d %s | cites=%s | defs num=%s nonum=%d\n", $i, $p['d7'], mb_substr($p['title'], 0, 40),
      $c ? min($c) . '-' . max($c) . ' (' . count($c) . ')' : '-', $num ? min($num) . '-' . max($num) . ' (' . count($num) . ')' : '-', count($d) - count($num));
    if ($d && count($d) > count($num) && $shown++ < 1) {
      preg_match_all('/<div[^>]*class="[^"]*footnote[^"]*"[^>]*>.{0,300}/s', $p['value'], $m);
      echo "   sample nonum: " . str_replace("\n", ' ', $m[0][0] ?? '') . "\n";
    }
  }
}
// 15641: what do its 10 citations look like?
foreach (s5_pages(15641) as $p) {
  if (preg_match_all('/.{0,80}name="nb\d+".{0,80}/s', $p['value'], $m)) {
    echo "15641 d7={$p['d7']}: " . str_replace("\n", ' ', $m[0][0]) . "\n";
    echo "  has 'n1' anchor anywhere: " . (preg_match('/name="n1"|id="n1"/', $p['value']) ? 'yes' : 'no') . "\n";
  }
}
