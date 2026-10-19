<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Event\TranscriptEvent;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Drupal\mandala_av_transcripts\TranscriptChangeNotifier;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The event surface: what is dispatched, when, and what bulk work coalesces to.
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class TranscriptEventsTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'file', 'node', 'text', 'options', 'mandala_av_transcripts'];

  /** @var array<int, array{string, TranscriptEvent}> */
  private array $seen = [];

  private TranscriptChangeNotifier $notifier;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'video', 'name' => 'Video'])->save();
    $dispatcher = $this->container->get('event_dispatcher');
    foreach ([
      TranscriptEvents::TRANSCRIPT_SAVED,
      TranscriptEvents::TRANSCRIPT_DELETED,
      TranscriptEvents::UNIT_SAVED,
      TranscriptEvents::UNIT_DELETED,
      TranscriptEvents::UNITS_REPLACED,
    ] as $name) {
      $dispatcher->addListener($name, function (TranscriptEvent $e) use ($name): void {
        $this->seen[] = [$name, $e];
      });
    }
    $this->notifier = $this->container->get('mandala_av_transcripts.change_notifier');
  }

  private function names(): array {
    return array_column($this->seen, 0);
  }

  private function node(): int {
    $n = Node::create(['type' => 'video', 'title' => 'v']);
    $n->save();
    return (int) $n->id();
  }

  private function transcript(int $id, ?int $nid = NULL): AvTranscript {
    $t = AvTranscript::create(['id' => $id, 'node' => $nid ?? $this->node()]);
    $t->save();
    return $t;
  }

  private function unit(int $id, int $trid, int $seq = 0): AvTcu {
    $u = AvTcu::create(['id' => $id, 'transcript' => $trid, 'sequence' => $seq, 'start' => '1.000']);
    $u->save();
    return $u;
  }

  private function tagValid(string $tag): bool {
    $backend = $this->container->get('cache.default');
    $backend->set('probe', 'x', -1, [$tag]);
    return (bool) $backend->get('probe');
  }

  public function testTranscriptSaveDispatchesWithNodeAndNewFlag(): void {
    $nid = $this->node();
    $t = $this->transcript(1, $nid);
    $this->assertSame([TranscriptEvents::TRANSCRIPT_SAVED], $this->names());
    $e = $this->seen[0][1];
    $this->assertSame([1, $nid, TRUE, NULL], [$e->transcriptId, $e->nodeId, $e->isNew, $e->previousNodeId]);

    $this->seen = [];
    $t->set('has_edits', TRUE)->save();
    $e = $this->seen[0][1];
    $this->assertFalse($e->isNew);
    $this->assertNull($e->previousNodeId, 'Same node: no previous.');
  }

  public function testMovingATranscriptReportsThePreviousNode(): void {
    $a = $this->node();
    $b = $this->node();
    $t = $this->transcript(2, $a);
    $this->seen = [];
    $t->set('node', $b)->save();
    $e = $this->seen[0][1];
    $this->assertSame($b, $e->nodeId);
    $this->assertSame($a, $e->previousNodeId);
  }

  public function testTranscriptDeleteDispatchesWithItsNode(): void {
    $nid = $this->node();
    $t = $this->transcript(3, $nid);
    $this->seen = [];
    $t->delete();
    $this->assertSame([TranscriptEvents::TRANSCRIPT_DELETED], $this->names());
    $this->assertSame([3, $nid], [$this->seen[0][1]->transcriptId, $this->seen[0][1]->nodeId]);
  }

  public function testUnitSaveAndDelete(): void {
    $nid = $this->node();
    $this->transcript(4, $nid);
    $this->seen = [];
    $u = $this->unit(40, 4);
    $this->assertSame([TranscriptEvents::UNIT_SAVED], $this->names());
    $e = $this->seen[0][1];
    $this->assertSame([4, $nid, 40, TRUE], [$e->transcriptId, $e->nodeId, $e->unitId, $e->isNew]);

    $this->seen = [];
    $u->set('start', '2.000')->save();
    $this->assertFalse($this->seen[0][1]->isNew);

    $this->seen = [];
    $u->delete();
    $this->assertSame([TranscriptEvents::UNIT_DELETED], $this->names());
    $this->assertSame(40, $this->seen[0][1]->unitId);
  }

  public function testUnitChangesInvalidateTheTranscriptUnitTag(): void {
    $this->transcript(5);
    $backend = $this->container->get('cache.default');
    $backend->set('p', 'x', -1, ['av_transcript_units:5']);
    $u = $this->unit(50, 5);
    $this->assertFalse($backend->get('p'));
    $backend->set('p', 'x', -1, ['av_transcript_units:5']);
    $u->delete();
    $this->assertFalse($backend->get('p'));
  }

  /**
   * Inside suppressed(): no per-unit events, one UNITS_REPLACED per transcript.
   */
  public function testSuppressedCoalescesToOneEventPerTranscript(): void {
    $this->transcript(6);
    $this->transcript(7);
    $this->seen = [];
    $backend = $this->container->get('cache.default');
    $this->notifier->suppressed(function () use ($backend): void {
      for ($i = 0; $i < 25; $i++) {
        $this->unit(600 + $i, 6, $i);
      }
      $this->unit(700, 7);
      // Held back: nothing dispatched and no tag written yet.
      $this->assertSame([], $this->names());
      $backend->set('p6', 'x', -1, ['av_transcript_units:6']);
      $this->assertNotFalse($backend->get('p6'));
    });
    $this->assertEqualsCanonicalizing(
      [TranscriptEvents::UNITS_REPLACED, TranscriptEvents::UNITS_REPLACED],
      $this->names(),
    );
    $this->assertEqualsCanonicalizing([6, 7], array_map(fn($s) => $s[1]->transcriptId, $this->seen));
    $this->assertFalse($backend->get('p6'), 'The tag is invalidated when the bulk operation ends.');
    $this->assertFalse($this->notifier->isSuppressed());
  }

  public function testSuppressedCoversDeletesAndNests(): void {
    $this->transcript(8);
    $u = $this->unit(80, 8);
    $this->seen = [];
    $this->notifier->suppressed(function () use ($u): void {
      $this->notifier->suppressed(function () use ($u): void {
        $u->delete();
      });
      $this->assertSame([], $this->names(), 'The inner wrapper ending does not flush.');
      $this->assertTrue($this->notifier->isSuppressed());
    });
    $this->assertSame([TranscriptEvents::UNITS_REPLACED], $this->names());
  }

  public function testSuppressedFlushesAndResumesWhenTheOperationThrows(): void {
    $this->transcript(9);
    $this->seen = [];
    try {
      $this->notifier->suppressed(function (): void {
        $this->unit(90, 9);
        throw new \RuntimeException('boom');
      });
      $this->fail('Exception expected');
    }
    catch (\RuntimeException) {
    }
    $this->assertSame([TranscriptEvents::UNITS_REPLACED], $this->names());
    $this->assertFalse($this->notifier->isSuppressed());
    $this->seen = [];
    $this->unit(91, 9, 1);
    $this->assertSame([TranscriptEvents::UNIT_SAVED], $this->names(), 'Per-unit events are back.');
  }

  public function testSuppressedReturnsTheOperationsValueAndSkipsUntouchedTranscripts(): void {
    $this->seen = [];
    $this->assertSame('v', $this->notifier->suppressed(fn() => 'v'));
    $this->assertSame([], $this->names());
  }

  public function testExplicitUnitsReplacedInvalidatesAndDispatches(): void {
    $nid = $this->node();
    $this->transcript(10, $nid);
    $this->seen = [];
    $backend = $this->container->get('cache.default');
    $backend->set('p', 'x', -1, ['av_transcript_units:10']);
    $this->notifier->unitsReplaced(10);
    $this->assertFalse($backend->get('p'));
    $this->assertSame([TranscriptEvents::UNITS_REPLACED], $this->names());
    $this->assertSame($nid, $this->seen[0][1]->nodeId);
  }

  /**
   * A throwing subscriber cannot undo the save, and is logged. Postsave runs
   * inside the storage transaction, so an escaping exception would roll back.
   */
  public function testSubscriberExceptionDoesNotRollBackTheSave(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(TranscriptEvents::TRANSCRIPT_SAVED, function (): void {
      throw new \RuntimeException('subscriber failed');
    });
    $this->transcript(11);
    $this->assertNotNull(AvTranscript::load(11), 'The transcript is saved regardless.');

    $dispatcher->addListener(TranscriptEvents::UNIT_SAVED, function (): void {
      throw new \RuntimeException('unit subscriber failed');
    });
    $this->unit(110, 11);
    $this->assertNotNull(AvTcu::load(110));
  }

}
