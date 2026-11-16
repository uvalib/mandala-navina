<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Transcript;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves `trid` from the `av_transcript` entity (T2 phase 7).
 *
 * The entity id is the legacy `trid` (T5 keeps D7's ids; new transcripts start
 * at 100,000), and there is one transcript per node. A soft-deleted transcript
 * (ADR 019) does not resolve: otherwise `trid_i` would stay on the node's
 * document after the transcript was deleted, and the viewer would be pointed at
 * a transcript it may not read.
 *
 * Looked up by the node's own id, not by the legacy composite key: the entity
 * stores a real entity reference, which is correct in every environment
 * whatever its migration history.
 */
class EntityTridResolver implements TridResolverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(NodeInterface $node): ?int {
    if ($node->isNew()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('av_transcript');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('node', $node->id())
      ->condition('deleted', 0)
      ->range(0, 1)
      ->execute();
    $trid = $ids ? (int) reset($ids) : 0;
    return $trid > 0 ? $trid : NULL;
  }

}
