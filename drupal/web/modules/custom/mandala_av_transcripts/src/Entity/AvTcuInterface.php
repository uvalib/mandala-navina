<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionLogInterface;

/**
 * One time-coded unit (TCU) of an AV transcript.
 */
interface AvTcuInterface extends ContentEntityInterface, EntityChangedInterface, RevisionLogInterface {

  /**
   * The transcript this unit belongs to.
   */
  public function getTranscript(): ?AvTranscriptInterface;

  /**
   * Whether the unit is soft-deleted (ADR 019), on its own or with its transcript.
   */
  public function isDeleted(): bool;

  /**
   * Sets the soft-delete flag. A unit deleted on its own is not "with transcript".
   */
  public function setDeleted(bool $deleted): static;

  /**
   * Whether the unit was hidden by its transcript's soft-delete.
   *
   * Restoring the transcript revives exactly these units, so a unit an editor
   * deleted earlier stays deleted.
   */
  public function isDeletedWithTranscript(): bool;

  /**
   * Marks the unit as hidden by its transcript's soft-delete.
   */
  public function setDeletedWithTranscript(bool $withTranscript): static;

  /**
   * Tier name => text, in the order stored.
   *
   * @return array<string, string>
   */
  public function getTiers(): array;

  /**
   * Sets the tier map (tier name => text).
   *
   * @param array<string, string> $tiers
   */
  public function setTiers(array $tiers): static;

  /**
   * Speaker tier name => speaker value.
   *
   * @return array<string, string>
   */
  public function getSpeakers(): array;

  /**
   * Sets the speaker map (speaker tier name => value).
   *
   * @param array<string, string> $speakers
   */
  public function setSpeakers(array $speakers): static;

}
