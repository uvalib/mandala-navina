<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Contributor;

use Drupal\node\NodeInterface;

/**
 * A contributor that adds fields to a kmasset document after the base layer.
 *
 * This is the D11 equivalent of D7's drupal_alter('kmaps_fields_solr_doc')
 * fan-out: the base builder (KmassetDocBuilder) assembles the common-core fields
 * shared by every asset type, then each tagged contributor adds its own slice
 * (image-specific fields, collection/access-path fields, …). Contributors run in
 * tag-priority order (higher priority first), before post-alter normalization.
 */
interface KmassetDocContributorInterface {

  /**
   * Whether this contributor applies to the given node.
   */
  public function applies(NodeInterface $node): bool;

  /**
   * Adds this contributor's fields to the document, in place.
   *
   * @param array $doc
   *   The document being built (source fields only — never copyField
   *   aggregates). Modified by reference.
   * @param \Drupal\node\NodeInterface $node
   *   The source node.
   */
  public function contribute(array &$doc, NodeInterface $node): void;

}
