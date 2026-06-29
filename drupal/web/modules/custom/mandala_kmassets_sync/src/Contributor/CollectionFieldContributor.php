<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Contributor;

use Drupal\node\NodeInterface;

/**
 * Adds the collection / access-path fields to a kmasset document (table B).
 *
 * STUB — blocked on Sprint 1 Step 1b. This is the 1a.8 ↔ 1b security seam
 * (contract §9). In D7 (shanti_collections_kmaps_fields_solr_doc_alter,
 * shanti_collections.module:782) these fields came from a collection
 * entity-reference field + Organic Groups. In D11 collection membership and
 * access are Group-based (ADR 011), which does not land until 1b — so the
 * access-bearing fields below cannot be populated faithfully yet:
 *
 *   collection_idfacet, collection_uid_s, collection_nid, collection_title,
 *   collection_uid_path_ss, collection_title_path_ss, collection_nid_path_is,
 *   subcollection_*  (and the real visibility_i/visibility_s resolution)
 *
 * Until then this contributor only sets the normalization marker that D7's
 * collections alter applied to every doc, so the rest of the pipeline is
 * exercised. The golden-diff harness excludes table-B fields accordingly.
 *
 * @todo 1b — populate collection/access fields from the Group structure
 *   (ADR 011) and resolve real visibility.
 */
class CollectionFieldContributor implements KmassetDocContributorInterface {

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
  }

}
