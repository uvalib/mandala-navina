<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupType;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Shared fixture for the collection-visibility kernel tests.
 *
 * Builds the smallest arrangement that reproduces production's shape: a
 * collection group type with field_group_access, a subcollection type (the
 * module's cascade hooks require it), an `audio` node bundle with a
 * group_node plugin and field_group_content_access, an out-of-scope `page`
 * bundle, and the three synchronized group roles granting view -- without
 * which Group's own permissions would deny everything and any assertion about
 * our veto would pass vacuously.
 */
#[RunTestsInSeparateProcesses]
abstract class CollectionVisibilityKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'text',
    'filter',
    'group',
    'gnode',
    'options',
    'entity',
    'flexible_permissions',
    'mandala_group_inheritance',
  ];

  /**
   * Node-level field_group_content_access values to exercise.
   *
   * Includes states the allowed-values list forbids — no field at all, an empty
   * field, and an out-of-range 99 — because those are precisely where a CASE
   * branch and a PHP switch are most likely to disagree.
   */
  protected const NODE_VALUES = ['absent', 'empty', 0, 1, 2, 3, 99];

  /**
   * Collection field_group_access values to exercise.
   */
  protected const GROUP_VALUES = ['empty', 0, 1, 2, 9];

  /**
   * The accounts under test, keyed by label.
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected array $accounts = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_type');
    $this->installEntitySchema('group_relationship');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'group', 'user']);

    NodeType::create(['type' => 'audio', 'name' => 'Audio'])->save();
    // A second bundle with NO group_node plugin, to prove out-of-scope content
    // is left completely alone.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $group_type = GroupType::create(['id' => 'collection', 'label' => 'Collection']);
    $group_type->save();
    // The subcollection bundle exists because the module's membership-cascade
    // and visibility-inheritance hooks look for it; without it, adding a member
    // to a collection throws on the missing field_parent_collection.
    GroupType::create(['id' => 'subcollection', 'label' => 'Subcollection'])->save();

    $this->createField('group', 'collection', 'field_group_access');
    $this->createField('group', 'subcollection', 'field_group_access');
    $this->createField('node', 'audio', 'field_group_content_access');
    $this->createField('group', 'subcollection', 'field_visibility_overridden', 'boolean');
    // GroupQueryAlter joins this field unconditionally (it must hide a
    // flagged review-holding group even from bypass), so every group_access
    // query in every test needs the table to exist, not just the test that
    // exercises the flag itself.
    $this->createField('group', 'collection', 'field_is_review_holding', 'boolean');
    $this->createField('group', 'subcollection', 'field_is_review_holding', 'boolean');
    $this->createEntityReferenceField('subcollection', 'field_parent_collection');

    // Install the group_node:audio plugin, which is what puts `audio` in scope
    // for CollectionVisibility::groupNodeBundles().
    $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type')
      ->createFromPlugin($group_type, 'group_node:audio')
      ->save();

    // Group's own permission model must PERMIT, or both sides of the oracle
    // would be FALSE everywhere and the test would pass vacuously. Mirroring
    // production, where collection-anonymous and collection-outsider both grant
    // view on every group_node bundle.
    // Group 3.x does not create these automatically; the site's own config
    // does. Shapes copied from group.role.collection-{anonymous,outsider,member}
    // in config/sync so the fixture matches production.
    $role_storage = $this->container->get('entity_type.manager')->getStorage('group_role');
    $roles = [
      'collection-anonymous' => ['scope' => 'outsider', 'global_role' => 'anonymous'],
      'collection-outsider' => ['scope' => 'outsider', 'global_role' => 'authenticated'],
      'collection-member' => ['scope' => 'insider', 'global_role' => 'authenticated'],
    ];
    foreach ($roles as $id => $definition) {
      $role_storage->create($definition + [
        'id' => $id,
        'label' => $id,
        'group_type' => 'collection',
        'admin' => FALSE,
        'permissions' => ['view group', 'view group_node:audio entity'],
      ])->save();
    }
    Role::load(Role::ANONYMOUS_ID)->grantPermission('access content')->save();
    Role::load(Role::AUTHENTICATED_ID)->grantPermission('access content')->save();

    // Kernel tests do not ship the uid 0 row that a real site always has.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();

    $this->accounts = [
      'anonymous' => User::load(0),
      'outsider' => $this->createUser(),
      'member' => $this->createUser(),
      // The ADR 015 content_editor shape: reaches private collections without
      // per-group membership.
      'bypass' => $this->createUser(['bypass mandala group access']),
    ];
  }

  /**
   * Group ids returned by a group_access-tagged query for the current account.
   *
   * Note nobody declares that tag by name: core's EntityViewsData builds it
   * from the entity type id, and EntityQuery does the same on accessCheck(TRUE).
   */
  protected function visibleGids(): array {
    $query = $this->container->get('database')->select('groups_field_data', 'g');
    $query->addTag('group_access');
    $query->addField('g', 'id');

    return array_flip(array_map('intval', $query->execute()->fetchCol()));
  }

  /**
   * Nids returned by a node_access-tagged query for the current account.
   *
   * Deliberately a tagged query rather than $node->access(): that is the whole
   * point of the oracle. Note the tag must be present -- Views attaches it in
   * Sql::execute(), not Sql::build(), which is an easy way to write a test that
   * silently asserts nothing.
   */
  protected function visibleNids(): array {
    $query = $this->container->get('database')->select('node_field_data', 'n');
    $query->addTag('node_access');
    $query->addField('n', 'nid');
    $query->condition('n.status', 1);

    return array_flip(array_map('intval', $query->execute()->fetchCol()));
  }

  /**
   * Creates a single-value field, reusing the storage if it already exists.
   */
  protected function createField(string $entity_type, string $bundle, string $field_name, string $type = 'integer'): void {
    if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'type' => $type,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
    ])->save();
  }

  /**
   * Creates a group-to-group entity reference field.
   */
  protected function createEntityReferenceField(string $bundle, string $field_name): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'group',
      'bundle' => $bundle,
    ])->save();
  }

}
