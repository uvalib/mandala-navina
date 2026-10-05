<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_group_inheritance\Kernel;

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

}
