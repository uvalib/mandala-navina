<?php

namespace Drupal\mandala_legacy_redirect\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects a legacy D7 bare-nid URL to the migrated D11 entity (ADR 016).
 *
 * D7 nids are unique per legacy site, not globally, so /node/{nid} is
 * ambiguous without the host it was served from. The ALB forwards each legacy
 * hostname to this site with Host untouched, and settings.php deliberately
 * does not trust X-Forwarded-Host, so getHost() is the raw Host header.
 *
 * On a legacy hostname a /node/{nid} that does not resolve is a 404, never a
 * fall-through: falling through would serve whichever unrelated D11 node
 * happens to carry that id.
 */
class LegacyHostRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before RouterListener (32), so the path is not routed as a D11 node.
    return [KernelEvents::REQUEST => ['onRequest', 40]];
  }

  /**
   * Returns the legacy nid for a bare-nid path, or NULL for any other path.
   */
  public static function legacyNid(string $path): ?string {
    return preg_match('#^/node/([1-9][0-9]*)/?$#', $path, $m) ? $m[1] : NULL;
  }

  /**
   * Maps a request host to its field_legacy_site token, or NULL.
   */
  public static function siteForHost(string $host, array $hosts): ?string {
    $host = strtolower($host);
    foreach ($hosts as $row) {
      if (strtolower($row['host']) === $host) {
        return $row['site'];
      }
    }
    return NULL;
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    // Cheap early exit: path shape first, so other requests pay nothing.
    $nid = self::legacyNid($request->getPathInfo());
    if ($nid === NULL) {
      return;
    }
    $config = $this->configFactory->get('mandala_legacy_redirect.settings');
    $site = self::siteForHost($request->getHost(), $config->get('hosts') ?? []);
    if ($site === NULL) {
      return;
    }

    $url = $this->lookup($site, $nid);
    if ($url === NULL) {
      $this->logger->notice('No D11 entity for legacy @site node @nid (host @host).', [
        '@site' => $site, '@nid' => $nid, '@host' => $request->getHost(),
      ]);
      throw new NotFoundHttpException();
    }
    $base = rtrim((string) $config->get('canonical_base_url'), '/');
    // Trusted: the target comes from site config, not from the request.
    $event->setResponse(new TrustedRedirectResponse($base . $url, 301));
  }

  /**
   * Resolves (site, legacy nid) to a canonical path via the ADR 017 key.
   *
   * Nodes first, then groups (migrated collections). Access-checked twice (query
   * and loaded entity), so an entity the requester cannot see is a 404, not a
   * redirect that confirms it exists.
   */
  protected function lookup(string $site, string $nid): ?string {
    foreach (['node', 'group'] as $type) {
      if (!$this->entityTypeManager->hasDefinition($type)) {
        continue;
      }
      $ids = $this->entityTypeManager->getStorage($type)->getQuery()
        ->accessCheck(TRUE)
        ->condition('field_legacy_site', $site)
        ->condition('field_legacy_nid', $nid)
        ->range(0, 2)
        ->execute();
      if (count($ids) > 1) {
        $this->logger->error('Ambiguous legacy key @site/@nid matches @type @ids.', [
          '@site' => $site, '@nid' => $nid, '@type' => $type, '@ids' => implode(',', $ids),
        ]);
        return NULL;
      }
      if ($ids) {
        $entity = $this->entityTypeManager->getStorage($type)->load(reset($ids));
        // The query's access check does not cover per-entity access such as
        // unpublished status, so check the loaded entity as well.
        return $entity && $entity->access('view') ? $entity->toUrl()->toString() : NULL;
      }
    }
    return NULL;
  }

}
