<?php

declare(strict_types=1);

namespace Drupal\shanti_images_carousel\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Finds the ordered ±N sibling window around a shanti_image node.
 *
 * For the single-image page's AJAX carousel.
 *
 * Ports D7's shanti_images_get_node_carousel() +
 * _shanti_images_get_coll_node_ids() (shanti_images.module /
 * shanti_images.inc): a node's siblings are every shanti_image in its
 * owning top-level collection *and every subcollection of that collection*,
 * flattened into one list, sorted by created DESC then title ASC (D7 has no
 * explicit order/weight field anywhere -- this sort *is* the order), then
 * windowed to ±$windowSize around the current node's position in that list.
 *
 * D11's data model has no direct node->collection field (confirmed --
 * membership is purely group_relationship-based, see
 * drupal/scripts/setup/images_content_model.php's migration note) and no
 * existing "ordered members of a group" query anywhere in the codebase
 * (NodeJsonController::buildCollection() only does the reverse lookup, node
 * -> owning group) -- this is genuinely new code, not a port of an existing
 * D11 service.
 */
class SiblingCarouselService {

  const CACHE_PREFIX = 'shanti_images_carousel:members:';

  /**
   * Cache TTL, in seconds.
   *
   * Matches the KMaps popover service's TTL convention (this module has no
   * bespoke invalidation hooks yet either -- see KmapsPopoverInfoService).
   */
  const CACHE_TTL = 21600;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Returns the ±$windowSize sibling nids around $node.
   *
   * Each row is marked via its 'active' key. Returns an empty array if the
   * node isn't in a collection at all -- matches D7's "hide the carousel
   * entirely" (nodata) case.
   *
   * @return array{nid: int, active: bool}[]
   *   The sibling window, ordered, current node included and flagged.
   */
  public function getSiblingWindow(NodeInterface $node, int $windowSize = 15): array {
    $topCollection = $this->findTopCollection($node);
    if (!$topCollection) {
      return [];
    }

    $nids = $this->getOrderedCollectionMemberNids($topCollection);
    if (!$nids) {
      return [];
    }

    $index = array_search((int) $node->id(), $nids, TRUE);
    if ($index === FALSE) {
      // Node's group membership changed since the list was cached, or the
      // node genuinely isn't a member (edge case) -- fall back to centering
      // the window on the list's midpoint, matching D7's own fallback.
      $index = intdiv(count($nids), 2);
    }

    $start = max(0, $index - $windowSize);
    $slice = array_slice($nids, $start, $windowSize * 2 + 1);

    return array_map(
      static fn (int $nid) => ['nid' => $nid, 'active' => $nid === (int) $node->id()],
      $slice,
    );
  }

  /**
   * The collection/subcollection group $node is directly a member of.
   *
   * Same lookup shape as NodeJsonController::buildCollection() and
   * CollectionFieldContributor::getOwningGroup() (duplicated here rather
   * than shared, matching this codebase's existing convention -- see those
   * classes' own docblocks). Used by the single-image page's "Mandala
   * Collections" link.
   */
  public function getImmediateCollection(NodeInterface $node): ?GroupInterface {
    $relStorage = $this->entityTypeManager->getStorage('group_relationship');
    foreach ($relStorage->loadByEntity($node, 'group_node:shanti_image') as $relationship) {
      $group = $relationship->getGroup();
      if (in_array($group->bundle(), ['collection', 'subcollection'], TRUE)) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * The node's owning top-level collection.
   *
   * The collection group it's directly a member of, or -- if it's a member
   * of a subcollection -- that subcollection's parent collection. This is
   * the pool a D11 sibling window is drawn from (see class docblock).
   */
  protected function findTopCollection(NodeInterface $node): ?GroupInterface {
    $group = $this->getImmediateCollection($node);
    if (!$group) {
      return NULL;
    }
    if ($group->bundle() === 'collection') {
      return $group;
    }
    if ($group->hasField('field_parent_collection') && !$group->get('field_parent_collection')->isEmpty()) {
      $parent = $group->get('field_parent_collection')->entity;
      if ($parent instanceof GroupInterface) {
        return $parent;
      }
    }
    return NULL;
  }

  /**
   * Every shanti_image nid in $collection and its subcollections.
   *
   * Sorted by created DESC / title ASC -- ports
   * _shanti_images_get_coll_node_ids()'s sort exactly
   * (shanti_images.inc:239-243).
   *
   * @return int[]
   *   Ordered node ids.
   */
  protected function getOrderedCollectionMemberNids(GroupInterface $collection): array {
    $cid = self::CACHE_PREFIX . $collection->id();
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    $subcollections = $groupStorage->loadByProperties([
      'type' => 'subcollection',
      'field_parent_collection' => $collection->id(),
    ]);

    $relStorage = $this->entityTypeManager->getStorage('group_relationship');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = [];
    foreach ([$collection, ...array_values($subcollections)] as $group) {
      foreach ($relStorage->loadByGroup($group, 'group_node:shanti_image') as $relationship) {
        $nids[(int) $relationship->getEntity()->id()] = TRUE;
      }
    }
    $nids = array_keys($nids);

    if ($nids) {
      $nodes = $nodeStorage->loadMultiple($nids);
      usort($nids, static function (int $a, int $b) use ($nodes) {
        $created = $nodes[$b]->getCreatedTime() <=> $nodes[$a]->getCreatedTime();
        return $created !== 0 ? $created : strcmp((string) $nodes[$a]->label(), (string) $nodes[$b]->label());
      });
    }

    $subcollectionTags = array_map(static fn ($g) => $g->getCacheTags(), array_values($subcollections));
    $tags = array_merge(...[
      $collection->getCacheTags(),
      ...$subcollectionTags,
      ['node_list:shanti_image'],
    ]);
    $this->cache->set($cid, $nids, time() + self::CACHE_TTL, $tags);

    return $nids;
  }

}
