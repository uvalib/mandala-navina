<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Suppresses per-node kmassets Solr sync while a migration is running.
 *
 * The node hooks in mandala_kmassets_sync.module fire a synchronous Solr write
 * on every insert/update (and a delete on every removal). During a full Images
 * migration that is ~111k inline writes to the real kmassets master — redundant
 * with the deliberate bulk index (drush kmassets:index-all + kmassets:audit),
 * a heavy load on shared Solr, and — on rollback — ~111k inline deletes against
 * the same index. See docs/deferred/kmassets-sync-hook-fires-during-migration.md.
 *
 * This subscriber flips an in-memory flag on the migrate import/rollback
 * lifecycle events; the node hooks consult it and no-op while it is set. The
 * flag is intentionally process-scoped (a plain property, not persistent State):
 * a `drush migrate:import` runs the whole migration and all its node saves in
 * one PHP process, so the flag is visible to the hooks; and if a migration
 * aborts before its POST_* event fires, the flag dies with the process rather
 * than silently disabling sync for every future request. Post-migration, index
 * deliberately via the bulk path — that is the sanctioned way docs reach Solr.
 */
class MigrateSyncSubscriber implements EventSubscriberInterface {

  /**
   * TRUE while a migration import or rollback is in progress in this process.
   */
  private bool $suppressed = FALSE;

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Suppress across both import and rollback: rollback deletes the migrated
    // nodes, which would otherwise fire hook_node_delete -> inline Solr deletes.
    return [
      MigrateEvents::PRE_IMPORT => 'suppress',
      MigrateEvents::POST_IMPORT => 'resume',
      MigrateEvents::PRE_ROLLBACK => 'suppress',
      MigrateEvents::POST_ROLLBACK => 'resume',
    ];
  }

  /**
   * Whether per-node kmassets sync is currently suppressed.
   */
  public function isSuppressed(): bool {
    return $this->suppressed;
  }

  /**
   * Begins suppression (migrate PRE_IMPORT / PRE_ROLLBACK).
   */
  public function suppress(): void {
    if (!$this->suppressed) {
      $this->suppressed = TRUE;
      $this->logger->notice('kmassets per-node Solr sync suppressed for the duration of the migration; index deliberately afterwards with drush kmassets:index-all + kmassets:audit.');
    }
  }

  /**
   * Ends suppression (migrate POST_IMPORT / POST_ROLLBACK).
   */
  public function resume(): void {
    if ($this->suppressed) {
      $this->suppressed = FALSE;
      $this->logger->notice('kmassets per-node Solr sync re-enabled after migration.');
    }
  }

}
