<?php

/**
 * T5 text fidelity: every D7 tier and speaker value of a migrated unit, after
 * NFC normalisation, equals what D11 stored. Run through the verify script, or:
 *
 *   D7DB=d7_av ddev drush php:script scripts/verify-av-transcript-text.php
 *
 * MySQL cannot normalise Unicode, so this streams the D7 rows in chunks and
 * compares in PHP. It also counts how many values NFC changed, which must be
 * the number the plan predicts, and prints OK/FAIL lines for the shell script.
 */

use Drupal\Core\Database\Database;

ini_set('memory_limit', '2G');

$d7db = getenv('D7DB') ?: 'd7_av';
$info = Database::getConnectionInfo('default')['default'];
$info['database'] = $d7db;
Database::addConnectionInfo('t5verify_d7', 'default', $info);
$d7 = Database::getConnection('default', 't5verify_d7');
$d11 = Database::getConnection();

if (!class_exists(\Normalizer::class)) {
  echo "  FAIL the PHP intl extension is missing\n";
  return;
}

$result = [];
foreach (['tcu_tier' => 'tiers', 'tcu_speaker' => 'speakers'] as $table => $field) {
  $compared = 0; $mismatch = 0; $changed = 0; $missingUnit = 0; $skippedOrphans = 0; $examples = [];
  $maxId = (int) $d7->query("SELECT MAX(tcuid) FROM {{$table}}")->fetchField();
  for ($from = 0; $from <= $maxId; $from += 5000) {
    $to = $from + 4999;
    $rows = $d7->query("SELECT tcuid, tier, value FROM {{$table}} WHERE tcuid BETWEEN :a AND :b", [':a' => $from, ':b' => $to])->fetchAll(\PDO::FETCH_ASSOC);
    if (!$rows) {
      continue;
    }
    $stored = [];
    foreach ($d11->query("SELECT id, $field FROM {av_tcu} WHERE id BETWEEN :a AND :b", [':a' => $from, ':b' => $to])->fetchAllKeyed() as $id => $json) {
      $stored[(int) $id] = json_decode((string) $json, TRUE) ?: [];
    }
    foreach ($rows as $r) {
      $id = (int) $r['tcuid'];
      if (!isset($stored[$id])) {
        // Orphan-trid units and orphan tier rows are not migrated: counted, not compared.
        $skippedOrphans++;
        continue;
      }
      $expected = \Normalizer::normalize((string) $r['value'], \Normalizer::FORM_C);
      if ($expected !== (string) $r['value']) {
        $changed++;
      }
      $compared++;
      if (!array_key_exists($r['tier'], $stored[$id]) || (string) $stored[$id][$r['tier']] !== $expected) {
        $mismatch++;
        if (count($examples) < 3) {
          $examples[] = "$table tcuid $id tier {$r['tier']}";
        }
      }
    }
    // Keys in D11 that D7 does not have (a value invented or kept stale).
  }
  $result[$table] = compact('compared', 'mismatch', 'changed', 'skippedOrphans', 'examples');
}

// D11 values with no D7 row behind them.
$extra = 0;
foreach (['tcu_tier' => 'tiers', 'tcu_speaker' => 'speakers'] as $table => $field) {
  $d7count = (int) $d7->query("SELECT COUNT(*) FROM {{$table}} x JOIN {tcu} c ON c.tcuid = x.tcuid JOIN {transcripts_apachesolr_transcript} t ON t.trid = c.trid AND t.module = 'transcripts_node' AND t.type = 'node'")->fetchField();
  $d11count = 0;
  foreach ($d11->query("SELECT $field FROM {av_tcu}")->fetchCol() as $json) {
    $d11count += count(json_decode((string) $json, TRUE) ?: []);
  }
  $result[$table]['d7_rows_of_migrated_units'] = $d7count;
  $result[$table]['d11_values'] = $d11count;
}

$line = function (string $label, bool $ok, string $detail): void {
  printf("  %s %-58s %s\n", $ok ? 'OK  ' : 'FAIL', $label, $detail);
};
foreach ($result as $table => $r) {
  $line("$table values equal D7's after NFC", $r['mismatch'] === 0, $r['mismatch'] === 0 ? (string) $r['compared'] : $r['mismatch'] . ' differ, e.g. ' . implode('; ', $r['examples']));
  $line("$table: D11 holds exactly D7's values for migrated units", $r['d7_rows_of_migrated_units'] === $r['d11_values'], "D7 {$r['d7_rows_of_migrated_units']}, D11 {$r['d11_values']}");
  printf("  INFO %-58s %d\n", "$table values NFC changed (migrated units)", $r['changed']);
  printf("  INFO %-58s %d\n", "$table rows on units not migrated (orphan trids / no unit)", $r['skippedOrphans']);
}
