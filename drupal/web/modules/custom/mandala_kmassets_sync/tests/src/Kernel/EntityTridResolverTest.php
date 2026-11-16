<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_kmassets_sync\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_kmassets_sync\Transcript\EntityTridResolver;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The entity-backed trid resolver (T2 phase 7).
 *
 * @coversDefaultClass \Drupal\mandala_kmassets_sync\Transcript\EntityTridResolver
 * @group mandala_kmassets_sync
 */
#[RunTestsInSeparateProcesses]
class EntityTridResolverTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'file', 'node', 'text', 'options', 'mandala_av_transcripts'];

  private EntityTridResolver $resolver;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'video', 'name' => 'Video'])->save();
    $this->resolver = new EntityTridResolver($this->container->get('entity_type.manager'));
  }

  private function node(): Node {
    $node = Node::create(['type' => 'video', 'title' => 'A video']);
    $node->save();
    return $node;
  }

  /**
   * The entity id is the trid, returned as an integer.
   */
  public function testResolvesTheTranscriptId(): void {
    $node = $this->node();
    AvTranscript::create(['id' => 27016, 'node' => $node->id()])->save();
    $this->assertSame(27016, $this->resolver->resolve($node));
  }

  /**
   * Ids at and above the 100,000 floor (new transcripts) resolve the same way.
   */
  public function testResolvesAboveTheIdFloor(): void {
    $node = $this->node();
    AvTranscript::create(['id' => 100005, 'node' => $node->id()])->save();
    $this->assertSame(100005, $this->resolver->resolve($node));
  }

  /**
   * A node with no transcript has no trid.
   */
  public function testNodeWithoutTranscriptIsNull(): void {
    $this->node();
    $other = $this->node();
    AvTranscript::create(['id' => 5, 'node' => $this->node()->id()])->save();
    $this->assertNull($this->resolver->resolve($other));
  }

  /**
   * An unsaved node has no id to look up.
   */
  public function testNewNodeIsNull(): void {
    $this->assertNull($this->resolver->resolve(Node::create(['type' => 'video', 'title' => 'x'])));
  }

  /**
   * A soft-deleted transcript does not resolve; restoring it does (ADR 019).
   */
  public function testSoftDeletedTranscriptDoesNotResolve(): void {
    $node = $this->node();
    $transcript = AvTranscript::create(['id' => 7, 'node' => $node->id()]);
    $transcript->save();
    $this->assertSame(7, $this->resolver->resolve($node));

    $transcript->setDeleted(TRUE)->save();
    $this->assertNull($this->resolver->resolve($node));

    $transcript->setDeleted(FALSE)->save();
    $this->assertSame(7, $this->resolver->resolve($node));
  }

  /**
   * Each node gets its own transcript's id.
   */
  public function testTranscriptsAreNotMixedUp(): void {
    $a = $this->node();
    $b = $this->node();
    AvTranscript::create(['id' => 11, 'node' => $a->id()])->save();
    AvTranscript::create(['id' => 12, 'node' => $b->id()])->save();
    $this->assertSame(11, $this->resolver->resolve($a));
    $this->assertSame(12, $this->resolver->resolve($b));
  }

}
