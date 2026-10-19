<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_av_transcripts\Entity\AvTcuInterface;

/**
 * A unit has no access of its own: it is exactly its transcript's.
 *
 * Units are never exposed on their own (Spike 11), so the same operation is
 * asked of the transcript, which in turn asks the node. A unit whose
 * transcript is gone is denied to everyone except the administer permission.
 */
class AvTcuAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $admin = AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
    if ($admin->isAllowed()) {
      return $admin;
    }
    assert($entity instanceof AvTcuInterface);
    $transcript = $entity->getTranscript();
    if ($transcript === NULL) {
      return AccessResult::forbidden('Unit has no transcript.')
        ->addCacheableDependency($entity)
        ->addCacheContexts(['user.permissions']);
    }
    return $transcript->access($operation, $account, TRUE)
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, $this->entityType->getAdminPermission());
  }

}
