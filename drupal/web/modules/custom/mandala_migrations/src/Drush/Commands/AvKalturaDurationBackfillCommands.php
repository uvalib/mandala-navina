<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Backfill for `field_kaltura_duration`, a gap AV15 uncovered directly.
 *
 * D7's "avduration" row (`shanticon-hourglass`, e.g. "3 min 14 sec") was
 * assumed during AV15's build to be unmigratable -- checked against the
 * PBCore instantiation paragraph's own `field_duration` sub-field, found
 * empty on the examples checked, and written off as "read live from the
 * Kaltura entry itself." That was wrong in a useful way: D7 does read it
 * from the Kaltura entry, but not live -- `node_kaltura.kaltura_duration`
 * is D7's own local cache of it (an `int`, seconds), joined to a node via
 * `field_video`/`field_audio`'s `field_*_entryid`. Confirmed directly:
 * D7 nid 33126's `node_kaltura` row has `kaltura_duration = 194`, which is
 * exactly the "3 min 14 sec" the live page renders. D11 already migrated
 * the Kaltura entry id itself (`field_video`/`field_audio`'s
 * `field_video_entry_id`/`field_audio_entry_id`, `kaltura_media` field
 * type) but never captured the duration -- no migration ever targeted
 * `node_kaltura` at all. This is a plain node-level backfill (no migrate
 * map involved): D11 already has the entry id needed to join straight to
 * D7's `node_kaltura` table.
 */
class AvKalturaDurationBackfillCommands extends DrushCommands {

  use AutowireTrait;

  private const BUNDLE_FIELDS = [
    'video' => 'field_video',
    'audio' => 'field_audio',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'av:backfill-kaltura-duration')]
  #[CLI\Option(name: 'dry-run', description: 'Report coverage without saving any node.')]
  #[CLI\Usage(name: 'drush av:backfill-kaltura-duration --dry-run', description: 'Report what would change.')]
  #[CLI\Usage(name: 'drush av:backfill-kaltura-duration', description: 'Backfill for real.')]
  public function backfill(array $options = ['dry-run' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];
    $db = Database::getConnection();
    $sourceDb = Database::getConnectionInfo('migrate_av')['default']['database'];
    $storage = $this->entityTypeManager->getStorage('node');

    $updated = 0;
    $alreadyCorrect = 0;
    $noKalturaRow = 0;
    foreach (self::BUNDLE_FIELDS as $bundle => $fieldName) {
      $valueColumn = "{$fieldName}_entry_id";
      $rows = $db->query(
        "SELECT n.entity_id AS nid, n.$valueColumn AS entry_id, d.field_kaltura_duration_value AS current_value
         FROM {node__$fieldName} n
         LEFT JOIN {node__field_kaltura_duration} d ON d.entity_id = n.entity_id
         WHERE n.$valueColumn IS NOT NULL AND n.$valueColumn != ''"
      )->fetchAll();

      foreach ($rows as $row) {
        $duration = $db->query(
          "SELECT kaltura_duration FROM $sourceDb.node_kaltura WHERE kaltura_entryid = :entryid",
          [':entryid' => $row->entry_id]
        )->fetchField();

        if ($duration === FALSE || $duration === NULL || (int) $duration <= 0) {
          $noKalturaRow++;
          continue;
        }
        $duration = (int) $duration;

        if ((int) $row->current_value === $duration) {
          $alreadyCorrect++;
          continue;
        }

        /** @var \Drupal\node\NodeInterface $node */
        $node = $storage->load($row->nid);
        if (!$node) {
          continue;
        }
        $node->set('field_kaltura_duration', $duration);
        if (!$dryRun) {
          // No setNewRevision(TRUE): updates the node's current revision in
          // place, same reasoning as the other AV backfills this session --
          // this is filling in a migration gap, not authoring new content.
          $node->save();
        }
        $updated++;
      }
    }

    $this->logger()->success('{action} {count} node(s) with field_kaltura_duration.', [
      'action' => $dryRun ? 'Would update' : 'Updated',
      'count' => $updated,
    ]);
    $this->logger()->notice('{count} already correct, {gap} had a Kaltura entry id but no usable node_kaltura.kaltura_duration in D7.', [
      'count' => $alreadyCorrect,
      'gap' => $noKalturaRow,
    ]);
  }

}
