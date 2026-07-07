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
   * Deletes the kmassets doc for a specific node from the master.
   *
   * @return bool
   *   TRUE if a delete was issued; FALSE if the node's bundle is not configured.
   *
   * @throws \RuntimeException
   *   On Solr HTTP or response error.
   */
  public function deleteNode(NodeInterface $node): bool {
    $uid = $this->uidFor($node);
    if ($uid === NULL) {
      return FALSE;
    }
    $this->deleteByQuery("uid:$uid");
    return TRUE;
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
   * Returns the versioned uid for a node, or NULL if the bundle is not configured.
   */
  public function uidFor(NodeInterface $node): ?string {
    $bundles = $this->configFactory->get('mandala_kmassets_sync.settings')->get('bundles') ?? [];
    $config = $bundles[$node->bundle()] ?? NULL;
    if ($config === NULL) {
      return NULL;
    }
    return $config['service'] . '-11-' . $node->id();
  }

  /**
   * Returns the machine names of all configured bundles.
   */
  public function configuredBundles(): array {
    return array_keys(
      $this->configFactory->get('mandala_kmassets_sync.settings')->get('bundles') ?? []
    );
  }

  /**
   * Runs a Solr select query against the master core and returns the response.
   *
   * Read counterpart to the write path, used by the audit command. Returns the
   * decoded Solr response body, e.g.
   *   ['responseHeader' => …, 'response' => ['numFound' => N, 'docs' => [...]],
   *    'nextCursorMark' => '…']
   *
   * @param array $params
   *   Solr request parameters (q, fl, rows, sort, cursorMark, …). `wt=json` is
   *   forced.
   *
   * @throws \RuntimeException
   *   On Solr HTTP or transport error.
   */
  public function select(array $params): array {
    $url = rtrim($this->solrCoreUrl(), '/') . '/select';
    try {
      $response = $this->httpClient->get($url, ['query' => ['wt' => 'json'] + $params]);
      return json_decode((string) $response->getBody(), TRUE) ?? [];
    }
    catch (RequestException $e) {
      throw new \RuntimeException('Solr query failed: ' . $e->getMessage(), 0, $e);
    }
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
    return rtrim($this->solrCoreUrl(), '/') . '/update?commit=true';
  }

  /**
   * Returns the configured kmassets core root URL (no trailing slash).
   *
   * @throws \RuntimeException
   *   If solr_master_url is not configured.
   */
  protected function solrCoreUrl(): string {
    $url = $this->configFactory->get('mandala_kmassets_sync.settings')->get('solr_master_url');
    if (!$url) {
      throw new \RuntimeException('mandala_kmassets_sync.settings.solr_master_url is not configured.');
    }
    return rtrim($url, '/');
  }

}
