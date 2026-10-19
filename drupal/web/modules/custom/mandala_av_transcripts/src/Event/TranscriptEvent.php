<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * What changed, as plain ids: subscribers load what they need.
 *
 * Ids rather than entities because a delete event has no entity left to load,
 * and because events can be handed to queue workers in T4.
 */
final class TranscriptEvent extends Event {

  public function __construct(
    public readonly int $transcriptId,
    public readonly ?int $nodeId,
    public readonly ?int $unitId = NULL,
    public readonly bool $isNew = FALSE,
    public readonly ?int $previousNodeId = NULL,
  ) {}

}
