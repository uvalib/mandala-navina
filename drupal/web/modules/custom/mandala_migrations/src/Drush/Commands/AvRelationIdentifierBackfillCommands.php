<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Second-pass backfill for AV4's `field_relation_identifier` gap.
 *
 * See docs/deferred/av4-relation-identifier-not-migrated.md. AV4's own
 * migration (d7_av_pbcore_relation) already has a `migration_lookup` process
 * for this field, targeting d7_av_audio/d7_av_video — but it always resolves
 * to NULL, because the relation paragraphs must exist before the host node
 * migrations that reference them, so audio/video haven't been migrated yet
 * at the point this migration runs. This is the second pass AV3 flagged as
 * necessary: now that both sides are fully migrated, resolve D7 nid -> D11
 * nid through each migration's own id map and set the field directly.
 */
class AvRelationIdentifierBackfillCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Backfill field_relation_identifier on migrated av_pbcore_relation paragraphs.
   */
  #[CLI\Command(name: 'av:backfill-relation-identifier')]
  #[CLI\Option(name: 'dry-run', description: 'Report coverage without saving any paragraph.')]
  #[CLI\Usage(name: 'drush av:backfill-relation-identifier --dry-run', description: 'Report what would change.')]
  #[CLI\Usage(name: 'drush av:backfill-relation-identifier', description: 'Backfill the field for real.')]
  public function backfill(array $options = ['dry-run' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];
    $db = Database::getConnection();
    $sourceDb = Database::getConnectionInfo('migrate_av')['default']['database'];

    // One D7 nid can be either an audio or a video node, so both id maps are
    // consulted; `field_relation_identifier` has no bundle restriction (D7's
    // field is a plain, unrestricted `node` entityreference).
    $rows = $db->query(
      "SELECT ri.entity_id AS item_id,
              ri.field_relation_identifier_target_id AS d7_target_nid,
              pr.destid1 AS paragraph_id,
              COALESCE(a.destid1, v.destid1) AS target_nid
       FROM $sourceDb.field_data_field_relation_identifier ri
       LEFT JOIN migrate_map_d7_av_pbcore_relation pr ON pr.sourceid1 = ri.entity_id
       LEFT JOIN migrate_map_d7_av_audio a ON a.sourceid1 = ri.field_relation_identifier_target_id
       LEFT JOIN migrate_map_d7_av_video v ON v.sourceid1 = ri.field_relation_identifier_target_id
       WHERE ri.deleted = 0"
    )->fetchAll();

    // Group by destination paragraph: the field is unlimited-cardinality in
    // both D7 and D11, even though every item in the current dataset only
    // ever carries one relation target.
    $targetsByParagraph = [];
    $unmappedParagraph = [];
    $unmappedTarget = [];
    foreach ($rows as $row) {
      if ($row->paragraph_id === NULL) {
        $unmappedParagraph[] = $row->item_id;
        continue;
      }
      if ($row->target_nid === NULL) {
        $unmappedTarget[] = $row->item_id;
        continue;
      }
      $targetsByParagraph[$row->paragraph_id][] = (int) $row->target_nid;
    }

    $storage = $this->entityTypeManager->getStorage('paragraph');
    $updated = 0;
    $notFound = [];
    foreach ($targetsByParagraph as $paragraphId => $targetNids) {
      $paragraph = $storage->load($paragraphId);
      if (!$paragraph) {
        $notFound[] = $paragraphId;
        continue;
      }
      $paragraph->set('field_relation_identifier', array_map(
        static fn (int $nid) => ['target_id' => $nid],
        $targetNids
      ));
      if (!$dryRun) {
        // No setNewRevision(TRUE): this updates the paragraph's current
        // revision in place, which is the one the host node's
        // entity_reference_revisions field already points at.
        $paragraph->save();
      }
      $updated++;
    }

    $this->logger()->success('{action} {count} av_pbcore_relation paragraph(s).', [
      'action' => $dryRun ? 'Would update' : 'Updated',
      'count' => $updated,
    ]);
    if ($unmappedParagraph) {
      $this->logger()->warning('No migrated paragraph for {count} source item(s): {ids}', [
        'count' => count($unmappedParagraph),
        'ids' => implode(', ', array_slice($unmappedParagraph, 0, 20)),
      ]);
    }
    if ($unmappedTarget) {
      $this->logger()->warning('No migrated target node for {count} source item(s): {ids}', [
        'count' => count($unmappedTarget),
        'ids' => implode(', ', array_slice($unmappedTarget, 0, 20)),
      ]);
    }
    if ($notFound) {
      $this->logger()->warning('Paragraph id(s) in the id map but no longer loadable: {ids}', [
        'ids' => implode(', ', $notFound),
      ]);
    }
  }

}
