<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Event\TranscriptEvent;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The single place transcript and unit changes become events and cache tags.
 *
 * The entities call this from postSave()/postDelete(). Bulk operations wrap
 * themselves in suppressed(): per-unit events and cache-tag writes are held,
 * and when the outermost wrapper ends each touched transcript gets exactly one
 * UNITS_REPLACED event and one cache-tag invalidation. That is what keeps a
 * 245,158-unit migration from doing 245,158 invalidations and as many Solr
 * writes.
 *
 * Process-scoped on purpose, like the kmassets MigrateSyncSubscriber: a bulk
 * run is one PHP process, and if it dies the flag dies with it instead of
 * silencing every later request.
 */
class TranscriptChangeNotifier {

  private int $suppressDepth = 0;

  /**
   * Transcript ids touched while suppressed, keyed by id.
   *
   * @var array<int, true>
   */
  private array $pending = [];

  public function __construct(
    private readonly EventDispatcherInterface $dispatcher,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * A unit was saved.
   */
  public function unitSaved(AvTcu $unit, bool $isNew): void {
    $was = $isNew ? FALSE : ($unit->getOriginal()?->get('deleted')->value ?? FALSE);
    if ($unit->isDeleted()) {
      // Soft-deleting reads as a delete to subscribers; saving a unit that is
      // already deleted changes nothing anyone can see.
      if (!$was) {
        $this->unitChanged($unit, TranscriptEvents::UNIT_DELETED, FALSE);
      }
      return;
    }
    // A restore reads as a save of an existing unit.
    $this->unitChanged($unit, TranscriptEvents::UNIT_SAVED, $isNew);
  }

  /**
   * A unit was deleted.
   */
  public function unitDeleted(AvTcu $unit): void {
    $this->unitChanged($unit, TranscriptEvents::UNIT_DELETED, FALSE);
  }

  /**
   * A transcript was saved.
   */
  public function transcriptSaved(AvTranscript $transcript, bool $isNew): void {
    if ($transcript->isDeleted()) {
      // Soft-deleting reads as a delete to subscribers (one event; the units go
      // with it). Saving a transcript that is already deleted changes nothing
      // anyone can see.
      $was = $isNew ? FALSE : ($transcript->getOriginal()?->get('deleted')->value ?? FALSE);
      if (!$was) {
        $this->transcriptDeleted($transcript);
      }
      return;
    }
    $previous = NULL;
    $original = $isNew ? NULL : $transcript->getOriginal();
    if ($original !== NULL) {
      $before = $original->get('node')->target_id;
      $now = $transcript->get('node')->target_id;
      $previous = ($before !== NULL && (int) $before !== (int) $now) ? (int) $before : NULL;
    }
    $this->dispatch(TranscriptEvents::TRANSCRIPT_SAVED, new TranscriptEvent(
      (int) $transcript->id(),
      self::nodeId($transcript),
      NULL,
      $isNew,
      $previous,
    ));
  }

  /**
   * A transcript was deleted.
   */
  public function transcriptDeleted(AvTranscript $transcript): void {
    Cache::invalidateTags([AvTranscript::unitsCacheTag((int) $transcript->id())]);
    $this->dispatch(TranscriptEvents::TRANSCRIPT_DELETED, new TranscriptEvent(
      (int) $transcript->id(),
      self::nodeId($transcript),
    ));
  }

  /**
   * All of a transcript's units changed at once.
   *
   * Call this after a whole-transcript operation (re-import, revert to upload)
   * that you did not wrap in suppressed().
   */
  public function unitsReplaced(int $transcriptId): void {
    Cache::invalidateTags([AvTranscript::unitsCacheTag($transcriptId)]);
    $this->dispatch(TranscriptEvents::UNITS_REPLACED, new TranscriptEvent($transcriptId, $this->nodeIdFor($transcriptId)));
  }

  /**
   * Runs a bulk operation with per-unit notifications held back.
   *
   * Re-entrant. When the outermost call ends (normally or by exception) every
   * transcript touched inside gets one unitsReplaced().
   *
   * @template T
   * @param callable(): T $operation
   * @return T
   */
  public function suppressed(callable $operation): mixed {
    $this->suppressDepth++;
    try {
      return $operation();
    }
    finally {
      $this->suppressDepth--;
      if ($this->suppressDepth === 0) {
        $pending = array_keys($this->pending);
        $this->pending = [];
        foreach ($pending as $id) {
          $this->unitsReplaced($id);
        }
      }
    }
  }

  /**
   * Whether per-unit notifications are currently held back.
   */
  public function isSuppressed(): bool {
    return $this->suppressDepth > 0;
  }

  private function unitChanged(AvTcu $unit, string $event, bool $isNew): void {
    $trid = $unit->get('transcript')->target_id;
    if ($trid === NULL) {
      return;
    }
    $trid = (int) $trid;
    if ($this->suppressDepth > 0) {
      $this->pending[$trid] = TRUE;
      return;
    }
    Cache::invalidateTags([AvTranscript::unitsCacheTag($trid)]);
    $this->dispatch($event, new TranscriptEvent($trid, $this->nodeIdFor($trid), (int) $unit->id(), $isNew));
  }

  /**
   * Dispatches, and never lets a subscriber's failure reach the caller.
   *
   * The entities call this from postSave()/postDelete(), which run inside the
   * storage transaction: an exception here would roll the save back, and a
   * faulty reindex hook must not be able to undo a transcript edit or abort a
   * migration row. Subscribers own their failures; this logs the ones that
   * escape.
   */
  private function dispatch(string $name, TranscriptEvent $event): void {
    try {
      $this->dispatcher->dispatch($event, $name);
    }
    catch (\Throwable $e) {
      $this->logger->error('A subscriber to @event failed for transcript @id: @message', [
        '@event' => $name,
        '@id' => $event->transcriptId,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  private static function nodeId(AvTranscript $transcript): ?int {
    $nid = $transcript->get('node')->target_id;
    return $nid === NULL ? NULL : (int) $nid;
  }

  /**
   * The node of a transcript, read from its row without loading the entity.
   */
  private function nodeIdFor(int $transcriptId): ?int {
    $nid = $this->database->select('av_transcript', 't')
      ->fields('t', ['node'])
      ->condition('t.id', $transcriptId)
      ->execute()
      ->fetchField();
    return $nid === FALSE || $nid === NULL ? NULL : (int) $nid;
  }

}
