<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\OrphanManager;
use Drupal\mandala_av_transcripts\TranscriptLifecycle;
use Drupal\node\Entity\Node;
use Drupal\Tests\mandala_group_inheritance\Kernel\CollectionVisibilityKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The orphan manager: list, restore, export, purge (ADR 019).
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class OrphanManagerTest extends CollectionVisibilityKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'group', 'gnode', 'options',
    'entity', 'flexible_permissions', 'mandala_group_inheritance',
    'file', 'mandala_av_transcripts',
  ];

  private OrphanManager $manager;

  private TranscriptLifecycle $lifecycle;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    $this->manager = $this->container->get('mandala_av_transcripts.orphan_manager');
    $this->lifecycle = $this->container->get('mandala_av_transcripts.lifecycle');
  }

  /**
   * A transcript $id with three units (the third has a bad timecode) on a new node.
   */
  private function transcript(int $id, string $title = 'n'): array {
    $node = Node::create(['type' => 'audio', 'title' => $title, 'status' => 1]);
    $node->save();
    $transcript = AvTranscript::create(['id' => $id, 'node' => $node->id()]);
    $transcript->setTiers(['transcript', 'translation']);
    $transcript->save();
    $base = $id * 10;
    AvTcu::create(['id' => $base + 1, 'transcript' => $id, 'sequence' => 0, 'start' => '1.000', 'end' => '2.500'])
      ->setTiers(['transcript' => 'Hello', 'translation' => 'Bonjour'])->save();
    AvTcu::create(['id' => $base + 2, 'transcript' => $id, 'sequence' => 1, 'start' => '2.500', 'end' => '4.000'])
      ->setTiers(['transcript' => 'World', 'translation' => ''])->save();
    AvTcu::create(['id' => $base + 3, 'transcript' => $id, 'sequence' => 2, 'start' => '4.000', 'end' => '3.000', 'timecode_status' => 'inverted'])
      ->setTiers(['transcript' => 'Odd'])->save();
    return [$transcript, $node];
  }

  private function rows(string $table, ?int $transcript = NULL): int {
    $query = $this->container->get('database')->select($table, 't');
    if ($transcript !== NULL) {
      $query->condition('t.transcript', $transcript);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

  private function reset(): void {
    foreach (['av_transcript', 'av_tcu'] as $type) {
      $this->container->get('entity_type.manager')->getStorage($type)->resetCache();
    }
  }

  public function testListShowsOnlyDeletedWithStateAndCounts(): void {
    [$kept] = $this->transcript(1);
    [$gone, $node] = $this->transcript(2, 'Gone');
    [$flagged] = $this->transcript(3);
    $this->lifecycle->softDelete($flagged);
    $node->delete();

    $rows = $this->manager->list();
    $this->assertSame([2, 3], array_keys($rows), 'The live transcript is not listed.');
    $this->assertSame('node_gone', $rows[2]['state']);
    $this->assertSame('Gone', $rows[2]['node_title']);
    $this->assertNull($rows[2]['node']);
    $this->assertSame('node_exists', $rows[3]['state']);
    $this->assertSame(3, $rows[2]['units']);
    $this->assertSame(1, $rows[2]['untimed_or_bad']);
  }

  public function testRestoreWhenNodeExists(): void {
    [$transcript] = $this->transcript(1);
    $this->lifecycle->softDelete($transcript);
    $this->manager->restore(1);
    $this->reset();
    $this->assertFalse(AvTranscript::load(1)->isDeleted());
    $this->assertSame([], $this->manager->list());
  }

  public function testRestoreRefusesWhenNodeIsGoneAndWhenNotDeleted(): void {
    [, $node] = $this->transcript(1);
    [$live] = $this->transcript(2);
    $node->delete();
    try {
      $this->manager->restore(1);
      $this->fail('A transcript with no node must not be restored.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('node no longer exists', $e->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->restore(2);
  }

  public function testExportJsonKeepsEverythingAndSrtVttAreReadable(): void {
    [, $node] = $this->transcript(1, 'Talk');
    // Deleting the node is what takes the snapshot the export carries.
    $node->delete();

    $json = json_decode($this->manager->export(1), TRUE);
    $this->assertSame('Talk', $json['transcript']['node_title']);
    $this->assertSame(['transcript', 'translation'], $json['tiers']);
    $this->assertCount(3, $json['units']);
    $this->assertSame(11, $json['units'][0]['id']);
    $this->assertSame('inverted', $json['units'][2]['timecode_status']);
    $this->assertEquals(4.0, $json['units'][2]['start'], 'Bad timecodes are exported as found.');
    $this->assertEquals(3.0, $json['units'][2]['end']);

    $srt = $this->manager->export(1, 'srt');
    $this->assertStringStartsWith("1\n00:00:01,000 --> 00:00:02,500\nHello\nBonjour\n\n2\n", $srt);
    $this->assertStringContainsString("3\n00:00:04,000 --> 00:00:04,000\nOdd", $srt, 'An inverted end is written as end = start.');
    $this->assertStringStartsWith("WEBVTT\n\n00:00:01.000 --> 00:00:02.500\nHello", $this->manager->export(1, 'vtt'));
  }

  public function testExportRefusesLiveTranscriptAndUnknownFormat(): void {
    [$live] = $this->transcript(1);
    try {
      $this->manager->export(1);
      $this->fail('A live transcript is not exported here.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->lifecycle->softDelete($live);
    $this->expectException(\InvalidArgumentException::class);
    $this->manager->export(1, 'doc');
  }

  public function testPurgeRemovesUnitsAndRevisionsOfThatTranscriptOnly(): void {
    [$a] = $this->transcript(1);
    [$b] = $this->transcript(2);
    // Give a unit a second revision so revisions are really counted.
    $unit = AvTcu::load(11);
    $unit->setNewRevision(TRUE);
    $unit->setTiers(['transcript' => 'Hello again']);
    $unit->save();
    $this->lifecycle->softDelete($a);
    $revisionsOfOthers = (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_tcu_revision} WHERE id BETWEEN 21 AND 23')->fetchField();

    $removed = $this->manager->purge([1]);

    $this->assertSame([1 => 3], $removed);
    $this->reset();
    $this->assertNull(AvTranscript::load(1));
    $this->assertSame(0, $this->rows('av_tcu', 1));
    $this->assertSame(0, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_tcu_revision} WHERE id BETWEEN 11 AND 13')->fetchField(), 'All unit revisions went.');
    $this->assertSame(0, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_transcript_revision} WHERE id = 1')->fetchField(), 'The transcript revisions went.');
    $this->assertNotNull(AvTranscript::load(2), 'The other transcript is untouched.');
    $this->assertSame(3, $this->rows('av_tcu', 2));
    $this->assertSame($revisionsOfOthers, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_tcu_revision} WHERE id BETWEEN 21 AND 23')->fetchField());
  }

  public function testPurgeRefusesALiveTranscriptAndDeletesNothingFromTheBatch(): void {
    [$gone] = $this->transcript(1);
    [$live] = $this->transcript(2);
    $this->lifecycle->softDelete($gone);

    try {
      $this->manager->purge([1, 2]);
      $this->fail('A live transcript must never be purged.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('not a soft-deleted', $e->getMessage());
    }
    $this->reset();
    $this->assertNotNull(AvTranscript::load(1), 'The valid id in the same batch is kept too.');
    $this->assertSame(3, $this->rows('av_tcu', 1));
    $this->assertSame(3, $this->rows('av_tcu', 2));
  }

}
