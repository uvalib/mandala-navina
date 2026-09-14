<?php

declare(strict_types=1);

namespace Drupal\mandala_group_inheritance\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Audits and repairs group role permission drift across content plugins.
 *
 * Found 2026-09-14: Group's own view/CRUD permissions are granted per
 * (group type, content plugin) pair, config-only -- installing a new
 * `group_node:*` plugin on a group type (e.g. adding `audio`/`video` to
 * `collection`/`subcollection` in AV4) auto-generates its permission
 * *strings* but grants them to nobody. `shanti_image` had a working shape;
 * `audio`/`video` silently had none, making every grouped AV node invisible
 * to every role -- including a site administrator, since Group's own
 * per-plugin permission check does not defer to `bypass node access` or
 * `bypass group access` the way core's node access system does. See the
 * 2026-09-14 session log.
 *
 * This is a structural gap, not a one-off: it will recur for every future
 * bundle added to a Group-enabled content type (Sources, Texts, ...) unless
 * something enforces the invariant "every group_node:* plugin on a group
 * type gets the same permission shape as every other one, per role" going
 * forward. Rather than hardcode `shanti_image` as a template, this derives
 * the canonical shape per (group type, role) from whatever plugins already
 * have permissions granted -- so it stays correct as new plugins are added,
 * with no code change here.
 */
class GroupContentPermissionAuditCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Matches a group_node:* permission string, capturing the bundle.
   *
   * Deliberately scoped to `group_node:*` (real content-type plugins), not
   * `group_membership` or other non-bundle relation plugins -- those aren't
   * subject to the "every bundle gets the same shape" invariant.
   */
  protected const PERMISSION_PATTERN = '/^(.*\bgroup_node:)([a-zA-Z0-9_]+)(\b.*)$/';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Audits (and optionally repairs) group role permission drift across content plugins.
   *
   * For each group type, derives the canonical permission shape per role
   * from whatever group_node:* plugins already have grants, then reports any
   * installed group_node:* plugin on that group type missing part of that
   * shape. With --fix, adds the missing permission strings (substituting the
   * plugin's own bundle name into the same verb pattern) and saves.
   */
  #[CLI\Command(name: 'mandala:group-permission-audit')]
  #[CLI\Option(name: 'fix', description: 'Add missing permission strings and save.')]
  #[CLI\Usage(name: 'drush mandala:group-permission-audit', description: 'Report permission drift across group_node:* plugins.')]
  #[CLI\Usage(name: 'drush mandala:group-permission-audit --fix', description: 'Report and repair the drift.')]
  public function audit(array $options = ['fix' => FALSE]): void {
    $fix = (bool) $options['fix'];

    $bundlesByGroupType = $this->installedBundlesByGroupType();
    if (!$bundlesByGroupType) {
      $this->logger()->notice('No group_node:* relationship types found.');
      return;
    }

    $findings = 0;
    $fixed = 0;

    foreach ($bundlesByGroupType as $groupType => $bundles) {
      foreach ($this->rolesForGroupType($groupType) as $roleName => $roleConfig) {
        $permissions = $roleConfig->get('permissions') ?? [];
        $shapeByBundle = $this->extractShapeByBundle($permissions);

        $canonical = [];
        foreach ($shapeByBundle as $templates) {
          $canonical += $templates;
        }
        if (!$canonical) {
          // No group_node:* permission granted to this role for ANY bundle
          // yet -- nothing to derive a shape from, not a finding.
          continue;
        }

        foreach ($bundles as $bundle) {
          $existing = $shapeByBundle[$bundle] ?? [];
          $missing = array_diff_key($canonical, $existing);
          if (!$missing) {
            continue;
          }

          $findings++;
          $missingStrings = array_map(
            fn(string $template) => $this->renderTemplate($template, $bundle),
            array_keys($missing),
          );
          $this->logger()->warning(
            "{role}: bundle '{bundle}' is missing {count} permission(s):\n  {list}",
            [
              'role' => $roleName,
              'bundle' => $bundle,
              'count' => count($missingStrings),
              'list' => implode("\n  ", $missingStrings),
            ],
          );

          if ($fix) {
            $updated = array_unique(array_merge($permissions, $missingStrings));
            sort($updated);
            $roleConfig->set('permissions', array_values($updated))->save();
            $permissions = $updated;
            $fixed += count($missingStrings);
          }
        }
      }
    }

    if ($findings === 0) {
      $this->logger()->success('No permission drift found across group_node:* plugins.');
      return;
    }

    if ($fix) {
      $this->logger()->success("Repaired: {fixed} permission(s) added across {findings} finding(s).", [
        'fixed' => $fixed,
        'findings' => $findings,
      ]);
    }
    else {
      $this->logger()->notice("{findings} finding(s). Re-run with --fix to repair.", ['findings' => $findings]);
    }
  }

  /**
   * Returns group_type => [bundle, ...] for every installed group_node:* plugin.
   */
  protected function installedBundlesByGroupType(): array {
    $result = [];
    foreach ($this->configFactory->listAll('group.relationship_type.') as $name) {
      $config = $this->configFactory->get($name);
      $plugin = (string) $config->get('content_plugin');
      if (!str_starts_with($plugin, 'group_node:')) {
        continue;
      }
      $groupType = (string) $config->get('group_type');
      $bundle = substr($plugin, strlen('group_node:'));
      $result[$groupType][] = $bundle;
    }
    return $result;
  }

  /**
   * Yields role name => immutable config object for every role of a group type.
   *
   * @return \Generator<string, \Drupal\Core\Config\ImmutableConfig>
   */
  protected function rolesForGroupType(string $groupType): \Generator {
    foreach ($this->configFactory->listAll('group.role.') as $name) {
      // Editable throughout, since --fix needs to save it.
      $config = $this->configFactory->getEditable($name);
      if ($config->get('group_type') === $groupType) {
        yield $name => $config;
      }
    }
  }

  /**
   * Parses a role's permissions into bundle => [verb-template => TRUE].
   *
   * A verb-template is the permission string with its bundle name replaced
   * by a placeholder, e.g. "view group_node:{} entity" is derived from
   * "view group_node:shanti_image entity" -- used both to compute the
   * canonical shape (by bundle-agnostic template) and to render it back out
   * for a different bundle.
   */
  protected function extractShapeByBundle(array $permissions): array {
    $shape = [];
    foreach ($permissions as $permission) {
      if (!preg_match(self::PERMISSION_PATTERN, $permission, $m)) {
        continue;
      }
      [, $prefix, $bundle, $suffix] = $m;
      $template = $prefix . '{}' . $suffix;
      $shape[$bundle][$template] = TRUE;
    }
    return $shape;
  }

  /**
   * Renders a verb-template back into a real permission string for a bundle.
   */
  protected function renderTemplate(string $template, string $bundle): string {
    return str_replace('{}', $bundle, $template);
  }

}
