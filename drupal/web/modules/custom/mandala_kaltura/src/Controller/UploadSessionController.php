<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_kaltura\KalturaSessionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
 * Access has two real gaps a code review caught after the initial build,
 * both addressed here:
 *
 * - The minted KS is partner-account-wide (SESSION_TYPE_USER, no
 *   entry-level privilege scoping -- the Kaltura API doesn't support
 *   restricting a session to a specific entry/action set, see
 *   KalturaSessionService's own docblock). Without a node context, ANY
 *   user with 'create video content'/'create audio content' could mint a
 *   session and use it against arbitrary entries in the partner account,
 *   not just the one they were editing. access() below narrows this:
 *   when the caller passes a `node` query parameter (the widget does this
 *   whenever it's rendered on an existing node's edit form), access
 *   requires update access to THAT node, not just the generic create
 *   permission. The add-form case (no node yet) still falls back to the
 *   permission check -- unavoidable, since there is no entity to check
 *   access against before one exists.
 * - There was no rate limit on minting: this endpoint is a GET with no
 *   CSRF token, so repeated calls could be scripted. Flood control below
 *   caps it per-user.
 */
class UploadSessionController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Flood event name and threshold for KS-minting attempts.
   *
   * A real upload mints exactly one KS per file, so this is generous
   * headroom for retries/multiple uploads in a session, not a tight
   * per-chunk limit -- chunks reuse the same already-minted KS.
   */
  protected const FLOOD_EVENT = 'mandala_kaltura.upload_session';
  protected const FLOOD_LIMIT = 30;
  protected const FLOOD_WINDOW_SECONDS = 3600;

  public function __construct(
    protected readonly KalturaSessionService $session,
    protected readonly FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mandala_kaltura.session'),
      $container->get('flood'),
    );
  }

  /**
   * Access callback (see class docblock) -- node-scoped when possible.
   */
  public function access(AccountInterface $account, Request $request): AccessResultInterface {
    $nid = $request->query->get('node');
    if ($nid !== NULL) {
      $node = $this->entityTypeManager()->getStorage('node')->load($nid);
      if ($node === NULL) {
        return AccessResult::forbidden('Referenced node does not exist.');
      }
      return $node->access('update', $account, TRUE);
    }

    // No node context (the node *add* form, before a node exists) --
    // fall back to the generic create permission.
    return AccessResult::allowedIfHasPermissions($account, [
      'create video content',
      'create audio content',
    ], 'OR')->cachePerPermissions();
  }

  /**
   * Mints a fresh upload-scoped Kaltura Session, or a clean error response.
   */
  public function mint(): JsonResponse {
    $uid = (string) $this->currentUser()->id();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW_SECONDS, $uid)) {
      $response = new JsonResponse(['error' => 'Too many upload session requests. Try again later.'], 429);
      $response->headers->set('Cache-Control', 'no-store');
      return $response;
    }
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW_SECONDS, $uid);

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
