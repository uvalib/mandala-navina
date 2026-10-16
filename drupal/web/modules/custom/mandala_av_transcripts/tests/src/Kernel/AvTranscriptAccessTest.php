<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\node\Entity\Node;
use Drupal\Tests\mandala_group_inheritance\Kernel\CollectionVisibilityKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Transcript and unit access follows the parent node, under the real AV7 rules.
 *
 * Runs on the collection-visibility fixture (real Group, real
 * mandala_group_inheritance hook), not a stub, so a pass means the AV7
 * enforcement actually reaches transcripts. Security-critical: a failure here
 * is private transcript text served to the public.
 *
 * Group field_group_access: 0 public, 1 private, 2 UVA. Node
 * field_group_content_access override: 2 private, 3 UVA, 0 use the collection.
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class AvTranscriptAccessTest extends CollectionVisibilityKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'group', 'gnode', 'options',
    'entity', 'flexible_permissions', 'mandala_group_inheritance',
    'file', 'mandala_av_transcripts',
  ];

  private int $next = 1;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    // The shared fixture's first createUser() is uid 1, which Drupal treats as
    // a superuser that passes every access check. Swap in ordinary accounts so
    // "outsider" really is one.
    $this->assertSame('1', (string) $this->accounts['outsider']->id(), 'fixture assumption: outsider is uid 1');
    $this->accounts['outsider'] = $this->createUser();
    $this->assertNotSame('1', (string) $this->accounts['outsider']->id());
  }

  /**
   * A published audio node in a collection, with a transcript and one unit.
   *
   * @return array{0: \Drupal\mandala_av_transcripts\Entity\AvTranscript, 1: \Drupal\mandala_av_transcripts\Entity\AvTcu, 2: \Drupal\node\Entity\Node}
   */
  private function fixture(int|string $group_access, int $node_override = 0, bool $published = TRUE, bool $in_collection = TRUE): array {
    $node = Node::create([
      'type' => 'audio',
      'title' => 'n' . $this->next,
      'status' => $published ? 1 : 0,
      'field_group_content_access' => $node_override,
    ]);
    $node->save();
    if ($in_collection) {
      $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
        'type' => 'collection',
        'label' => 'c' . $this->next,
        'field_group_access' => $group_access,
      ]);
      $group->save();
      $group->addMember($this->accounts['member']);
      $group->addRelationship($node, 'group_node:audio');
    }
    $transcript = AvTranscript::create(['id' => $this->next, 'node' => $node->id()]);
    $transcript->save();
    $unit = AvTcu::create(['id' => $this->next, 'transcript' => $transcript->id(), 'sequence' => 0, 'start' => '1.000']);
    $unit->save();
    $this->next++;
    return [$transcript, $unit, $node];
  }

  /**
   * Asserts the same answer for the node, its transcript and its unit.
   */
  private function assertView(string $who, bool $expected, array $f, string $why): void {
    [$transcript, $unit, $node] = $f;
    $account = $this->accounts[$who];
    $this->assertSame($expected, $node->access('view', $account), "Node view for $who: $why");
    $this->assertSame($expected, $transcript->access('view', $account), "Transcript view for $who: $why");
    $this->assertSame($expected, $unit->access('view', $account), "Unit view for $who: $why");
  }

  /**
   * Public collection: everyone may view.
   */
  public function testPublicCollection(): void {
    $f = $this->fixture(0);
    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $who) {
      $this->assertView($who, TRUE, $f, 'public');
    }
  }

  /**
   * Private collection: members and bypass only. The case that must not leak.
   */
  public function testPrivateCollection(): void {
    $f = $this->fixture(1);
    $this->assertView('anonymous', FALSE, $f, 'private');
    $this->assertView('outsider', FALSE, $f, 'private');
    $this->assertView('member', TRUE, $f, 'private');
    $this->assertView('bypass', TRUE, $f, 'private');
  }

  /**
   * A user who is not in the node's collection is denied, even when they are in another one.
   */
  public function testMemberOfAnotherCollectionIsDenied(): void {
    $this->fixture(1);
    $other = $this->fixture(1);
    // 'member' belongs to both (fixture adds them); remove from the second.
    $group = $this->container->get('entity_type.manager')->getStorage('group')->loadMultiple();
    $last = end($group);
    $last->removeMember($this->accounts['member']);
    $this->assertView('member', FALSE, $other, 'member of a different collection only');
  }

  /**
   * UVA-only: any logged-in account, never anonymous.
   */
  public function testUvaCollection(): void {
    $f = $this->fixture(2);
    $this->assertView('anonymous', FALSE, $f, 'uva');
    $this->assertView('outsider', TRUE, $f, 'uva');
  }

  /**
   * The node's own override beats a public collection.
   */
  public function testNodeOverrideBeatsPublicCollection(): void {
    $this->assertView('anonymous', FALSE, $this->fixture(0, 2), 'node private in public collection');
    $this->assertView('anonymous', FALSE, $this->fixture(0, 3), 'node uva in public collection');
    $this->assertView('outsider', TRUE, $this->fixture(0, 3), 'node uva, logged in');
  }

  /**
   * An unpublished node's transcript is not viewable by those who cannot see the node.
   */
  public function testUnpublishedNode(): void {
    $f = $this->fixture(0, 0, FALSE);
    $this->assertView('anonymous', FALSE, $f, 'unpublished');
    $this->assertView('outsider', FALSE, $f, 'unpublished');
  }

  /**
   * A node in no collection behaves as the node does (the AV7 hook's own rule).
   */
  public function testNodeWithoutCollection(): void {
    $f = $this->fixture(0, 0, TRUE, FALSE);
    $this->assertView('anonymous', TRUE, $f, 'collection-less published');
  }

  /**
   * Editing and every revision operation need node edit access, not view.
   */
  public function testWriteOperationsNeedNodeEditAccess(): void {
    // Collection-less so only node permissions are in play; per-collection
    // update grants are Group's business and not what is under test.
    [$transcript, $unit] = $this->fixture(0, 0, TRUE, FALSE);
    $viewer = $this->accounts['outsider'];
    $editor = $this->createUser(['edit any audio content', 'delete any audio content']);
    foreach (['update', 'delete', 'view all revisions', 'view revision', 'revert revision', 'delete revision', 'something unknown'] as $op) {
      $this->assertFalse($transcript->access($op, $viewer), "Viewer must not $op transcript");
      $this->assertFalse($unit->access($op, $viewer), "Viewer must not $op unit");
    }
    foreach (['update', 'delete', 'view all revisions', 'revert revision'] as $op) {
      $this->assertTrue($transcript->access($op, $editor), "Editor may $op transcript");
      $this->assertTrue($unit->access($op, $editor), "Editor may $op unit");
    }
    // An editor still cannot reach a private collection's transcript to view it.
    $private = $this->fixture(1);
    $this->assertFalse($private[0]->access('view', $editor), 'Editor not in the collection cannot view');
    $this->assertFalse($private[1]->access('view', $editor));
  }

  /**
   * A transcript with no node is denied to everyone but administrators.
   */
  public function testOrphanedTranscriptFailsClosed(): void {
    [$transcript, $unit, $node] = $this->fixture(0);
    $node->delete();
    $transcript = AvTranscript::load($transcript->id());
    $unit = AvTcu::load($unit->id());
    $admin = $this->createUser(['administer av transcripts']);
    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $who) {
      $this->assertFalse($transcript->access('view', $this->accounts[$who]), "$who must not view an orphaned transcript");
      $this->assertFalse($unit->access('view', $this->accounts[$who]), "$who must not view an orphaned unit");
    }
    $this->assertTrue($transcript->access('view', $admin));
    $this->assertTrue($unit->access('view', $admin));
  }

  /**
   * A unit whose transcript is missing is denied.
   */
  public function testOrphanedUnitFailsClosed(): void {
    [$transcript, $unit] = $this->fixture(0);
    $transcript->delete();
    $unit = AvTcu::load($unit->id());
    $this->assertFalse($unit->access('view', $this->accounts['outsider']));
    $this->assertFalse($unit->access('view', $this->accounts['anonymous']));
  }

  /**
   * Nobody gets create access from the node: only the administer permission.
   */
  public function testCreateAccess(): void {
    $handler = $this->container->get('entity_type.manager');
    $admin = $this->createUser(['administer av transcripts']);
    foreach (['av_transcript', 'av_tcu'] as $type) {
      $this->assertFalse($handler->getAccessControlHandler($type)->createAccess(NULL, $this->accounts['bypass']));
      $this->assertTrue($handler->getAccessControlHandler($type)->createAccess(NULL, $admin));
    }
  }

  /**
   * Cached decisions depend on the node, so a visibility change invalidates them.
   */
  public function testDecisionCarriesNodeAndTranscriptCacheTags(): void {
    [$transcript, , $node] = $this->fixture(1);
    $result = $transcript->access('view', $this->accounts['outsider'], TRUE);
    $this->assertTrue($result->isForbidden());
    $tags = $result->getCacheTags();
    $this->assertContains('node:' . $node->id(), $tags);
    $this->assertContains('av_transcript:' . $transcript->id(), $tags);
  }

  /**
   * A decision follows the node when its visibility changes.
   */
  public function testChangingTheNodeChangesTheAnswer(): void {
    [$transcript, , $node] = $f = $this->fixture(0);
    $this->assertTrue($transcript->access('view', $this->accounts['anonymous']));
    $node->set('field_group_content_access', 2)->save();
    // Access handlers memoise per entity; a long-running request would not see
    // the edit, a fresh request does. Reset all three to model the fresh one.
    foreach (['node', 'av_transcript'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
    $this->assertFalse(AvTranscript::load($transcript->id())->access('view', $this->accounts['anonymous']));
  }

}
