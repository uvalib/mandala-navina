<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_kmassets_sync\KmassetDirectSink;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for driving the kmassets direct sink (1a.8).
 *
 * Usage:
 *   ddev drush kmassets:index 5          # index node 5
 *   ddev drush kmassets:delete "uid:images-11-*"  # clean up all D11 test docs
 */
class KmassetsSyncCommands extends DrushCommands {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly KmassetDirectSink $sink,
  ) {
    parent::__construct();
  }

  /**
   * Index a single node to the kmassets Solr master.
   *
   * @param int $nid
   *   Node ID to index.
   *
   * @command kmassets:index
   * @usage drush kmassets:index 5
   */
  public function index(int $nid): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }
    $indexed = $this->sink->indexNode($node);
    if ($indexed) {
      $this->logger()->success("Indexed node {nid} ({uid}).", [
        'nid' => $nid,
        'uid' => $node->bundle() . '-11-' . $nid,
      ]);
    }
    else {
      $this->logger()->warning("Node {nid} skipped (unpublished or bundle not configured).", ['nid' => $nid]);
    }
  }

  /**
   * Delete kmassets docs matching a Solr query from the master.
   *
   * @param string $query
   *   Solr query string, e.g. "uid:images-11-*"
   *
   * @command kmassets:delete
   * @usage drush kmassets:delete "uid:images-11-*"
   */
  public function delete(string $query): void {
    $this->sink->deleteByQuery($query);
    $this->logger()->success("Deleted docs matching: {query}", ['query' => $query]);
  }

}
