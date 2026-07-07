<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Audits the kmassets Solr index against Drupal node state (1a.9).
 *
 * The direct sink (1a.8) keeps the index in sync via node lifecycle hooks, but
 * a hook can fire against an unreachable Solr master and fail silently. This
 * auditor detects the drift that leaves behind. Three discrepancy classes:
 *
 *  - MISSING  — a published node has no doc in Solr (a write was lost).
 *  - STALE    — a doc exists but its node_changed lags the node's changed
 *               timestamp (an update was lost).
 *  - ORPHANED — a doc exists for a node that is deleted or unpublished (a
 *               delete/unpublish was lost).
 *
 * Missing and stale are found in one Drupal-driven pass (batch the published
 * nodes, ask Solr which uids it holds and when they changed). Orphaned is the
 * reverse: cursor-page every D11 doc for the service and flag any whose uid is
 * not in the published set. The stale check is the expensive half (it fetches
 * node_changed for every doc), so it is opt-in.
 *
 * Scope: a staging/ops validation tool, not a hot path. It holds the set of
 * published uids for the audited services in memory (one short string per node)
 * so the orphan pass needs no extra Drupal round-trips.
 */
class KmassetAuditor {

  /**
   * Solr cursor page size for the orphan pass.
   */
  protected const CURSOR_ROWS = 1000;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly KmassetDirectSink $sink,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Audits the configured bundles and optionally repairs the discrepancies.
   *
   * @param string $bundleFilter
   *   A single bundle machine name to audit, or '' for all configured bundles.
   * @param bool $checkStale
   *   Whether to run the (more expensive) stale check.
   * @param bool $fix
   *   Whether to repair discrepancies: reindex missing/stale nodes, delete
   *   orphaned docs.
   * @param int $batchSize
   *   Nodes loaded per batch in the Drupal-driven pass.
   *
   * @return array
   *   Report array: keys `missing` (uid list), `stale` (uid => [solr, drupal]),
   *   `orphaned` (uid list), `checked_nodes`, `checked_docs`, and `fixed`
   *   (['indexed' => int, 'deleted' => int]).
   */
  public function audit(string $bundleFilter = '', bool $checkStale = FALSE, bool $fix = FALSE, int $batchSize = 200): array {
    $bundles = $this->bundlesToAudit($bundleFilter);

    $report = [
      'missing' => [],
      'stale' => [],
      'orphaned' => [],
      'checked_nodes' => 0,
      'checked_docs' => 0,
      'fixed' => ['indexed' => 0, 'deleted' => 0],
    ];

    // The set of every published-node uid across the audited bundles, keyed for
    // O(1) lookup in the orphan pass. uid => nid.
    $publishedUids = [];

    // Pass A — Drupal → Solr: missing + (optionally) stale.
    foreach ($bundles as $bundle => $service) {
      foreach ($this->publishedNodeBatches($bundle, $batchSize) as $nodes) {
        // uid => node, and uid => expected node_changed epoch.
        $expected = [];
        foreach ($nodes as $node) {
          $uid = $service . '-11-' . $node->id();
          $publishedUids[$uid] = (int) $node->id();
          $expected[$uid] = $node;
          $report['checked_nodes']++;
        }

        $present = $this->solrChangedFor(array_keys($expected), $checkStale);

        foreach ($expected as $uid => $node) {
          if (!array_key_exists($uid, $present)) {
            $report['missing'][] = $uid;
            continue;
          }
          if ($checkStale) {
            $solrEpoch = $present[$uid];
            if ($solrEpoch !== NULL && $solrEpoch < (int) $node->getChangedTime()) {
              $report['stale'][$uid] = [
                'solr' => gmdate('Y-m-d\TH:i:s\Z', $solrEpoch),
                'drupal' => gmdate('Y-m-d\TH:i:s\Z', (int) $node->getChangedTime()),
              ];
            }
          }
        }
      }
    }

    // Pass B — Solr → Drupal: orphaned. One cursor sweep per distinct service.
    foreach (array_unique(array_values($bundles)) as $service) {
      foreach ($this->solrUidCursor($service) as $uid) {
        $report['checked_docs']++;
        if (!isset($publishedUids[$uid])) {
          $report['orphaned'][] = $uid;
        }
      }
    }

    if ($fix) {
      $report['fixed'] = $this->repair($report);
    }

    return $report;
  }

  /**
   * Resolves the bundle => service map to audit.
   *
   * @throws \InvalidArgumentException
   *   If a named bundle is not configured.
   */
  protected function bundlesToAudit(string $bundleFilter): array {
    $configured = $this->configFactory->get('mandala_kmassets_sync.settings')->get('bundles') ?? [];
    $map = [];
    foreach ($configured as $bundle => $config) {
      $map[$bundle] = $config['service'];
    }
    if ($bundleFilter !== '') {
      if (!isset($map[$bundleFilter])) {
        throw new \InvalidArgumentException("Bundle '$bundleFilter' is not configured in mandala_kmassets_sync.settings.");
      }
      return [$bundleFilter => $map[$bundleFilter]];
    }
    return $map;
  }

  /**
   * Yields batches of published nodes for a bundle, ordered by nid.
   *
   * @return \Generator<\Drupal\node\NodeInterface[]>
   */
  protected function publishedNodeBatches(string $bundle, int $batchSize): \Generator {
    $storage = $this->entityTypeManager->getStorage('node');
    $offset = 0;
    do {
      $nids = $storage->getQuery()
        ->condition('type', $bundle)
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->sort('nid')
        ->range($offset, $batchSize)
        ->execute();
      if ($nids) {
        yield $storage->loadMultiple($nids);
      }
      $offset += $batchSize;
    } while (count($nids) === $batchSize);
  }

  /**
   * Asks Solr which of the given uids exist, and (optionally) their change time.
   *
   * @param string[] $uids
   *   Candidate uids to look up.
   * @param bool $withChanged
   *   Whether to fetch node_changed (needed only for the stale check).
   *
   * @return array
   *   uid => node_changed epoch (or NULL when not fetched), for present uids
   *   only. Absent uids are not in the map.
   */
  protected function solrChangedFor(array $uids, bool $withChanged): array {
    if (!$uids) {
      return [];
    }
    $clauses = array_map(static fn($uid) => 'uid:' . $uid, $uids);
    $params = [
      'q' => implode(' OR ', $clauses),
      'fl' => $withChanged ? 'uid,node_changed' : 'uid',
      'rows' => count($uids),
    ];
    $response = $this->sink->select($params);
    $docs = $response['response']['docs'] ?? [];

    $present = [];
    foreach ($docs as $doc) {
      $uid = $doc['uid'] ?? NULL;
      if ($uid === NULL) {
        continue;
      }
      $changed = NULL;
      if ($withChanged && !empty($doc['node_changed'])) {
        $ts = strtotime((string) $doc['node_changed']);
        $changed = $ts === FALSE ? NULL : $ts;
      }
      $present[$uid] = $changed;
    }
    return $present;
  }

  /**
   * Cursor-pages every D11 uid for a service.
   *
   * Uses Solr's cursorMark deep-paging (stable, no start-offset drift). The sort
   * must be on the kmassets uniqueKey — which is `uid`, not `id` — since
   * cursorMark requires a uniqueKey tie-breaker in the sort.
   *
   * @return \Generator<string>
   *   Each D11 uid (e.g. images-11-42) in the index for this service.
   */
  protected function solrUidCursor(string $service): \Generator {
    $cursor = '*';
    do {
      $response = $this->sink->select([
        'q' => 'uid:' . $service . '-11-*',
        'fl' => 'uid',
        'rows' => self::CURSOR_ROWS,
        'sort' => 'uid asc',
        'cursorMark' => $cursor,
      ]);
      foreach ($response['response']['docs'] ?? [] as $doc) {
        if (!empty($doc['uid'])) {
          yield $doc['uid'];
        }
      }
      $next = $response['nextCursorMark'] ?? $cursor;
      $done = ($next === $cursor);
      $cursor = $next;
    } while (!$done);
  }

  /**
   * Repairs the discrepancies in a report: reindex missing/stale, delete orphans.
   *
   * @return array
   *   ['indexed' => int, 'deleted' => int].
   */
  protected function repair(array $report): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $indexed = 0;

    // Reindex missing + stale nodes (nid parsed from the uid tail).
    $toReindex = array_merge($report['missing'], array_keys($report['stale']));
    $nids = [];
    foreach ($toReindex as $uid) {
      $nid = $this->nidFromUid($uid);
      if ($nid !== NULL) {
        $nids[] = $nid;
      }
    }
    foreach (array_chunk($nids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if ($this->sink->indexNode($node) !== NULL) {
          $indexed++;
        }
      }
    }

    // Delete orphaned docs by uid, batched into OR queries.
    $deleted = 0;
    foreach (array_chunk($report['orphaned'], 200) as $chunk) {
      $query = implode(' OR ', array_map(static fn($uid) => 'uid:' . $uid, $chunk));
      $this->sink->deleteByQuery($query);
      $deleted += count($chunk);
    }

    return ['indexed' => $indexed, 'deleted' => $deleted];
  }

  /**
   * Extracts the node id from a D11 uid ({service}-11-{nid}).
   */
  protected function nidFromUid(string $uid): ?int {
    if (preg_match('/-11-(\d+)$/', $uid, $m) === 1) {
      return (int) $m[1];
    }
    return NULL;
  }

}
