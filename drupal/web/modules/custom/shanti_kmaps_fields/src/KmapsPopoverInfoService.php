<?php

namespace Drupal\shanti_kmaps_fields;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches term info and related-asset counts for the KMaps popover.
 *
 * Ports two D7 mechanisms into one in-process service:
 * _shanti_kmaps_fields_kmaps_get_info() (single term-doc lookup on kmterms)
 * and kmaps_explorer_get_popover_data() (domain-specific nested-query counts
 * on kmterms + a grouped asset_type count on kmassets). D7's counts path
 * made a self-referential HTTP call to its own /mandala/popover/populate
 * endpoint; D11 is single-site (ADR 005), so this is a plain method call.
 */
class KmapsPopoverInfoService {

  const CACHE_PREFIX = 'shanti_kmaps_fields:popover:';

  /**
   * Matches D7's shanti_kmaps_fields_kmap_info_cache_ttl default (12h).
   */
  const CACHE_TTL = 43200;

  /**
   * Ancestor-field Solr suffix per domain, matching D7's perspective choice.
   */
  const ANCESTOR_PERSPECTIVE = [
    'places' => '_pol.admin.hier',
    'subjects' => '_generic',
    'terms' => '_tib.alpha',
  ];

  /**
   * Kmassets asset_type values ported to popover categories directly.
   *
   * D11's Images sync writes asset_type "images" (not D7's "picture"
   * special case), so this is a flat map, no special-casing needed.
   */
  const ASSET_TYPE_CATEGORIES = ['images', 'audio-video', 'sources', 'texts', 'visuals'];

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The default cache bin.
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger channel.
   */
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
   * Builds the full popover data set for one KMaps tag.
   *
   * @param string $domain
   *   One of: places, subjects, terms.
   * @param int $id
   *   The KMaps term ID.
   * @param string $fallback_label
   *   The label already known from the field item, used if the Solr doc
   *   lookup fails or returns no header.
   * @param string|null $defids
   *   Pipe-delimited definition UIDs from the field item (terms only).
   *
   * @return array|null
   *   NULL if the term doc could not be found. Otherwise an array with
   *   keys: label, domain, kid, ftypes, desc, tree (ancestor breadcrumb),
   *   defs (definition links, terms only), links (Full Entry + non-zero
   *   Related X links).
   */
  public function getPopoverData(string $domain, int $id, string $fallback_label, ?string $defids = NULL): ?array {
    $doc = $this->getTermDoc($domain, $id);
    if ($doc === NULL) {
      return NULL;
    }

    $config = $this->configFactory->get('shanti_kmaps_admin.settings');

    $label = $doc['header'] ?? $fallback_label;
    $desc = 'For more information about this term, see Full Entry below.';
    $def_links = [];

    // Terms domain: swap in the Tibetan name as the label, list associated
    // subjects as prefix description. Matches
    // shanti_kmaps_fields_get_popover_array()'s terms-only branch.
    if ($domain === 'terms') {
      $wylie = $label;
      if (!empty($doc['name_tibt'][0])) {
        $label = $doc['name_tibt'][0];
      }
      $subject_links = [];
      foreach ($doc['associated_subjects'] ?? [] as $n => $subject_name) {
        $subject_id = $doc['associated_subject_ids'][$n] ?? NULL;
        if ($subject_id === NULL) {
          continue;
        }
        $subject_links[] = [
          'label' => $subject_name,
          'url' => $this->explorerUrl($config, 'subjects', $subject_id),
        ];
      }
      $desc = ['wylie' => $wylie, 'subjects' => $subject_links, 'text' => $desc];
    }

    if ($defids) {
      foreach (array_filter(explode('|', $defids)) as $n => $defid) {
        $def_links[] = ['n' => $n, 'defid' => $defid];
      }
    }

    // Feature types (places only).
    $ftypes = [];
    if ($domain === 'places') {
      $ftype_names = $doc['feature_types'] ?? [];
      $ftype_ids = $doc['feature_type_ids'] ?? [];
      foreach ($ftype_names as $n => $name) {
        $ftype_id = $ftype_ids[$n] ?? NULL;
        if ($ftype_id === NULL) {
          continue;
        }
        $ftypes[] = [
          'label' => trim($name, " /"),
          'url' => $this->explorerUrl($config, 'places', $ftype_id),
        ];
      }
    }

    // Ancestor breadcrumb.
    $ancestors = [];
    $perspective = self::ANCESTOR_PERSPECTIVE[$domain] ?? '';
    $ancestors_key = $domain === 'terms' ? 'ancestors' . $perspective : 'ancestors';
    $ancestor_ids = $doc['ancestor_ids' . $perspective] ?? [];
    $ancestor_titles = $doc[$ancestors_key] ?? [];
    if (!empty($ancestor_ids)) {
      foreach ($ancestor_ids as $n => $ancestor_id) {
        $ancestors[] = [
          'label' => $ancestor_titles[$n] ?? $ancestor_id,
          'url' => $this->explorerUrl($config, $domain, $ancestor_id),
        ];
      }
      // Drop the term's own entry, always the last ancestor.
      if ((string) end($ancestor_ids) === (string) $id) {
        array_pop($ancestors);
      }
    }

    // Links: Full Entry, then non-zero Related X counts.
    $links = [
      'Full Entry' => [
        'icon' => 'link-external',
        'href' => $this->explorerUrl($config, $domain, $id),
      ],
    ];
    foreach ($this->getRelatedCounts($domain, $id) as $category => $info) {
      if (empty($info['count'])) {
        continue;
      }
      $label_key = 'Related ' . ucfirst($category === 'images' ? 'photos' : $category)
        . " ({$info['count']})";
      $links[$label_key] = ['icon' => $category, 'href' => $info['href']];
    }

    return [
      'label' => $label,
      'domain' => $domain,
      'kid' => $id,
      'ftypes' => $ftypes,
      'desc' => $desc,
      'defs' => $def_links,
      'tree' => $ancestors,
      'links' => $links,
    ];
  }

  /**
   * Looks up the raw kmterms Solr document for one KMaps term.
   */
  protected function getTermDoc(string $domain, int $id): ?array {
    $cid = self::CACHE_PREFIX . 'doc:' . $domain . '-' . $id;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $solr_url = $this->configFactory->get('shanti_kmaps_admin.settings')->get('server_solr_terms');
    if (empty($solr_url)) {
      $this->logger->warning('KMaps terms Solr URL not configured — popover skipped.');
      return NULL;
    }

    $url = rtrim($solr_url, '/') . '/select?' . http_build_query([
      'q' => 'uid:' . $domain . '-' . $id,
      'wt' => 'json',
    ]);

    $doc = $this->fetchSolrDoc($url);
    if ($doc !== NULL) {
      $this->cache->set($cid, $doc, time() + self::CACHE_TTL);
    }
    return $doc;
  }

  /**
   * Related-asset counts by category.
   *
   * Ported from kmaps_explorer_get_popover_data() +
   * shanti_kmaps_fields_get_all_counts_by_kmapid().
   *
   * @return array<string, array{href: string, count: int}>
   *   Keyed by category (places, subjects, images, audio-video, sources,
   *   texts, visuals).
   */
  protected function getRelatedCounts(string $domain, int $id): array {
    $cid = self::CACHE_PREFIX . 'counts:' . $domain . '-' . $id;
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $config = $this->configFactory->get('shanti_kmaps_admin.settings');
    $terms_url = $config->get('server_solr_terms');
    $assets_url = $config->get('server_solr');
    $counts = [];

    if ($terms_url) {
      $counts = $this->getKmtermsRelatedCounts($domain, $id, rtrim($terms_url, '/'));
    }
    if ($assets_url) {
      $counts += $this->getKmassetsCounts($domain, $id, rtrim($assets_url, '/'));
    }

    $this->cache->set($cid, $counts, time() + self::CACHE_TTL, ['kmaps_popover']);
    return $counts;
  }

  /**
   * Related places/subjects counts from nested-child-doc kmterms queries.
   *
   * Domain-specific query shapes ported verbatim from
   * kmaps_explorer_get_popover_data()'s places/subjects/terms branches.
   */
  protected function getKmtermsRelatedCounts(string $domain, int $id, string $terms_url): array {
    $config = $this->configFactory->get('shanti_kmaps_admin.settings');
    $subjects_count = 0;
    $places_count = 0;

    if ($domain === 'places') {
      $rel_places = $this->groupCounts($terms_url, http_build_query([
        'fl' => '*',
        'q' => "{!child of=block_type:parent}ancestor_uids_generic:places-{$id}",
        'wt' => 'json',
        'group' => 'true',
        'group.field' => 'block_child_type',
        'group.limit' => 0,
        'fq' => '-related_kmaps_node_type:child',
      ]), 'block_child_type');
      $places_count += $rel_places['related_places'] ?? 0;

      $rel_subjects = $this->groupCounts($terms_url, http_build_query([
        'fl' => '*',
        'q' => "{!child of=block_type:parent}id:places-{$id}",
        'wt' => 'json',
        'group' => 'true',
        'group.field' => 'block_child_type',
        'group.limit' => 0,
        'fq' => '-related_kmaps_node_type:child',
      ]), 'block_child_type');
      $subjects_count += ($rel_subjects['feature_types'] ?? 0) + ($rel_subjects['related_subjects'] ?? 0);
    }
    elseif ($domain === 'subjects') {
      $rel_subjects = $this->groupCounts($terms_url, http_build_query([
        'fl' => '*',
        'q' => "{!child of=block_type:parent}ancestor_uids_generic:subjects-{$id}",
        'wt' => 'json',
        'group' => 'true',
        'group.field' => 'block_child_type',
        'group.limit' => 0,
        'fq' => '-related_kmaps_node_type:child',
      ]), 'block_child_type');
      $subjects_count += $rel_subjects['related_subjects'] ?? 0;

      $rel_places_q = "{!parent which=block_type:parent}related_subjects_id_s:subjects-{$id}"
        . " OR feature_type_id_i:{$id}";
      $rel_places = $this->groupCounts($terms_url, http_build_query([
        'q' => $rel_places_q,
        'wt' => 'json',
        'group' => 'true',
        'group.field' => 'tree',
        'group.limit' => 0,
        'fq' => '-related_kmaps_node_type:child',
      ]), 'tree');
      $places_count += $rel_places['places'] ?? 0;
    }
    // Terms domain: D7 does not query related places/subjects for terms.
    $counts = [];
    if ($places_count > 0) {
      $counts['places'] = [
        'href' => $this->explorerUrl($config, $domain, $id, 'places'),
        'count' => $places_count,
      ];
    }
    if ($subjects_count > 0) {
      $counts['subjects'] = [
        'href' => $this->explorerUrl($config, $domain, $id, 'subjects'),
        'count' => $subjects_count,
      ];
    }
    return $counts;
  }

  /**
   * Related-asset counts from kmassets, grouped by asset_type.
   *
   * Covers images/audio-video/sources/texts/visuals.
   */
  protected function getKmassetsCounts(string $domain, int $id, string $assets_url): array {
    $config = $this->configFactory->get('shanti_kmaps_admin.settings');
    $groups = $this->groupCounts($assets_url, http_build_query([
      'q' => "kmapid:{$domain}-{$id}",
      'start' => 0,
      'facets' => 'on',
      'group' => 'true',
      'group.field' => 'asset_type',
      'group.facet' => 'true',
      'group.ngroups' => 'true',
      'group.limit' => 0,
      'wt' => 'json',
    ]), 'asset_type');

    $counts = [];
    foreach (self::ASSET_TYPE_CATEGORIES as $type) {
      if (!empty($groups[$type])) {
        $counts[$type] = [
          'href' => $this->explorerUrl($config, $domain, $id, $type === 'images' ? 'images' : $type),
          'count' => $groups[$type],
        ];
      }
    }
    return $counts;
  }

  /**
   * Runs a Solr select/query request and returns groupValue => numFound.
   */
  protected function groupCounts(string $base_url, string $query_string, string $group_field): array {
    $url = $base_url . '/select?' . $query_string;
    $body = $this->fetchSolrJson($url);
    $groups = $body['grouped'][$group_field]['groups'] ?? [];
    $result = [];
    foreach ($groups as $group) {
      $value = $group['groupValue'] ?? NULL;
      if ($value !== NULL) {
        $result[$value] = $group['doclist']['numFound'] ?? 0;
      }
    }
    return $result;
  }

  /**
   * Fetches and JSON-decodes a Solr response, or NULL on failure.
   */
  protected function fetchSolrJson(string $url): ?array {
    try {
      $response = $this->httpClient->get($url, ['timeout' => 10]);
      return json_decode($response->getBody()->getContents(), TRUE) ?? [];
    }
    catch (\Exception $e) {
      $this->logger->warning('KMaps popover Solr request failed: @msg (@url)', [
        '@msg' => $e->getMessage(),
        '@url' => $url,
      ]);
      return NULL;
    }
  }

  /**
   * Fetches a single term document (response.docs[0]) or NULL.
   */
  protected function fetchSolrDoc(string $url): ?array {
    $body = $this->fetchSolrJson($url);
    return $body['response']['docs'][0] ?? NULL;
  }

  /**
   * Builds an explorer URL for a domain+id.
   *
   * Optionally on a different "app" path segment (e.g. "images", "texts")
   * than the term's own domain — matches D7's
   * str_replace('overview', $type, $kme_url) pattern.
   */
  protected function explorerUrl($config, string $domain, int|string $id, ?string $path_segment = NULL): string {
    $tpl = $config->get('explorer_' . $domain) ?? '';
    $url = str_replace('__KMAPID__', (string) $id, $tpl);
    if ($path_segment !== NULL) {
      $url = str_contains($url, 'overview')
        ? str_replace('overview', $path_segment, $url)
        : rtrim($url, '/') . '/' . $path_segment;
    }
    return $url;
  }

}
