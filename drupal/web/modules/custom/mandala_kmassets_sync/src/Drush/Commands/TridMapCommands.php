<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\mandala_kmassets_sync\Transcript\LegacyTridMapResolver;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Loads the D7 node-to-trid mapping that feeds kmassets `trid_i`.
 *
 * Source: D7 `transcripts_apachesolr_transcript` (`id` is the D7 nid,
 * `trid` the transcript id), read through the `migrate_av` connection. Rows
 * whose D7 node is not in the AV node table are skipped. The table is rebuilt
 * from scratch each run, so a re-run is idempotent.
 */
class TridMapCommands extends DrushCommands {

  use AutowireTrait;

  private const LEGACY_SITE = 'audio-video';

  public function __construct(
    private readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Load the D7 node-to-trid map into mandala_kmassets_trid_map.
   */
  #[CLI\Command(name: 'kmassets:load-trid-map')]
  #[CLI\Option(name: 'dry-run', description: 'Report counts without writing.')]
  #[CLI\Usage(name: 'drush kmassets:load-trid-map --dry-run', description: 'Report what would load.')]
  public function load(array $options = ['dry-run' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];
    $source = Database::getConnection('default', 'migrate_av');

    $rows = $source->query(
      "SELECT t.id AS nid, t.trid
       FROM {transcripts_apachesolr_transcript} t
       INNER JOIN {node} n ON n.nid = t.id
       WHERE t.module = 'transcripts_node' AND t.type = 'node' AND t.status = 1"
    )->fetchAll();

    $this->logger()->notice('{n} D7 transcript rows to map.', ['n' => count($rows)]);
    if ($dryRun) {
      return;
    }

    $tx = $this->database->startTransaction();
    try {
      $this->database->truncate(LegacyTridMapResolver::TABLE)->execute();
      $insert = $this->database->insert(LegacyTridMapResolver::TABLE)
        ->fields(['legacy_site', 'legacy_nid', 'trid']);
      foreach ($rows as $row) {
        $insert->values([self::LEGACY_SITE, (int) $row->nid, (int) $row->trid]);
      }
      $insert->execute();
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      throw $e;
    }
    $this->logger()->success('Loaded {n} rows into {t}.', ['n' => count($rows), 't' => LegacyTridMapResolver::TABLE]);
  }

}
