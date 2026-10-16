<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\Core\Database\Database;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\TranscriptReader;
use Drupal\node\Entity\Node;
use Drupal\Tests\mandala_group_inheritance\Kernel\CollectionVisibilityKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The read service: ordering, one query, access, cache metadata.
 *
 * Runs on the real collection-visibility fixture so "private transcript, wrong
 * viewer" is the real AV7 rule, not a stub.
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class TranscriptReaderTest extends CollectionVisibilityKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'group', 'gnode', 'options',
    'entity', 'flexible_permissions', 'mandala_group_inheritance',
    'file', 'mandala_av_transcripts',
  ];

  private TranscriptReader $reader;

  private int $nextNode = 1;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    $this->reader = $this->container->get('mandala_av_transcripts.reader');
  }

  /**
   * A transcript (id $trid) on a new audio node in a collection of $group_access.
   */
  private function transcript(int $trid, int $group_access = 0): AvTranscript {
    $node = Node::create(['type' => 'audio', 'title' => 'n' . $this->nextNode, 'status' => 1]);
    $node->save();
    $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => 'collection',
      'label' => 'c' . $this->nextNode++,
      'field_group_access' => $group_access,
    ]);
    $group->save();
    $group->addMember($this->accounts['member']);
    $group->addRelationship($node, 'group_node:audio');
    $t = AvTranscript::create(['id' => $trid, 'node' => $node->id()]);
    $t->setTiers(['content_bod', 'ts_content_eng']);
    $t->save();
    return $t;
  }

  private function unit(int $id, int $trid, int $seq, string $start, ?string $end = NULL, array $extra = []): AvTcu {
    $values = ['id' => $id, 'transcript' => $trid, 'sequence' => $seq, 'start' => $start] + $extra;
    if ($end !== NULL) {
      $values['end'] = $end;
    }
    $u = AvTcu::create($values);
    $u->save();
    return $u;
  }

  /**
   * Units come back by sequence, whatever order they were written in, with
   * decoded maps, numeric times and no neighbours' units.
   */
  public function testUnitsInSequenceOrderWithDecodedValues(): void {
    $t = $this->transcript(10);
    $other = $this->transcript(11);
    $this->unit(103, 10, 2, '30.000', '35.500');
    $this->unit(101, 10, 0, '-0.125', '4.000', ['timecode_status' => 'ok']);
    $b = $this->unit(102, 10, 1, '4.000');
    $b->setTiers(['content_bod' => 'བཀྲ་ཤིས', 'ts_content_eng' => 'hello'])->setSpeakers(['spk' => 'Lama']);
    $b->set('end_original', '3.000')->set('timecode_status', 'inverted');
    $b->save();
    $this->unit(999, 11, 0, '1.000');

    $r = $this->reader->read(10, $this->accounts['anonymous']);
    $this->assertSame(10, $r->id);
    $this->assertSame((int) $t->getNode()->id(), $r->nid);
    $this->assertSame(['content_bod', 'ts_content_eng'], $r->tiers);
    $this->assertSame([101, 102, 103], array_column($r->units, 'id'));
    $this->assertSame([0, 1, 2], array_column($r->units, 'sequence'));
    $this->assertSame(-0.125, $r->units[0]['start']);
    $this->assertNull($r->units[1]['end']);
    $this->assertSame(3.0, $r->units[1]['end_original']);
    $this->assertNull($r->units[0]['end_original']);
    $this->assertSame('inverted', $r->units[1]['timecode_status']);
    $this->assertSame(['content_bod' => 'བཀྲ་ཤིས', 'ts_content_eng' => 'hello'], $r->units[1]['tiers']);
    $this->assertSame(['spk' => 'Lama'], $r->units[1]['speakers']);
    $this->assertSame([], $r->units[0]['tiers']);
    $this->assertSame([], $r->units[0]['speakers']);
  }

  /**
   * The largest D7 transcript (1,380 units) is one query on av_tcu, in order.
   */
  public function testLargestTranscriptIsOneQuery(): void {
    $this->transcript(20);
    $rows = [];
    for ($i = 0; $i < 1380; $i++) {
      $rows[] = ['id' => 5000 + $i, 'transcript' => 20, 'sequence' => 1379 - $i, 'start' => sprintf('%.3f', (1379 - $i) * 2)];
    }
    foreach ($rows as $row) {
      AvTcu::create($row)->save();
    }

    $log = 'tcu_read_log';
    Database::startLog($log);
    $r = $this->reader->read(20, $this->accounts['anonymous']);
    $queries = Database::getLog($log);
    $tcu_selects = array_filter($queries, fn(array $q) => str_contains($q['query'], 'av_tcu') && str_starts_with(ltrim($q['query']), 'SELECT'));
    $this->assertCount(1, $tcu_selects, 'Exactly one query touches av_tcu.');
    $this->assertCount(1380, $r->units);
    $this->assertSame(range(0, 1379), array_column($r->units, 'sequence'));
    $this->assertSame(0.0, $r->units[0]['start']);
    $this->assertSame(2758.0, $r->units[1379]['start']);
  }

  /**
   * A time window returns the units that start inside it.
   */
  public function testTimeWindow(): void {
    $this->transcript(30);
    foreach ([0, 10, 20, 30, 40] as $i => $start) {
      $this->unit(300 + $i, 30, $i, sprintf('%.3f', $start));
    }
    $r = $this->reader->read(30, $this->accounts['anonymous'], 10, 30);
    $this->assertSame([10.0, 20.0, 30.0], array_column($r->units, 'start'));
    $this->assertSame([40.0], array_column($this->reader->read(30, $this->accounts['anonymous'], 35)->units, 'start'));
  }

  /**
   * A private collection's transcript reads for members, never for others, and
   * every denial looks the same as "no such transcript".
   */
  public function testPrivateTranscriptIsNotReadable(): void {
    $this->transcript(40, 1);
    $this->unit(400, 40, 0, '1.000');
    $this->assertNull($this->reader->read(40, $this->accounts['anonymous']));
    $this->assertNull($this->reader->read(40, $this->accounts['outsider']));
    $this->assertNotNull($this->reader->read(40, $this->accounts['member']));
    $this->assertNotNull($this->reader->read(40, $this->accounts['bypass']));
    $this->assertNull($this->reader->read(41, $this->accounts['member']), 'No such transcript.');
  }

  /**
   * The current user is the default viewer.
   */
  public function testDefaultsToTheCurrentUser(): void {
    $this->transcript(50, 1);
    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($this->accounts['outsider']);
    $this->assertNull($this->reader->read(50));
    $switcher->switchBack();
    $switcher->switchTo($this->accounts['member']);
    $this->assertNotNull($this->reader->read(50));
    $switcher->switchBack();
  }

  /**
   * A transcript whose node is gone reads as nothing, for everyone.
   */
  public function testOrphanedTranscriptReadsAsNothing(): void {
    $t = $this->transcript(60);
    $this->unit(600, 60, 0, '1.000');
    $t->getNode()->delete();
    $this->assertNull($this->reader->read(60, $this->accounts['member']));
    $this->assertNull($this->reader->read(60, $this->accounts['bypass']));
  }

  /**
   * readForNode finds the node's transcript, under the same access rule.
   */
  public function testReadForNode(): void {
    $t = $this->transcript(70);
    $this->unit(700, 70, 0, '1.000');
    $nid = (int) $t->getNode()->id();
    $this->assertSame(70, $this->reader->readForNode($nid, $this->accounts['anonymous'])->id);
    $this->assertNull($this->reader->readForNode(99999, $this->accounts['anonymous']));
    $p = $this->transcript(71, 1);
    $this->assertNull($this->reader->readForNode((int) $p->getNode()->id(), $this->accounts['anonymous']));
  }

  /**
   * Cache metadata names the transcript, node and unit list, and unit edits
   * invalidate the unit tag.
   */
  public function testCacheabilityAndUnitInvalidation(): void {
    $t = $this->transcript(80);
    $u = $this->unit(800, 80, 0, '1.000');
    $r = $this->reader->read(80, $this->accounts['anonymous']);
    $tags = $r->cacheability->getCacheTags();
    $this->assertContains('av_transcript:80', $tags);
    $this->assertContains('node:' . $t->getNode()->id(), $tags);
    $this->assertContains('av_transcript_units:80', $tags);
    $this->assertContains('user', $r->cacheability->getCacheContexts());

    $backend = $this->container->get('cache.default');
    $backend->set('probe', 'x', -1, ['av_transcript_units:80']);
    $this->assertNotFalse($backend->get('probe'));
    $u->set('start', '2.000')->save();
    $this->assertFalse($backend->get('probe'), 'Saving a unit invalidates the transcript unit tag.');

    $backend->set('probe2', 'x', -1, ['av_transcript_units:80']);
    $u->delete();
    $this->assertFalse($backend->get('probe2'), 'Deleting a unit invalidates it too.');
    $this->assertSame([], $this->reader->read(80, $this->accounts['anonymous'])->units);
  }

}
