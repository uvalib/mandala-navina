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
    'system', 'user', 'migrate', 'mandala_migrations', 'migrate_drupal', 'migrate_plus', 'migrate_tools', 'paragraphs', 'entity_reference_revisions', 'file', 'field', 'text', 'filter', 'node', 'options', 'mandala_av_transcripts',
  ];

  use D7AvTranscriptFixtureTrait;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    $this->buildD7();
  }

  protected function tearDown(): void {
    $this->dropD7();
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

}
