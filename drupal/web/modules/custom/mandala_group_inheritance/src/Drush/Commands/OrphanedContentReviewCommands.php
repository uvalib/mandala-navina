<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\mandala_group_inheritance\Access\CollectionVisibility;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Creates and sweeps the orphaned-content review group.
 *
 * Decided 2026-10-02 (Than): a single parent "Orphaned Content" collection
 * holds one subcollection child per site (AV, Images, Texts, Sources). Both
 * are flagged `field_is_review_holding`, which GroupQueryAlter uses to
 * exclude them from every collection listing for every viewer, including
 * bypass -- see docs/deferred/orphaned-content-temp-group-on-migration.md.
 *
 * The parent/child groups are real site content, not config, so they are
 * created here rather than shipped in config/sync -- each environment
 * (DDEV, dev-0, and later staging/production) runs this once for itself,
 * the same way SubcollectionAccessBackfillCommands repairs environment
 * state rather than shipping a fixed answer as config.
 */
class OrphanedContentReviewCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * field_legacy_site value => child subcollection label.
   *
   * Matches SubcollectionAccessBackfillCommands::SITES' audio-video/images
   * keys, extended to the two not-yet-migrated sites so the structure is
   * ready when they land.
   */
  private const SITES = [
    'audio-video' => 'AV',
    'images' => 'Images',
    'texts' => 'Texts',
    'sources' => 'Sources',
  ];

  private const PARENT_LABEL = 'Orphaned Content (review holding)';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Creates the parent review group and its four per-site children.
   *
   * Idempotent: does nothing if a flagged parent already exists.
   */
  #[CLI\Command(name: 'group:create-review-holding-groups')]
  #[CLI\Usage(name: 'drush group:create-review-holding-groups', description: 'Create the parent + 4 child review-holding groups, if not already present.')]
  public function createReviewGroups(): void {
    $storage = $this->entityTypeManager->getStorage('group');

    $existing = $storage->loadByProperties([
      'type' => 'collection',
      'field_is_review_holding' => 1,
    ]);
    if ($existing) {
      $parent = reset($existing);
      $this->logger()->notice('Parent review group already exists (gid {gid}). Nothing to do.', ['gid' => $parent->id()]);
      return;
    }

    $parent = $storage->create([
      'type' => 'collection',
      'label' => self::PARENT_LABEL,
      // 1 = private on the group's own field_group_access scale (0 public, 1
      // private, 2 UVA) -- NOT CollectionVisibility::CODE_*, which is a
      // different scale for the node-override field. See
      // CollectionVisibility::groupVisibilityExpression().
      'field_group_access' => 1,
      'field_is_review_holding' => 1,
      'field_overview' => [
        'value' => 'Holds asset content found with no collection membership during migration. '
          . 'Not a real collection -- content here is pending per-site staff review (reassign to '
          . 'a real collection, or delete). See '
          . 'docs/deferred/orphaned-content-temp-group-on-migration.md.',
        'format' => 'basic_html',
      ],
    ]);
    $parent->save();
    $this->logger()->success('Created parent review group, gid {gid}.', ['gid' => $parent->id()]);

    foreach (self::SITES as $site => $label) {
      $child = $storage->create([
        'type' => 'subcollection',
        'label' => "Orphaned Content: $label",
        'field_group_access' => 1,
        'field_is_review_holding' => 1,
        'field_parent_collection' => ['target_id' => $parent->id()],
      ]);
      $child->save();
      $this->logger()->success('Created {label} child subcollection, gid {gid}.', [
        'label' => $label,
        'gid' => $child->id(),
      ]);
    }
  }

  /**
   * Sweeps a site's group-less nodes into its review-holding subcollection.
   *
   * Scope: only node bundles with a group_node plugin installed
   * (CollectionVisibility::groupNodeBundles()), matching field_legacy_site.
   * A node with its own field_group_content_access override keeps it --
   * CollectionVisibility::forNode() already prefers the node's own value
   * over the group's, so moving these into a private-by-default review
   * group does not loosen a restriction any node already carries.
   */
  #[CLI\Command(name: 'group:sweep-orphans')]
  #[CLI\Option(name: 'site', description: 'field_legacy_site to sweep: audio-video, images, texts, or sources.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving anything.')]
  #[CLI\Usage(name: 'drush group:sweep-orphans --site=audio-video --dry-run', description: 'Report AV nodes with no collection membership.')]
  #[CLI\Usage(name: 'drush group:sweep-orphans --site=audio-video', description: 'Move AV orphans into the AV review-holding group.')]
  public function sweep(array $options = ['site' => NULL, 'dry-run' => FALSE]): void {
    $site = (string) ($options['site'] ?? '');
    if (!isset(self::SITES[$site])) {
      throw new \InvalidArgumentException(sprintf('Unknown or missing --site "%s"; expected one of: %s.', $site, implode(', ', array_keys(self::SITES))));
    }
    $dryRun = (bool) $options['dry-run'];

    $child_groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
      'type' => 'subcollection',
      'field_is_review_holding' => 1,
      'label' => 'Orphaned Content: ' . self::SITES[$site],
    ]);
    if (!$child_groups) {
      throw new \RuntimeException('No review-holding child group found for this site. Run group:create-review-holding-groups first.');
    }
    $reviewGroup = reset($child_groups);

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $candidateIds = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', CollectionVisibility::groupNodeBundles(), 'IN')
      ->condition('field_legacy_site', $site)
      ->execute();

    $swept = 0;
    $alreadyGrouped = 0;
    foreach ($nodeStorage->loadMultiple($candidateIds) as $node) {
      if (CollectionVisibility::owningGroup($node) !== NULL) {
        $alreadyGrouped++;
        continue;
      }

      $this->logger()->notice('{action} node {nid} ("{title}") into {label}.', [
        'action' => $dryRun ? 'Would move' : 'Moving',
        'nid' => $node->id(),
        'title' => $node->label(),
        'label' => $reviewGroup->label(),
      ]);

      if (!$dryRun) {
        $reviewGroup->addRelationship($node, 'group_node:' . $node->bundle());
      }
      $swept++;
    }

    $this->logger()->success('{action} {count} orphan(s) for {site} into {label}.', [
      'action' => $dryRun ? 'Would sweep' : 'Swept',
      'count' => $swept,
      'site' => $site,
      'label' => $reviewGroup->label(),
    ]);
    $this->logger()->notice('{count} node(s) already had a collection and were left alone.', ['count' => $alreadyGrouped]);
    if ($swept && !$dryRun) {
      $this->logger()->warning('Re-index affected nodes so Solr visibility matches, e.g. `drush kmassets:index-all audio`.');
    }
  }

}
