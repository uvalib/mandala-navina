<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * A transcript as the viewer needs it: metadata plus its units, in order.
 *
 * Units are plain arrays, not entities, so reading 1,380 of them does not load
 * 1,380 entities. Each is:
 *   id (int), sequence (int), start (float seconds), end (?float),
 *   end_original (?float), timecode_status (string),
 *   tiers (array<string, string>), speakers (array<string, string>).
 */
final readonly class TranscriptRead {

  /**
   * @param int $id
   *   The transcript id (D7 trid, and the kmassets trid_i).
   * @param int $nid
   *   The AV node.
   * @param string[] $tiers
   *   Tier names in display order.
   * @param array<int, array<string, mixed>> $units
   *   The units, ordered by sequence.
   */
  public function __construct(
    public int $id,
    public int $nid,
    public array $tiers,
    public bool $hasEdits,
    public array $units,
    public CacheableMetadata $cacheability,
  ) {}

}
