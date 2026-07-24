<?php

namespace Drupal\mandala_migrations\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Maps a D7 role-id array to D11 role machine names, element-wise.
 *
 * This is "static_map, but array-aware." The core static_map plugin treats an
 * array source as a nested key-path (map[a][b]) rather than mapping each
 * element, so it cannot translate a user's role SET. migration_lookup can, but
 * only against another migration's map table — and building that map table for
 * roles meant running an `entity:user_role` destination, which REPLACES the
 * committed D11 role config entity and silently wipes its permissions (see
 * docs/deferred/d7-user-role-migration-wipes-committed-role-permissions.md).
 *
 * This plugin holds the D7-rid → D11-role-machine-name dictionary in memory and
 * maps each element itself, so nothing ever saves a role config entity. The
 * `d7_user_role` lookup migration is thereby eliminated.
 *
 * Configuration:
 * - map: required. An array keyed by D7 rid whose values are D11 role machine
 *   names. Several D7 rids may map onto the same D11 role (the three custom
 *   editor roles collapse onto content_editor); duplicates are removed.
 *
 * Unmapped rids are dropped. In particular D7 rids 1/2 (anonymous/authenticated)
 * are never present in users_roles (they are implicit in D7 and are locked,
 * unassignable roles in D11), so they are intentionally left out of the map.
 *
 * Example:
 * @code
 * roles:
 *   plugin: mandala_role_map
 *   source: roles
 *   map:
 *     3: administrator
 *     4: content_editor
 *     5: content_editor
 *     6: content_editor
 * @endcode
 *
 * The source `roles` is a multi-value property (an array of rids), so this
 * plugin MUST handle the whole array itself. Without handle_multiples = TRUE,
 * migrate's pipeline applies the plugin element-wise (once per rid) and nests
 * the results (e.g. [["administrator"],["content_editor"]]), which the
 * entity:user destination cannot assign — users end up with no mapped roles.
 *
 * @MigrateProcessPlugin(
 *   id = "mandala_role_map",
 *   handle_multiples = TRUE
 * )
 */
class RoleMap extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!isset($this->configuration['map']) || !is_array($this->configuration['map'])) {
      throw new MigrateException('The mandala_role_map plugin requires a "map" configuration array (D7 rid => D11 role machine name).');
    }
    $map = $this->configuration['map'];

    // Accept a scalar rid as well as the expected array of rids.
    $rids = is_array($value) ? $value : [$value];

    $roles = [];
    foreach ($rids as $rid) {
      // Cast to string so integer YAML keys and DB-string rids compare alike.
      $key = (string) $rid;
      if (isset($map[$key])) {
        // Key by machine name to collapse the editor-role duplicates.
        $roles[$map[$key]] = $map[$key];
      }
    }

    return array_values($roles);
  }

}
