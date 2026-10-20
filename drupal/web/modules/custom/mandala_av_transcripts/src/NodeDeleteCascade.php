<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_av_transcripts\Entity\AvTranscriptInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting an AV node soft-deletes its transcript (ADR 019).
 *
 * The transcript and its units are kept for administrators, with a snapshot of
 * the node taken at the last moment it can be read: its title, legacy key and
 * collections vanish with it, and an administrator reviewing the orphan needs
 * them. It is two steps: snapshot() from hook_node_predelete, cascade() from
 * hook_node_delete once the node is really gone. Group deletes the node's
 * relationship rows from the generic hook_entity_delete, which Drupal runs after
 * hook_node_delete, so reading the collections in hook_node_delete would work
 * today; predelete means this never depends on that ordering.
 *
 * Applies to every delete, migration rollback included. The transcript's id is
 * D7's trid, so a later import of the same transcript finds this row and
 * revives it instead of creating a duplicate.
 *
 * A failure here is logged and swallowed. It runs inside the node delete's
 * transaction, and a transcript problem must not stop an editor deleting a
 * node. The fallback is safe: a transcript whose node is gone is already
 * denied to everyone but administrators and unreadable by the reader.
 */
class NodeDeleteCascade {

  /**
   * Snapshots taken in snapshot(), keyed by node id.
   *
   * @var array<int, array{id: int, values: array<string, mixed>}>
   */
  private array $snapshots = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranscriptLifecycle $lifecycle,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * The id of the node's transcript, if it has one.
   */
  public function transcriptIdFor(int $nid): ?int {
    $id = $this->database->select('av_transcript', 't')
      ->fields('t', ['id'])
      ->condition('t.node', $nid)
      ->execute()
      ->fetchField();
    return $id === FALSE ? NULL : (int) $id;
  }

  /**
   * Records what the node is, before it is deleted.
   */
  public function snapshot(NodeInterface $node): void {
    try {
      $trid = $this->transcriptIdFor((int) $node->id());
      if ($trid === NULL) {
        return;
      }
      $this->snapshots[(int) $node->id()] = [
        'id' => $trid,
        'values' => [
          'node_title' => mb_substr((string) $node->label(), 0, 255),
          'node_legacy_site' => $node->hasField('field_legacy_site') ? ($node->get('field_legacy_site')->value ?: NULL) : NULL,
          'node_legacy_nid' => $node->hasField('field_legacy_nid') ? ($node->get('field_legacy_nid')->value ?: NULL) : NULL,
          'node_collections' => json_encode($this->collectionIds($node)),
        ],
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not snapshot node @nid before deleting its transcript: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Soft-deletes the node's transcript, once the node is gone.
   */
  public function cascade(NodeInterface $node): void {
    $nid = (int) $node->id();
    $snapshot = $this->snapshots[$nid] ?? NULL;
    unset($this->snapshots[$nid]);
    if ($snapshot === NULL) {
      return;
    }
    try {
      $transcript = $this->entityTypeManager->getStorage('av_transcript')->load($snapshot['id']);
      if (!$transcript instanceof AvTranscriptInterface) {
        return;
      }
      if ($transcript->isDeleted()) {
        // Already soft-deleted on its own: keep when and by whom, add the node.
        foreach ($snapshot['values'] as $field => $value) {
          $transcript->set($field, $value);
        }
        $transcript->save();
        return;
      }
      $this->lifecycle->softDelete($transcript, 'Soft-deleted: its AV node was deleted.', $snapshot['values']);
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not soft-delete transcript @id after node @nid was deleted: @message', [
        '@id' => $snapshot['id'],
        '@nid' => $nid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Ids of the groups (collections) the node belongs to; none without Group.
   *
   * @return int[]
   */
  private function collectionIds(NodeInterface $node): array {
    if (!$this->entityTypeManager->hasDefinition('group_relationship')) {
      return [];
    }
    $ids = [];
    foreach ($this->entityTypeManager->getStorage('group_relationship')->loadByEntity($node) as $relationship) {
      $ids[(int) $relationship->getGroupId()] = (int) $relationship->getGroupId();
    }
    ksort($ids);
    return array_values($ids);
  }

}
