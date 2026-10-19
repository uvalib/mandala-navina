<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_av_transcripts\Event\TranscriptEvent;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Drupal\mandala_kmassets_sync\KmassetDirectSink;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps a node's kmassets document current when its transcript appears or goes.
 *
 * `trid_i` on the node's document is the only transcript fact the kmassets
 * document carries, and it exists exactly while the node has a transcript. So
 * the document is rebuilt when a transcript is created, moved to another node
 * (both nodes), or deleted. Unit edits do not touch it and are not subscribed
 * to: transcript text goes to the transcript core (T4), not to kmassets.
 *
 * It does what the node hooks in mandala_kmassets_sync.module do, for the same
 * reasons: skip when Solr is not configured (DDEV, CI), skip while a migration
 * is running (index deliberately afterwards), index only published nodes, and
 * never let a Solr failure escape into the save that triggered it.
 *
 * It cannot loop. Rebuilding a node's document reads the transcript and writes
 * only to Solr; nothing here saves a node or a transcript. The re-entrancy
 * guard is a backstop in case that ever changes.
 */
class TranscriptReindexSubscriber implements EventSubscriberInterface {

  private bool $reindexing = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KmassetDirectSink $sink,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MigrateSyncSubscriber $migrateSuppressor,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TranscriptEvents::TRANSCRIPT_SAVED => 'reindex',
      TranscriptEvents::TRANSCRIPT_DELETED => 'reindex',
    ];
  }

  /**
   * Re-indexes the transcript's node, and the node it left if it moved.
   */
  public function reindex(TranscriptEvent $event): void {
    if ($this->reindexing
      || empty($this->configFactory->get('mandala_kmassets_sync.settings')->get('solr_master_url'))
      || $this->migrateSuppressor->isSuppressed()) {
      return;
    }
    $this->reindexing = TRUE;
    try {
      foreach (array_unique(array_filter([$event->nodeId, $event->previousNodeId])) as $nid) {
        $this->indexNode((int) $nid);
      }
    }
    finally {
      $this->reindexing = FALSE;
    }
  }

  /**
   * Indexes one node if it exists and is published; logs and swallows failure.
   *
   * Unpublished nodes have no document to refresh (the node update hook removes
   * it on unpublish).
   */
  private function indexNode(int $nid): void {
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($node instanceof NodeInterface && $node->isPublished()) {
        $this->sink->indexNode($node);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to re-index kmassets doc for node @nid after a transcript change: @message', [
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
