<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;

/**
 * Finds, restores, exports and purges soft-deleted transcripts (ADR 019).
 *
 * The one place the orphan logic lives; the drush commands are a thin layer
 * over it, and so would an admin screen be. It has no access check of its own:
 * callers are shell commands, which need server access, and any future screen
 * must gate on 'administer av transcripts'.
 *
 * Two kinds of orphan. One whose node still exists can be restored. One whose
 * node was deleted cannot: core has no node undelete, and re-attaching to
 * another node is deferred. Both can be exported and purged.
 */
class OrphanManager {

  public const FORMATS = ['json', 'srt', 'vtt'];

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranscriptLifecycle $lifecycle,
  ) {}

  /**
   * Lists soft-deleted transcripts, oldest delete first.
   *
   * @return array<int, array<string, mixed>>
   *   Keyed by transcript id. Each row has: id, state ('node_gone' or
   *   'node_exists'), node (id or NULL), node_title, legacy_site, legacy_nid,
   *   collections (int[]), deleted_at, deleted_by, units, untimed_or_bad
   *   (units whose timecode_status is not 'ok').
   */
  public function list(): array {
    $query = $this->database->select('av_transcript', 't');
    $query->leftJoin('node_field_data', 'n', 'n.nid = t.node AND n.default_langcode = 1');
    $query->fields('t', ['id', 'node', 'node_title', 'node_legacy_site', 'node_legacy_nid', 'node_collections', 'deleted_at', 'deleted_by']);
    $query->addField('n', 'nid', 'live_nid');
    $query->addField('n', 'title', 'live_title');
    $query->condition('t.deleted', 1)->orderBy('t.deleted_at')->orderBy('t.id');
    $rows = [];
    foreach ($query->execute() as $row) {
      $collections = json_decode((string) $row->node_collections, TRUE);
      $rows[(int) $row->id] = [
        'id' => (int) $row->id,
        'state' => $row->live_nid === NULL ? 'node_gone' : 'node_exists',
        'node' => $row->live_nid === NULL ? NULL : (int) $row->live_nid,
        // The snapshot is taken when the node is deleted; until then the live title.
        'node_title' => $row->node_title ?? $row->live_title,
        'legacy_site' => $row->node_legacy_site,
        'legacy_nid' => $row->node_legacy_nid === NULL ? NULL : (int) $row->node_legacy_nid,
        'collections' => is_array($collections) ? $collections : [],
        'deleted_at' => $row->deleted_at === NULL ? NULL : (int) $row->deleted_at,
        'deleted_by' => $row->deleted_by === NULL ? NULL : (int) $row->deleted_by,
        'units' => 0,
        'untimed_or_bad' => 0,
      ];
    }
    if ($rows) {
      $counts = $this->database->select('av_tcu', 'u');
      $counts->addField('u', 'transcript');
      $counts->addExpression('COUNT(*)', 'units');
      $counts->addExpression("SUM(CASE WHEN u.timecode_status IS NULL OR u.timecode_status <> 'ok' THEN 1 ELSE 0 END)", 'bad');
      $counts->condition('u.transcript', array_keys($rows), 'IN')->groupBy('u.transcript');
      foreach ($counts->execute() as $count) {
        $rows[(int) $count->transcript]['units'] = (int) $count->units;
        $rows[(int) $count->transcript]['untimed_or_bad'] = (int) $count->bad;
      }
    }
    return $rows;
  }

  /**
   * Restores a soft-deleted transcript whose node still exists.
   *
   * @throws \InvalidArgumentException
   *   If it is not a soft-deleted transcript, or its node is gone.
   */
  public function restore(int $id): void {
    $transcript = $this->loadDeleted($id);
    if ($transcript->getNode() === NULL) {
      throw new \InvalidArgumentException("Transcript $id cannot be restored: its node no longer exists. Export it, or leave it for re-attach (deferred).");
    }
    $this->lifecycle->restore($transcript);
  }

  /**
   * Exports a soft-deleted transcript's units, in sequence order.
   *
   * json keeps everything (the faithful copy to take before a purge). srt and
   * vtt are for reading: a unit with no end, or an end before its start, is
   * written with end = start, and the tier text is joined by newlines.
   *
   * @throws \InvalidArgumentException
   */
  public function export(int $id, string $format = 'json'): string {
    if (!in_array($format, self::FORMATS, TRUE)) {
      throw new \InvalidArgumentException("Unknown format '$format'; use " . implode(', ', self::FORMATS) . '.');
    }
    $transcript = $this->loadDeleted($id);
    $units = [];
    $result = $this->database->select('av_tcu', 'u')
      ->fields('u', ['id', 'sequence', 'start', 'end', 'end_original', 'timecode_status', 'tiers', 'speakers', 'deleted', 'deleted_with_transcript'])
      ->condition('u.transcript', $id)
      ->orderBy('u.sequence')->orderBy('u.id')
      ->execute();
    foreach ($result as $row) {
      $units[] = [
        'id' => (int) $row->id,
        'sequence' => (int) $row->sequence,
        'start' => (float) $row->start,
        'end' => $row->end === NULL ? NULL : (float) $row->end,
        'end_original' => $row->end_original === NULL ? NULL : (float) $row->end_original,
        'timecode_status' => $row->timecode_status ?? 'ok',
        'tiers' => self::decodeMap($row->tiers),
        'speakers' => self::decodeMap($row->speakers),
        'deleted_on_its_own' => (bool) $row->deleted && !$row->deleted_with_transcript,
      ];
    }
    if ($format === 'json') {
      $row = $this->list()[$id] ?? ['id' => $id];
      return json_encode(['transcript' => $row, 'tiers' => $transcript->getTiers(), 'units' => $units], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    $out = $format === 'vtt' ? "WEBVTT\n\n" : '';
    foreach ($units as $n => $unit) {
      $end = ($unit['end'] === NULL || $unit['end'] < $unit['start']) ? $unit['start'] : $unit['end'];
      $text = implode("\n", array_filter(array_map('strval', $unit['tiers']), static fn (string $t): bool => $t !== ''));
      $out .= ($format === 'srt' ? ($n + 1) . "\n" : '')
        . self::timestamp($unit['start'], $format) . ' --> ' . self::timestamp($end, $format) . "\n"
        . $text . "\n\n";
    }
    return $out;
  }

  /**
   * Hard-deletes soft-deleted transcripts with their units and all revisions.
   *
   * This is the only operation that removes data. Refuses anything not already
   * soft-deleted, so a live transcript can never be purged by mistake.
   *
   * @param int[] $ids
   *
   * @return array<int, int>
   *   Units removed, keyed by transcript id.
   *
   * @throws \InvalidArgumentException
   *   Before anything is deleted, if any id is not a soft-deleted transcript.
   */
  public function purge(array $ids): array {
    $transcripts = [];
    foreach ($ids as $id) {
      $transcripts[(int) $id] = $this->loadDeleted((int) $id);
    }
    $removed = [];
    foreach ($transcripts as $id => $transcript) {
      $transaction = $this->database->startTransaction();
      try {
        $removed[$id] = 0;
        // Unit tables are written directly: a per-unit entity delete would
        // fire an event and a cache invalidation for each of thousands.
        // 'transcript' is not revisionable, so revisions are found by unit id.
        $unitIds = $this->database->select('av_tcu', 'u')->fields('u', ['id'])->condition('u.transcript', $id)->execute()->fetchCol();
        foreach (array_chunk($unitIds, 500) as $chunk) {
          $this->database->delete('av_tcu_revision')->condition('id', $chunk, 'IN')->execute();
          $removed[$id] += (int) $this->database->delete('av_tcu')->condition('id', $chunk, 'IN')->execute();
        }
        // The entity delete removes the transcript's revisions and tells
        // subscribers (one TRANSCRIPT_DELETED, harmless after the soft one).
        $transcript->delete();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    $this->entityTypeManager->getStorage('av_tcu')->resetCache();
    return $removed;
  }

  private function loadDeleted(int $id): AvTranscriptInterface {
    $this->entityTypeManager->getStorage('av_transcript')->resetCache([$id]);
    $transcript = $this->entityTypeManager->getStorage('av_transcript')->load($id);
    if (!$transcript instanceof AvTranscriptInterface || !$transcript->isDeleted()) {
      throw new \InvalidArgumentException("Transcript $id is not a soft-deleted transcript.");
    }
    return $transcript;
  }

  private static function timestamp(float $seconds, string $format): string {
    $ms = (int) round($seconds * 1000);
    return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $format === 'srt' ? ',' : '.', $ms % 1000);
  }

  /**
   * @return array<string, string>
   */
  private static function decodeMap(?string $raw): array {
    $decoded = ($raw === NULL || $raw === '') ? [] : json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
