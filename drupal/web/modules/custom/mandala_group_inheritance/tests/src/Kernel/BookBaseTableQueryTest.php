<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\node\Entity\Node;

/**
 * Book's tree queries are filtered, not broken, by the node query alter.
 *
 * Book tags its outline queries node_access with base_table 'book', a table
 * that has nid but no type column. NodeQueryAlter used to read "b.type" off it
 * and every non-bypass viewer got an SQL error (Spike 13 S1 finding 3). The
 * fix reads the bundle from node_field_data instead; this test pins that the
 * query now runs AND still hides a private collection's pages, since skipping
 * the filter would also have made the error go away.
 *
 * Uses a minimal table named 'book' rather than the contrib Book module: the
 * alter only cares about the declared base table's name and its nid column.
 *
 * @group mandala_group_inheritance
 */
#[RunTestsInSeparateProcesses]
class BookBaseTableQueryTest extends CollectionVisibilityKernelTestBase {

  /**
   * A non-member sees the public page and not the private one; a member both.
   */
  public function testBookTreeQueryFiltersByCollection(): void {
    $this->container->get('database')->schema()->createTable('book', [
      'fields' => [
        'nid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'bid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['nid'],
    ]);

    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $nids = [];
    // field_group_access: 0 = public, 1 = private (CollectionVisibility::forGroup).
    foreach (['public' => 0, 'private' => 1] as $label => $value) {
      $group = $storage->create(['type' => 'collection', 'label' => $label, 'field_group_access' => $value]);
      $group->save();
      $group->addMember($this->accounts['member']);

      $node = Node::create(['type' => 'audio', 'title' => $label, 'status' => 1]);
      $node->save();
      $group->addRelationship($node, 'group_node:audio');
      $this->container->get('database')->insert('book')
        ->fields(['nid' => $node->id(), 'bid' => $node->id()])
        ->execute();
      $nids[$label] = (int) $node->id();
    }

    $expected = [
      'anonymous' => ['public'],
      'outsider' => ['public'],
      'member' => ['public', 'private'],
    ];
    $switcher = $this->container->get('account_switcher');
    foreach ($expected as $account => $labels) {
      $switcher->switchTo($this->accounts[$account]);
      $visible = $this->bookTreeNids();
      $switcher->switchBack();

      $want = array_map(fn($l) => $nids[$l], $labels);
      sort($want);
      $this->assertSame($want, $visible, "Book tree nids for $account.");
    }
  }

  /**
   * Nids from a query shaped like Book's own outline queries.
   */
  private function bookTreeNids(): array {
    $query = $this->container->get('database')->select('book', 'b');
    $query->addTag('node_access');
    $query->addMetaData('base_table', 'book');
    $query->addField('b', 'nid');
    $nids = array_map('intval', $query->execute()->fetchCol());
    sort($nids);
    return $nids;
  }

}
