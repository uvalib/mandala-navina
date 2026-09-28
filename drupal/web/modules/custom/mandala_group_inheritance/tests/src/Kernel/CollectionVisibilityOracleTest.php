<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Asserts the PHP and SQL implementations of collection visibility agree.
 *
 * The visibility rule necessarily exists twice: once in PHP, for
 * hook_entity_access() on entity pages, and once in SQL, for the query alter
 * that filters listings. Two expressions of one rule is exactly the shape that
 * has drifted four times in this codebase (PR #201, PR #199,
 * SiblingCarouselService, PR #255).
 *
 * This test makes drift impossible to land: for every combination of node
 * override, collection visibility and account type, it asserts that
 * $node->access('view') and "is the nid returned by a node_access-tagged
 * query" give the same answer. If someone changes one implementation without
 * the other, a cell flips and the test fails.
 *
 * @group mandala_group_inheritance
 */
class CollectionVisibilityOracleTest extends KernelTestBase {

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
   * Every combination of inputs resolves identically in PHP and in SQL.
   */
  public function testPhpAndSqlAgree(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $cases = [];

    foreach (self::GROUP_VALUES as $group_value) {
      $group = $storage->create([
        'type' => 'collection',
        'label' => 'Collection ' . var_export($group_value, TRUE),
      ]);
      if ($group_value !== 'empty') {
        $group->set('field_group_access', $group_value);
      }
      $group->save();
      $group->addMember($this->accounts['member']);

      foreach (self::NODE_VALUES as $node_value) {
        $values = [
          'type' => 'audio',
          'title' => 'n-' . var_export($group_value, TRUE) . '-' . var_export($node_value, TRUE),
          'status' => 1,
        ];
        if ($node_value !== 'absent' && $node_value !== 'empty') {
          $values['field_group_content_access'] = $node_value;
        }
        $node = Node::create($values);
        $node->save();
        $group->addRelationship($node, 'group_node:audio');

        $cases[(int) $node->id()] = [$group_value, $node_value];
      }
    }

    // An out-of-scope bundle, and a node belonging to no collection at all.
    $page = Node::create(['type' => 'page', 'title' => 'out of scope', 'status' => 1]);
    $page->save();
    $orphan = Node::create([
      'type' => 'audio',
      'title' => 'orphan',
      'status' => 1,
      'field_group_content_access' => 2,
    ]);
    $orphan->save();

    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $switcher = $this->container->get('account_switcher');
    $allowed_seen = 0;
    $denied_seen = 0;

    foreach ($this->accounts as $label => $account) {
      $switcher->switchTo($account);
      $visible = $this->visibleNids();

      foreach ($cases as $nid => [$group_value, $node_value]) {
        $node = $node_storage->load($nid);
        $php = $node->access('view', $account);
        $sql = isset($visible[$nid]);

        $this->assertSame($php, $sql, sprintf(
          'Disagreement for account "%s", collection field_group_access=%s, node field_group_content_access=%s: entity access says %s, the tagged query says %s.',
          $label,
          var_export($group_value, TRUE),
          var_export($node_value, TRUE),
          $php ? 'ALLOWED' : 'DENIED',
          $sql ? 'ALLOWED' : 'DENIED',
        ));

        $php ? $allowed_seen++ : $denied_seen++;
      }

      // Out-of-scope bundles are untouched, and a collection-less node stays
      // visible even when it carries a restriction -- matching the hook, which
      // returns neutral before reading the node's own override. Those orphans
      // are handled by migrating them into a review group, not here. See
      // docs/deferred/orphaned-content-temp-group-on-migration.md.
      $this->assertArrayHasKey((int) $page->id(), $visible, "Out-of-scope bundle filtered for $label.");
      $this->assertArrayHasKey((int) $orphan->id(), $visible, "Collection-less node filtered for $label.");

      $switcher->switchBack();
    }

    // Guard against a vacuous pass: if Group's permissions denied everything,
    // or the alter filtered everything, both sides would agree on FALSE for
    // every cell and this test would prove nothing.
    $this->assertGreaterThan(0, $allowed_seen, 'No combination was ever allowed; the fixture is not exercising the rule.');
    $this->assertGreaterThan(0, $denied_seen, 'No combination was ever denied; the fixture is not exercising the rule.');
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
