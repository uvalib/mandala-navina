<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\node\Entity\Node;

/**
 * Covers the one way query-time visibility filtering can leak across users.
 *
 * Node grants would have supplied 'user.node_grants:view' automatically.
 * Filtering in the query does not, so the listings this module filters are
 * cached under whatever context we nominate — and nominating the wrong one
 * serves one user's results to another.
 *
 * The trap is specific and easy to fall into: 'user.group_permissions' looks
 * like the obvious choice and is wrong, because it varies by Group's
 * *calculated permissions* rather than by membership identity. Two accounts
 * with identical roles but different collection memberships produce the same
 * permissions hash and would collide. testMembersWithIdenticalRolesDiverge()
 * demonstrates that those two accounts genuinely see different content, which
 * is what makes the collision a real leak rather than a theoretical one.
 *
 * @group mandala_group_inheritance
 */
#[RunTestsInSeparateProcesses]
class CollectionMembershipsCacheContextTest extends CollectionVisibilityKernelTestBase {

  /**
   * The cache context service under test.
   */
  protected function context(): string {
    return $this->container
      ->get('cache_context.user.mandala_collection_memberships')
      ->getContext();
  }

  /**
   * Two accounts with identical roles but different memberships must differ.
   *
   * This is the assertion that would fail under 'user.group_permissions'.
   */
  public function testContextVariesByMembership(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $switcher = $this->container->get('account_switcher');

    $first = $this->createPrivateCollection('First');
    $second = $this->createPrivateCollection('Second');

    $user_a = $this->createUser();
    $user_b = $this->createUser();
    $this->assertSame($user_a->getRoles(), $user_b->getRoles(), 'Fixture precondition: both accounts must hold identical roles.');

    $first->addMember($user_a);
    $second->addMember($user_b);

    $switcher->switchTo($user_a);
    $context_a = $this->context();
    $switcher->switchBack();

    $switcher->switchTo($user_b);
    $context_b = $this->context();
    $switcher->switchBack();

    $this->assertNotSame(
      $context_a,
      $context_b,
      'Two accounts with identical roles but different collection memberships hashed the same, so cached listings would be served across them.',
    );
  }

  /**
   * Accounts that are members of nothing collapse into a single bucket.
   *
   * The reason for hashing memberships rather than using the 'user' context:
   * anonymous traffic and the large majority of authenticated users belong to
   * no collection, and they must share one cache entry rather than one each.
   */
  public function testNonMembersShareOneBucket(): void {
    $switcher = $this->container->get('account_switcher');

    $switcher->switchTo($this->accounts['anonymous']);
    $anonymous = $this->context();
    $switcher->switchBack();

    $switcher->switchTo($this->createUser());
    $outsider_one = $this->context();
    $switcher->switchBack();

    $switcher->switchTo($this->createUser());
    $outsider_two = $this->context();
    $switcher->switchBack();

    $this->assertSame($anonymous, $outsider_one);
    $this->assertSame($outsider_one, $outsider_two);
  }

  /**
   * The same memberships in a different order hash identically.
   *
   * Otherwise the cache would fragment on an accident of insertion order.
   */
  public function testContextIsOrderIndependent(): void {
    $switcher = $this->container->get('account_switcher');

    $first = $this->createPrivateCollection('First');
    $second = $this->createPrivateCollection('Second');

    $forwards = $this->createUser();
    $first->addMember($forwards);
    $second->addMember($forwards);

    $backwards = $this->createUser();
    $second->addMember($backwards);
    $first->addMember($backwards);

    $switcher->switchTo($forwards);
    $context_forwards = $this->context();
    $switcher->switchBack();

    $switcher->switchTo($backwards);
    $context_backwards = $this->context();
    $switcher->switchBack();

    $this->assertSame($context_forwards, $context_backwards);
  }

  /**
   * Two accounts with identical roles really do see different content.
   *
   * Without this, the context assertions above would be defending against a
   * collision that costs nothing. This proves the underlying results genuinely
   * vary by membership and not by permissions, which is what makes a
   * permissions-keyed context unsafe.
   */
  public function testMembersWithIdenticalRolesDiverge(): void {
    $switcher = $this->container->get('account_switcher');

    $first = $this->createPrivateCollection('First');
    $second = $this->createPrivateCollection('Second');

    $in_first = $this->createNode($first, 'only visible to first');
    $in_second = $this->createNode($second, 'only visible to second');

    $user_a = $this->createUser();
    $user_b = $this->createUser();
    $first->addMember($user_a);
    $second->addMember($user_b);

    $switcher->switchTo($user_a);
    $visible_a = $this->visibleNids();
    $switcher->switchBack();

    $switcher->switchTo($user_b);
    $visible_b = $this->visibleNids();
    $switcher->switchBack();

    $this->assertArrayHasKey((int) $in_first->id(), $visible_a);
    $this->assertArrayNotHasKey((int) $in_second->id(), $visible_a);

    $this->assertArrayHasKey((int) $in_second->id(), $visible_b);
    $this->assertArrayNotHasKey((int) $in_first->id(), $visible_b);
  }

  /**
   * A private collection with a single member.
   */
  protected function createPrivateCollection(string $label) {
    $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => 'collection',
      'label' => $label,
      'field_group_access' => 1,
    ]);
    $group->save();

    return $group;
  }

  /**
   * A published node inside the given collection.
   */
  protected function createNode($group, string $title) {
    $node = Node::create(['type' => 'audio', 'title' => $title, 'status' => 1]);
    $node->save();
    $group->addRelationship($node, 'group_node:audio');

    return $node;
  }

}
