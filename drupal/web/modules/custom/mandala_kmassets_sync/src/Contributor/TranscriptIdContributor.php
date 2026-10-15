<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Contributor;

use Drupal\mandala_kmassets_sync\Transcript\TridResolverInterface;
use Drupal\node\NodeInterface;

/**
 * Writes `trid_i` on AV documents that have a transcript.
 *
 * The React client reads `kmap.trid_i` from the node's kmassets document and
 * queries the transcript core by `is_trid`; the solr-proxy access filter joins
 * the transcript core to kmassets on the same field (Spike 11, T9). No D11
 * document carried it before this contributor. Nodes without a transcript get
 * no field, as in D7.
 */
class TranscriptIdContributor implements KmassetDocContributorInterface {

  private const BUNDLES = ['audio', 'video'];

  public function __construct(
    private readonly TridResolverInterface $resolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return in_array($node->bundle(), self::BUNDLES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(array &$doc, NodeInterface $node): void {
    $trid = $this->resolver->resolve($node);
    if ($trid !== NULL && $trid > 0) {
      $doc['trid_i'] = $trid;
    }
  }

}
