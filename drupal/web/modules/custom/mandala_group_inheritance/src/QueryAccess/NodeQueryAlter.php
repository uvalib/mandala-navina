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
 * Enforces collection visibility on access-checked node queries.
 *
 * hook_entity_access() governs individual entity access checks — a node page,
 * $entity->access(), an EntityQuery result after the fact. It does NOT govern
 * the SQL that Views and EntityQuery build, so listings returned every
 * published node to anonymous users regardless of its collection's visibility:
 * 11,582 rows from av_gallery and 111,339 from image_gallery, restricted titles
 * included. This class closes that, applying the same veto as a query
 * condition.
 *
 * Keyed on the `node_access` tag rather than on individual views. Core attaches
 * that tag to any query that declares itself access-checked — Views via
 * NodeViewsData's 'access query tag', EntityQuery via accessCheck(TRUE) — so
 * enforcement reaches views that do not exist yet, and REST/JSON:API built on
 * either. A per-view alter would have to name each view and would fail open on
 * everything unnamed.
 *
 * Runs alongside core's own node_access implementation, which currently returns
 * early because no module implements hook_node_grants(). Both add their own
 * condition group, which AND together at the top level, so ordering is
 * irrelevant and this stays correct if grants are ever introduced.
 *
 * Not covered, unavoidably and identically for a grants implementation: queries
 * that never carry the tag. That means accessCheck(FALSE) callers and views
 * with disable_sql_rewrite. See the module README for the current list.
 */
final class NodeQueryAlter {

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RendererInterface $renderer,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Applies the visibility veto to a node_access-tagged query.
   */
  public function alter(SelectInterface $query): void {
    // Mirror hook_entity_access(), which only restricts 'view'.
    if (($query->getMetaData('op') ?: 'view') !== 'view') {
      return;
    }

    $account = $query->getMetaData('account') ?: $this->currentUser;

    // Bypass short-circuits before the query is touched. Note this decision
    // varies by permission, so it has to be bubbled even though nothing is
    // added to the query.
    if (CollectionVisibility::hasNodeBypass($account)) {
      $this->applyCacheability((new CacheableMetadata())->addCacheContexts(['user.permissions']));
      return;
    }

    $bundles = CollectionVisibility::groupNodeBundles();
    if (!$bundles) {
      // No group_node plugins installed: nothing is in scope.
      return;
    }

    $base_table = $this->baseTable($query);
    if ($base_table === NULL) {
      // Core throws here. We must not: a third-party query tagged node_access
      // without a node table would take the whole site down, and this module
      // is not core. Log and leave the query alone.
      $this->logger->warning('Query tagged node_access has no node base table; visibility not enforced. Add a base_table meta-data key if this query should be filtered.');
      return;
    }

    $grel = $query->leftJoin(
      'group_relationship_field_data',
      'mgi_rel',
      "%alias.entity_id = $base_table.nid AND %alias.plugin_id IN (:mgi_plugins[])",
      [':mgi_plugins[]' => CollectionVisibility::groupNodePluginIds()],
    );
    $gfa = $query->leftJoin(
      'group__field_group_access',
      'mgi_gfa',
      "%alias.entity_id = $grel.gid AND %alias.deleted = 0",
    );
    $nca = $query->leftJoin(
      'node__field_group_content_access',
      'mgi_nca',
      "%alias.entity_id = $base_table.nid AND %alias.deleted = 0",
    );

    $visibility = CollectionVisibility::nodeVisibilityExpression($nca, $gfa);

    $allowed = $query->orConditionGroup()
      // Bundles with no group_node plugin are none of our business. Discovered,
      // not listed, so Texts and Sources are covered the moment their plugins
      // are installed.
      ->condition("$base_table.type", $bundles, 'NOT IN')
      // Not in a collection: nothing to enforce. This deliberately matches the
      // hook, which returns neutral before reading the node's own override --
      // so 18 published video nodes marked private or UVA but belonging to no
      // collection stay visible. Those orphans are being migrated into a
      // temporary review group instead; see
      // docs/deferred/orphaned-content-temp-group-on-migration.md.
      ->isNull("$grel.gid")
      ->where("$visibility = :mgi_public", [':mgi_public' => CollectionVisibility::CODE_PUBLIC]);

    if ($account->isAuthenticated()) {
      // UVA means any authenticated user, copying D7 exactly.
      $allowed->where("$visibility = :mgi_uva", [':mgi_uva' => CollectionVisibility::CODE_UVA]);
    }

    $member_gids = $this->memberGids($account);
    if ($member_gids) {
      // Inlined rather than joined: anonymous and non-members get an empty
      // list, so the hot query never touches the membership table at all.
      // Group's own addIndividualConditions() inlines ids the same way.
      $allowed->condition(
        $query->andConditionGroup()
          ->where("$visibility = :mgi_private", [':mgi_private' => CollectionVisibility::CODE_PRIVATE])
          ->condition("$grel.gid", $member_gids, 'IN')
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
        // A listing cannot declare a dependency on the rows it filtered OUT, so
        // per-entity cache tags are not enough here: if a collection flips
        // public to private, the cached anonymous /images page has to
        // invalidate even though the now-hidden nodes were never in it.
        ->addCacheTags(['group_list', 'group_relationship_list'])
    );
  }

  /**
   * The alias of the node base table, or NULL if the query has none.
   *
   * Follows core's resolution order (see NodeDatabaseHooks): trust the
   * base_table meta-data when present, otherwise prefer node/node_field_data
   * over the revision tables.
   */
  protected function baseTable(SelectInterface $query): ?string {
    $tables = $query->getTables();

    $declared = $query->getMetaData('base_table');
    if ($declared && isset($tables[$declared])) {
      return $declared;
    }

    $fallback = NULL;
    foreach ($tables as $alias => $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      if ($info['table'] === 'node' || $info['table'] === 'node_field_data') {
        return $alias;
      }
      if ($declared && $info['table'] === $declared) {
        $fallback = $alias;
      }
    }

    return $fallback;
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
   *
   * Guarded on hasRenderContext() exactly as Group's QueryAlterBase does, so
   * the same code path is safe under drush and cron where there is no context.
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
