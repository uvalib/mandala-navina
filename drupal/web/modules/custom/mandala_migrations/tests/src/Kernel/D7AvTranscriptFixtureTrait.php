<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_migrations\Kernel;

use Drupal\Core\Database\Database;

/**
 * A D7-shaped copy of the transcript tables, behind a second prefixed connection.
 *
 * Shared by the source-plugin test and the end-to-end migration test. The tables
 * follow the D7 schema as read from the 2026-09-01 production dump (`tcu` has
 * FLOAT start and end and no index on `trid`). Each row stands for a case found
 * in the real corpus; see docs/planning/t5-transcript-migration-plan.md.
 */
trait D7AvTranscriptFixtureTrait {

  private const D7_TABLES = [
    'tcu', 'tcu_tier', 'tcu_speaker', 'transcripts_apachesolr_transcript',
    'flag', 'flagging', 'field_data_field_transcript', 'file_managed', 'variable',
  ];

  private ?string $d7Prefix = NULL;

  /**
   * Drops the D7 tables; call from tearDown().
   */
  private function dropD7(): void {
    if ($this->d7Prefix !== NULL) {
      $schema = Database::getConnection('default', 'd7_av_test')->schema();
      foreach (self::D7_TABLES as $table) {
        if ($schema->tableExists($table)) {
          $schema->dropTable($table);
        }
      }
    }
  }

  /**
   * Builds the D7-shaped tables and their rows behind a prefixed connection.
   */
  private function buildD7(): void {
    $info = Database::getConnectionInfo('default')['default'];
    $this->d7Prefix = 'd7av' . substr(md5(uniqid('', TRUE)), 0, 8) . '_';
    $info['prefix'] = $this->d7Prefix;
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
