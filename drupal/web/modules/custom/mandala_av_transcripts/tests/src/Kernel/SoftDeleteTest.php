<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\Event\TranscriptEvent;
use Drupal\mandala_av_transcripts\Event\TranscriptEvents;
use Drupal\mandala_av_transcripts\TranscriptLifecycle;
use Drupal\mandala_av_transcripts\TranscriptReader;
use Drupal\node\Entity\Node;
use Drupal\Tests\mandala_group_inheritance\Kernel\CollectionVisibilityKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Soft-delete (ADR 019): hidden everywhere, nothing lost, restore is exact.
 *
 * Covers the flag on both entities, the lifecycle service, and every reader of
 * the data: the display read (which queries av_tcu directly, so the entity flag
 * alone would not reach it), the access handlers, and the events subscribers
 * react to.
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class SoftDeleteTest extends CollectionVisibilityKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'group', 'gnode', 'options',
    'entity', 'flexible_permissions', 'mandala_group_inheritance',
    'file', 'mandala_av_transcripts',
  ];

  private TranscriptLifecycle $lifecycle;

  private TranscriptReader $reader;

  /** @var string[] */
  private array $events = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    $this->lifecycle = $this->container->get('mandala_av_transcripts.lifecycle');
    $this->reader = $this->container->get('mandala_av_transcripts.reader');
    foreach ([
      TranscriptEvents::TRANSCRIPT_SAVED,
      TranscriptEvents::TRANSCRIPT_DELETED,
      TranscriptEvents::UNIT_SAVED,
      TranscriptEvents::UNIT_DELETED,
      TranscriptEvents::UNITS_REPLACED,
    ] as $name) {
      $this->container->get('event_dispatcher')->addListener($name, function (TranscriptEvent $e) use ($name): void {
        $this->events[] = $name;
      });
    }
  }

  /**
   * A transcript with units 10, 11, 12 on a published audio node in a public collection.
   *
   * @return array{0: AvTranscript, 1: Node}
   */
  private function fixture(): array {
    $node = Node::create(['type' => 'audio', 'title' => 'n', 'status' => 1]);
    $node->save();
    $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => 'collection',
      'label' => 'c',
      'field_group_access' => 0,
    ]);
    $group->save();
    $group->addRelationship($node, 'group_node:audio');
    $transcript = AvTranscript::create(['id' => 1, 'node' => $node->id()]);
    $transcript->save();
    foreach ([10, 11, 12] as $seq => $id) {
      AvTcu::create(['id' => $id, 'transcript' => 1, 'sequence' => $seq, 'start' => (string) ($seq + 1) . '.000'])->save();
    }
    $this->events = [];
    return [$transcript, $node];
  }

  private function unit(int $id): AvTcu {
    $this->container->get('entity_type.manager')->getStorage('av_tcu')->resetCache();
    return AvTcu::load($id);
  }

  private function reload(int $id): ?AvTranscript {
    $this->container->get('entity_type.manager')->getStorage('av_transcript')->resetCache();
    return AvTranscript::load($id);
  }

  /**
   * Drops the handlers' per-process access caches (a real request starts fresh).
   */
  private function resetAccess(): void {
    $manager = $this->container->get('entity_type.manager');
    $manager->getAccessControlHandler('av_transcript')->resetCache();
    $manager->getAccessControlHandler('av_tcu')->resetCache();
  }

  private function admin(): User {
    Role::create(['id' => 'transcript_admin', 'label' => 'Transcript admin'])
      ->grantPermission('administer av transcripts')->save();
    $user = User::create(['name' => 'ta', 'status' => 1, 'roles' => ['transcript_admin']]);
    $user->save();
    return $user;
  }

  private function liveUnitIds(): array {
    return array_column($this->reader->read(1, $this->accounts['anonymous'])?->units ?? [], 'id');
  }

  public function testFlagsDefaultToLive(): void {
    [$transcript] = $this->fixture();
    $this->assertFalse($transcript->isDeleted());
    $this->assertFalse($this->unit(10)->isDeleted());
    $this->assertFalse($this->unit(10)->isDeletedWithTranscript());
  }

  public function testSoftDeleteFlagsTranscriptAndUnitsAndKeepsEverything(): void {
    [$transcript] = $this->fixture();
    $revisionsBefore = (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_tcu_revision}')->fetchField();

    $this->assertTrue($this->lifecycle->softDelete($transcript));

    $this->assertTrue($this->reload(1)->isDeleted(), 'The transcript is still there, flagged.');
    foreach ([10, 11, 12] as $id) {
      $this->assertTrue($this->unit($id)->isDeleted(), "Unit $id flagged");
      $this->assertTrue($this->unit($id)->isDeletedWithTranscript(), "Unit $id flagged as taken by the transcript");
    }
    $this->assertSame($revisionsBefore, (int) $this->container->get('database')->query('SELECT COUNT(*) FROM {av_tcu_revision}')->fetchField(), 'Unit revisions are untouched.');
    $revisions = $this->container->get('entity_type.manager')->getStorage('av_transcript')->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', 1)->execute();
    $this->assertCount(2, $revisions, 'The delete is on the transcript audit trail.');
  }

  public function testRestoreRevivesOnlyTheUnitsTheTranscriptTook(): void {
    [$transcript] = $this->fixture();
    // An editor deletes unit 11 on its own, before the transcript goes.
    $this->unit(11)->setDeleted(TRUE)->save();
    $this->assertFalse($this->unit(11)->isDeletedWithTranscript());

    $this->lifecycle->softDelete($transcript);
    $this->assertTrue($this->lifecycle->restore($this->reload(1)));

    $this->assertFalse($this->reload(1)->isDeleted());
    $this->assertFalse($this->unit(10)->isDeleted());
    $this->assertFalse($this->unit(12)->isDeleted());
    $this->assertTrue($this->unit(11)->isDeleted(), 'The unit deleted on its own stays deleted.');
    $this->assertFalse($this->unit(11)->isDeletedWithTranscript());
  }

  public function testLifecycleIsIdempotent(): void {
    [$transcript] = $this->fixture();
    $this->assertFalse($this->lifecycle->restore($transcript), 'Restoring a live transcript does nothing.');
    $this->assertTrue($this->lifecycle->softDelete($transcript));
    $this->assertFalse($this->lifecycle->softDelete($this->reload(1)), 'Deleting twice does nothing.');
    $revisions = $this->container->get('entity_type.manager')->getStorage('av_transcript')->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', 1)->execute();
    $this->assertCount(2, $revisions);
  }

  public function testReaderHidesADeletedTranscriptAndItsUnits(): void {
    [$transcript, $node] = $this->fixture();
    $this->assertSame([10, 11, 12], $this->liveUnitIds());

    $this->lifecycle->softDelete($transcript);

    $this->assertNull($this->reader->read(1, $this->accounts['anonymous']));
    $this->assertNull($this->reader->readForNode((int) $node->id(), $this->accounts['anonymous']));
    $this->assertNull($this->reader->read(1, $this->admin()), 'Hidden from the viewer even for an administrator.');

    $this->lifecycle->restore($this->reload(1));
    $this->assertSame([10, 11, 12], $this->liveUnitIds());
  }

  public function testReaderOmitsAUnitDeletedOnItsOwn(): void {
    $this->fixture();
    $this->unit(11)->setDeleted(TRUE)->save();
    $this->assertSame([10, 12], $this->liveUnitIds());
  }

  public function testAccessDeniedToEveryoneButAdministrators(): void {
    [$transcript, $node] = $this->fixture();
    $admin = $this->admin();
    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $who) {
      $this->assertTrue($transcript->access('view', $this->accounts[$who]), "$who sees a live transcript");
    }

    $this->lifecycle->softDelete($transcript);
    $this->resetAccess();
    $transcript = $this->reload(1);

    foreach (['anonymous', 'outsider', 'member', 'bypass'] as $who) {
      $this->assertFalse($transcript->access('view', $this->accounts[$who]), "$who: deleted transcript, view");
      $this->assertFalse($transcript->access('update', $this->accounts[$who]), "$who: deleted transcript, update");
      $this->assertFalse($this->unit(10)->access('view', $this->accounts[$who]), "$who: deleted unit, view");
    }
    $this->assertTrue($transcript->access('view', $admin));
    $this->assertTrue($this->unit(10)->access('view', $admin));

    $this->lifecycle->restore($transcript);
    $this->resetAccess();
    $this->assertTrue($this->reload(1)->access('view', $this->accounts['anonymous']));
    $this->assertTrue($this->unit(10)->access('view', $this->accounts['anonymous']));
  }

  public function testUnitDeletedOnItsOwnIsDeniedToo(): void {
    $this->fixture();
    $this->unit(10)->setDeleted(TRUE)->save();
    $this->assertFalse($this->unit(10)->access('view', $this->accounts['anonymous']));
    $this->assertTrue($this->unit(10)->access('view', $this->admin()));
  }

  public function testSubscribersSeeTheTranscriptLevelEventsOnly(): void {
    [$transcript] = $this->fixture();

    $this->lifecycle->softDelete($transcript);
    $this->assertSame([TranscriptEvents::TRANSCRIPT_DELETED], $this->events, 'One delete event; no per-unit events.');

    $this->events = [];
    $deleted = $this->reload(1);
    $deleted->set('has_edits', TRUE);
    $deleted->save();
    $this->assertSame([], $this->events, 'Saving an already-deleted transcript tells nobody anything.');

    $this->lifecycle->restore($this->reload(1));
    $this->assertSame([TranscriptEvents::TRANSCRIPT_SAVED, TranscriptEvents::UNITS_REPLACED], $this->events);
  }

  public function testSoftDeletingOneUnitReadsAsADeleteAndRestoringAsASave(): void {
    $this->fixture();
    $this->unit(10)->setDeleted(TRUE)->save();
    $this->assertSame([TranscriptEvents::UNIT_DELETED], $this->events);

    $this->events = [];
    $this->unit(10)->setDeleted(FALSE)->save();
    $this->assertSame([TranscriptEvents::UNIT_SAVED], $this->events);
  }

  public function testADeletedTranscriptStillHoldsItsNodesSlot(): void {
    [$transcript, $node] = $this->fixture();
    $this->lifecycle->softDelete($transcript);

    // One transcript per node is a database unique key, and a soft-deleted row
    // still counts: a new transcript for the node means restoring or purging
    // the old one first.
    $this->expectException(\Exception::class);
    AvTranscript::create(['id' => 2, 'node' => $node->id()])->save();
  }

  public function testPurgeIsStillAHardDelete(): void {
    [$transcript] = $this->fixture();
    $this->lifecycle->softDelete($transcript);
    $this->reload(1)->delete();
    $this->assertNull($this->reload(1));
  }

}
