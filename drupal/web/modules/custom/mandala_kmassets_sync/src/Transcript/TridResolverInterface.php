<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Transcript;

use Drupal\node\NodeInterface;

/**
 * Resolves the transcript id (`trid`) the React client uses for an AV node.
 *
 * The client finds a node's transcript through `trid_i` on the node's kmassets
 * document, then queries the transcript core by `is_trid` (Spike 11). The
 * source of that id is deliberately behind this interface: it came from a table
 * loaded from D7 until T2 phase 7, and EntityTridResolver now reads the
 * `av_transcript` entity. TranscriptIdContributor did not change.
 */
interface TridResolverInterface {

  /**
   * The transcript id for the node, or NULL when it has none.
   *
   * Must be a positive integer: `trid_i` is a Solr int, and the client queries
   * it as one.
   */
  public function resolve(NodeInterface $node): ?int;

}
