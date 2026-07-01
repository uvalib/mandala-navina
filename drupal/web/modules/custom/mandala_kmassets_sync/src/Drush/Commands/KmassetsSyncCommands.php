<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_kmassets_sync\KmassetDirectSink;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for driving the kmassets direct sink (1a.8).
 *
 * Usage:
 *   ddev drush kmassets:index 5                   # index node 5
 *   ddev drush kmassets:delete "uid:images-11-*"  # clean up all D11 test docs
 */
class KmassetsSyncCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KmassetDirectSink $sink,
  ) {
    parent::__construct();
  }

  /**
   * Index a single node to the kmassets Solr master.
   */
  #[CLI\Command(name: 'kmassets:index')]
  #[CLI\Argument(name: 'nid', description: 'Node ID to index.')]
  #[CLI\Usage(name: 'drush kmassets:index 5', description: 'Index node 5 to the kmassets Solr master.')]
  public function index(int $nid): void {
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node) {
      throw new \InvalidArgumentException("Node $nid not found.");
    }
    $uid = $this->sink->indexNode($node);
    if ($uid !== NULL) {
      $this->logger()->success("Indexed {uid}.", ['uid' => $uid]);
    }
    else {
      $this->logger()->warning("Node {nid} skipped (unpublished or bundle not configured).", ['nid' => $nid]);
    }
  }

  /**
   * Delete kmassets docs matching a Solr query from the master.
   */
  #[CLI\Command(name: 'kmassets:delete')]
  #[CLI\Argument(name: 'query', description: 'Solr query, e.g. "uid:images-11-*"')]
  #[CLI\Usage(name: 'drush kmassets:delete "uid:images-11-*"', description: 'Delete all D11 test docs from kmassets.')]
  public function delete(string $query): void {
    $this->sink->deleteByQuery($query);
    $this->logger()->success("Deleted docs matching: {query}", ['query' => $query]);
  }

}
