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
