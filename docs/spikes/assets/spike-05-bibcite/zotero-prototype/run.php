<?php

/**
 * Fetch + map only (no Drupal). Usage:
 *   php run.php users|groups <libraryId> [maxItems] [apiKey]
 */
require __DIR__ . '/ZoteroClient.php';
require __DIR__ . '/ZoteroMapper.php';

[, $type, $id] = $argv + [NULL, 'users', 0];
$max = (int) ($argv[3] ?? 60);
$client = new ZoteroClient(rtrim($type, 's'), (int) $id, $argv[4] ?? NULL);
$mapper = new ZoteroMapper();
$counts = [];
$rows = [];
foreach ($client->items(NULL, NULL, $max) as $item) {
  if (!($m = $mapper->map($item))) {
    continue;
  }
  $k = $m['zotero_type'] . ' -> ' . $m['type'] . ($m['fallback'] ? ' (FALLBACK)' : '');
  $counts[$k] = ($counts[$k] ?? 0) + 1;
  $rows[] = $m;
}
ksort($counts);
echo "mapped " . count($rows) . " items\n";
foreach ($counts as $k => $n) {
  echo str_pad((string) $n, 4) . $k . "\n";
}
echo "\nsample:\n" . json_encode($rows[0] ?? NULL, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
