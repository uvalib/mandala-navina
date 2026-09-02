<?php

declare(strict_types=1);

namespace Drupal\shanti_grid_view\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the masonry-grid click-to-open info panel.
 *
 * Ports D7's shanti_grid_view_item_info() (shanti/grid/info/%/%) for the
 * node case only -- the module's data-source variant (shanti/grid/dinfo,
 * arbitrary non-entity views) isn't ported; see
 * docs/planning/b3-masonry-gallery-production-reference.md for why.
 *
 * D7's callback rendered node_view($node, 'grid_details') and hand-rolled
 * its own cache_get()/cache_set() around it. Here the 'grid_details' view
 * mode is the same idea, but caching is left entirely to core's normal
 * render-cache machinery (the view builder's own #cache tags/contexts) --
 * no bespoke cache layer to keep in sync with node updates.
 *
 * Access: unlike D7 (access arguments => array('access content'), no
 * per-node check at all), this route requires _entity_access: 'node.view'
 * (see shanti_grid_view.routing.yml) -- the real per-entity check, same
 * convention as mandala_node_api.node_json. See the production-reference
 * doc's "real access-control gap" finding.
 */
class GridInfoController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $gridEntityTypeManager,
    protected RendererInterface $renderer,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * Renders {$node} in the 'grid_details' view mode as a plain HTML fragment.
   */
  public function info(NodeInterface $node): Response {
    $build = $this->gridEntityTypeManager
      ->getViewBuilder('node')
      ->view($node, 'grid_details');

    $html = (string) $this->renderer->renderInIsolation($build);

    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    return $response;
  }

}
