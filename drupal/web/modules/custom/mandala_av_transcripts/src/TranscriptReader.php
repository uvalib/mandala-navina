<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;

/**
 * Reads a transcript and its units for display.
 *
 * Access is checked here, against the transcript (which asks the node), so no
 * caller can forget it. A transcript that does not exist, is soft-deleted,
 * whose node the account cannot view, or whose node is gone all return NULL: the caller
 * cannot tell them apart, so this is not an oracle for which nodes have a
 * hidden transcript.
 *
 * The units come from one query on the (transcript, sequence) index; the
 * optional time window uses the (transcript, start) index.
 */
class TranscriptReader {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Reads a transcript by id.
   *
   * @param float|int|null $from
   *   Only units starting at or after this many seconds.
   * @param float|int|null $to
   *   Only units starting at or before this many seconds.
   */
  public function read(int $trid, ?AccountInterface $account = NULL, float|int|null $from = NULL, float|int|null $to = NULL): ?TranscriptRead {
    $transcript = $this->entityTypeManager->getStorage('av_transcript')->load($trid);
    return $transcript instanceof AvTranscriptInterface ? $this->readEntity($transcript, $account, $from, $to) : NULL;
  }

  /**
   * Reads the transcript of a node, if it has one the account may see.
   */
  public function readForNode(int $nid, ?AccountInterface $account = NULL, float|int|null $from = NULL, float|int|null $to = NULL): ?TranscriptRead {
    $ids = $this->entityTypeManager->getStorage('av_transcript')
      ->getQuery()->accessCheck(FALSE)->condition('node', $nid)->condition('deleted', 0)->range(0, 1)->execute();
    return $ids ? $this->read((int) reset($ids), $account, $from, $to) : NULL;
  }

  /**
   * Reads an already-loaded transcript.
   */
  public function readEntity(AvTranscriptInterface $transcript, ?AccountInterface $account = NULL, float|int|null $from = NULL, float|int|null $to = NULL): ?TranscriptRead {
    $node = $transcript->getNode();
    // A soft-deleted transcript is gone for display, administrators included:
    // they manage it elsewhere, the viewer never shows it.
    if ($transcript->isDeleted() || $node === NULL || !$transcript->access('view', $account ?? $this->currentUser)) {
      return NULL;
    }

    $query = $this->database->select('av_tcu', 't')
      ->fields('t', ['id', 'sequence', 'start', 'end', 'end_original', 'timecode_status', 'tiers', 'speakers'])
      ->condition('t.transcript', $transcript->id())
      ->condition('t.deleted', 0);
    if ($from !== NULL) {
      $query->condition('t.start', (string) $from, '>=');
    }
    if ($to !== NULL) {
      $query->condition('t.start', (string) $to, '<=');
    }
    $query->orderBy('t.sequence')->orderBy('t.id');

    $units = [];
    foreach ($query->execute() as $row) {
      $units[] = [
        'id' => (int) $row->id,
        'sequence' => (int) $row->sequence,
        'start' => (float) $row->start,
        'end' => $row->end === NULL ? NULL : (float) $row->end,
        'end_original' => $row->end_original === NULL ? NULL : (float) $row->end_original,
        'timecode_status' => $row->timecode_status ?? 'ok',
        'tiers' => self::decodeMap($row->tiers),
        'speakers' => self::decodeMap($row->speakers),
      ];
    }

    // Depends on the transcript, its node (visibility, publication), the unit
    // list tag that unit saves and deletes invalidate, and the viewer.
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['av_transcript:' . $transcript->id(), 'node:' . $node->id(), AvTranscript::unitsCacheTag((int) $transcript->id())])
      ->addCacheContexts(['user.permissions', 'user']);

    return new TranscriptRead(
      (int) $transcript->id(),
      (int) $node->id(),
      $transcript->getTiers(),
      $transcript->hasEdits(),
      $units,
      $cacheability,
    );
  }

  /**
   * Decodes a JSON object column, tolerating NULL, empty and malformed values.
   *
   * @return array<string, string>
   */
  private static function decodeMap(?string $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
