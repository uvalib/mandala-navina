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
 *   ddev drush kmassets:index 5                    # index one node
 *   ddev drush kmassets:index-all                  # bulk index all configured bundles
 *   ddev drush kmassets:index-all shanti_image     # bulk index one bundle
 *   ddev drush kmassets:delete "uid:images-11-*"   # clean up all D11 test docs
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
   * Bulk index all published nodes of configured bundles to the kmassets Solr master.
   */
  #[CLI\Command(name: 'kmassets:index-all')]
  #[CLI\Argument(name: 'bundle', description: 'Bundle machine name to index (default: all configured bundles).')]
  #[CLI\Option(name: 'batch-size', description: 'Nodes loaded per batch.')]
  #[CLI\Usage(name: 'drush kmassets:index-all', description: 'Index all configured bundles.')]
  #[CLI\Usage(name: 'drush kmassets:index-all shanti_image', description: 'Index only shanti_image nodes.')]
  #[CLI\Usage(name: 'drush kmassets:index-all --batch-size=50', description: 'Index with smaller batches.')]
  public function indexAll(string $bundle = '', array $options = ['batch-size' => 100]): void {
    $bundles = $this->sink->configuredBundles();
    if ($bundle !== '') {
      if (!in_array($bundle, $bundles, TRUE)) {
        throw new \InvalidArgumentException("Bundle '$bundle' is not configured in mandala_kmassets_sync.settings.");
      }
      $bundles = [$bundle];
    }

    $batchSize = max(1, (int) $options['batch-size']);
    $storage = $this->entityTypeManager->getStorage('node');
    $indexed = $skipped = $errors = 0;

    foreach ($bundles as $b) {
      $this->logger()->info("Indexing bundle: {bundle}", ['bundle' => $b]);
      $offset = 0;

      do {
        $nids = $storage->getQuery()
          ->condition('type', $b)
          ->condition('status', 1)
          ->accessCheck(FALSE)
          ->sort('nid')
          ->range($offset, $batchSize)
          ->execute();

        foreach ($storage->loadMultiple($nids) as $node) {
          try {
            $uid = $this->sink->indexNode($node);
            $uid !== NULL ? $indexed++ : $skipped++;
          }
          catch (\Throwable $e) {
            $errors++;
            $this->logger()->error("Node {nid} failed: {msg}", [
              'nid' => $node->id(),
              'msg' => $e->getMessage(),
            ]);
          }
        }

        $offset += $batchSize;
        $this->logger()->info("  {indexed} indexed, {errors} errors so far...", [
          'indexed' => $indexed,
          'errors' => $errors,
        ]);
      } while (count($nids) === $batchSize);
    }

    $this->logger()->success(
      "Done: {indexed} indexed, {skipped} skipped, {errors} errors.",
      ['indexed' => $indexed, 'skipped' => $skipped, 'errors' => $errors],
    );
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
