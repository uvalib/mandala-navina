<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;

/**
 * Access to a transcript is access to its AV node (Spike 11, AV7 rules).
 *
 * Nothing here knows about collections, visibility or Group: this asks the
 * node, and the node's own access runs everything already wired for it
 * (published status, the AV7 collection-visibility hook, bypass roles). That
 * is the point: a second copy of the visibility rule is how it has drifted
 * four times before (see CollectionVisibilityOracleTest).
 *
 * - view: the node is viewable.
 * - every other operation (update, delete, view/revert/delete revision, and any
 *   operation Drupal adds later): the node is editable. Mapping unknown
 *   operations to "update" fails closed rather than open.
 * - A transcript whose node is gone is denied to everyone except the
 *   administer permission.
 */
class AvTranscriptAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin = AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
    if ($admin->isAllowed()) {
      return $admin;
    }
    assert($entity instanceof AvTranscriptInterface);
    $node = $entity->getNode();
    if ($node === NULL) {
      // Fail closed. The transcript is a dependency so that restoring the node
      // reference invalidates the cached denial.
      return AccessResult::forbidden('Transcript has no AV node.')
        ->addCacheableDependency($entity)
        ->addCacheContexts(['user.permissions']);
    }
    $node_operation = $operation === 'view' ? 'view' : 'update';
    return $node->access($node_operation, $account, TRUE)
      ->addCacheableDependency($entity)
      ->addCacheableDependency($node);
  }

  /**
   * {@inheritdoc}
   *
   * Creating a transcript has no node to ask yet, so it is administrators and
   * code that skips the check (the migration, the upload workflow). The editor
   * create path arrives with T6.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
  }

}
