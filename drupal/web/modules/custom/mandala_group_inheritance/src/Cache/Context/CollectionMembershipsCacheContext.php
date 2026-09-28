<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Cache context varying by which collections the account belongs to.
 *
 * Node grants would have given us 'user.node_grants:view' for free. Filtering
 * at query time does not, and picking the wrong context here is the one way
 * this approach can leak content between users, so it is worth being explicit
 * about why the obvious candidates are wrong:
 *
 * - 'user.group_permissions' varies by Group's *calculated permissions*, not by
 *   membership identity. Two accounts with identical roles but different
 *   collection memberships hash the same and would serve each other's
 *   listings. That is a real cross-user leak, not a theoretical one.
 * - 'user' is correct but fragments the render cache per account, which for
 *   anonymous traffic across 111k images is the difference between one cached
 *   gallery page and one per visitor.
 *
 * This context hashes the account's sorted collection ids instead, so every
 * account that is a member of nothing — anonymous, and the large majority of
 * authenticated users — collapses into a single bucket, while members vary
 * correctly. The same idea as Group's own GroupPermissionsHashGenerator.
 */
final class CollectionMembershipsCacheContext implements CacheContextInterface {

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Mandala collection memberships');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    if (!$this->currentUser->isAuthenticated()) {
      return 'none';
    }

    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $this->currentUser->id())
      ->execute();

    if (!$ids) {
      return 'none';
    }

    $gids = [];
    foreach ($storage->loadMultiple($ids) as $relationship) {
      $gids[] = (int) $relationship->getGroupId();
    }
    $gids = array_unique($gids);
    sort($gids);

    // Hashed rather than listed: an account with 26 memberships would otherwise
    // produce a cache key long enough to matter.
    return hash('sha256', implode(',', $gids));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    // Any membership change has to invalidate what was cached under this
    // context, including the cascade into subcollections.
    return (new CacheableMetadata())->addCacheTags(['group_relationship_list']);
  }

}
