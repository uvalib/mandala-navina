<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_group_inheritance\Access\CollectionVisibility;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Sweeps group-less asset nodes into an admin-created review group.
 *
 * Decided 2026-10-02 (Than): the orphaned-content review group(s) are
 * created by hand by admin editors through the normal Group UI, not by
 * this module. This command only does the sweep -- an operator creates a
 * group (or reuses one), flags it `field_is_review_holding` on its edit
 * form so GroupQueryAlter excludes it from every collection listing (see
 * docs/deferred/orphaned-content-temp-group-on-migration.md), notes its
 * group id, then runs this command with that --gid.
 *
 * A node's own field_group_content_access override is left alone --
 * CollectionVisibility::forNode() already prefers the node's own value
 * over the group's, so moving a node into the review group never loosens
 * a restriction it already carries.
 */
class OrphanedContentReviewCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * field_legacy_site values this module knows how to sweep.
   *
   * Matches SubcollectionAccessBackfillCommands::SITES' keys, extended to
   * the two not-yet-migrated sites so --site (or sweeping all of them) is
   * ready when they land -- a not-yet-migrated site simply contributes 0
   * candidate nodes.
   */
  private const SITES = ['audio-video', 'images', 'texts', 'sources'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Moves a site's (or every site's) group-less nodes into a given group.
   *
   * Scope: only node bundles with a group_node plugin installed
   * (CollectionVisibility::groupNodeBundles()), matching field_legacy_site.
   */
  #[CLI\Command(name: 'group:sweep-orphans', aliases: ['sweep-orphans'])]
  #[CLI\Option(name: 'site', description: 'field_legacy_site to sweep: audio-video, images, texts, or sources. Omit to sweep every known site into the same --gid.')]
  #[CLI\Option(name: 'gid', description: 'Group id to move orphans into. Required -- create or choose the target group by hand first.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving anything.')]
  #[CLI\Usage(name: 'drush group:sweep-orphans --site=audio-video --gid=415 --dry-run', description: 'Report AV nodes with no collection membership.')]
  #[CLI\Usage(name: 'drush group:sweep-orphans --site=audio-video --gid=415', description: 'Move AV orphans into group 415.')]
  #[CLI\Usage(name: 'drush sweep-orphans --gid=415', description: 'Move every known site\'s orphans into group 415.')]
  public function sweep(array $options = ['site' => NULL, 'gid' => NULL, 'dry-run' => FALSE]): void {
    $site = $options['site'] !== NULL ? (string) $options['site'] : NULL;
    if ($site !== NULL && !in_array($site, self::SITES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown --site "%s"; expected one of: %s.', $site, implode(', ', self::SITES)));
    }
    $sitesToSweep = $site !== NULL ? [$site] : self::SITES;

    $gid = $options['gid'] !== NULL ? (int) $options['gid'] : NULL;
    if (!$gid) {
      throw new \InvalidArgumentException('--gid is required: the id of the group to move orphans into. Create or choose it by hand first.');
    }
    $reviewGroup = $this->entityTypeManager->getStorage('group')->load($gid);
    if (!$reviewGroup) {
      throw new \InvalidArgumentException(sprintf('No group with id %d exists.', $gid));
    }
    if (!$reviewGroup->get('field_is_review_holding')->value) {
      $this->logger()->warning('Group {gid} ("{label}") is not flagged field_is_review_holding. It will still receive the swept nodes, but it will appear as a normal collection in /collections listings unless you set the flag.', [
        'gid' => $gid,
        'label' => $reviewGroup->label(),
      ]);
    }

    $dryRun = (bool) $options['dry-run'];
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $swept = 0;
    $alreadyGrouped = 0;
    // Sites like Images run past 100k nodes; loadMultiple() on every
    // candidate id at once is the memory-exhaustion path. Batch instead, and
    // reset the static entity cache each round -- otherwise the cache itself
    // grows unbounded across batches and the OOM just moves later.
    $batchSize = 500;
    foreach ($sitesToSweep as $siteToSweep) {
      $candidateIds = $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', CollectionVisibility::groupNodeBundles(), 'IN')
        ->condition('field_legacy_site', $siteToSweep)
        ->execute();

      foreach (array_chunk($candidateIds, $batchSize) as $batch) {
        foreach ($nodeStorage->loadMultiple($batch) as $node) {
          if (CollectionVisibility::owningGroup($node) !== NULL) {
            $alreadyGrouped++;
            continue;
          }

          $this->logger()->notice('{action} node {nid} ("{title}", site {site}) into {label} (gid {gid}).', [
            'action' => $dryRun ? 'Would move' : 'Moving',
            'nid' => $node->id(),
            'title' => $node->label(),
            'site' => $siteToSweep,
            'label' => $reviewGroup->label(),
            'gid' => $gid,
          ]);

          if (!$dryRun) {
            $reviewGroup->addRelationship($node, 'group_node:' . $node->bundle());
          }
          $swept++;
        }
        $nodeStorage->resetCache($batch);
      }
    }

    $this->logger()->success('{action} {count} orphan(s) ({sites}) into {label} (gid {gid}).', [
      'action' => $dryRun ? 'Would sweep' : 'Swept',
      'count' => $swept,
      'sites' => implode(', ', $sitesToSweep),
      'label' => $reviewGroup->label(),
      'gid' => $gid,
    ]);
    $this->logger()->notice('{count} node(s) already had a collection and were left alone.', ['count' => $alreadyGrouped]);
    if ($swept && !$dryRun) {
      $this->logger()->warning('Re-index affected nodes so Solr visibility matches, e.g. `drush kmassets:index-all audio`.');
    }
  }

}
