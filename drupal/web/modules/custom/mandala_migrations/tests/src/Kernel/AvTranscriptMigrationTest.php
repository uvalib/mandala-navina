<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_migrations\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Entity\Migration as MigrationEntity;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The two T5 migrations, as exported in config/sync, run end to end.
 *
 * The YAML under test is the file in drupal/config/sync, loaded and saved as a
 * migration config entity with only the source key swapped for the test's
 * prefixed D7 connection, so what passes here is what deploys. Real entities,
 * the real event subscriber, the real id map.
 *
 * D7 nodes 100, 101, 102, 104, 200 and 201 have D11 nodes (found by legacy key);
 * node 105 deliberately has none.
 *
 * @group mandala_migrations
 */
#[RunTestsInSeparateProcesses]
class AvTranscriptMigrationTest extends KernelTestBase {

  use D7AvTranscriptFixtureTrait;

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'options', 'file',
    'migrate', 'migrate_drupal', 'migrate_plus', 'migrate_tools', 'paragraphs', 'entity_reference_revisions',
    'mandala_av_transcripts', 'mandala_migrations',
  ];

  /**
   * D7 node id => D11 node id, for the nodes that exist.
   *
   * @var int[]
   */
  private array $nodes = [];

  /**
   * Events the transcript notifier dispatched, by name.
   *
   * @var string[]
   */
  private array $events = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    $this->buildD7();

    NodeType::create(['type' => 'video', 'name' => 'Video'])->save();
    FieldStorageConfig::create(['field_name' => 'field_legacy_site', 'entity_type' => 'node', 'type' => 'string'])->save();
    FieldStorageConfig::create(['field_name' => 'field_legacy_nid', 'entity_type' => 'node', 'type' => 'integer'])->save();
    FieldStorageConfig::create(['field_name' => 'field_transcript', 'entity_type' => 'node', 'type' => 'file', 'settings' => ['target_type' => 'file']])->save();
    foreach (['field_legacy_site', 'field_legacy_nid', 'field_transcript'] as $name) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'video', 'label' => $name])->save();
    }

    foreach ([100, 101, 102, 104, 200, 201] as $d7nid) {
      $values = ['type' => 'video', 'title' => "Node $d7nid", 'status' => 1, 'field_legacy_site' => 'audio-video', 'field_legacy_nid' => $d7nid];
      if ($d7nid === 100) {
        $file = File::create(['uri' => 'public://a.vtt', 'filename' => 'a.vtt', 'status' => 1]);
        $file->save();
        $values['field_transcript'] = ['target_id' => $file->id()];
        $this->fileId = (int) $file->id();
      }
      $node = Node::create($values);
      $node->save();
      $this->nodes[$d7nid] = (int) $node->id();
    }
    // A node of another site with a colliding legacy nid must never be picked.
    Node::create(['type' => 'video', 'title' => 'Other site', 'status' => 1, 'field_legacy_site' => 'images', 'field_legacy_nid' => 105])->save();

    foreach ([TranscriptEvents::UNIT_SAVED, TranscriptEvents::UNITS_REPLACED, TranscriptEvents::TRANSCRIPT_SAVED] as $name) {
      $this->container->get('event_dispatcher')->addListener($name, function () use ($name): void {
        $this->events[] = $name;
      });
    }
  }

  private int $fileId = 0;

  protected function tearDown(): void {
    $this->dropD7();
    parent::tearDown();
  }

  /**
   * The migration as exported, saved as a config entity with the test source key.
   */
  private function migration(string $id): MigrationInterface {
    $manager = $this->container->get('plugin.manager.migration');
    if (!MigrationEntity::load($id)) {
      $definition = Yaml::decode(file_get_contents(DRUPAL_ROOT . "/../config/sync/migrate_plus.migration.$id.yml"));
      $definition['source']['key'] = 'd7_av_test';
      unset($definition['migration_group'], $definition['uuid']);
      MigrationEntity::create($definition)->save();
      $manager->clearCachedDefinitions();
    }
    return $manager->createInstance($id);
  }

  private function migrate(string $id, string $how = 'import'): int {
    return (new MigrateExecutable($this->migration($id), new MigrateMessage()))->$how();
  }

  private function migrateAll(): void {
    $this->migrate('d7_av_transcript');
    $this->migrate('d7_av_tcu');
  }

  private function loadTranscript(int $id): ?AvTranscript {
    $this->container->get('entity_type.manager')->getStorage('av_transcript')->resetCache();
    return AvTranscript::load($id);
  }

  private function loadUnit(int $id): ?AvTcu {
    $this->container->get('entity_type.manager')->getStorage('av_tcu')->resetCache();
    return AvTcu::load($id);
  }

  private function rowCount(string $type, string $where = ''): int {
    return (int) $this->container->get('database')->query("SELECT COUNT(*) FROM {{$type}} $where")->fetchField();
  }

  public function testTranscriptsLandWithTheirNodeFileFormatTiersAndEditsFlag(): void {
    $this->migrateAll();

    $t = $this->loadTranscript(10);
    $this->assertSame($this->nodes[100], (int) $t->get('node')->target_id, 'The node is found by legacy key, not by D7 nid.');
    $this->assertSame($this->fileId, (int) $t->get('source_file')->target_id, "The node's current field_transcript file.");
    $this->assertSame('vtt', $t->get('source_format')->value);
    $this->assertSame(['content_bod', 'ts_content_eng'], $t->getTiers());
    $this->assertTrue((bool) $t->get('has_edits')->value);
    $this->assertSame(1700005000, (int) $t->get('edits_flagged_at')->value);
    $this->assertSame(200, (int) $t->get('created')->value);
    $this->assertFalse($t->isDeleted());

    $t11 = $this->loadTranscript(11);
    $this->assertNull($t11->get('source_file')->target_id, 'A node with no file has no source_file.');
    $this->assertFalse((bool) $t11->get('has_edits')->value);
    $this->assertNull($this->loadTranscript(13), 'Another module\'s tracking row is not migrated.');
  }

  public function testUnitsLandInDisplayOrderWithTimecodesAndStatusesAsFound(): void {
    $this->migrateAll();

    $this->assertSame(20, (int) $this->loadUnit(201)->get('transcript')->target_id);
    $this->assertSame([2, 1, 3, 0], array_map(fn ($id) => (int) $this->loadUnit($id)->get('sequence')->value, [201, 204, 203, 202]), 'Sequence is time order: 202, 204, 201, 203.');
    $this->assertSame('-0.125', (string) $this->loadUnit(212)->get('start')->value, 'The negative start is kept.');
    $this->assertSame('157.267', (string) $this->loadUnit(213)->get('start')->value);
    $this->assertSame('ok', $this->loadUnit(201)->get('timecode_status')->value);
    $this->assertSame('zero_length_other', $this->loadUnit(203)->get('timecode_status')->value, 'Start = end on the last unit.');
    $this->assertSame('zero_length_other', $this->loadUnit(211)->get('timecode_status')->value);
    $this->assertSame(['content_bod' => 'a', 'ts_content_eng' => 'b'], $this->loadUnit(201)->getTiers());
    $this->assertSame("\u{00E9}", $this->loadUnit(202)->getTiers()['content_bod'], 'NFC applied.');
    $this->assertNull($this->loadUnit(201)->get('end_original')->value, 'Nothing is repaired.');
  }

  public function testOnlyUnitsOfMigratedTranscriptsArriveAndTheOrphanStaysOut(): void {
    $this->migrateAll();

    $this->assertNull($this->loadUnit(221), 'The orphan trid has no tracking row.');
    $this->assertNull($this->loadTranscript(15), 'Its node does not exist in D11, so the row failed.');
    $this->assertNull($this->loadUnit(6), 'A unit of a transcript that failed is not migrated; it must not point at a transcript that does not exist.');
    $this->assertSame(6, $this->rowCount('av_transcript'));
    $this->assertSame(12, $this->rowCount('av_tcu'));
  }

  public function testAFailedTranscriptIsReportedNotSilentlySkipped(): void {
    $migration = $this->migration('d7_av_transcript');
    (new MigrateExecutable($migration, new MigrateMessage()))->import();

    $messages = array_map(fn ($m) => $m->message, iterator_to_array($migration->getIdMap()->getMessages()));
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Expected one D11 node for legacy key (audio-video, 105), found 0', $messages[0]);
  }

  public function testARerunIsIdempotentAndAnUpdateRunMakesNoNewRevisions(): void {
    $this->migrateAll();
    $before = [$this->rowCount('av_transcript'), $this->rowCount('av_tcu'), $this->rowCount('av_transcript_revision'), $this->rowCount('av_tcu_revision')];

    $this->migrate('d7_av_transcript');
    $this->migrate('d7_av_tcu');
    $this->assertSame($before, [$this->rowCount('av_transcript'), $this->rowCount('av_tcu'), $this->rowCount('av_transcript_revision'), $this->rowCount('av_tcu_revision')], 'A plain re-run processes nothing.');

    foreach (['d7_av_transcript', 'd7_av_tcu'] as $id) {
      $this->migration($id)->getIdMap()->prepareUpdate();
      $this->migrate($id);
    }
    $this->assertSame($before, [$this->rowCount('av_transcript'), $this->rowCount('av_tcu'), $this->rowCount('av_transcript_revision'), $this->rowCount('av_tcu_revision')], '--update rewrites in place: same rows, one revision each.');
  }

  public function testUnitNotificationsAreCoalescedDuringTheRun(): void {
    $this->events = [];
    $this->migrate('d7_av_transcript');
    $this->migrate('d7_av_tcu');

    $this->assertSame(0, count(array_keys($this->events, TranscriptEvents::UNIT_SAVED, TRUE)), 'No per-unit events while a transcript migration runs.');
    $this->assertSame(6, count(array_keys($this->events, TranscriptEvents::UNITS_REPLACED, TRUE)), 'One UNITS_REPLACED for each of the six transcripts that received units, however many units each got.');
    $this->assertFalse($this->container->get('mandala_av_transcripts.change_notifier')->isSuppressed(), 'The hold is released when the run ends.');
  }

  public function testASoftDeletedTranscriptIsRevivedWithItsUnitsButNotUnitsAnEditorDeleted(): void {
    $this->migrateAll();
    // An editor deleted unit 202 on purpose; then the node was deleted, which
    // soft-deletes the transcript and the units that were still live.
    $editorDeleted = $this->loadUnit(202);
    $editorDeleted->setDeleted(TRUE)->save();
    $this->container->get('mandala_av_transcripts.lifecycle')->softDelete($this->loadTranscript(20), 'The node was deleted.');
    $this->assertTrue($this->loadTranscript(20)->isDeleted());
    $this->assertTrue($this->loadUnit(201)->isDeleted());

    $this->migration('d7_av_transcript')->getIdMap()->prepareUpdate();
    $this->migrate('d7_av_transcript');
    $this->assertFalse($this->loadTranscript(20)->isDeleted(), 'The transcript import revived the transcript.');
    $this->assertFalse($this->loadUnit(201)->isDeleted(), 'Restoring it revived the units that went with it, right away.');

    $this->migration('d7_av_tcu')->getIdMap()->prepareUpdate();
    $this->migrate('d7_av_tcu');
    $this->assertFalse($this->loadUnit(201)->isDeleted(), 'The unit import did not delete it again.');
    $this->assertTrue($this->loadUnit(202)->isDeleted(), 'A unit an editor deleted on purpose stays deleted.');
  }

  public function testRollbackIndexesTheIdMapOnDestinationId(): void {
    // Core indexes only the source ids, so each rolled-back row would scan the
    // whole map. At 243,005 units that took hours.
    $this->migrateAll();
    $map = $this->migration('d7_av_tcu')->getIdMap();
    $schema = \Drupal::database()->schema();
    $this->assertFalse($schema->indexExists($map->mapTableName(), 'destid1'));

    $this->migrate('d7_av_tcu', 'rollback');

    $this->assertTrue($schema->indexExists($map->mapTableName(), 'destid1'));
    $this->migrate('d7_av_tcu', 'rollback');
    $this->migrate('d7_av_tcu');
    $this->assertSame(12, $this->rowCount('av_tcu'), 'The index does not get in the way of a re-import.');
  }

  public function testRollbackHardDeletesUnitsThenTranscripts(): void {
    $this->migrateAll();
    $this->assertSame(12, $this->rowCount('av_tcu'));

    $this->migrate('d7_av_tcu', 'rollback');
    $this->assertSame(0, $this->rowCount('av_tcu'), 'Units are really deleted, not soft-deleted.');
    $this->assertSame(6, $this->rowCount('av_transcript'));

    $this->migrate('d7_av_transcript', 'rollback');
    $this->assertSame(0, $this->rowCount('av_transcript'));
    $this->assertSame(0, $this->rowCount('av_transcript', 'WHERE deleted = 1'));
    $this->assertFalse($this->container->get('mandala_av_transcripts.change_notifier')->isSuppressed(), 'The hold is released after a rollback too.');
  }

}
