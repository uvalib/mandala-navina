<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_kmassets_sync\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_av_transcripts\Event\TranscriptEvent;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Drupal\mandala_kmassets_sync\EventSubscriber\MigrateSyncSubscriber;
use Drupal\mandala_kmassets_sync\EventSubscriber\TranscriptReindexSubscriber;
use Drupal\mandala_kmassets_sync\KmassetDirectSink;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\mandala_kmassets_sync\EventSubscriber\TranscriptReindexSubscriber
 * @group mandala_kmassets_sync
 */
class TranscriptReindexSubscriberTest extends UnitTestCase {

  /** @var \Drupal\node\NodeInterface[] */
  private array $nodes = [];

  /** @var int[] */
  private array $indexed = [];

  private function node(int $nid, bool $published = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($nid);
    $node->method('isPublished')->willReturn($published);
    return $this->nodes[$nid] = $node;
  }

  /**
   * Builds the subscriber with a recording sink.
   *
   * @param \Closure|null $onIndex
   *   Runs inside the sink's indexNode(), to simulate failure or re-entrancy.
   */
  private function subscriber(string $solr = 'http://solr', bool $suppressed = FALSE, ?\Closure $onIndex = NULL, ?LoggerInterface $logger = NULL, ?\Closure &$subscriberRef = NULL): TranscriptReindexSubscriber {
    $sink = $this->createMock(KmassetDirectSink::class);
    $sink->method('indexNode')->willReturnCallback(function (NodeInterface $n) use ($onIndex) {
      $this->indexed[] = (int) $n->id();
      if ($onIndex) {
        $onIndex($n);
      }
      return 'doc';
    });
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn($id) => $this->nodes[$id] ?? NULL);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('node')->willReturn($storage);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('solr_master_url')->willReturn($solr);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('mandala_kmassets_sync.settings')->willReturn($config);
    $migrate = $this->createMock(MigrateSyncSubscriber::class);
    $migrate->method('isSuppressed')->willReturn($suppressed);
    return new TranscriptReindexSubscriber($etm, $sink, $factory, $migrate, $logger ?? $this->createMock(LoggerInterface::class));
  }

  public function testSubscribesToTranscriptSaveAndDeleteOnly(): void {
    $events = TranscriptReindexSubscriber::getSubscribedEvents();
    $this->assertSame([TranscriptEvents::TRANSCRIPT_SAVED, TranscriptEvents::TRANSCRIPT_DELETED], array_keys($events));
  }

  public function testIndexesThePublishedNode(): void {
    $this->node(5);
    $this->subscriber()->reindex(new TranscriptEvent(1, 5, NULL, TRUE));
    $this->assertSame([5], $this->indexed);
  }

  public function testAMovedTranscriptRefreshesBothNodes(): void {
    $this->node(5);
    $this->node(6);
    $this->subscriber()->reindex(new TranscriptEvent(1, 6, NULL, FALSE, 5));
    $this->assertSame([6, 5], $this->indexed);
  }

  public function testUnpublishedOrMissingNodesAreSkipped(): void {
    $this->node(5, FALSE);
    $this->subscriber()->reindex(new TranscriptEvent(1, 5));
    $this->subscriber()->reindex(new TranscriptEvent(1, 404));
    $this->subscriber()->reindex(new TranscriptEvent(1, NULL));
    $this->assertSame([], $this->indexed);
  }

  public function testSkippedWhenSolrIsNotConfigured(): void {
    $this->node(5);
    $this->subscriber('')->reindex(new TranscriptEvent(1, 5));
    $this->assertSame([], $this->indexed);
  }

  public function testSkippedWhileAMigrationRuns(): void {
    $this->node(5);
    $this->subscriber('http://solr', TRUE)->reindex(new TranscriptEvent(1, 5));
    $this->assertSame([], $this->indexed);
  }

  public function testASolrFailureIsLoggedNotThrown(): void {
    $this->node(5);
    $this->node(6);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with($this->stringContains('Failed to re-index'), $this->arrayHasKey('@nid'));
    $sub = $this->subscriber('http://solr', FALSE, function (NodeInterface $n): void {
      if ($n->id() === 6) {
        throw new \RuntimeException('solr down');
      }
    }, $logger);
    $sub->reindex(new TranscriptEvent(1, 6, NULL, FALSE, 5));
    $this->assertSame([6, 5], $this->indexed, 'The failure on one node does not stop the other.');
  }

  /**
   * If indexing ever caused another transcript event, it must not recurse.
   */
  public function testReentrantEventsAreIgnored(): void {
    $this->node(5);
    $self = NULL;
    $self = $this->subscriber('http://solr', FALSE, function () use (&$self): void {
      $self->reindex(new TranscriptEvent(1, 5));
    });
    $self->reindex(new TranscriptEvent(1, 5));
    $this->assertSame([5], $this->indexed);
    // And the guard is released afterwards.
    $self->reindex(new TranscriptEvent(1, 5));
    $this->assertSame([5, 5], $this->indexed);
  }

}
