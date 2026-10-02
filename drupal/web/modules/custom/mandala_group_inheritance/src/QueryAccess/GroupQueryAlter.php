<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\QueryAccess;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_group_inheritance\Access\CollectionVisibility;
use Psr\Log\LoggerInterface;

/**
 * Enforces collection visibility on access-checked group queries.
 *
 * The collections half of the same gap NodeQueryAlter closes for content.
 * views.view.collections is based on groups_field_data rather than
 * node_field_data, so node grants would not have touched it however they were
 * implemented — it listed 125 private and 24 UVA-only collection names and
 * descriptions to anonymous users.
 *
 * Simpler than the node side in three ways: a collection carries no override
 * field, so visibility comes straight from its own field_group_access; there is
 * no relationship to join, since the row IS the group; and membership is tested
 * against the row's own id.
 *
 * Deliberately NOT covered: views based on group_relationship_field_data, which
 * carry the group_relationship_access tag instead. views.view.my_collections is
 * the case that matters, and it lists the viewer's own memberships, so there is
 * nothing there to hide from them.
 */
final class GroupQueryAlter {

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RendererInterface $renderer,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Applies the visibility veto to a group_access-tagged query.
   */
  public function alter(SelectInterface $query): void {
    if (($query->getMetaData('op') ?: 'view') !== 'view') {
      return;
    }

    $account = $query->getMetaData('account') ?: $this->currentUser;

    $base_table = $this->baseTable($query);
    if ($base_table === NULL) {
      $this->logger->warning('Query tagged group_access has no group base table; visibility not enforced.');
      return;
    }

    // An orphaned-content review holding group (created and flagged by hand
    // through the Group UI, not by this module) is never a browsable
    // collection, for anyone — including an account with bypass, which is
    // why this is a separate, unconditional join rather than folded into the
    // visibility expression below (that one IS skipped for bypass). See
    // docs/deferred/orphaned-content-temp-group-on-migration.md.
    $review_holding = $query->leftJoin(
      'group__field_is_review_holding',
      'mgi_review_holding',
      "%alias.entity_id = $base_table.id AND %alias.deleted = 0",
    );
    $query->condition(
      $query->orConditionGroup()
        ->isNull("$review_holding.field_is_review_holding_value")
        ->condition("$review_holding.field_is_review_holding_value", 0)
    );

    // Note this uses the GROUP bypass set, which omits 'bypass node access'.
    // That asymmetry is deliberate — see CollectionVisibility::hasNodeBypass().
    if (CollectionVisibility::hasGroupBypass($account)) {
      $this->applyCacheability(
        (new CacheableMetadata())
          ->addCacheContexts(['user.permissions'])
          ->addCacheTags(['group_list'])
      );
      return;
    }

    $gfa = $query->leftJoin(
      'group__field_group_access',
      'mgi_gfa',
      "%alias.entity_id = $base_table.id AND %alias.deleted = 0",
    );

    $visibility = CollectionVisibility::groupVisibilityExpression($gfa);

    $allowed = $query->orConditionGroup()
      // Group bundles outside the collection model are none of our business.
      ->condition("$base_table.type", CollectionVisibility::GROUP_BUNDLES, 'NOT IN')
      ->where("$visibility = :mgi_public", [':mgi_public' => CollectionVisibility::CODE_PUBLIC]);

    if ($account->isAuthenticated()) {
      $allowed->where("$visibility = :mgi_uva", [':mgi_uva' => CollectionVisibility::CODE_UVA]);
    }

    $member_gids = $this->memberGids($account);
    if ($member_gids) {
      // Membership is against the row's own id here, not a related gid.
      $allowed->condition(
        $query->andConditionGroup()
          ->where("$visibility = :mgi_private", [':mgi_private' => CollectionVisibility::CODE_PRIVATE])
          ->condition("$base_table.id", $member_gids, 'IN')
      );
    }

    $query->condition($allowed);

    $this->applyCacheability(
      (new CacheableMetadata())
        ->addCacheContexts([
          'user.permissions',
          'user.roles:authenticated',
          'user.mandala_collection_memberships',
        ])
        ->addCacheTags(['group_list', 'group_relationship_list'])
    );
  }

  /**
   * The alias of the group base table, or NULL if the query has none.
   */
  protected function baseTable(SelectInterface $query): ?string {
    $tables = $query->getTables();

    $declared = $query->getMetaData('base_table');
    if ($declared && isset($tables[$declared])) {
      return $declared;
    }

    foreach ($tables as $alias => $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      if ($info['table'] === 'groups' || $info['table'] === 'groups_field_data') {
        return $alias;
      }
    }

    return NULL;
  }

  /**
   * The collection/subcollection ids this account is a member of.
   */
  protected function memberGids(AccountInterface $account): array {
    if (!$account->isAuthenticated()) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('group_relationship');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('plugin_id', 'group_membership')
      ->condition('entity_id', $account->id())
      ->execute();

    if (!$ids) {
      return [];
    }

    $gids = [];
    foreach ($storage->loadMultiple($ids) as $relationship) {
      $gids[] = (int) $relationship->getGroupId();
    }

    return array_values(array_unique($gids));
  }

  /**
   * Bubbles cacheability out of the query and into the render context.
   */
  protected function applyCacheability(CacheableMetadata $metadata): void {
    if (!$this->renderer->hasRenderContext()) {
      return;
    }
    $build = [];
    $metadata->applyTo($build);
    $this->renderer->render($build);
  }

}
