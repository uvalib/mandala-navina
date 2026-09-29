<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\mandala_kaltura\KalturaSessionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Mints a Kaltura upload session for AV12's browser-direct upload widget.
 *
 * Deliberately returns a plain (non-cacheable) JsonResponse, not
 * CacheableJsonResponse: a minted KS is a live credential, not cacheable
 * data, and Drupal's dynamic page cache only stores responses implementing
 * CacheableResponseInterface -- staying off that interface is what keeps
 * this route out of the cache, not cache-context tuning. `Cache-Control:
 * no-store` is set explicitly too, for any intermediate proxy.
 *
 * Access is gated on the route's own `_permission` requirement (see
 * mandala_kaltura.routing.yml) rather than a per-node `_entity_access`
 * check: AV12's widget also appears on the node *add* form, before a node
 * exists to check access against. This means a user who can create
 * audio/video content can mint an upload session even while editing a node
 * they otherwise couldn't edit -- a known simplification, not audited
 * against the full editorial access model (see
 * docs/deferred/... contributor-tier note) before this is used in
 * production.
 */
class UploadSessionController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    protected readonly KalturaSessionService $session,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mandala_kaltura.session'),
    );
  }

  /**
   * Mints a fresh upload-scoped Kaltura Session, or a clean 503 if unavailable.
   */
  public function mint(): JsonResponse {
    $session = $this->session->mintUploadSession();

    if ($session === NULL) {
      $response = new JsonResponse(['error' => 'Kaltura upload is not currently available.'], 503);
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }

    $response = new JsonResponse($session);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

}
