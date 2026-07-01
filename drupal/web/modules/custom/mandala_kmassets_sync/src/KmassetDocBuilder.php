<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_kmassets_sync\Contributor\KmassetDocContributorInterface;
use Drupal\node\NodeInterface;

/**
 * Builds the flat kmassets Solr "golden doc" from a D11 node (Sprint 1, 1a.8).
 *
 * Faithful D11 port of the D7 producer chain documented in
 * docs/planning/kmasset-solr-doc-contract.md §7. Three layers run in order:
 *
 *   1. Common-core base — this class (D7 _shanti_kmaps_fields_get_solr_doc).
 *   2. Tagged contributors — image/collection field slices, the D11 equivalent
 *      of D7 drupal_alter('kmaps_fields_solr_doc').
 *   3. Post-alter normalization — this class (sort keys, date defaulting, URL
 *      rewrite, entity-decode).
 *
 * The builder emits ONLY source/producer fields (contract tables A–C). It never
 * emits the copyField aggregates (ids/str/titles/names/creators/descriptions —
 * table D); Solr fills those at index time. It also never emits the volatile
 * index-set fields (timestamp/_version_).
 *
 * Known divergences from the D7 golden fixtures, by design / data availability:
 *  - node_user / node_user_i / node_user_full_s: D11 user migration is deferred,
 *    so migrated nodes are owned by uid 0 until it lands.
 *  - visibility_i / visibility_s + the entire collection/access layer (table B):
 *    Group-based in D11 (ADR 011), owned by 1b. Defaulted/stubbed here.
 *  - url_html/url_ajax/url_json: D11 single-site (ADR 005) URL scheme is a
 *    deferred decision; templates are config (settings.yml).
 */
class KmassetDocBuilder {

  /**
   * Domain → integer code used by kmapid_is (D7: places=1, subjects=2, terms=3).
   */
  protected const DOMAIN_CODES = ['places' => 1, 'subjects' => 2, 'terms' => 3];

  protected ConfigFactoryInterface $configFactory;

  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\mandala_kmassets_sync\Contributor\KmassetDocContributorInterface[]
   */
  protected array $contributors;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    iterable $contributors,
  ) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->contributors = $contributors instanceof \Traversable
      ? iterator_to_array($contributors)
      : $contributors;
  }

  /**
   * Builds the kmasset document for a node.
   *
   * @return array|null
   *   The flat Solr add-doc (source fields only), or NULL if the node's bundle
   *   has no kmassets configuration or the node is not published.
   */
  public function build(NodeInterface $node): ?array {
    $bundle_config = $this->bundleConfig($node->bundle());
    if ($bundle_config === NULL || !$node->isPublished()) {
      return NULL;
    }

    $doc = $this->buildBase($node, $bundle_config);

    foreach ($this->contributors as $contributor) {
      if ($contributor->applies($node)) {
        $contributor->contribute($doc, $node);
      }
    }

    $this->normalize($doc, $node);

    return $doc;
  }

  /**
   * Layer 1 — the common-core base shared by every asset type.
   *
   * D7: _shanti_kmaps_fields_get_solr_doc() lines 1180-1262.
   */
  protected function buildBase(NodeInterface $node, array $bundle_config): array {
    $nid = (int) $node->id();
    $service = $bundle_config['service'];

    // Generation marker "11" is a FROZEN constant — it identifies the D7→D11
    // migration-era rebuild, not the running Drupal version. It must not change
    // on Drupal upgrades (12, 13, …). Only a new wholesale data migration that
    // warrants a fresh uid space would introduce a new generation number.
    // Pattern {service}-11-\d+ distinguishes these docs from D7-era {service}-\d+.
    // See docs/deferred/kmassets-uid-identity-across-migration.md.
    $uid = $service . '-11-' . $nid;
    $doc = [
      'id' => $uid,
      'uid' => $uid,
      // Divergence: uid not migrated yet → owner is uid 0 (Anonymous).
      'node_user' => $node->getOwnerId() == 0 ? 'Anonymous' : $node->getOwner()->getAccountName(),
      'node_user_i' => (int) $node->getOwnerId(),
      'node_lang' => 'en',
      'node_created' => gmdate('Y-m-d\TH:i:s\Z', (int) $node->getCreatedTime()),
      'node_changed' => gmdate('Y-m-d\TH:i:s\Z', (int) $node->getChangedTime()),
      'pogrified_s' => 'fields_module',
      'title' => $node->label(),
      'service' => $service,
      'asset_type' => $bundle_config['asset_type'],
      'url_html' => str_replace('__NID__', (string) $nid, $bundle_config['url_html'] ?? ''),
      'url_ajax' => str_replace('__NID__', (string) $nid, $bundle_config['url_ajax'] ?? ''),
      'url_json' => str_replace('__NID__', (string) $nid, $bundle_config['url_json'] ?? ''),
      // Stub: real visibility is Group-based (1b). Default public for now.
      'visibility_i' => 1,
      'visibility_s' => 'public',
      'solrdoc_ts_i' => time(),
    ];

    // node_user_full_s — realname if the realname module/field is present.
    if ($node->getOwnerId() != 0) {
      $owner = $node->getOwner();
      if ($owner->hasField('realname') && !$owner->get('realname')->isEmpty()) {
        $doc['node_user_full_s'] = $owner->get('realname')->value;
      }
    }

    // asset_subtype — D7 "extra_fields" map; for images, field_image_type.
    $subtype_field = $bundle_config['asset_subtype_field'] ?? NULL;
    if ($subtype_field && $node->hasField($subtype_field) && !$node->get($subtype_field)->isEmpty()) {
      $doc['asset_subtype'] = $node->get($subtype_field)->value;
    }

    $doc += $this->buildKmaps($node);

    return $doc;
  }

  /**
   * Builds the kmapid family from every KMaps field on the node.
   *
   * D7: _shanti_kmaps_fields_get_solr_doc() lines 1087-1143. Ancestor expansion
   * in D7 hit the kmterms Solr index live; in D11 the ancestral path is stored
   * on the field's `path` column at migration time (KmapsPathResolver), so this
   * reads it directly — no live Solr dependency at build time.
   */
  protected function buildKmaps(NodeInterface $node): array {
    $kmapids_strict = [];
    $kmapheads = [];
    // Non-deduplicated expanded list — kmapid_is is computed over this (D7
    // computes _is before the array_unique that produces kmapid).
    $kmapids = [];
    $idfacets = ['places' => [], 'subjects' => [], 'terms' => []];

    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'shanti_kmaps_fields_default') {
        continue;
      }
      if ($node->get($field_name)->isEmpty()) {
        continue;
      }
      foreach ($node->get($field_name) as $item) {
        $domain = $item->get('domain')->getValue();
        $id = $item->get('id')->getValue();
        $header = $item->get('header')->getValue();
        $path = $item->get('path')->getValue();
        $defids = $item->get('defids')->getValue();

        $kmapids_strict[] = "$domain-$id";
        $kmapheads[] = $header;

        if (isset($idfacets[$domain])) {
          $idfacets[$domain][] = "$header|$domain-$id";
        }

        // Expand ancestors from the stored path (slash-delimited ids), then
        // guarantee the term itself is included.
        foreach ($this->ancestorKmapids($domain, (string) $id, (string) $path) as $kid) {
          $kmapids[] = $kid;
        }

        // Definition ids attach as extra expanded ids + a header each (D7 quirk:
        // grows kmapheads/kmapid_strict_ss but not kmapid_strict).
        if (!empty($defids)) {
          foreach (explode('|', (string) $defids) as $defid) {
            if ($defid !== '') {
              $kmapheads[] = $header;
              $kmapids[] = $defid;
            }
          }
        }
      }
    }

    $kmapid_is = [];
    foreach ($kmapids as $k) {
      $parts = explode('-', $k);
      if (count($parts) === 2) {
        [$d, $uid] = $parts;
        $di = self::DOMAIN_CODES[$d] ?? 9;
        if (is_numeric($uid)) {
          $kmapid_is[] = (int) $uid * 100 + $di;
        }
      }
    }

    $result = [
      'kmapid' => array_values(array_unique($kmapids)),
      'kmapid_strict' => $kmapids_strict,
      'kmapid_strict_ss' => $kmapheads,
      'kmapid_is' => $kmapid_is,
    ];
    // Per-domain idfacets — omit empty ones (Solr drops empty multivalued
    // fields, so the golden docs carry only the domains actually tagged).
    foreach (['places', 'subjects', 'terms'] as $domain) {
      $facets = array_values(array_unique($idfacets[$domain]));
      if ($facets) {
        $result["kmapid_{$domain}_idfacet"] = $facets;
      }
    }
    return $result;
  }

  /**
   * Returns the domain-prefixed ancestor kmapid list for a term (self last).
   */
  protected function ancestorKmapids(string $domain, string $id, string $path): array {
    $ids = [];
    foreach (array_filter(explode('/', $path), 'strlen') as $token) {
      $ids[] = "$domain-$token";
    }
    // Guarantee the term itself is present (and last, matching D7).
    $self = "$domain-$id";
    $ids = array_values(array_filter($ids, fn($v) => $v !== $self));
    $ids[] = $self;
    return $ids;
  }

  /**
   * Layer 3 — post-alter normalization.
   *
   * D7: _shanti_kmaps_fields_get_solr_doc() lines 1270-1352 + normalize helper.
   */
  protected function normalize(array &$doc, NodeInterface $node): void {
    // title_sort_s — from the (possibly contributor-updated) title.
    $title = is_array($doc['title'] ?? NULL) ? ($doc['title'][0] ?? '') : ($doc['title'] ?? '');
    $doc['title_sort_s'] = trim(strip_tags((string) $title), "'\"“”‘’()-: []");

    // creator_sort_s — derive when no contributor set it.
    if (empty($doc['creator_sort_s'])) {
      if (empty($doc['creator']) && empty($doc['creator_s'])) {
        $name = $node->getOwnerId() == 0 ? '' : $node->getOwner()->getAccountName();
        $doc['creator_sort_s'] = $name;
        $doc['creator'] = $name;
      }
      elseif (!empty($doc['creator_s'])) {
        $doc['creator_sort_s'] = $doc['creator_s'];
      }
      elseif (is_array($doc['creator'])) {
        $doc['creator_sort_s'] = $doc['creator'][0];
      }
      else {
        $doc['creator_sort_s'] = (string) $doc['creator'];
      }
    }
    if (!empty($doc['creator_sort_s'])) {
      $doc['creator_sort_s'] = htmlspecialchars_decode(trim($doc['creator_sort_s']), ENT_QUOTES);
    }

    // date_start defaulting + corruption guard.
    $epoch = '0000-01-01T00:00:00Z';
    $date_pat = '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/';
    if (empty($doc['date_start'])) {
      $doc['date_start'] = $epoch;
    }
    elseif (is_array($doc['date_start'])) {
      $doc['date_start'] = $doc['date_start'][0] ?? $epoch;
    }
    if (!preg_match($date_pat, $doc['date_start'])) {
      $doc['date_start_corrupt_s'] = $doc['date_start'];
      $doc['date_start'] = $epoch;
    }
    if (!empty($doc['date_end'])) {
      if (is_array($doc['date_end'])) {
        $doc['date_end'] = $doc['date_end'][0] ?? $epoch;
      }
      if (!preg_match($date_pat, $doc['date_end'])) {
        $doc['date_end_corrupt_s'] = $doc['date_end'];
        $doc['date_end'] = $epoch;
      }
    }

    // URL normalization: http→https, prod-alias domains → canonical domains.
    foreach (['url_html', 'url_ajax', 'url_json', 'url_thumb'] as $fld) {
      if (empty($doc[$fld])) {
        continue;
      }
      $doc[$fld] = preg_replace('/^\s*http:/', 'https:', $doc[$fld]);
      $doc[$fld] = preg_replace(
        '/https:\/\/mandala-(av|images|sources|texts|visuals)\.lib\.virginia\.edu/',
        'https://$1.mandala.library.virginia.edu',
        $doc[$fld],
      );
    }

    if (($doc['node_lang'] ?? NULL) === 'und') {
      $doc['node_lang'] = '';
    }

    $this->normalizeText($doc);
  }

  /**
   * Entity-decodes the text-bearing fields (NFC/Unicode fidelity step).
   *
   * D7: _shanti_kmaps_fields_normalize_solrdoc().
   */
  protected function normalizeText(array &$doc): void {
    $pattern = '/(title|description|caption|summary|creator|name)/';
    foreach (array_keys($doc) as $key) {
      if (!preg_match($pattern, $key)) {
        continue;
      }
      $val = $doc[$key];
      if (is_array($val)) {
        foreach ($val as $i => $item) {
          if (is_string($item)) {
            $val[$i] = str_replace('& ', '&amp; ', html_entity_decode($item));
          }
        }
        $doc[$key] = $val;
      }
      elseif (is_string($val)) {
        $doc[$key] = str_replace('& ', '&amp; ', html_entity_decode($val));
      }
    }
  }

  /**
   * Returns the kmassets settings for a bundle, or NULL if unconfigured.
   */
  protected function bundleConfig(string $bundle): ?array {
    $bundles = $this->configFactory->get('mandala_kmassets_sync.settings')->get('bundles') ?? [];
    return $bundles[$bundle] ?? NULL;
  }

}
