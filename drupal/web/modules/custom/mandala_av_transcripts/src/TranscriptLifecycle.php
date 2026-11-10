<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;

/**
 * Soft-deletes and restores a transcript with its units (ADR 019).
 *
 * Deleting a transcript flags it and every live unit in one transaction; the
 * units get `deleted_with_transcript` as well, so restoring the transcript
 * revives exactly the units it took with it and a unit an editor deleted
 * earlier stays deleted. Nothing is removed: revisions stay attached.
 *
 * Units are flagged with one UPDATE, not loaded and saved. A transcript can
 * have over a thousand, and a per-unit save would write a revision, an event
 * and a cache invalidation each. Subscribers get the transcript-level event
 * instead (TRANSCRIPT_DELETED on delete; TRANSCRIPT_SAVED and UNITS_REPLACED on
 * restore), and a transcript revision records who did it and when.
 *
 * Purging (a hard delete) is the entity's own delete(); this class never does it.
 */
class TranscriptLifecycle {

  public function __construct(
    private readonly Connection $database,
    private readonly TranscriptChangeNotifier $notifier,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Soft-deletes the transcript and its live units.
   *
   * @param string $reason
   *   The revision log message.
   * @param array<string, mixed> $values
   *   Further base-field values to set in the same save (the node snapshot).
   *
   * @return bool
   *   FALSE if it was already deleted.
   */
  public function softDelete(AvTranscriptInterface $transcript, string $reason = 'Soft-deleted.', array $values = []): bool {
    if ($transcript->isDeleted()) {
      return FALSE;
    }
    $flipped = $this->unitIds($transcript, 'deleted', 0);
    $transaction = $this->database->startTransaction();
    try {
      // Units first, so a subscriber reacting to the event sees them gone.
      $this->database->update('av_tcu')
        ->fields(['deleted' => 1, 'deleted_with_transcript' => 1])
        ->condition('transcript', $transcript->id())
        ->condition('deleted', 0)
        ->execute();
      foreach ($values as $field => $value) {
        $transcript->set($field, $value);
      }
      $transcript->set('deleted_at', $this->time->getRequestTime());
      $transcript->set('deleted_by', (int) $this->currentUser->id() ?: NULL);
      $this->saveWithRevision($transcript->setDeleted(TRUE), $reason);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    $this->forgetUnits($flipped);
    Cache::invalidateTags([AvTranscript::unitsCacheTag((int) $transcript->id())]);
    return TRUE;
  }

  /**
   * Restores the transcript and the units it took with it.
   *
   * @return bool
   *   FALSE if it was not deleted.
   */
  public function restore(AvTranscriptInterface $transcript): bool {
    if (!$transcript->isDeleted()) {
      return FALSE;
    }
    $flipped = $this->unitIds($transcript, 'deleted_with_transcript', 1);
    $transaction = $this->database->startTransaction();
    try {
      $this->database->update('av_tcu')
        ->fields(['deleted' => 0, 'deleted_with_transcript' => 0])
        ->condition('transcript', $transcript->id())
        ->condition('deleted_with_transcript', 1)
        ->execute();
      $transcript->set('deleted_at', NULL);
      $transcript->set('deleted_by', NULL);
      $this->saveWithRevision($transcript->setDeleted(FALSE), 'Restored.');
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    $this->forgetUnits($flipped);
    // Index consumers rewrite the whole transcript, not unit by unit.
    $this->notifier->unitsReplaced((int) $transcript->id());
    return TRUE;
  }

  /**
   * Ids of the transcript's units that have the given flag value.
   *
   * @return int[]
   */
  private function unitIds(AvTranscriptInterface $transcript, string $flag, int $value): array {
    return array_map('intval', $this->database->select('av_tcu', 'u')
      ->fields('u', ['id'])
      ->condition('transcript', $transcript->id())
      ->condition($flag, $value)
      ->execute()
      ->fetchCol());
  }

  /**
   * Drops the entity caches of units whose flags were changed with raw SQL.
   *
   * The flags are flipped with one UPDATE (a transcript can have 1,380 units, and
   * a save each would write a revision each), which Drupal's entity cache cannot
   * see. Without this a unit loaded earlier keeps reading as live, and saving it
   * writes the old flag straight back (found by the T5 migration tests).
   *
   * @param int[] $ids
   */
  private function forgetUnits(array $ids): void {
    if ($ids) {
      $this->entityTypeManager->getStorage('av_tcu')->resetCache($ids);
    }
  }

  /**
   * Saves as a new revision, so the transcript's audit trail shows who and when.
   */
  private function saveWithRevision(AvTranscriptInterface $transcript, string $message): void {
    $transcript->setNewRevision(TRUE);
    $transcript->setRevisionLogMessage($message);
    $transcript->setRevisionCreationTime($this->time->getRequestTime());
    $transcript->setRevisionUserId((int) $this->currentUser->id());
    $transcript->save();
  }

}
