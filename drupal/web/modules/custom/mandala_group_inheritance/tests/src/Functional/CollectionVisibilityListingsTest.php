<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Functional;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

use Drupal\Tests\BrowserTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;

/**
 * Asserts restricted titles do not appear in rendered listing pages.
 *
 * The kernel tests assert that the tagged query returns the right rows. That
 * is necessary but not sufficient: the exposure being fixed is a *title
 * rendered on a page*, and only a request through the full stack — views
 * rendering, render cache, page cache — proves it is gone.
 *
 * It also covers the fail-closed direction, which is the more dangerous one to
 * get wrong. PR #201 made every grouped AV node invisible to everyone
 * including uid 1, and a test that only asserted "restricted content is
 * hidden" would have passed happily.
 *
 * @group mandala_group_inheritance
 */
#[RunTestsInSeparateProcesses]
class CollectionVisibilityListingsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'views',
    'group',
    'gnode',
    'field',
    'options',
    'entity',
    'flexible_permissions',
    'mandala_group_inheritance',
    // Supplies views.view.mandala_visibility_test, a minimal node listing
    // standing in for av_gallery/image_gallery.
    'mandala_group_inheritance_test',
  ];

  /**
   * Titles used in the fixture, by visibility.
   */
  protected const PUBLIC_TITLE = 'Zz Public Recording';
  protected const PRIVATE_TITLE = 'Zz Private Recording';
  protected const UVA_TITLE = 'Zz Uva Only Recording';

  /**
   * The private collection.
   */
  protected $privateGroup;

  /**
   * A member of the private collection.
   */
  protected $member;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create(['type' => 'audio', 'name' => 'Audio'])->save();

    $group_type = GroupType::create(['id' => 'collection', 'label' => 'Collection']);
    $group_type->save();
    GroupType::create(['id' => 'subcollection', 'label' => 'Subcollection'])->save();

    $this->createField('group', 'collection', 'field_group_access');
    $this->createField('group', 'subcollection', 'field_group_access');
    $this->createField('node', 'audio', 'field_group_content_access');
    $this->createField('group', 'subcollection', 'field_visibility_overridden', 'boolean');
    // GroupQueryAlter joins this field unconditionally on every group_access
    // query, so the table must exist even though this test never sets it.
    $this->createField('group', 'collection', 'field_is_review_holding', 'boolean');
    $this->createField('group', 'subcollection', 'field_is_review_holding', 'boolean');

    FieldStorageConfig::create([
      'field_name' => 'field_parent_collection',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'group'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_parent_collection',
      'entity_type' => 'group',
      'bundle' => 'subcollection',
    ])->save();

    \Drupal::entityTypeManager()->getStorage('group_relationship_type')
      ->createFromPlugin($group_type, 'group_node:audio')
      ->save();

    $role_storage = \Drupal::entityTypeManager()->getStorage('group_role');
    foreach ([
      'collection-anonymous' => ['scope' => 'outsider', 'global_role' => 'anonymous'],
      'collection-outsider' => ['scope' => 'outsider', 'global_role' => 'authenticated'],
      'collection-member' => ['scope' => 'insider', 'global_role' => 'authenticated'],
    ] as $id => $definition) {
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

    $storage = \Drupal::entityTypeManager()->getStorage('group');

    $public_group = $storage->create(['type' => 'collection', 'label' => 'Public', 'field_group_access' => 0]);
    $public_group->save();
    $public_group->addRelationship($this->createAudioNode(self::PUBLIC_TITLE), 'group_node:audio');

    $this->privateGroup = $storage->create(['type' => 'collection', 'label' => 'Private', 'field_group_access' => 1]);
    $this->privateGroup->save();
    $this->privateGroup->addRelationship($this->createAudioNode(self::PRIVATE_TITLE), 'group_node:audio');

    $uva_group = $storage->create(['type' => 'collection', 'label' => 'Uva', 'field_group_access' => 2]);
    $uva_group->save();
    $uva_group->addRelationship($this->createAudioNode(self::UVA_TITLE), 'group_node:audio');

    $this->member = $this->drupalCreateUser();
    $this->privateGroup->addMember($this->member);
  }

  /**
   * Anonymous users must not see restricted titles in a listing.
   */
  public function testAnonymousListingHidesRestrictedTitles(): void {
    $this->drupalGet('mandala-visibility-test');
    $this->assertSession()->statusCodeEquals(200);

    // Fail-closed guard first: if public content vanished too, the rest of
    // this test would pass for entirely the wrong reason.
    $this->assertSession()->pageTextContains(self::PUBLIC_TITLE);

    $this->assertSession()->pageTextNotContains(self::PRIVATE_TITLE);
    $this->assertSession()->pageTextNotContains(self::UVA_TITLE);
  }

  /**
   * An authenticated non-member sees UVA content but not private content.
   */
  public function testAuthenticatedListingShowsUvaOnly(): void {
    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet('mandala-visibility-test');

    $this->assertSession()->pageTextContains(self::PUBLIC_TITLE);
    $this->assertSession()->pageTextContains(self::UVA_TITLE);
    $this->assertSession()->pageTextNotContains(self::PRIVATE_TITLE);
  }

  /**
   * A member sees the private content of their own collection.
   *
   * The fail-closed direction, and the reason this test exists at all: grants
   * and query filters both fail hard, and hiding content from the people
   * entitled to it is a worse outcome than the leak being fixed.
   */
  public function testMemberSeesOwnPrivateContent(): void {
    $this->drupalLogin($this->member);
    $this->drupalGet('mandala-visibility-test');

    $this->assertSession()->pageTextContains(self::PUBLIC_TITLE);
    $this->assertSession()->pageTextContains(self::PRIVATE_TITLE);
  }

  /**
   * Two members of different collections do not see each other's content.
   *
   * The rendered-page counterpart of the cache-context kernel test: proves the
   * page cache is not serving one member's listing to another.
   */
  public function testMembersDoNotSeeEachOthersContent(): void {
    $this->drupalLogin($this->member);
    $this->drupalGet('mandala-visibility-test');
    $this->assertSession()->pageTextContains(self::PRIVATE_TITLE);

    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet('mandala-visibility-test');
    $this->assertSession()->pageTextNotContains(self::PRIVATE_TITLE);
  }

  /**
   * Creates a published audio node.
   */
  protected function createAudioNode(string $title) {
    $node = Node::create(['type' => 'audio', 'title' => $title, 'status' => 1]);
    $node->save();

    return $node;
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

}
