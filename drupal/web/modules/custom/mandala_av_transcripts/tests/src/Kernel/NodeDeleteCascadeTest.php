<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\mandala_av_transcripts\Entity\AvTcu;
use Drupal\mandala_av_transcripts\Entity\AvTranscript;
use Drupal\mandala_av_transcripts\TranscriptLifecycle;
use Drupal\node\Entity\Node;
use Drupal\Tests\mandala_group_inheritance\Kernel\CollectionVisibilityKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deleting an AV node soft-deletes its transcript, with a snapshot of the node.
 *
 * @group mandala_av_transcripts
 */
#[RunTestsInSeparateProcesses]
class NodeDeleteCascadeTest extends CollectionVisibilityKernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'node', 'text', 'filter', 'group', 'gnode', 'options',
    'entity', 'flexible_permissions', 'mandala_group_inheritance',
    'file', 'mandala_av_transcripts',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('av_transcript');
    $this->installEntitySchema('av_tcu');
    FieldStorageConfig::create([
      'field_name' => 'field_legacy_site', 'entity_type' => 'node', 'type' => 'list_string',
      'settings' => ['allowed_values' => ['av' => 'AV']],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_legacy_nid', 'entity_type' => 'node', 'type' => 'integer'])->save();
    foreach (['field_legacy_site', 'field_legacy_nid'] as $name) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'audio', 'label' => $name])->save();
    }
  }

  /**
   * An audio node with a legacy key, in two collections, with a 3-unit transcript.
   *
   * @return array{0: Node, 1: int[]}
   *   The node and its group ids.
   */
  private function fixture(): array {
    $node = Node::create([
      'type' => 'audio', 'title' => 'A talk on the middle way', 'status' => 1,
      'field_legacy_site' => 'av', 'field_legacy_nid' => 4242,
    ]);
    $node->save();
    $groups = [];
    foreach (['c1', 'c2'] as $label) {
      $group = $this->container->get('entity_type.manager')->getStorage('group')->create([
        'type' => 'collection', 'label' => $label, 'field_group_access' => 0,
      ]);
      $group->save();
      $group->addRelationship($node, 'group_node:audio');
      $groups[] = (int) $group->id();
    }
    AvTranscript::create(['id' => 7, 'node' => $node->id()])->save();
    foreach ([0, 1, 2] as $seq) {
      AvTcu::create(['id' => 70 + $seq, 'transcript' => 7, 'sequence' => $seq, 'start' => (string) ($seq + 1) . '.000'])->save();
    }
    return [$node, $groups];
  }

  private function transcript(): ?AvTranscript {
    $this->container->get('entity_type.manager')->getStorage('av_transcript')->resetCache();
    return AvTranscript::load(7);
  }

  public function testDeletingTheNodeSoftDeletesTheTranscriptWithASnapshot(): void {
    [$node, $groups] = $this->fixture();
    $this->container->get('current_user')->setAccount($this->accounts['member']);
    $nid = (int) $node->id();

    $node->delete();

    $t = $this->transcript();
    $this->assertNotNull($t, 'The transcript is kept.');
    $this->assertTrue($t->isDeleted());
    $this->assertSame('A talk on the middle way', $t->get('node_title')->value);
    $this->assertSame('av', $t->get('node_legacy_site')->value);
    $this->assertSame(4242, (int) $t->get('node_legacy_nid')->value);
    $this->assertSame($groups, json_decode($t->get('node_collections')->value, TRUE), 'The node\'s collections are recorded.');
    $this->assertSame((int) $this->accounts['member']->id(), (int) $t->get('deleted_by')->target_id);
    $this->assertNotEmpty($t->get('deleted_at')->value);
    $this->assertSame($nid, (int) $t->get('node')->target_id, 'The dangling node reference is kept.');
    foreach ([70, 71, 72] as $id) {
      $this->container->get('entity_type.manager')->getStorage('av_tcu')->resetCache();
      $unit = AvTcu::load($id);
      $this->assertTrue($unit->isDeleted() && $unit->isDeletedWithTranscript(), "Unit $id");
    }
    $this->assertNull($this->container->get('mandala_av_transcripts.reader')->read(7, $this->accounts['bypass']));
  }

  public function testANodeWithoutATranscriptDeletesQuietly(): void {
    $node = Node::create(['type' => 'audio', 'title' => 'No transcript', 'status' => 1]);
    $node->save();
    $node->delete();
    $this->assertNull(Node::load($node->id()));
  }

  public function testATranscriptDeletedEarlierKeepsItsOriginalWhenAndWho(): void {
    [$node] = $this->fixture();
    $this->container->get('current_user')->setAccount($this->accounts['member']);
    $this->container->get('mandala_av_transcripts.lifecycle')->softDelete($this->transcript());
    $when = $this->transcript()->get('deleted_at')->value;
    $who = $this->transcript()->get('deleted_by')->target_id;

    $this->container->get('current_user')->setAccount($this->accounts['bypass']);
    $node->delete();

    $t = $this->transcript();
    $this->assertSame($when, $t->get('deleted_at')->value);
    $this->assertSame($who, $t->get('deleted_by')->target_id);
    $this->assertSame('A talk on the middle way', $t->get('node_title')->value, 'The node snapshot is still added.');
  }

  public function testAFailingCascadeNeverBlocksTheNodeDelete(): void {
    [$node] = $this->fixture();
    $failing = $this->createMock(TranscriptLifecycle::class);
    $failing->method('softDelete')->willThrowException(new \RuntimeException('boom'));
    $this->container->set('mandala_av_transcripts.lifecycle', $failing);

    $node->delete();

    $this->assertNull(Node::load($node->id()), 'The node is gone.');
    $t = $this->transcript();
    $this->assertFalse($t->isDeleted(), 'The transcript was left as it was...');
    $this->assertNull($this->container->get('mandala_av_transcripts.reader')->read(7, $this->accounts['anonymous']), '...but is unreadable without its node.');
    $this->assertFalse($t->access('view', $this->accounts['member']), '...and denied to non-administrators.');
  }

  public function testTheDeleteFormWarnsOnlyWhenThereIsALiveTranscript(): void {
    [$node] = $this->fixture();
    $note = fn(Node $n): ?string => $this->warning($n);

    $this->assertStringContainsString('transcript with 3 units', (string) $note($node));

    $this->container->get('mandala_av_transcripts.lifecycle')->softDelete($this->transcript());
    $this->assertNull($note($node), 'Nothing to warn about once it is already deleted.');

    $plain = Node::create(['type' => 'audio', 'title' => 'p', 'status' => 1]);
    $plain->save();
    $this->assertNull($note($plain));
  }

  /**
   * Runs the module's form alter on the node delete form; returns the warning text.
   */
  private function warning(Node $node): ?string {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'delete');
    $form_object->setEntity($node);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    $form = [];
    mandala_av_transcripts_form_alter($form, $form_state, 'node_audio_delete_form');
    return isset($form['av_transcript_kept']) ? (string) $form['av_transcript_kept']['text']['#markup'] : NULL;
  }

}
