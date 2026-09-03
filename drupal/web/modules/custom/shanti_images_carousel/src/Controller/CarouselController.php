<?php

declare(strict_types=1);

namespace Drupal\shanti_images_carousel\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;
use Drupal\shanti_images_carousel\Service\SiblingCarouselService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the ±15 sibling window for the single-image page's AJAX carousel.
 *
 * Ports D7's GET /api/carouseldata/{nid} (shanti_images_get_node_carousel())
 * -- see SiblingCarouselService's docblock for the ordering/windowing
 * mechanism this reuses.
 *
 * Unlike D7 (access callback => TRUE, blanket public, no per-node check at
 * all), this route requires _entity_access: 'node.view' on the *current*
 * node (see shanti_images_carousel.routing.yml) -- same convention as
 * mandala_node_api.node_json and shanti_grid_view's GridInfoController, so
 * a private-collection image's carousel can't be fetched by a session that
 * can't see the node itself. Response shape returns JSON (nid/url/active/
 * portrait per sibling); the JS behavior builds the DOM, rather than
 * returning a pre-rendered HTML fragment via renderInIsolation() -- avoids
 * that pattern's #attached-assets-dropped gotcha entirely, since there's no
 * per-item render pipeline to lose attachments from.
 */
class CarouselController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    protected SiblingCarouselService $siblingCarousel,
    protected IiifUrlBuilder $iiifUrlBuilder,
    protected EntityTypeManagerInterface $carouselEntityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('shanti_images_carousel.sibling_carousel'),
      $container->get('shanti_iiif.url_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Returns the sibling window as JSON.
   */
  public function data(NodeInterface $node): CacheableJsonResponse {
    $window = $this->siblingCarousel->getSiblingWindow($node);

    $cacheability = (new CacheableMetadata())
      ->addCacheableDependency($node)
      ->addCacheContexts(['user'])
      ->addCacheTags(['node_list:shanti_image']);

    if (!$window) {
      $response = new CacheableJsonResponse(['siblings' => []]);
      $response->addCacheableDependency($cacheability);
      return $response;
    }

    $nodeStorage = $this->carouselEntityTypeManager->getStorage('node');
    $siblingNodes = $nodeStorage->loadMultiple(array_column($window, 'nid'));

    $siblings = [];
    foreach ($window as $row) {
      $sibling = $siblingNodes[$row['nid']] ?? NULL;
      if (!$sibling instanceof NodeInterface || $sibling->get('field_iiif_id')->isEmpty()) {
        continue;
      }
      $cacheability->addCacheableDependency($sibling);

      $iiifId = (string) $sibling->get('field_iiif_id')->value;
      $rotation = (int) ($sibling->get('field_image_rotation')->value ?? 0);
      $portrait = in_array(($rotation / 90) % 2, [1, -1], TRUE);

      $siblings[] = [
        'nid' => (int) $sibling->id(),
        'title' => (string) $sibling->label(),
        'url' => $sibling->toUrl()->toString(),
        'thumbUrl' => $this->iiifUrlBuilder->buildUrl($iiifId, NULL, 90, $rotation, 'full', TRUE, 'jpg', TRUE),
        'active' => $row['active'],
        'portrait' => $portrait,
      ];
    }

    $response = new CacheableJsonResponse(['siblings' => $siblings]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
