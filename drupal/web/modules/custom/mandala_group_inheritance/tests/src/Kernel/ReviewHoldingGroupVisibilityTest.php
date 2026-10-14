<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Asserts a review-holding group never appears in a group_access listing.
 *
 * The orphaned-content review group (and its per-site children) must not be
 * browsable as a real collection by anyone, including an account with bypass
 * — unlike every other visibility rule in this module, which bypass is
 * supposed to override. See
 * docs/deferred/orphaned-content-temp-group-on-migration.md.
 *
 * @group mandala_group_inheritance
 */
#[RunTestsInSeparateProcesses]
class ReviewHoldingGroupVisibilityTest extends CollectionVisibilityKernelTestBase {

  /**
   * A flagged public group is hidden from everyone, including bypass.
   */
  public function testFlaggedGroupHiddenFromEveryone(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');

    $normal = $storage->create([
      'type' => 'collection',
      'label' => 'Normal public collection',
      'field_group_access' => 0,
    ]);
    $normal->save();

    $review = $storage->create([
      'type' => 'collection',
      'label' => 'Orphaned Content (review holding)',
      // Public access value deliberately, to prove the exclusion is NOT
      // riding on field_group_access -- it must hold even if a review group
      // were ever misconfigured as public.
      'field_group_access' => 0,
      'field_is_review_holding' => 1,
    ]);
    $review->save();

    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $label) {
      $this->container->get('current_user')->setAccount($this->accounts[$label]);

      $visible = $this->visibleGids();
      $this->assertArrayHasKey((int) $normal->id(), $visible, "Normal collection visible to $label.");
      $this->assertArrayNotHasKey((int) $review->id(), $visible, "Review-holding group hidden from $label.");
    }
  }

  /**
   * Creates and saves a group of the given bundle.
   */
  private function makeGroup(string $type, string $label, array $values = []): object {
    $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => $type,
      'label' => $label,
      'field_group_access' => 0,
    ] + $values);
    $group->save();
    return $group;
  }

  /**
   * Reloads a group fresh from storage and returns its review-holding flag.
   */
  private function flag(object $group): bool {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache([$group->id()]);
    return (bool) $storage->load($group->id())->get('field_is_review_holding')->value;
  }

  /**
   * A subcollection created under a flagged parent is flagged, however saved.
   */
  public function testNewChildOfFlaggedParentIsFlagged(): void {
    $parent = $this->makeGroup('collection', 'Orphaned Nodes', ['field_is_review_holding' => 1]);
    $child = $this->makeGroup('subcollection', 'Orphaned AV', [
      'field_parent_collection' => $parent->id(),
      'field_is_review_holding' => 0,
    ]);
    $this->assertTrue($this->flag($child), 'Child created unflagged under a flagged parent is flagged.');
  }

  /**
   * A child under an unflagged parent, or with none, is never flagged.
   */
  public function testChildOfUnflaggedParentOrNoParentIsUnflagged(): void {
    $parent = $this->makeGroup('collection', 'Real collection');
    $under = $this->makeGroup('subcollection', 'Real sub', [
      'field_parent_collection' => $parent->id(),
      'field_is_review_holding' => 1,
    ]);
    $this->assertFalse($this->flag($under), 'A hand-ticked flag under an unflagged parent is cleared.');

    $orphan = $this->makeGroup('subcollection', 'Parentless', ['field_is_review_holding' => 1]);
    $this->assertFalse($this->flag($orphan), 'No parent means unflagged.');
  }

  /**
   * An editor cannot un-flag a child of a flagged parent.
   */
  public function testChildCannotBeUnflaggedWhileParentFlagged(): void {
    $parent = $this->makeGroup('collection', 'Orphaned Nodes', ['field_is_review_holding' => 1]);
    $child = $this->makeGroup('subcollection', 'Orphaned AV', ['field_parent_collection' => $parent->id()]);
    $child->set('field_is_review_holding', 0);
    $child->save();
    $this->assertTrue($this->flag($child));
  }

  /**
   * Flagging or unflagging a parent carries to its existing children.
   */
  public function testParentFlagChangePropagatesToChildren(): void {
    $parent = $this->makeGroup('collection', 'Collection');
    $a = $this->makeGroup('subcollection', 'A', ['field_parent_collection' => $parent->id()]);
    $b = $this->makeGroup('subcollection', 'B', ['field_parent_collection' => $parent->id()]);
    $this->assertFalse($this->flag($a));

    $parent->set('field_is_review_holding', 1);
    $parent->save();
    $this->assertTrue($this->flag($a), 'Flagging the parent flags child A.');
    $this->assertTrue($this->flag($b), 'Flagging the parent flags child B.');

    $parent->set('field_is_review_holding', 0);
    $parent->save();
    $this->assertFalse($this->flag($a), 'Unflagging the parent clears child A.');
    $this->assertFalse($this->flag($b), 'Unflagging the parent clears child B.');
  }

  /**
   * Re-parenting a child re-derives its flag from the new parent.
   */
  public function testReparentingRederivesFlag(): void {
    $review = $this->makeGroup('collection', 'Orphaned Nodes', ['field_is_review_holding' => 1]);
    $real = $this->makeGroup('collection', 'Real collection');
    $child = $this->makeGroup('subcollection', 'Child', ['field_parent_collection' => $real->id()]);
    $this->assertFalse($this->flag($child));

    $child->set('field_parent_collection', $review->id());
    $child->save();
    $this->assertTrue($this->flag($child), 'Moved under a flagged parent: flagged.');

    $child->set('field_parent_collection', $real->id());
    $child->save();
    $this->assertFalse($this->flag($child), 'Moved back under a real parent: cleared.');
  }

  /**
   * Listings hide a child of a flagged parent even if its own flag is stale.
   */
  public function testListingHidesChildOfFlaggedParentWithStaleFlag(): void {
    $parent = $this->makeGroup('collection', 'Orphaned Nodes', ['field_is_review_holding' => 1]);
    $child = $this->makeGroup('subcollection', 'Orphaned AV', ['field_parent_collection' => $parent->id()]);

    // Force the stored flag to 0 behind the entity hooks, as a stale row.
    $this->container->get('database')
      ->update('group__field_is_review_holding')
      ->fields(['field_is_review_holding_value' => 0])
      ->condition('entity_id', $child->id())
      ->execute();

    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $label) {
      $this->container->get('current_user')->setAccount($this->accounts[$label]);
      $this->assertArrayNotHasKey((int) $child->id(), $this->visibleGids(), "Child of a flagged parent hidden from $label.");
    }
  }

  /**
   * Returns the labels the parent-collection picker offers an account.
   */
  private function parentPickerLabels(string $account, object $host, string $match): array {
    $this->container->get('current_user')->setAccount($this->accounts[$account]);
    $handler = $this->container->get('plugin.manager.entity_reference_selection')->getInstance([
      'target_type' => 'group',
      'handler' => 'default:group',
      'target_bundles' => ['collection' => 'collection'],
      'entity' => $host,
    ]);
    $labels = [];
    foreach ($handler->getReferenceableEntities($match, 'CONTAINS', 20) as $bundle_group) {
      $labels = array_merge($labels, array_values($bundle_group));
    }
    return $labels;
  }

  /**
   * An administrator can pick a flagged parent on a subcollection, no one else.
   *
   * Otherwise a child of a flagged parent can never be edited: its parent is
   * not a valid choice in its own entity-reference field.
   */
  public function testFlaggedParentSelectableOnlyInSubcollectionPickerByBypass(): void {
    $review = $this->makeGroup('collection', 'ZZ Orphaned Nodes', ['field_is_review_holding' => 1]);
    $child = $this->makeGroup('subcollection', 'ZZ child', ['field_parent_collection' => $review->id()]);
    $other = $this->makeGroup('collection', 'ZZ plain collection');
    $new_child = $this->container->get('entity_type.manager')->getStorage('group')
      ->create(['type' => 'subcollection', 'label' => 'ZZ new']);

    $this->assertContains('ZZ Orphaned Nodes', $this->parentPickerLabels('bypass', $child, 'ZZ'), 'Bypass sees the flagged parent when editing a child.');
    $this->assertContains('ZZ Orphaned Nodes', $this->parentPickerLabels('bypass', $new_child, 'ZZ'), 'Bypass sees it when adding a child.');
    $this->assertContains('ZZ plain collection', $this->parentPickerLabels('bypass', $child, 'ZZ'));

    $this->assertNotContains('ZZ Orphaned Nodes', $this->parentPickerLabels('member', $child, 'ZZ'), 'A non-bypass editor never sees it.');
    $this->assertNotContains('ZZ Orphaned Nodes', $this->parentPickerLabels('bypass', $other, 'ZZ'), 'Bypass sees it only on a subcollection host, not on a collection.');
  }

  /**
   * The flag is display-only on both subcollection forms.
   */
  public function testFlagIsDisabledOnSubcollectionForms(): void {
    $this->container->get('current_user')->setAccount($this->accounts['bypass']);
    $parent = $this->makeGroup('collection', 'Parent');
    $child = $this->makeGroup('subcollection', 'Child', ['field_parent_collection' => $parent->id()]);
    $storage = $this->container->get('entity_type.manager')->getStorage('group');

    // The kernel base installs no form displays, so give both bundles one
    // carrying the flag, as config/sync does.
    foreach (['collection', 'subcollection'] as $bundle) {
      EntityFormDisplay::create(['targetEntityType' => 'group', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE])
        ->setComponent('field_is_review_holding', ['type' => 'boolean_checkbox'])
        ->save();
    }

    foreach ([$child, $storage->create(['type' => 'subcollection'])] as $entity) {
      $operation = $entity->isNew() ? 'add' : 'edit';
      $form = $this->container->get('entity.form_builder')->getForm($entity, $operation === 'add' ? 'add' : 'edit');
      $this->assertTrue($form['field_is_review_holding']['#disabled'] ?? FALSE, "Flag disabled on the subcollection $operation form.");
    }

    $collection_form = $this->container->get('entity.form_builder')->getForm($parent, 'edit');
    $this->assertEmpty($collection_form['field_is_review_holding']['#disabled'] ?? FALSE, 'Still editable on a collection.');
  }

}
