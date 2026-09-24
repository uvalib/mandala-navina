<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Restore each subcollection's own D7 `group_access` where the migration
 * overwrote it with the parent's.
 *
 * `mandala_group_inheritance_group_presave()` copies the parent collection's
 * `field_group_access` onto every NEW subcollection (ADR 011's default). A
 * migration save is a new-entity save, so it overwrote the D7 value the
 * migration had just set: found 2026-09-24 comparing the 2026-09-01 prod AV
 * dump against D11 -- 15 AV subcollections were looser than D7 (10 private ->
 * public, 2 UVA-only -> public, 3 private -> UVA-only), exposing the ~971
 * member nodes that use "Use group defaults". In every case D11's value was
 * exactly the parent's. The hook is fixed to leave a migration's value alone;
 * this command repairs environments already migrated under the old hook.
 *
 * Where the D7 value differs from the parent's, also sets
 * `field_visibility_overridden` so a later change to the parent's visibility
 * does not propagate over it (ADR 011). It only ever turns the flag on.
 *
 * Group updates do NOT re-index member nodes' Solr docs. After a real run,
 * re-index the affected bundles (`drush kmassets:index-all audio` / `video`
 * / `shanti_image`) so search visibility matches.
 */
class SubcollectionAccessBackfillCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * field_legacy_site value => Migrate source connection key.
   */
  private const SITES = [
    'audio-video' => 'migrate_av',
    'images' => 'migrate',
  ];

  private const LABELS = [0 => 'public', 1 => 'private', 2 => 'UVA-only'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'group:backfill-subcollection-access')]
  #[CLI\Option(name: 'site', description: 'field_legacy_site to repair: audio-video (default) or images.')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving any group.')]
  #[CLI\Usage(name: 'drush group:backfill-subcollection-access --dry-run', description: 'Report AV subcollections that differ from D7.')]
  #[CLI\Usage(name: 'drush group:backfill-subcollection-access --site=images', description: 'Repair Images subcollections.')]
  public function backfill(array $options = ['site' => 'audio-video', 'dry-run' => FALSE]): void {
    $site = (string) $options['site'];
    $dryRun = (bool) $options['dry-run'];
    if (!isset(self::SITES[$site])) {
      throw new \InvalidArgumentException(sprintf('Unknown --site "%s"; expected one of: %s.', $site, implode(', ', array_keys(self::SITES))));
    }

    $source = Database::getConnection('default', self::SITES[$site]);
    $d7 = $source->query(
      "SELECT a.entity_id AS nid, a.group_access_value AS v
       FROM {field_data_group_access} a
       WHERE a.entity_type = 'node' AND a.bundle = 'subcollection' AND a.deleted = 0"
    )->fetchAllKeyed();

    $groups = $this->entityTypeManager->getStorage('group')->loadByProperties([
      'type' => 'subcollection',
      'field_legacy_site' => $site,
    ]);

    $changed = 0;
    $correct = 0;
    $noSource = 0;
    foreach ($groups as $group) {
      $nid = (int) $group->get('field_legacy_nid')->value;
      if (!isset($d7[$nid])) {
        $noSource++;
        continue;
      }
      $want = (int) $d7[$nid];
      $have = (int) $group->get('field_group_access')->value;

      $parent = $group->get('field_parent_collection')->entity;
      $parentValue = $parent ? (int) $parent->get('field_group_access')->value : NULL;
      $needsOverride = $parentValue !== NULL && $want !== $parentValue
        && !$group->get('field_visibility_overridden')->value;

      if ($have === $want && !$needsOverride) {
        $correct++;
        continue;
      }

      $this->logger()->notice('{id} (D7 nid {nid}) "{label}": {have} -> {want}{override}', [
        'id' => $group->id(),
        'nid' => $nid,
        'label' => $group->label(),
        'have' => self::LABELS[$have] ?? $have,
        'want' => self::LABELS[$want] ?? $want,
        'override' => $needsOverride ? ' (marking visibility overridden; parent is ' . (self::LABELS[$parentValue] ?? $parentValue) . ')' : '',
      ]);

      $group->set('field_group_access', $want);
      if ($needsOverride) {
        $group->set('field_visibility_overridden', TRUE);
      }
      if (!$dryRun) {
        // No new revision: this repairs a migration gap, it is not authoring.
        $group->save();
      }
      $changed++;
    }

    $this->logger()->success('{action} {count} subcollection(s) for {site}.', [
      'action' => $dryRun ? 'Would update' : 'Updated',
      'count' => $changed,
      'site' => $site,
    ]);
    $this->logger()->notice('{correct} already correct, {gap} had no D7 group_access row.', [
      'correct' => $correct,
      'gap' => $noSource,
    ]);
    if ($changed && !$dryRun) {
      $this->logger()->warning('Re-index affected member nodes so Solr visibility matches, e.g. `drush kmassets:index-all audio` / `video` / `shanti_image`.');
    }
  }

}
