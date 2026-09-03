<?php

declare(strict_types=1);

namespace Drupal\shanti_collections_view\Plugin\views\argument;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\shanti_images_carousel\Service\SiblingCarouselService;
use Drupal\views\Attribute\ViewsArgument;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters a node-based view to the members of a collection group.
 *
 * Takes a group id and restricts the query to nodes returned by
 * SiblingCarouselService::getCollectionMemberNids() -- the same "members of
 * this collection + its subcollections" query B2's sibling carousel already
 * built and OOM-hardened (real collections run into the thousands of
 * members). Not tied to any specific Views field/table the way a normal
 * argument is -- the where clause is added directly against the view's own
 * base table's id field, since membership isn't a plain SQL join.
 */
#[ViewsArgument(id: 'collection_membership')]
class CollectionMembership extends ArgumentPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $collectionMembershipEntityTypeManager,
    protected SiblingCarouselService $siblingCarousel,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('shanti_images_carousel.sibling_carousel'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $baseTable = $this->view->storage->get('base_table');
    $baseField = $this->view->storage->get('base_field');

    $collection = $this->collectionMembershipEntityTypeManager->getStorage('group')->load($this->argument);
    $nids = $collection ? $this->siblingCarousel->getCollectionMemberNids($collection) : [];

    if (!$nids) {
      // No members (or an invalid group id) -- force an empty result set
      // rather than an unfiltered one.
      $this->query->addWhere(0, '1 = 0');
      return;
    }

    $this->query->addWhere(0, "$baseTable.$baseField", $nids, 'IN');
  }

}
