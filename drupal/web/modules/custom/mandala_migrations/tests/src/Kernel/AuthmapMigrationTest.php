<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_migrations\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Our own authmap source and destination plugins, run as a real migration.
 *
 * externalauth removed its authmap source and deprecated its destination, so
 * these two plugins carry the NetBadge account links. The source test reads a
 * D7-shaped `authmap` table (aid, uid, authname, module) through a second,
 * prefixed connection to the test database, the way the real migration reads
 * the shared D7 user database.
 *
 * @group mandala_migrations
 */
#[RunTestsInSeparateProcesses]
class AuthmapMigrationTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'migrate', 'externalauth', 'mandala_migrations', 'migrate_drupal', 'migrate_plus', 'migrate_tools', 'paragraphs', 'entity_reference_revisions', 'file', 'field', 'text', 'filter',
  ];

  private ?string $d7Prefix = NULL;

  protected function tearDown(): void {
    if ($this->d7Prefix !== NULL) {
      Database::getConnection('default', 'd7_users_test')->schema()->dropTable('authmap');
    }
    parent::tearDown();
  }

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('externalauth', ['authmap']);
    $this->installSchema('migrate_tools', ['migrate_tools_sync_source_ids']);
    foreach ([2, 3, 4] as $uid) {
      User::create(['uid' => $uid, 'name' => "u$uid", 'status' => 1])->save();
    }
  }

  private function runMigration(array $source, array $extra = []): array {
    $definition = [
      'id' => 'test_authmap',
      'source' => $source + ['constants' => ['provider' => 'simplesamlphp_auth']],
      'process' => [
        'uid' => 'uid',
        'authname' => 'authname',
        'provider' => 'constants/provider',
      ],
      'destination' => ['plugin' => 'mandala_authmap'],
    ] + $extra;
    $migration = $this->container->get('plugin.manager.migration')->createStubMigration($definition);
    $executable = new MigrateExecutable($migration);
    $result = $executable->import();
    return [$result, $migration];
  }

  private function links(): array {
    return $this->container->get('database')->select('authmap', 'a')
      ->fields('a', ['uid', 'provider', 'authname'])->orderBy('uid')->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * A D7-shaped authmap table behind a prefixed connection, with four rows.
   */
  private function d7Source(): void {
    $info = Database::getConnectionInfo('default')['default'];
    // Unique per test process and dropped in tearDown, so nothing outlives it.
    $this->d7Prefix = 'd7' . substr(md5(uniqid('', TRUE)), 0, 8) . '_';
    $info['prefix'] = $this->d7Prefix;
    Database::addConnectionInfo('d7_users_test', 'default', $info);
    $d7 = Database::getConnection('default', 'd7_users_test');
    $d7->schema()->createTable('authmap', [
      'fields' => [
        'aid' => ['type' => 'serial', 'not null' => TRUE],
        'uid' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'authname' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        'module' => ['type' => 'varchar', 'length' => 128, 'not null' => TRUE, 'default' => ''],
      ],
      'primary key' => ['aid'],
    ]);
    foreach ([[2, 'abc2d', 'simplesamlphp_auth'], [3, 'xyz3q', 'simplesamlphp_auth'], [4, 'other4', 'some_other_module']] as [$uid, $name, $module]) {
      $d7->insert('authmap')->fields(['uid' => $uid, 'authname' => $name, 'module' => $module])->execute();
    }
  }

  public function testDestinationLinksTheUsersAndRerunsAreIdempotent(): void {
    $rows = [
      ['uid' => 2, 'authname' => 'abc2d'],
      ['uid' => 3, 'authname' => 'xyz3q'],
    ];
    $source = ['plugin' => 'embedded_data', 'data_rows' => $rows, 'ids' => ['uid' => ['type' => 'integer']]];

    [$result] = $this->runMigration($source);

    $this->assertSame(MigrationInterface::RESULT_COMPLETED, $result);
    $this->assertSame([
      ['uid' => '2', 'provider' => 'simplesamlphp_auth', 'authname' => 'abc2d'],
      ['uid' => '3', 'provider' => 'simplesamlphp_auth', 'authname' => 'xyz3q'],
    ], $this->links());
    $this->assertSame(2, (int) $this->container->get('externalauth.authmap')->getUid('abc2d', 'simplesamlphp_auth'));

    // A changed authname is updated, and the row count stays the same.
    $rows[0]['authname'] = 'abc2d-new';
    $source['data_rows'] = $rows;
    $migration = $this->container->get('plugin.manager.migration')->createStubMigration([
      'id' => 'test_authmap_2',
      'source' => $source + ['constants' => ['provider' => 'simplesamlphp_auth']],
      'process' => ['uid' => 'uid', 'authname' => 'authname', 'provider' => 'constants/provider'],
      'destination' => ['plugin' => 'mandala_authmap'],
    ]);
    (new MigrateExecutable($migration))->import();
    $this->assertCount(2, $this->links(), 'Re-running does not duplicate links.');
    $this->assertSame(2, (int) $this->container->get('externalauth.authmap')->getUid('abc2d-new', 'simplesamlphp_auth'));
  }

  public function testAMissingUserFailsTheRowWithoutStoppingTheRest(): void {
    $source = [
      'plugin' => 'embedded_data',
      'data_rows' => [['uid' => 99, 'authname' => 'ghost'], ['uid' => 4, 'authname' => 'real4']],
      'ids' => ['uid' => ['type' => 'integer']],
    ];

    [$result, $migration] = $this->runMigration($source);

    $this->assertSame(MigrationInterface::RESULT_COMPLETED, $result);
    $this->assertSame([['uid' => '4', 'provider' => 'simplesamlphp_auth', 'authname' => 'real4']], $this->links());
    $messages = iterator_to_array($migration->getIdMap()->getMessages());
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('user 99 does not exist', $messages[0]->message);
  }

  public function testSourceReadsTheD7TableAndHonoursTheModuleFilter(): void {
    $this->d7Source();

    [$result] = $this->runMigration(['plugin' => 'mandala_d7_authmap', 'key' => 'd7_users_test', 'modules' => ['simplesamlphp_auth']]);

    $this->assertSame(MigrationInterface::RESULT_COMPLETED, $result);
    $this->assertSame([
      ['uid' => '2', 'provider' => 'simplesamlphp_auth', 'authname' => 'abc2d'],
      ['uid' => '3', 'provider' => 'simplesamlphp_auth', 'authname' => 'xyz3q'],
    ], $this->links(), 'The row from another auth module is not labelled simplesamlphp_auth.');
  }

  public function testSourceWithoutAModuleFilterReadsEveryRow(): void {
    $this->d7Source();

    $this->runMigration(['plugin' => 'mandala_d7_authmap', 'key' => 'd7_users_test']);

    $this->assertCount(3, $this->links());
  }

}
