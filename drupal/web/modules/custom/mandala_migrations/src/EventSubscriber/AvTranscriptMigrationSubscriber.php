<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;
use Drupal\mandala_av_transcripts\TranscriptChangeNotifier;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigrateRollbackEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * What the transcript migrations need from the T2 module (T5 plan).
 *
 * 1. COALESCE UNIT NOTIFICATIONS. A run writes 243,005 units, and each save
 *    would otherwise write a cache-tag invalidation and dispatch an event (T4
 *    will turn those into Solr writes). While one of our migrations runs, the
 *    notifier is held, so each transcript gets one UNITS_REPLACED at the end.
 *    The hold is released on the matching POST_* event, and it is process
 *    scoped, so a run that dies cannot silence later requests. (The
 *    transcript-level kmassets reindex is already covered by
 *    mandala_kmassets_sync's own migrate suppressor.)
 *
 * 2. REVIVE A SOFT-DELETED TRANSCRIPT (ADR 019). Rolling back or deleting an AV
 *    node soft-deletes its transcript and units. A later import of the same
 *    `trid` finds that row by id and re-points it at the new node, but the row
 *    is still flagged deleted. The sanctioned way to undo that is
 *    TranscriptLifecycle::restore(), which also revives exactly the units the
 *    transcript took with it and leaves any unit an editor deleted on purpose
 *    deleted. So the migration does not touch the `deleted` flag itself; this
 *    subscriber calls restore() after the save.
 *
 *    A re-import needs `--update`: the transcript rows are still in the id map
 *    after a node rollback, so a plain import would skip them.
 *
 * The lifecycle is injected as a closure (a service closure in the services
 * file), not as the service. Constructing the lifecycle can reach the event
 * dispatcher and so this subscriber, and a direct dependency made a circular
 * reference on a drush boot (orphan_manager -> lifecycle -> this -> lifecycle)
 * that no Kernel test reproduced; the closure resolves it only when a transcript
 * actually needs reviving.
 */
class AvTranscriptMigrationSubscriber implements EventSubscriberInterface {

  /**
   * The migrations this applies to.
   */
  public const MIGRATIONS = ['d7_av_transcript', 'd7_av_tcu'];

  /**
   * Migrations holding the notifier, by id.
   *
   * @var array<string, true>
   */
  private array $holding = [];

  public function __construct(
    private readonly TranscriptChangeNotifier $notifier,
    private readonly \Closure $lifecycle,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_IMPORT => 'hold',
      MigrateEvents::POST_IMPORT => 'release',
      MigrateEvents::PRE_ROLLBACK => 'hold',
      MigrateEvents::POST_ROLLBACK => 'release',
      MigrateEvents::POST_ROW_SAVE => 'revive',
    ];
  }

  /**
   * Starts holding unit notifications for one of our migrations.
   */
  public function hold(MigrateImportEvent|MigrateRollbackEvent $event): void {
    $id = $event->getMigration()->id();
    if (in_array($id, static::MIGRATIONS, TRUE) && !isset($this->holding[$id])) {
      $this->holding[$id] = TRUE;
      $this->notifier->hold();
    }
  }

  /**
   * Ends the hold for that migration; one UNITS_REPLACED per touched transcript.
   */
  public function release(MigrateImportEvent|MigrateRollbackEvent $event): void {
    $id = $event->getMigration()->id();
    if (isset($this->holding[$id])) {
      unset($this->holding[$id]);
      $this->notifier->release();
    }
  }

  /**
   * Restores a transcript the import has just updated if it was soft-deleted.
   */
  public function revive(MigratePostRowSaveEvent $event): void {
    if ($event->getMigration()->id() !== 'd7_av_transcript') {
      return;
    }
    $ids = $event->getDestinationIdValues();
    $transcript = $this->entityTypeManager->getStorage('av_transcript')->load(reset($ids));
    if ($transcript instanceof AvTranscriptInterface && $transcript->isDeleted()) {
      ($this->lifecycle)()->restore($transcript);
    }
  }

}
