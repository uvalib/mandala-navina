<?php

namespace Drupal\shanti_kmaps_fields;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves KMaps ancestor paths via the kmterms Solr index.
 *
 * Used by code that needs to populate a KMaps field's path column outside the
 * autocomplete widget — primarily Drupal migrations and programmatic save
 * paths. The autocomplete widget already gets the path inline from its Solr
 * query (see KmapsAutocompleteController), so it does not use this service.
 */
class KmapsPathResolver {

  const CACHE_PREFIX = 'shanti_kmaps_fields:path:';

  /**
   * Maximum IDs per Solr request. Solr query length scales with this; 100 is
   * comfortably below typical maxBooleanClauses (1024) and URL limits.
   */
  const SOLR_CHUNK = 100;

  protected ClientInterface $httpClient;

  protected ConfigFactoryInterface $configFactory;

  protected CacheBackendInterface $cache;

  protected LoggerInterface $logger;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('shanti_kmaps_fields');
  }

  /**
   * Resolves the ancestor path for a single KMaps term.
   *
   * @param string $domain
   *   One of: subjects, places, terms.
   * @param int $id
   *   The KMaps term ID (the integer suffix of the Solr doc id).
   *
   * @return string|null
   *   Slash-delimited path (e.g. "6403/272/282/2610"), or NULL if the term
   *   could not be found or Solr was unreachable.
   */
  public function resolvePath(string $domain, int $id): ?string {
    $results = $this->resolvePathMultiple($domain, [$id]);
    return $results[$id] ?? NULL;
  }

  /**
   * Resolves ancestor paths for many terms in batched Solr requests.
   *
   * @param string $domain
   *   One of: subjects, places, terms.
   * @param int[] $ids
   *   KMaps term IDs.
   *
   * @return array<int, string|null>
   *   Map of id → path (or NULL when not found). Every input id is keyed in
   *   the result, including ones that weren't found.
   */
  public function resolvePathMultiple(string $domain, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $result = array_fill_keys($ids, NULL);

    if (empty($ids)) {
      return $result;
    }

    // Cache lookup. Keyed per (domain, id). NULL is also cached to avoid
    // re-querying for terms that genuinely don't exist.
    $cid_for = fn(int $id) => self::CACHE_PREFIX . $domain . ':' . $id;
    $cids = array_map($cid_for, $ids);
    $cached = $this->cache->getMultiple($cids);
    $prefix_len = strlen(self::CACHE_PREFIX . $domain . ':');
    foreach ($cached as $cid => $item) {
      $id = (int) substr($cid, $prefix_len);
      $result[$id] = $item->data;
    }

    // Anything still NULL is either a cache miss or a cached miss; we only
    // need to fetch the misses (cache miss = cid was returned in $cids but
    // not in $cached).
    $cached_ids = array_map(fn($cid) => (int) substr($cid, $prefix_len), array_keys($cached));
    $misses = array_values(array_diff($ids, $cached_ids));
    if (empty($misses)) {
      return $result;
    }

    $solr_url = $this->configFactory
      ->get('shanti_kmaps_admin.settings')
      ->get('server_solr_terms');
    if (empty($solr_url)) {
      $this->logger->warning('KMaps Solr URL not configured — path resolution skipped.');
      return $result;
    }

    foreach (array_chunk($misses, self::SOLR_CHUNK) as $chunk) {
      $fetched = $this->fetchChunk($solr_url, $domain, $chunk);
      foreach ($chunk as $id) {
        $path = $fetched[$id] ?? NULL;
        $result[$id] = $path;
        // Permanent cache — clear if KMaps taxonomy is restructured.
        $this->cache->set($cid_for($id), $path);
      }
    }

    return $result;
  }

  /**
   * Fetches one chunk of IDs from Solr.
   *
   * @return array<int, string>
   *   Map of id → path for docs found in this chunk only.
   */
  protected function fetchChunk(string $solr_url, string $domain, array $ids): array {
    $clauses = array_map(fn(int $id) => '"' . $domain . '-' . $id . '"', $ids);
    $q = 'id:(' . implode(' OR ', $clauses) . ')';

    $params = http_build_query([
      'q'    => $q,
      'fl'   => 'id,ancestor_id_path',
      'rows' => count($ids),
      'wt'   => 'json',
    ]);
    $url = rtrim($solr_url, '/') . '/select?' . $params;

    try {
      $response = $this->httpClient->get($url, ['timeout' => 10]);
      $body = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      $this->logger->warning('KMaps Solr unreachable during path resolution: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }

    $found = [];
    $prefix = $domain . '-';
    foreach ($body['response']['docs'] ?? [] as $doc) {
      $doc_id = $doc['id'] ?? '';
      if (!str_starts_with($doc_id, $prefix)) {
        continue;
      }
      $id = (int) substr($doc_id, strlen($prefix));
      $ancestor = $doc['ancestor_id_path'] ?? [];
      $found[$id] = implode('/', is_array($ancestor) ? $ancestor : [$ancestor]);
    }
    return $found;
  }

}
