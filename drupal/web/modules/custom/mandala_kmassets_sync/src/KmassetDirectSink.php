<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Posts kmassets Solr documents directly to the Solr master update endpoint.
 *
 * Synchronous, single-doc path. Suitable for incremental updates and
 * development/staging validation. Uses the D11 versioned uid format
 * ({service}-11-{nid}) — see docs/deferred/kmassets-uid-identity-across-migration.md.
 *
 * The Solr master URL is configured via mandala_kmassets_sync.settings.solr_master_url
 * and should point to the core root (e.g. .../solr/kmassets). Overridable
 * per-environment via $config[] in settings.php.
 */
class KmassetDirectSink {

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly KmassetDocBuilder $docBuilder,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Builds and indexes a single node to the Solr master.
   *
   * @return string|null
   *   The indexed uid on success; NULL if the node is skipped (unpublished or
   *   no bundle config).
   *
   * @throws \RuntimeException
   *   On Solr HTTP or response error.
   */
  public function indexNode(NodeInterface $node): ?string {
    $doc = $this->docBuilder->build($node);
    if ($doc === NULL) {
      return NULL;
    }
    $this->solrPost([$doc]);
    $this->loggerFactory->get('mandala_kmassets_sync')
      ->info('Indexed @uid to kmassets master.', ['@uid' => $doc['uid']]);
    return $doc['uid'];
  }

  /**
   * Deletes all kmassets docs matching a Solr query from the master.
   *
   * Example: deleteByQuery('uid:images-11-*') cleans up all D11 test docs.
   *
   * @throws \RuntimeException
   *   On Solr HTTP or response error.
   */
  public function deleteByQuery(string $query): void {
    $this->solrPost(['delete' => ['query' => $query]]);
    $this->loggerFactory->get('mandala_kmassets_sync')
      ->info('Deleted kmassets docs matching: @query', ['@query' => $query]);
  }

  /**
   * POSTs a JSON payload to the Solr update endpoint with immediate commit.
   */
  protected function solrPost(array $payload): void {
    $url = $this->masterUpdateUrl();
    try {
      $response = $this->httpClient->post($url, ['json' => $payload]);
      $body = json_decode((string) $response->getBody(), TRUE) ?? [];
      if (($body['responseHeader']['status'] ?? -1) !== 0) {
        throw new \RuntimeException('Solr update failed: ' . json_encode($body['error'] ?? $body));
      }
    }
    catch (RequestException $e) {
      throw new \RuntimeException('Solr request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Returns the full Solr update URL with commit=true.
   */
  protected function masterUpdateUrl(): string {
    $url = $this->configFactory->get('mandala_kmassets_sync.settings')->get('solr_master_url');
    if (!$url) {
      throw new \RuntimeException('mandala_kmassets_sync.settings.solr_master_url is not configured.');
    }
    return rtrim($url, '/') . '/update?commit=true';
  }

}
