<?php
/**
 * T5 sample run: migrate every Nth tracked transcript (and all its units) with
 * d7_av_transcript and d7_av_tcu, to measure time and memory on a real database.
 * Plan: docs/planning/t5-transcript-migration-plan.md ("Running it", step 2).
 *
 * Usage, inside the app container. Copy the file in first (the repo's scripts/ is not
 * in the image): scp to the host, then `sudo docker cp` to /tmp. vendor/bin/drush is a
 * shell wrapper, so call drush.php with php to pass a memory limit:
 *   cd /opt/drupal/app/drupal && php -d memory_limit=1G vendor/drush/drush/drush.php \
 *     php:script /tmp/t5-sample-run.php -- <plan|run|units> [every]
 *   plan   print the sample size and unit count, change nothing
 *   run    import transcripts then units
 *   units  import units only (transcripts already imported)
 *   every  take every Nth transcript by trid (default 20, about 5%)
 *
 * HOW IT SCOPES. Both migrations take a `trids` source option, but setting it on
 * one migration is not enough: core makes d7_av_tcu require d7_av_transcript to
 * be fully processed (migration_lookup adds that dependency itself), which a
 * sample can never satisfy. So a runtime config override scopes BOTH, and the
 * transcript migration's source then IS the sample. Nothing is written to config.
 *
 * SIDE EFFECT, FIXED AT THE END: rebuilding plugin definitions under the override
 * writes the scoped definitions to the persistent plugin cache. If that is left
 * behind, every later `migrate:import` in the container sees only the sample
 * (found 2026-11-16: the full run "processed 0 items"). The last statement clears
 * the cache. If this script dies before then, run
 *   drush ev '\Drupal::service("plugin.manager.migration")->clearCachedDefinitions();'
 * and check `drush migrate:status d7_av_transcript,d7_av_tcu` shows the full totals
 * (5,343 and 243,005).
 *
 * Sample rows stay in the tables; the full run updates them in place.
 */
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;

$args = $extra ?? [];
$step = $args[0] ?? 'plan';
$every = (int) ($args[1] ?? 20);
$manager = \Drupal::service('plugin.manager.migration');
$src = $manager->createInstance('d7_av_transcript')->getSourcePlugin();
$db = $src->getDatabase();
$all = $db->query("SELECT trid FROM transcripts_apachesolr_transcript WHERE module='transcripts_node' AND type='node' ORDER BY trid")->fetchCol();
$sample = [];
foreach ($all as $i => $trid) { if ($i % $every === 0) { $sample[] = (int) $trid; } }
$units = (int) $db->select('tcu', 'c')->condition('c.trid', $sample, 'IN')->countQuery()->execute()->fetchField();
printf("tracked=%d sample=%d (every %d) units_in_sample=%d\n", count($all), count($sample), $every, $units);
if (!in_array($step, ['run', 'units'], TRUE)) { return; }

// Scope BOTH migrations to the sample with a runtime config override (nothing is written to config), so
// d7_av_transcript's source is the sample and counts as fully processed for d7_av_tcu's requirement check.
$override = new class($sample) implements \Drupal\Core\Config\ConfigFactoryOverrideInterface {
  public function __construct(private array $trids) {}
  public function loadOverrides($names) {
    $o = [];
    foreach (['migrate_plus.migration.d7_av_transcript', 'migrate_plus.migration.d7_av_tcu'] as $n) {
      if (in_array($n, $names, TRUE)) { $o[$n] = ['source' => ['trids' => $this->trids]]; }
    }
    return $o;
  }
  public function getCacheSuffix() { return 't5sample'; }
  public function createConfigObject($name, $collection = \Drupal\Core\Config\StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return new \Drupal\Core\Cache\CacheableMetadata(); }
};
\Drupal::configFactory()->addOverride($override);
$manager->clearCachedDefinitions();
foreach ($step === 'units' ? ['d7_av_tcu'] : ['d7_av_transcript', 'd7_av_tcu'] as $id) {
  $m = $manager->createInstance($id);
  $t0 = microtime(true);
  $r = (new MigrateExecutable($m, new MigrateMessage()))->import();
  printf("%s: result=%d seconds=%.1f peak_mem_mb=%.0f\n", $id, $r, microtime(true) - $t0, memory_get_peak_usage(TRUE) / 1048576);
}
$d = \Drupal::database();
printf("av_transcript=%d av_tcu=%d\n", $d->query('select count(*) from av_transcript')->fetchField(), $d->query('select count(*) from av_tcu')->fetchField());

// Do not leave the scoped definitions in the persistent plugin cache.
\Drupal::service('plugin.manager.migration')->clearCachedDefinitions();
