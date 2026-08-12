<?php

declare(strict_types=1);

namespace Drupal\mandala_node_api\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\node\NodeInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves node-detail JSON for the React app (Spike 6, Option A).
 *
 * Fulfils the contract mandala_kmassets_sync already publishes into Solr's
 * url_json field (mandala_kmassets_sync.settings.yml,
 * bundles.shanti_image.url_json = '__BASE_URL__/api/json/__NID__') — until
 * now nothing served that path, so the URL was live in the index but
 * resolved to nothing (see docs/spikes/spike-06-api-compatibility.md,
 * "Concrete gap this surfaces").
 *
 * Access is delegated entirely to core node access via the route's
 * `_entity_access: node.view` requirement, so
 * mandala_group_inheritance_entity_access()'s private-collection gating
 * applies for free — no access logic is reimplemented here.
 *
 * Under Spike 6's decided URL strategy (Option A: the React app always
 * fetches through the same-origin mandala-wp-proxy plugin, which does a
 * server-to-server fetch, not a browser cross-origin request), this
 * controller deliberately does NOT implement JSONP (?callback=) or CORS
 * headers — both were only needed for direct browser cross-origin fetches,
 * which no longer happen under the decided architecture. See
 * docs/deferred/mandala-wp-proxy-json-proxy-open-ssrf.md for the proxy side.
 *
 * Response shape is curated, not a raw-node-dump port of D7's
 * shanti_images_node_json() (which returned the entire raw node object,
 * including internal fields like field_admin_notes / field_private_note —
 * deliberately not replicated here). The exact field set the live React
 * client reads from this response was not fully audited in Spike 6 (see
 * that doc's "What this does NOT establish"); treat this shape as a
 * reasonable first cut that needs validation against the running client,
 * not a finished contract — especially before the same pattern is used to
 * build the Sources/Texts/AV equivalents.
 */
class NodeJsonController extends ControllerBase implements ContainerInjectionInterface {

  public function __construct(
    protected IiifUrlBuilder $iiifUrlBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('shanti_iiif.url_builder'),
    );
  }

  /**
   * Returns the node-detail JSON for a single asset.
   */
  public function view(NodeInterface $node): CacheableJsonResponse {
    // Only Images (shanti_image) is migrated as of Spike 6 (2026-08-07
    // audit) — Sources/Texts/AV each need their own response shaping when
    // they migrate, per the per-site shape differences that audit found.
    if ($node->bundle() !== 'shanti_image') {
      throw new NotFoundHttpException();
    }

    $response = new CacheableJsonResponse($this->buildData($node));

    // Private-collection access can change per session (group membership),
    // so this must never be cached across users, and must invalidate when
    // the node or its owning group changes.
    $cache = CacheableMetadata::createFromObject($node)->addCacheContexts(['user']);
    $response->addCacheableDependency($cache);

    return $response;
  }

  /**
   * Assembles the response body for a shanti_image node.
   */
  protected function buildData(NodeInterface $node): array {
    $data = [
      'nid' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'title' => $node->label(),
      'type' => $node->bundle(),
      'created' => gmdate('Y-m-d\TH:i:s\Z', (int) $node->getCreatedTime()),
      'changed' => gmdate('Y-m-d\TH:i:s\Z', (int) $node->getChangedTime()),
      'langcode' => $node->language()->getId(),
      'legacy_nid' => $this->scalar($node, 'field_legacy_nid'),
      'image' => $this->buildImage($node),
      'descriptions' => $this->buildDescriptions($node),
      'agents' => $this->buildAgents($node),
      'collection' => $this->buildCollection($node),
      'kmaps' => $this->buildKmapTerms($node),
      'technical' => $this->buildScalarGroup($node, [
        'field_image_type', 'field_image_color', 'field_image_quality',
        'field_image_digital', 'field_image_enhancement', 'field_aperture',
        'field_exposure_bias', 'field_flash_settings', 'field_focal_length',
        'field_iso_speed_rating', 'field_lens', 'field_metering_mode',
        'field_noise_reduction', 'field_sensing_method', 'field_spot_feature',
        'field_light_source', 'field_image_capture_device', 'field_altitude',
        'field_latitude', 'field_longitude', 'field_physical_size',
      ]),
      'rights' => $this->buildScalarGroup($node, [
        'field_copyright_date', 'field_copyright_holder',
        'field_copyright_statement', 'field_license_url', 'field_rights_notes',
      ]),
      'keywords' => $this->multiScalar($node, 'field_keywords'),
      'other_ids' => $this->multiScalar($node, 'field_other_ids'),
      'organization_name' => $this->scalar($node, 'field_organization_name'),
      'sponsor_name' => $this->scalar($node, 'field_sponsor_name'),
      'project_name' => $this->scalar($node, 'field_project_name'),
      'general_note' => $this->scalar($node, 'field_general_note'),
      'classifications' => $this->buildClassifications($node),
    ];

    // Drop empty/null values so the response isn't cluttered with fields
    // that don't apply to this asset — matches how kmassets sync omits
    // empty multivalued Solr fields (KmassetDocBuilder::buildKmaps()).
    return array_filter($data, static fn ($v) => $v !== NULL && $v !== [] && $v !== '');
  }

  /**
   * IIIF geometry + derivative URLs, mirroring what kmassets sync already
   * computes for the same node (ImageFieldContributor::contributeImageGeometry)
   * so the served detail JSON and the Solr-indexed thumb URL stay consistent.
   */
  protected function buildImage(NodeInterface $node): array {
    $iiif_id = $this->scalar($node, 'field_iiif_id');
    if ($iiif_id === NULL) {
      // ~36 metadata-only records have no IIIF image (Spike 6 field audit).
      return [];
    }

    $width = $this->scalar($node, 'field_iiif_width');
    $height = $this->scalar($node, 'field_iiif_height');

    return array_filter([
      'iiif_id' => $iiif_id,
      'width' => $width !== NULL ? (int) $width : NULL,
      'height' => $height !== NULL ? (int) $height : NULL,
      'url' => $this->iiifUrlBuilder->buildUrl($iiif_id),
      'url_thumb' => $this->iiifUrlBuilder->buildUrl($iiif_id, 200, 200, 0, 'full', TRUE),
      'info_url' => $this->iiifUrlBuilder->infoUrl($iiif_id),
      'rotation' => $this->scalar($node, 'field_image_rotation'),
    ], static fn ($v) => $v !== NULL);
  }

  /**
   * All descriptions (not just the primary + alt split Solr uses), each
   * carrying its own language/authors. Mirrors the paragraph fields
   * ImageFieldContributor::contributeDescriptions() reads.
   */
  protected function buildDescriptions(NodeInterface $node): array {
    if (!$node->hasField('field_image_descriptions') || $node->get('field_image_descriptions')->isEmpty()) {
      return [];
    }

    $descriptions = [];
    foreach ($node->get('field_image_descriptions')->referencedEntities() as $para) {
      $language = 'en';
      if ($para->hasField('field_language') && !$para->get('field_language')->isEmpty()) {
        $language = $para->get('field_language')->first()->get('header')->getValue() ?: 'en';
      }
      $authors = [];
      if ($para->hasField('field_author')) {
        foreach ($para->get('field_author') as $author) {
          $authors[] = $author->value;
        }
      }
      $descriptions[] = array_filter([
        'title' => $para->hasField('field_description_title') ? (string) $para->get('field_description_title')->value : '',
        'summary' => $para->hasField('field_summary') ? (string) $para->get('field_summary')->value : '',
        // Filter-processed output (matches D7's safe_value — filtered HTML,
        // not raw input), consistent with ImageFieldContributor's choice.
        'description' => $para->hasField('field_description') && !$para->get('field_description')->isEmpty()
          ? (string) $para->get('field_description')->processed
          : '',
        'language' => $language,
        'authors' => $authors,
      ], static fn ($v) => $v !== '' && $v !== []);
    }
    return $descriptions;
  }

  /**
   * Creator/contributor agents (field_image_agents paragraphs).
   */
  protected function buildAgents(NodeInterface $node): array {
    if (!$node->hasField('field_image_agents') || $node->get('field_image_agents')->isEmpty()) {
      return [];
    }

    $agents = [];
    foreach ($node->get('field_image_agents')->referencedEntities() as $agent) {
      $agents[] = array_filter([
        'name' => $agent->hasField('field_agent_name') ? (string) $agent->get('field_agent_name')->value : '',
        'dates' => $agent->hasField('field_agent_dates') ? (string) $agent->get('field_agent_dates')->value : '',
      ], static fn ($v) => $v !== '');
    }
    return $agents;
  }

  /**
   * The node's owning collection/subcollection, if any.
   *
   * Same group_relationship lookup pattern as
   * mandala_group_inheritance's node-access hook and
   * CollectionFieldContributor::getOwningGroup() — duplicated here rather
   * than shared, matching this codebase's existing convention (see that
   * class's own docblock, which notes it mirrors the access-hook version).
   */
  protected function buildCollection(NodeInterface $node): array {
    $rel_storage = $this->entityTypeManager()->getStorage('group_relationship');
    foreach ($rel_storage->loadByEntity($node) as $relationship) {
      $group = $relationship->getGroup();
      if (in_array($group->bundle(), ['collection', 'subcollection'], TRUE)) {
        return array_filter([
          'title' => $group->label(),
          'uid' => 'images-11-' . $group->id(),
          'legacy_nid' => $group->hasField('field_legacy_nid') && !$group->get('field_legacy_nid')->isEmpty()
            ? (int) $group->get('field_legacy_nid')->value
            : NULL,
        ], static fn ($v) => $v !== NULL && $v !== '');
      }
    }
    return [];
  }

  /**
   * KMaps term references (subjects/places/terms/collections), each with
   * its domain/id/header/path — every field of type
   * shanti_kmaps_fields_default on the node, matching
   * KmassetDocBuilder::buildKmaps()'s field-type-based discovery rather
   * than a hardcoded field-name list.
   */
  protected function buildKmapTerms(NodeInterface $node): array {
    $terms = [];
    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'shanti_kmaps_fields_default' || $node->get($field_name)->isEmpty()) {
        continue;
      }
      $items = [];
      foreach ($node->get($field_name) as $item) {
        $items[] = [
          'domain' => $item->get('domain')->getValue(),
          'id' => $item->get('id')->getValue(),
          'header' => $item->get('header')->getValue(),
          'path' => $item->get('path')->getValue(),
        ];
      }
      $terms[$field_name] = $items;
    }
    return $terms;
  }

  /**
   * field_external_classification paragraphs (schema/title pairs).
   */
  protected function buildClassifications(NodeInterface $node): array {
    if (!$node->hasField('field_external_classification') || $node->get('field_external_classification')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($node->get('field_external_classification')->referencedEntities() as $para) {
      $out[] = array_filter([
        'title' => $para->hasField('field_classification_notes') ? (string) $para->get('field_classification_notes')->value : '',
      ], static fn ($v) => $v !== '');
    }
    return array_filter($out);
  }

  /**
   * A flat associative array of scalar field values, keyed by field name
   * with the `field_` prefix stripped. Fields not present, empty, or
   * unset on this node are simply omitted.
   */
  protected function buildScalarGroup(NodeInterface $node, array $field_names): array {
    $out = [];
    foreach ($field_names as $field_name) {
      $value = $this->scalar($node, $field_name);
      if ($value !== NULL) {
        $out[preg_replace('/^field_/', '', $field_name)] = $value;
      }
    }
    return $out;
  }

  /**
   * The scalar `value` of a single-value field, or NULL if absent/empty.
   */
  protected function scalar(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field_name)->value;
    return ($value === NULL || $value === '') ? NULL : (string) $value;
  }

  /**
   * All values of a multi-value scalar field.
   */
  protected function multiScalar(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($node->get($field_name) as $item) {
      if ($item->value !== NULL && $item->value !== '') {
        $out[] = $item->value;
      }
    }
    return $out;
  }

}
