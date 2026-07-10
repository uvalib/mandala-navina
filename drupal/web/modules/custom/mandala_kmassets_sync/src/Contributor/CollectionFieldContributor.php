<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Contributor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Adds the collection / access-path fields to a kmasset document (table B).
 *
 * Faithful-enough D11 port of the security-critical slice of D7's
 * shanti_collections_kmaps_fields_solr_doc_alter() (shanti_collections.module:782).
 * In D7 these fields came from a collection entity-reference field + Organic
 * Groups; in D11, collection membership and access are Group-based (ADR 011),
 * resolved here via group_relationship lookups (same pattern as
 * mandala_group_inheritance's node-access hook).
 *
 * Scope (see docs/planning/kmasset-solr-doc-contract.md §7.2.B): this
 * populates the two fields that are load-bearing for Sprint 1's security
 * acceptance criterion -- visibility_i/visibility_s (overriding the base
 * builder's public-only default) and collection_uid_s (consumed by ADR 014's
 * proxy fq). It deliberately does NOT populate collection_uid_path_ss,
 * collection_title_path_ss, collection_nid_path_is, collection_idfacet, or
 * subcollection_* -- those are breadcrumb/faceting metadata, not
 * access-gating, and require collections to be indexed as kmassets docs in
 * their own right (asset_type:collections), which this module does not do
 * yet. See docs/deferred/kmassets-collection-docs-and-facets.md.
 */
class CollectionFieldContributor implements KmassetDocContributorInterface {

  /**
   * Group field_group_access -> kmassets visibility_i/visibility_s.
   *
   * field_group_access (ADR 011 / mandala_group_inheritance): 0=public,
   * 1=private, 2=subscribable.
   * kmassets visibility_i (contract §6): 1=public, 2=private, 3=uva.
   *
   * The two enumerations don't line up: kmassets has no "subscribable"
   * concept. Mapped to private (fail closed) rather than guessing it means
   * "uva" -- a real decision, not yet made; see
   * docs/deferred/kmassets-collection-docs-and-facets.md.
   */
  protected const ACCESS_TO_VISIBILITY = [
    0 => [1, 'public'],
    1 => [2, 'private'],
    2 => [2, 'private'],
  ];

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(array &$doc, NodeInterface $node): void {
    // D7 shanti_collections.module:792 — set on every doc this alter touches.
    $doc['mogrified_s'] = 'true';

    $group = $this->getOwningGroup($node);
    if ($group === NULL) {
      // Not in any collection: base builder's public default already holds.
      return;
    }

    $access = (int) $group->get('field_group_access')->value;
    [$visibility_i, $visibility_s] = self::ACCESS_TO_VISIBILITY[$access] ?? [1, 'public'];
    $doc['visibility_i'] = $visibility_i;
    $doc['visibility_s'] = $visibility_s;

    $doc['collection_uid_s'] = $this->groupKmassetUid($group);
    $doc['collection_title'] = $group->label();

    if ($group->hasField('field_legacy_nid') && !$group->get('field_legacy_nid')->isEmpty()) {
      $doc['collection_nid'] = (int) $group->get('field_legacy_nid')->value;
    }
  }

  /**
   * The node's immediate owning collection or subcollection, if any.
   *
   * Mirrors mandala_group_inheritance's _mandala_group_inheritance_node_access()
   * lookup. A node belongs to at most one collection/subcollection in the
   * Sprint 1 content model (ADR 011 one-level nesting).
   */
  protected function getOwningGroup(NodeInterface $node): ?GroupInterface {
    $rel_storage = $this->entityTypeManager->getStorage('group_relationship');
    $relationships = $rel_storage->loadByEntity($node);
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if (in_array($group->bundle(), ['collection', 'subcollection'], TRUE)) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * The kmasset-style uid for a collection/subcollection group.
   *
   * Mirrors KmassetDocBuilder::buildBase()'s frozen {service}-11-{d11-id}
   * pattern exactly -- keyed off the D11 group id, NOT field_legacy_nid.
   * Per docs/deferred/kmassets-uid-identity-across-migration.md, the D7
   * nid belongs in a separate uid_legacy_s-style field (not implemented
   * here for collections; that decision was scoped to image docs), never
   * embedded in the primary uid. Sprint 1 only covers Images collections,
   * so "images" is hardcoded rather than derived from bundle config the
   * way node service/asset_type is.
   */
  protected function groupKmassetUid(GroupInterface $group): string {
    return 'images-11-' . $group->id();
  }

}
