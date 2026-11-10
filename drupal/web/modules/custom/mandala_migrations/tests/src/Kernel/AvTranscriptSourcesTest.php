<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_migrations\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The D7 transcript sources (`d7_av_transcript`, `d7_av_tcu`).
 *
 * Reads a D7-shaped copy of the transcript tables through a second, prefixed
 * connection, as the real migrations read `migrate_av`. The tables are built
 * from the D7 schema as read from the 2026-09-01 production dump (`tcu` has
 * FLOAT start and end and no index on `trid`). The data is small and each row
 * stands for a case found in the real corpus; see
 * docs/planning/t5-transcript-migration-plan.md.
 *
 * @group mandala_migrations
 */
#[RunTestsInSeparateProcesses]
class AvTranscriptSourcesTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'migrate', 'mandala_migrations', 'migrate_drupal', 'migrate_plus', 'migrate_tools', 'paragraphs', 'entity_reference_revisions', 'file', 'field', 'text', 'filter',
  ];

  private const TABLES = [
    'tcu', 'tcu_tier', 'tcu_speaker', 'transcripts_apachesolr_transcript',
    'flag', 'flagging', 'field_data_field_transcript', 'file_managed', 'variable',
  ];

  private ?string $prefix = NULL;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    $this->buildD7();
  }

  protected function tearDown(): void {
    if ($this->prefix !== NULL) {
      $schema = Database::getConnection('default', 'd7_av_test')->schema();
      foreach (self::TABLES as $table) {
        if ($schema->tableExists($table)) {
          $schema->dropTable($table);
        }
      }
    }
    parent::tearDown();
  }

  /**
   * Reads every row a source plugin yields, keyed by the given source column.
   */
  private function rows(string $plugin, string $key, array $config = []): array {
    $migration = $this->container->get('plugin.manager.migration')->createStubMigration([
      'id' => 'test_' . $plugin,
      'source' => ['plugin' => $plugin, 'key' => 'd7_av_test'] + $config,
      'process' => ['x' => $key],
      'destination' => ['plugin' => 'null'],
    ]);
    $out = [];
    foreach ($migration->getSourcePlugin() as $row) {
      $source = $row->getSource();
      $out[$source[$key]] = $source;
    }
    return $out;
  }

  public function testTranscriptsAreOneRowPerTrackingRowAndOthersAreExcluded(): void {
    $rows = $this->rows('d7_av_transcript', 'trid');

    $this->assertSame([10, 11, 12, 14, 15, 20, 21], array_map('intval', array_keys($rows)), 'Trid 13 belongs to another module and is excluded.');
  }

  public function testEditsFlagUsesTheLatestFlaggingOfTheRightFlagAndEntityType(): void {
    $rows = $this->rows('d7_av_transcript', 'trid');

    $this->assertTrue($rows[10]['has_edits']);
    $this->assertSame('1700005000', (string) $rows[10]['edits_flagged_at'], 'Two flaggings collapse to the latest.');
    $this->assertTrue($rows[15]['has_edits']);
    $this->assertSame('1600000000', (string) $rows[15]['edits_flagged_at']);
    $this->assertFalse($rows[11]['has_edits'], 'A different flag does not count.');
    $this->assertFalse($rows[12]['has_edits'], 'A flagging on a user with the same id does not count.');
    $this->assertNull($rows[12]['edits_flagged_at']);
  }

  public function testSourceFormatComesFromTheCurrentAttachmentsExtension(): void {
    $rows = $this->rows('d7_av_transcript', 'trid');

    $this->assertSame('vtt', $rows[10]['source_format']);
    $this->assertNull($rows[11]['source_format'], 'An extension outside vtt/xml/txt/srt is not guessed.');
    $this->assertSame('srt', $rows[12]['source_format'], 'The extension is matched case-insensitively.');
    $this->assertNull($rows[14]['source_format'], 'A tracked node with no field_transcript row has no format.');
    $this->assertNull($rows[14]['current_fid']);
    $this->assertSame('xml', $rows[15]['source_format']);
    $this->assertSame('901', (string) $rows[10]['current_fid']);
  }

  public function testTiersFollowTheConfiguredOrderNotTheTrackingOrder(): void {
    $rows = $this->rows('d7_av_transcript', 'trid');

    $this->assertSame(['content_bod', 'ts_content_eng'], $rows[10]['tier_list'], 'Tracking order is reversed; the configured order wins.');
    $this->assertSame(['content_bod', 'ts_content_wylie', 'zz_odd'], $rows[11]['tier_list'], 'Hidden tiers follow the visible ones; an unknown tier is kept, last.');
    $this->assertSame(['content_bod', 'dzo_bod'], $rows[12]['tier_list'], 'An empty tracking column falls back to the tiers on the units.');
    $this->assertSame('["content_bod","ts_content_eng"]', $rows[10]['tiers_json']);
  }

  public function testCreatedIsTheEarliestUnitCreated(): void {
    $rows = $this->rows('d7_av_transcript', 'trid');

    $this->assertSame(200, (int) $rows[10]['created']);
    $this->assertSame(500, (int) $rows[11]['created']);
  }

  public function testMissingTierConfigurationFailsInsteadOfGuessing(): void {
    Database::getConnection('default', 'd7_av_test')->delete('variable')->condition('name', 'transcripts_ui_tiers')->execute();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('refusing to guess');
    $this->rows('d7_av_transcript', 'trid');
  }

  public function testTranscriptsCanBeScopedByTrid(): void {
    $rows = $this->rows('d7_av_transcript', 'trid', ['trids' => [11]]);

    $this->assertSame([11], array_map('intval', array_keys($rows)));
  }

  public function testUnitsOfUntrackedTranscriptsAreExcluded(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    $ids = array_map('intval', array_keys($rows));
    sort($ids);
    $this->assertArrayNotHasKey(221, $rows, 'Trid 22 has units but no tracking row.');
    $this->assertSame([1, 2, 3, 4, 5, 6, 201, 202, 203, 204, 211, 212, 213], $ids, 'Every unit of every tracked transcript, and no other.');
  }

  public function testSequenceIsTimeOrderWithTcuidBreakingTies(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    // Trid 20 in tcuid order is 201 (5.0), 202 (1.0), 203 (5.0), 204 (3.5).
    $this->assertSame(
      [202 => 0, 204 => 1, 201 => 2, 203 => 3],
      [202 => (int) $rows[202]['sequence'], 204 => (int) $rows[204]['sequence'], 201 => (int) $rows[201]['sequence'], 203 => (int) $rows[203]['sequence']],
    );
    // Sequence restarts at 0 in the next transcript.
    $this->assertSame(0, (int) $rows[212]['sequence']);
    $this->assertSame(2, (int) $rows[213]['sequence']);
  }

  public function testNextStartFollowsTheSameOrderAndIsNullOnTheLastUnit(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    $this->assertSame('3.500', (string) $rows[202]['next_start_s']);
    $this->assertSame('5.000', (string) $rows[204]['next_start_s']);
    $this->assertSame('5.000', (string) $rows[201]['next_start_s'], 'A tied neighbour still counts as the next unit.');
    $this->assertNull($rows[203]['next_start_s']);
    $this->assertNull($rows[213]['next_start_s']);
  }

  public function testTimecodesAreExactDecimalsAndCarriedAsFound(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    $this->assertSame('157.267', (string) $rows[213]['start_s'], 'A single-precision float is not allowed to leak sub-millisecond noise.');
    $this->assertSame('158.400', (string) $rows[213]['end_s']);
    $this->assertSame('-0.125', (string) $rows[212]['start_s'], 'The one negative start is kept.');
    $this->assertSame('0.000', (string) $rows[211]['end_s'], 'An end of 0 is stored as found, not repaired.');
  }

  public function testTiersAndSpeakersAreJsonObjectsNormalisedToNfc(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    $this->assertSame(['content_bod' => 'a', 'ts_content_eng' => 'b'], json_decode($rows[201]['tiers_json'], TRUE));
    $this->assertSame(['ss_speaker' => 'Lama'], json_decode($rows[201]['speakers_json'], TRUE));
    $this->assertSame(0, (int) $rows[201]['nfc_changed']);

    // 202 holds a decomposed e + combining acute in a tier and in a speaker.
    $this->assertSame(['content_bod' => "\u{00E9}"], json_decode($rows[202]['tiers_json'], TRUE));
    $this->assertSame(['ss_speaker' => "Caf\u{00E9}"], json_decode($rows[202]['speakers_json'], TRUE));
    $this->assertSame(2, (int) $rows[202]['nfc_changed']);
    $this->assertStringContainsString("\u{00E9}", $rows[202]['tiers_json'], 'Written unescaped, as the entity writes it.');
  }

  public function testEmptyValuesAreCarriedAndAUnitWithNoTiersGetsAnEmptyObject(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid');

    $this->assertSame(['content_bod' => ''], json_decode($rows[203]['tiers_json'], TRUE), 'An empty tier value is kept, not dropped.');
    $this->assertSame('{}', $rows[204]['tiers_json'], 'A unit with no tier rows yields {}.');
    $this->assertSame('{}', $rows[204]['speakers_json']);
  }

  public function testSequenceSurvivesAnOuterFilterOfTheKindMigrateAdds(): void {
    // Migrate ANDs a condition onto the source query to skip rows it has
    // already imported. SQL evaluates WHERE before window functions, so numbering
    // done in the outer query would restart over the survivors. Unit 202 (the
    // first of trid 20 in time order) is filtered out here the same way.
    $source = $this->container->get('plugin.manager.migration')->createStubMigration([
      'id' => 'test_resume',
      'source' => ['plugin' => 'd7_av_tcu', 'key' => 'd7_av_test'],
      'process' => ['x' => 'tcuid'],
      'destination' => ['plugin' => 'null'],
    ])->getSourcePlugin();
    $query = $source->query();
    $query->condition('c.tcuid', 202, '<>');
    $rows = $query->execute()->fetchAllAssoc('tcuid', \PDO::FETCH_ASSOC);

    $this->assertArrayNotHasKey(202, $rows, 'The filter removed the unit.');
    $this->assertSame(1, (int) $rows[204]['sequence'], 'Numbering still counts the filtered unit.');
    $this->assertSame('5.000', (string) $rows[204]['next_start_s']);
    $this->assertSame(2, (int) $rows[201]['sequence']);
    $this->assertSame(3, (int) $rows[203]['sequence']);
  }

  public function testUnitsCanBeScopedByTranscriptWithoutChangingTheirSequence(): void {
    $rows = $this->rows('d7_av_tcu', 'tcuid', ['trids' => [21]]);

    $ids = array_map('intval', array_keys($rows));
    sort($ids);
    $this->assertSame([211, 212, 213], $ids);
    $this->assertSame(0, (int) $rows[212]['sequence']);
    $this->assertSame(1, (int) $rows[211]['sequence']);
    $this->assertSame(2, (int) $rows[213]['sequence']);
  }

  /**
   * Builds the D7-shaped tables and their rows behind a prefixed connection.
   */
  private function buildD7(): void {
    $info = Database::getConnectionInfo('default')['default'];
    $this->prefix = 'd7av' . substr(md5(uniqid('', TRUE)), 0, 8) . '_';
    $info['prefix'] = $this->prefix;
    Database::addConnectionInfo('d7_av_test', 'default', $info);
    $d7 = Database::getConnection('default', 'd7_av_test');
    $schema = $d7->schema();

    $int = ['type' => 'int', 'not null' => TRUE, 'default' => 0];
    $text = ['type' => 'text', 'size' => 'big'];
    $schema->createTable('tcu', [
      'fields' => [
        'tcuid' => ['type' => 'serial', 'not null' => TRUE],
        'trid' => $int,
        'start' => ['type' => 'float', 'size' => 'normal', 'not null' => TRUE, 'default' => 0],
        'end' => ['type' => 'float', 'size' => 'normal', 'not null' => TRUE, 'default' => 0],
        'created' => $int,
        'changed' => $int,
      ],
      'primary key' => ['tcuid'],
    ]);
    foreach (['tcu_tier', 'tcu_speaker'] as $table) {
      $schema->createTable($table, [
        'fields' => [
          'tcuid' => $int,
          'tier' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
          'value' => $text + ['not null' => TRUE],
        ],
        'primary key' => ['tcuid', 'tier'],
      ]);
    }
    $schema->createTable('transcripts_apachesolr_transcript', [
      'fields' => [
        'trid' => ['type' => 'serial', 'not null' => TRUE],
        'fid' => $int,
        'module' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'type' => ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''],
        'id' => $int,
        'status' => $int,
        'tiers' => $text,
      ],
      'primary key' => ['trid'],
    ]);
    $schema->createTable('flag', [
      'fields' => ['fid' => ['type' => 'serial', 'not null' => TRUE], 'name' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => '']],
      'primary key' => ['fid'],
    ]);
    $schema->createTable('flagging', [
      'fields' => [
        'flagging_id' => ['type' => 'serial', 'not null' => TRUE],
        'fid' => $int,
        'entity_type' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'entity_id' => $int,
        'uid' => $int,
        'sid' => $int,
        'timestamp' => $int,
      ],
      'primary key' => ['flagging_id'],
    ]);
    $schema->createTable('field_data_field_transcript', [
      'fields' => [
        'entity_type' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'deleted' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'entity_id' => $int,
        'revision_id' => $int,
        'language' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE, 'default' => ''],
        'delta' => $int,
        'field_transcript_fid' => $int,
      ],
      'primary key' => ['entity_type', 'entity_id', 'deleted', 'delta', 'language'],
    ]);
    $schema->createTable('file_managed', [
      'fields' => ['fid' => ['type' => 'serial', 'not null' => TRUE], 'filename' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => '']],
      'primary key' => ['fid'],
    ]);
    $schema->createTable('variable', [
      'fields' => ['name' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''], 'value' => $text + ['not null' => TRUE]],
      'primary key' => ['name'],
    ]);

    $variables = [
      'transcripts_ui_tiers' => ['content_bod' => 'Tibetan', 'dzo_bod' => 'Dzongkha', 'ts_content_eng' => 'English'],
      'transcripts_ui_hidden_tiers' => ['ts_content_wylie' => 'Wylie'],
    ];
    foreach ($variables as $name => $value) {
      $d7->insert('variable')->fields(['name' => $name, 'value' => serialize($value)])->execute();
    }

    // [trid, node, module, tiers]. Trid 13 is another module's; 22 (below) has
    // units and no tracking row at all.
    $tracked = [
      [10, 100, 'transcripts_node', 'ts_content_eng content_bod'],
      [11, 101, 'transcripts_node', 'zz_odd content_bod ts_content_wylie'],
      [12, 102, 'transcripts_node', ''],
      [13, 103, 'other_module', 'content_bod'],
      [14, 104, 'transcripts_node', 'content_bod'],
      [15, 105, 'transcripts_node', 'content_bod'],
      [20, 200, 'transcripts_node', 'content_bod ts_content_eng'],
      [21, 201, 'transcripts_node', 'content_bod'],
    ];
    foreach ($tracked as [$trid, $node, $module, $tiers]) {
      $d7->insert('transcripts_apachesolr_transcript')->fields([
        'trid' => $trid, 'fid' => 500 + $trid, 'module' => $module, 'type' => 'node', 'id' => $node, 'status' => 1, 'tiers' => $tiers,
      ])->execute();
    }

    // The current attachment of each node: [node, fid, filename].
    foreach ([[100, 901, 'a.vtt'], [101, 902, 'b.docx'], [102, 903, 'c.SRT'], [105, 904, 'x.xml']] as [$node, $fid, $name]) {
      $d7->insert('file_managed')->fields(['fid' => $fid, 'filename' => $name])->execute();
      $d7->insert('field_data_field_transcript')->fields([
        'entity_type' => 'node', 'bundle' => 'video', 'deleted' => 0, 'entity_id' => $node, 'revision_id' => $node, 'language' => 'und', 'delta' => 0, 'field_transcript_fid' => $fid,
      ])->execute();
    }

    $d7->insert('flag')->fields(['fid' => 1, 'name' => 'keep_transcript_edits'])->execute();
    $d7->insert('flag')->fields(['fid' => 2, 'name' => 'favorites'])->execute();
    // [flag, type, entity, timestamp]: node 100 twice; 101 on the wrong flag;
    // 102 on a user (not the node); 105 once.
    foreach ([[1, 'node', 100, 1700000000], [1, 'node', 100, 1700005000], [2, 'node', 101, 1650000000], [1, 'user', 102, 1650000000], [1, 'node', 105, 1600000000]] as [$fid, $type, $entity, $time]) {
      $d7->insert('flagging')->fields(['fid' => $fid, 'entity_type' => $type, 'entity_id' => $entity, 'uid' => 1, 'sid' => 0, 'timestamp' => $time])->execute();
    }

    // [tcuid, trid, start, end, created]
    $units = [
      [1, 10, 1.0, 2.0, 300], [2, 10, 2.0, 3.0, 200],
      [3, 11, 1.0, 2.0, 500],
      [4, 12, 1.0, 2.0, 100],
      [5, 14, 1.0, 2.0, 700],
      [6, 15, 1.0, 2.0, 800],
      [201, 20, 5.0, 6.0, 1000], [202, 20, 1.0, 2.0, 1000], [203, 20, 5.0, 5.0, 1000], [204, 20, 3.5, 4.0, 1000],
      [211, 21, 0.0, 0.0, 1000], [212, 21, -0.125, 0.5, 1000], [213, 21, 157.267, 158.4, 1000],
      [221, 22, 1.0, 2.0, 1000],
    ];
    foreach ($units as [$tcuid, $trid, $start, $end, $created]) {
      $d7->insert('tcu')->fields(['tcuid' => $tcuid, 'trid' => $trid, 'start' => $start, 'end' => $end, 'created' => $created, 'changed' => $created])->execute();
    }

    // Tier rows: trid 12's unit carries two tiers (the fallback source); 202
    // has a decomposed e + combining acute; 203 an empty value; 204 none.
    $tiers = [
      [4, 'dzo_bod', 'x'], [4, 'content_bod', 'y'],
      [201, 'content_bod', 'a'], [201, 'ts_content_eng', 'b'],
      [202, 'content_bod', "e\u{0301}"],
      [203, 'content_bod', ''],
    ];
    foreach ($tiers as [$tcuid, $tier, $value]) {
      $d7->insert('tcu_tier')->fields(['tcuid' => $tcuid, 'tier' => $tier, 'value' => $value])->execute();
    }
    foreach ([[201, 'ss_speaker', 'Lama'], [202, 'ss_speaker', "Cafe\u{0301}"]] as [$tcuid, $tier, $value]) {
      $d7->insert('tcu_speaker')->fields(['tcuid' => $tcuid, 'tier' => $tier, 'value' => $value])->execute();
    }
  }

}
