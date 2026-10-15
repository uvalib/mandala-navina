<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mandala_av_transcripts\AvTranscriptIdSequence;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @coversDefaultClass \Drupal\mandala_av_transcripts\Entity\AvTranscript
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class AvTranscriptTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'file', 'node', 'text', 'options', 'mandala_av_transcripts'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);
    NodeType::create(['type' => 'video', 'name' => 'Video'])->save();
  }

  private function node(): Node {
    $node = Node::create(['type' => 'video', 'title' => 'A video']);
    $node->save();
    return $node;
  }

  /**
   * The migration sets D7's trid as the id, and it survives a save.
   */
  public function testExplicitIdIsKept(): void {
    $t = AvTranscript::create(['id' => 27016, 'node' => $this->node()->id()]);
    $t->save();
    $this->assertSame('27016', (string) AvTranscript::load(27016)->id());
  }

  /**
   * Ids the database allocates start at the floor, clear of D7's range.
   */
  public function testAllocatedIdsStartAtTheFloor(): void {
    (new AvTranscriptIdSequence($this->container->get('database')))
      ->reserve('av_transcript', AvTranscript::ID_FLOOR);
    $t = AvTranscript::create(['node' => $this->node()->id()]);
    $t->save();
    $this->assertGreaterThanOrEqual(AvTranscript::ID_FLOOR, (int) $t->id());
  }

  /**
   * Reserving never moves the sequence below ids already in use.
   */
  public function testReserveDoesNotGoBelowExistingIds(): void {
    AvTranscript::create(['id' => 250000, 'node' => $this->node()->id()])->save();
    (new AvTranscriptIdSequence($this->container->get('database')))
      ->reserve('av_transcript', AvTranscript::ID_FLOOR);
    $t = AvTranscript::create(['node' => $this->node()->id()]);
    $t->save();
    $this->assertGreaterThan(250000, (int) $t->id());
  }

  /**
   * One transcript per node: entity validation flags the second one.
   */
  public function testSecondTranscriptForANodeFailsValidation(): void {
    $nid = $this->node()->id();
    AvTranscript::create(['id' => 1, 'node' => $nid])->save();
    $violations = AvTranscript::create(['id' => 2, 'node' => $nid])->validate();
    $this->assertGreaterThan(0, $violations->count());
  }

  /**
   * ...and the database refuses it even when validation is skipped.
   */
  public function testSecondTranscriptForANodeFailsAtTheDatabase(): void {
    $nid = $this->node()->id();
    AvTranscript::create(['id' => 1, 'node' => $nid])->save();
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('av_transcript__node');
    AvTranscript::create(['id' => 2, 'node' => $nid])->save();
  }

  /**
   * Tier names round-trip in order, including non-ASCII, as JSON.
   */
  public function testTiersRoundTrip(): void {
    $t = AvTranscript::create(['id' => 5, 'node' => $this->node()->id()]);
    $t->setTiers(['content_bod', 'ts_content_eng', 'dzo_bod']);
    $t->save();
    $this->assertSame(['content_bod', 'ts_content_eng', 'dzo_bod'], AvTranscript::load(5)->getTiers());
    $this->assertSame([], AvTranscript::create(['id' => 6, 'node' => $this->node()->id()])->getTiers());
  }

  /**
   * Flagging edits stamps the flag time once.
   */
  public function testEditsFlagIsStamped(): void {
    $t = AvTranscript::create(['id' => 7, 'node' => $this->node()->id(), 'has_edits' => TRUE]);
    $t->save();
    $this->assertTrue(AvTranscript::load(7)->hasEdits());
    $this->assertFalse(AvTranscript::load(7)->get('edits_flagged_at')->isEmpty());
  }

  /**
   * A new revision keeps the old one, with its log message.
   */
  public function testNewRevisionIsKept(): void {
    $t = AvTranscript::create(['id' => 8, 'node' => $this->node()->id()]);
    $t->save();
    $t->setNewRevision(TRUE);
    $t->setRevisionLogMessage('reverted to upload');
    $t->set('has_edits', TRUE);
    $t->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('av_transcript');
    $this->assertCount(2, $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', 8)->execute());
    $this->assertSame('reverted to upload', AvTranscript::load(8)->getRevisionLogMessage());
  }

  /**
   * With no access handler yet, everyone but an administrator is denied.
   */
  public function testAccessFailsClosed(): void {
    $t = AvTranscript::create(['id' => 9, 'node' => $this->node()->id()]);
    $t->save();
    $this->assertFalse($t->access('view', \Drupal\user\Entity\User::getAnonymousUser()));
  }

}
