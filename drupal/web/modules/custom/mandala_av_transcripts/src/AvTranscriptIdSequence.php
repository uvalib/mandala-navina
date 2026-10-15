<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Database\Connection;

/**
 * Moves an entity table's id allocation clear of the D7 id range.
 *
 * The migration sets D7 ids explicitly (trid, tcuid), so the database sequence
 * only ever allocates ids for transcripts and units created in D11. Starting it
 * high keeps those apart from D7's range, which is still growing while D7 is in
 * production. MySQL/MariaDB only; elsewhere this is a no-op (the sequence of
 * those drivers is not an AUTO_INCREMENT counter).
 */
class AvTranscriptIdSequence {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Ensures the next allocated id in $table is at least $floor.
   */
  public function reserve(string $table, int $floor): void {
    if ($this->database->driver() !== 'mysql') {
      return;
    }
    $max = (int) $this->database->query("SELECT MAX(id) FROM {" . $table . "}")->fetchField();
    $next = max($floor, $max + 1);
    $this->database->query("ALTER TABLE {" . $table . "} AUTO_INCREMENT = " . $next);
  }

}
