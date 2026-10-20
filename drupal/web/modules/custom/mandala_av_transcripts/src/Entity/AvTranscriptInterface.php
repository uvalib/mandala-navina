<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\node\NodeInterface;

/**
 * A transcript of one AV node.
 */
interface AvTranscriptInterface extends ContentEntityInterface, EntityChangedInterface, RevisionLogInterface {

  /**
   * The AV node this transcript belongs to.
   */
  public function getNode(): ?NodeInterface;

  /**
   * Whether the transcript is soft-deleted (ADR 019).
   *
   * Change it through the lifecycle service, which also flags the units.
   */
  public function isDeleted(): bool;

  /**
   * Sets the soft-delete flag on this transcript only.
   */
  public function setDeleted(bool $deleted): static;

  /**
   * Whether staff have edited the transcript since upload (D7 keep_transcript_edits).
   */
  public function hasEdits(): bool;

  /**
   * The tier names this transcript uses, in display order.
   *
   * @return string[]
   */
  public function getTiers(): array;

  /**
   * Sets the tier names.
   *
   * @param string[] $tiers
   */
  public function setTiers(array $tiers): static;

}
