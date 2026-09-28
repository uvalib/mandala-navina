<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\node\Entity\Node;

/**
 * Asserts the PHP and SQL implementations of collection visibility agree.
 *
 * The visibility rule necessarily exists twice: once in PHP, for
 * hook_entity_access() on entity pages, and once in SQL, for the query alter
 * that filters listings. Two expressions of one rule is exactly the shape that
 * has drifted four times in this codebase (PR #201, PR #199,
 * SiblingCarouselService, PR #255).
 *
 * This test makes drift impossible to land: for every combination of node
 * override, collection visibility and account type, it asserts that
 * $node->access('view') and "is the nid returned by a node_access-tagged
 * query" give the same answer. If someone changes one implementation without
 * the other, a cell flips and the test fails.
 *
 * @group mandala_group_inheritance
 */
#[RunTestsInSeparateProcesses]
class CollectionVisibilityOracleTest extends CollectionVisibilityKernelTestBase {

  /**
   * Every combination of inputs resolves identically in PHP and in SQL.
   */
  public function testPhpAndSqlAgree(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $cases = [];

    foreach (self::GROUP_VALUES as $group_value) {
      $group = $storage->create([
        'type' => 'collection',
        'label' => 'Collection ' . var_export($group_value, TRUE),
      ]);
      if ($group_value !== 'empty') {
        $group->set('field_group_access', $group_value);
      }
      $group->save();
      $group->addMember($this->accounts['member']);

      foreach (self::NODE_VALUES as $node_value) {
        $values = [
          'type' => 'audio',
          'title' => 'n-' . var_export($group_value, TRUE) . '-' . var_export($node_value, TRUE),
          'status' => 1,
        ];
        if ($node_value !== 'absent' && $node_value !== 'empty') {
          $values['field_group_content_access'] = $node_value;
        }
        $node = Node::create($values);
        $node->save();
        $group->addRelationship($node, 'group_node:audio');

        $cases[(int) $node->id()] = [$group_value, $node_value];
      }
    }

    // An out-of-scope bundle, and a node belonging to no collection at all.
    $page = Node::create(['type' => 'page', 'title' => 'out of scope', 'status' => 1]);
    $page->save();
    $orphan = Node::create([
      'type' => 'audio',
      'title' => 'orphan',
      'status' => 1,
      'field_group_content_access' => 2,
    ]);
    $orphan->save();

    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $switcher = $this->container->get('account_switcher');
    $allowed_seen = 0;
    $denied_seen = 0;

    foreach ($this->accounts as $label => $account) {
      $switcher->switchTo($account);
      $visible = $this->visibleNids();

      foreach ($cases as $nid => [$group_value, $node_value]) {
        $node = $node_storage->load($nid);
        $php = $node->access('view', $account);
        $sql = isset($visible[$nid]);

        $this->assertSame($php, $sql, sprintf(
          'Disagreement for account "%s", collection field_group_access=%s, node field_group_content_access=%s: entity access says %s, the tagged query says %s.',
          $label,
          var_export($group_value, TRUE),
          var_export($node_value, TRUE),
          $php ? 'ALLOWED' : 'DENIED',
          $sql ? 'ALLOWED' : 'DENIED',
        ));

        $php ? $allowed_seen++ : $denied_seen++;
      }

      // Out-of-scope bundles are untouched, and a collection-less node stays
      // visible even when it carries a restriction -- matching the hook, which
      // returns neutral before reading the node's own override. Those orphans
      // are handled by migrating them into a review group, not here. See
      // docs/deferred/orphaned-content-temp-group-on-migration.md.
      $this->assertArrayHasKey((int) $page->id(), $visible, "Out-of-scope bundle filtered for $label.");
      $this->assertArrayHasKey((int) $orphan->id(), $visible, "Collection-less node filtered for $label.");

      $switcher->switchBack();
    }

    // Guard against a vacuous pass: if Group's permissions denied everything,
    // or the alter filtered everything, both sides would agree on FALSE for
    // every cell and this test would prove nothing.
    $this->assertGreaterThan(0, $allowed_seen, 'No combination was ever allowed; the fixture is not exercising the rule.');
    $this->assertGreaterThan(0, $denied_seen, 'No combination was ever denied; the fixture is not exercising the rule.');
  }

  /**
   * The same agreement, for collections themselves.
   *
   * views.view.collections is based on groups_field_data, so this is a
   * genuinely separate surface from the node half — node grants would not have
   * covered it however they were implemented. 125 private and 24 UVA-only
   * collection names were listed to anonymous users before this.
   */
  public function testGroupPhpAndSqlAgree(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $cases = [];

    foreach (self::GROUP_VALUES as $group_value) {
      $group = $storage->create([
        'type' => 'collection',
        'label' => 'Collection ' . var_export($group_value, TRUE),
      ]);
      if ($group_value !== 'empty') {
        $group->set('field_group_access', $group_value);
      }
      $group->save();
      $group->addMember($this->accounts['member']);
      $cases[(int) $group->id()] = $group_value;
    }

    $switcher = $this->container->get('account_switcher');
    $allowed_seen = 0;
    $denied_seen = 0;

    foreach ($this->accounts as $label => $account) {
      $switcher->switchTo($account);
      $visible = $this->visibleGids();

      foreach ($cases as $gid => $group_value) {
        $group = $storage->load($gid);
        $php = $group->access('view', $account);
        $sql = isset($visible[$gid]);

        $this->assertSame($php, $sql, sprintf(
          'Disagreement for account "%s", collection field_group_access=%s: entity access says %s, the tagged query says %s.',
          $label,
          var_export($group_value, TRUE),
          $php ? 'ALLOWED' : 'DENIED',
          $sql ? 'ALLOWED' : 'DENIED',
        ));

        $php ? $allowed_seen++ : $denied_seen++;
      }

      $switcher->switchBack();
    }

    $this->assertGreaterThan(0, $allowed_seen, 'No collection was ever allowed; the fixture is not exercising the rule.');
    $this->assertGreaterThan(0, $denied_seen, 'No collection was ever denied; the fixture is not exercising the rule.');
  }

}
