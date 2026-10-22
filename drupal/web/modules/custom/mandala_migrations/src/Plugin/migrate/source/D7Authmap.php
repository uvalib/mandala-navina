<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * D7 `authmap` rows: the external (NetBadge) identity each user logs in with.
 *
 * Our own source plugin because `externalauth` removed its `authmap` source in
 * 2.0.14 (upstream issue #3618043: it ships no migrations any more). The
 * 2.0.11 plugin this replaces was the same query.
 *
 * D7 keeps `authmap` in the shared user database, so the migration group
 * points this at the `migrate_users` connection. The ids are `uid` alone, as
 * before, so an existing id map stays valid; with one provider per user
 * (every D7 row has module = simplesamlphp_auth) that is unique. The optional
 * `modules` list restricts the rows to those D7 `module` values, so a row
 * belonging to a different auth module is never labelled with this
 * migration's constant provider.
 *
 * @code
 * source:
 *   plugin: mandala_d7_authmap
 *   modules: [simplesamlphp_auth]
 * @endcode
 *
 * @MigrateSource(
 *   id = "mandala_d7_authmap",
 *   source_module = "user"
 * )
 */
class D7Authmap extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('authmap', 'a')
      ->fields('a', ['aid', 'uid', 'authname', 'module']);
    if (!empty($this->configuration['modules'])) {
      $query->condition('a.module', (array) $this->configuration['modules'], 'IN');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'aid' => $this->t('Authmap row id.'),
      'uid' => $this->t('The user (users.uid) this identity belongs to.'),
      'authname' => $this->t('Unique authentication name.'),
      'module' => $this->t('Module which controls the authentication.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uid' => ['type' => 'integer'],
    ];
  }

}
