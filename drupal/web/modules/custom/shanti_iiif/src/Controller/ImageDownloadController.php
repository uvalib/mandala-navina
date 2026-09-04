<?php

declare(strict_types=1);

namespace Drupal\shanti_iiif\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams a downloadable derivative of a shanti_image's IIIF bytes.
 *
 * Ports D7's GET /image/download/{i3fid}/{width}
 * (shanti_images_image_download()) -- see shanti_iiif.routing.yml for why
 * the route requires {node} rather than
 * a bare IIIF identifier. D7's own version gated on blanket 'access content'
 * only, no per-node check at all; this route requires _entity_access:
 * 'node.view' on the node instead, same convention as
 * shanti_images_carousel's CarouselController and mandala_node_api.node_json.
 *
 * Proxies through Drupal's own origin (rather than linking the IIIF server
 * directly) because the HTML5 download attribute -- what makes the browser
 * save the file instead of navigating to it -- is silently ignored for
 * cross-origin URLs; this is the same reason D7's route exists.
 */
class ImageDownloadController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Named download sizes -> IIIF target width.
   *
   * NULL means the full-size original (IIIF "full" region/size, no scaling).
   */
  protected const SIZES = [
    'large' => 1200,
    'medium' => 800,
    'small' => 400,
    'original' => NULL,
  ];

  public function __construct(
    protected IiifUrlBuilder $iiifUrlBuilder,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('shanti_iiif.url_builder'),
      $container->get('http_client'),
    );
  }

  /**
   * Streams the requested size as a browser download.
   */
  public function download(NodeInterface $node, string $size): StreamedResponse {
    if (!array_key_exists($size, self::SIZES)) {
      throw new NotFoundHttpException();
    }
    if (!$node->hasField('field_iiif_id') || $node->get('field_iiif_id')->isEmpty()) {
      throw new NotFoundHttpException();
    }

    $i3fid = trim((string) $node->get('field_iiif_id')->value);
    $rotation = 0;
    if ($node->hasField('field_image_rotation') && !$node->get('field_image_rotation')->isEmpty()) {
      $rotation = (int) $node->get('field_image_rotation')->value;
    }

    $width = self::SIZES[$size];
    $sourceUrl = $this->iiifUrlBuilder->buildUrl($i3fid, $width, NULL, $rotation);
    $filename = $this->buildFilename($node, $size);

    try {
      // Streamed request -- the upstream body is only read chunk-by-chunk
      // below, never buffered whole in memory (originals can be large).
      $upstream = $this->httpClient->request('GET', $sourceUrl, [
        'stream' => TRUE,
        'timeout' => 15,
      ]);
    }
    catch (GuzzleException) {
      throw new NotFoundHttpException();
    }

    $contentType = $upstream->getHeaderLine('Content-Type') ?: 'image/jpeg';
    $body = $upstream->getBody();

    $response = new StreamedResponse(static function () use ($body): void {
      while (!$body->eof()) {
        echo $body->read(8192);
        flush();
      }
    });
    $response->headers->set('Content-Type', $contentType);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    return $response;
  }

  /**
   * Builds a filesystem-safe download filename from the node's title.
   */
  protected function buildFilename(NodeInterface $node, string $size): string {
    $slug = mb_strtolower((string) $node->label());
    $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug) ?? 'image';
    $slug = trim($slug, '_') ?: 'image';
    return $slug . '-' . $size . '.jpg';
  }

}
