<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\mandala_av_transcripts\AvTranscriptIdSequence;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @coversDefaultClass \Drupal\mandala_av_transcripts\Entity\AvTcu
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class AvTcuTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'file', 'node', 'text', 'options', 'mandala_av_transcripts'];

  private AvTranscript $transcript;

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
    $node = Node::create(['type' => 'video', 'title' => 'A video']);
    $node->save();
    $this->transcript = AvTranscript::create(['id' => 1, 'node' => $node->id()]);
    $this->transcript->save();
  }

  private function unit(array $values = []): AvTcu {
    return AvTcu::create($values + [
      'transcript' => $this->transcript->id(),
      'sequence' => 0,
      'start' => '1.500',
    ]);
  }

  /**
   * The migration sets D7's tcuid as the id, and it survives a save.
   */
  public function testExplicitIdIsKept(): void {
    $this->unit(['id' => 1394104])->save();
    $this->assertSame('1394104', (string) AvTcu::load(1394104)->id());
  }

  /**
   * Allocated ids start at the floor, clear of D7's range.
   */
  public function testAllocatedIdsStartAtTheFloor(): void {
    (new AvTranscriptIdSequence($this->container->get('database')))->reserve('av_tcu', AvTcu::ID_FLOOR);
    $u = $this->unit();
    $u->save();
    $this->assertGreaterThanOrEqual(AvTcu::ID_FLOOR, (int) $u->id());
  }

  /**
   * Timecodes keep millisecond precision, a negative start, and a missing end.
   */
  public function testTimecodesRoundTrip(): void {
    $this->unit(['id' => 1, 'start' => '-0.125', 'end' => '81807.000'])->save();
    $this->unit(['id' => 2, 'sequence' => 1, 'start' => '12.345'])->save();
    $a = AvTcu::load(1);
    $this->assertSame('-0.125', $a->get('start')->value);
    $this->assertSame('81807.000', $a->get('end')->value);
    $b = AvTcu::load(2);
    $this->assertSame('12.345', $b->get('start')->value);
    $this->assertTrue($b->get('end')->isEmpty());
    $this->assertTrue($b->get('end_original')->isEmpty());
    $this->assertSame('ok', $b->get('timecode_status')->value);
  }

  /**
   * A corrected end keeps the original and the status.
   */
  public function testCorrectedEndKeepsOriginal(): void {
    $this->unit(['id' => 3, 'start' => '10.000', 'end' => '12.000', 'end_original' => '9.000', 'timecode_status' => 'inverted'])->save();
    $u = AvTcu::load(3);
    $this->assertSame('9.000', $u->get('end_original')->value);
    $this->assertSame('inverted', $u->get('timecode_status')->value);
  }

  /**
   * Tier and speaker maps round-trip with order and non-ASCII text intact.
   */
  public function testTierAndSpeakerMapsRoundTrip(): void {
    $tiers = ['content_bod' => 'བཀྲ་ཤིས', 'ts_content_eng' => 'hello', 'dzo_bod' => 'ཨ'];
    $u = $this->unit(['id' => 4]);
    $u->setTiers($tiers)->setSpeakers(['speaker_a' => 'Lama']);
    $u->save();
    $loaded = AvTcu::load(4);
    $this->assertSame($tiers, $loaded->getTiers());
    $this->assertSame(['speaker_a' => 'Lama'], $loaded->getSpeakers());
    $this->assertSame([], $this->unit(['id' => 5])->getTiers());
    $this->assertSame([], $this->unit(['id' => 5])->setTiers([])->getTiers());
  }

  /**
   * A unit resolves its transcript.
   */
  public function testUnitResolvesItsTranscript(): void {
    $u = $this->unit(['id' => 6]);
    $u->save();
    $this->assertSame('1', (string) AvTcu::load(6)->getTranscript()->id());
  }

  /**
   * Revisions are per unit: editing one leaves the others at one revision.
   */
  public function testRevisionsArePerUnit(): void {
    $a = $this->unit(['id' => 7]);
    $a->save();
    $this->unit(['id' => 8, 'sequence' => 1])->save();
    $a->setNewRevision(TRUE);
    $a->setRevisionLogMessage('typo fix');
    $a->setTiers(['content_bod' => 'x']);
    $a->save();
    $q = fn(int $id) => $this->container->get('entity_type.manager')->getStorage('av_tcu')
      ->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $id)->execute();
    $this->assertCount(2, $q(7));
    $this->assertCount(1, $q(8));
    $this->assertSame('typo fix', AvTcu::load(7)->getRevisionLogMessage());
  }

  /**
   * The two read indexes exist.
   */
  public function testReadIndexesExist(): void {
    $indexes = [];
    foreach ($this->container->get('database')->query('SHOW INDEX FROM {av_tcu}')->fetchAll() as $row) {
      $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
    }
    $this->assertSame([1 => 'transcript', 2 => 'sequence'], $indexes['av_tcu__transcript_sequence'] ?? NULL);
    $this->assertSame([1 => 'transcript', 2 => 'start'], $indexes['av_tcu__transcript_start'] ?? NULL);
  }

  /**
   * With no access handler yet, everyone but an administrator is denied.
   */
  public function testAccessFailsClosed(): void {
    $u = $this->unit(['id' => 9]);
    $u->save();
    $this->assertFalse($u->access('view', User::getAnonymousUser()));
  }

}
