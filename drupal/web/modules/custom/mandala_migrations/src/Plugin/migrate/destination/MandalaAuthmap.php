<?php

namespace Drupal\mandala_migrations\Plugin\migrate\destination;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Destination plugin: links a migrated user to an external identity.
 *
 * Our own plugin because `externalauth` deprecated its `authmap` destination in
 * 2.0.14 and removes it in a later release (upstream issue #3618043), pointing
 * at exactly this: a custom destination that calls AuthmapInterface::save().
 *
 * Accepts rows with:
 *   uid       - the D11 user id (the user migration preserves D7 uids)
 *   provider  - the authentication provider name (simplesamlphp_auth)
 *   authname  - the unique name that provider presents (the NetBadge uid)
 *
 * save() inserts or updates the link for the user and provider, so a re-run
 * leaves the same rows. A row whose user does not exist fails with a message
 * instead of a fatal error.
 *
 * @MigrateDestination(
 *   id = "mandala_authmap"
 * )
 */
class MandalaAuthmap extends DestinationBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    protected AuthmapInterface $authmap,
    protected UserStorageInterface $userStorage,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('externalauth.authmap'),
      $container->get('entity_type.manager')->getStorage('user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uid' => ['type' => 'integer'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(?MigrationInterface $migration = NULL) {
    return [
      'uid' => 'The user the identity belongs to',
      'provider' => 'The authentication provider that presents the authname',
      'authname' => 'Unique authentication name given by the provider',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $uid = $row->getDestinationProperty('uid');
    $account = $this->userStorage->load($uid);
    if ($account === NULL) {
      throw new MigrateException("Cannot link an external identity: user $uid does not exist.");
    }
    $this->authmap->save(
      $account,
      (string) $row->getDestinationProperty('provider'),
      (string) $row->getDestinationProperty('authname'),
    );
    return [$account->id()];
  }

}
