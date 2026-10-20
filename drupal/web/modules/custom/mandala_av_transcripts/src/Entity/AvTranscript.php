<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mandala_av_transcripts\Access\AvTranscriptAccessControlHandler;
use Drupal\mandala_av_transcripts\AvTranscriptStorageSchema;
use Drupal\node\NodeInterface;

/**
 * One transcript per AV node (Spike 11, "Sketch for the recommended model").
 *
 * Non-fieldable on purpose: base fields only, no Field UI, no per-unit field
 * tables. Revisionable, but its revisions carry no text; they are an audit
 * trail of structural operations (inserted units, reverted to upload). Text
 * history lives on av_tcu.
 *
 * The id is D7's `trid` and is set explicitly by the migration. Ids the
 * database allocates start at ID_FLOOR (see AvTranscriptIdSequence), clear of
 * D7's range, because legacy D7 is still in production and keeps minting
 * trids until cutover. The id doubles as the kmassets `trid_i`.
 *
 * Access is the parent node's: see AvTranscriptAccessControlHandler.
 */
#[ContentEntityType(
  id: 'av_transcript',
  label: new TranslatableMarkup('AV transcript'),
  label_collection: new TranslatableMarkup('AV transcripts'),
  label_singular: new TranslatableMarkup('AV transcript'),
  label_plural: new TranslatableMarkup('AV transcripts'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AvTranscriptAccessControlHandler::class,
    'storage_schema' => AvTranscriptStorageSchema::class,
  ],
  base_table: 'av_transcript',
  revision_table: 'av_transcript_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
  translatable: FALSE,
  admin_permission: 'administer av transcripts',
)]
class AvTranscript extends RevisionableContentEntityBase implements AvTranscriptInterface {

  use EntityChangedTrait;
  use RevisionLogEntityTrait;

  /**
   * First id the database allocates; D7's highest trid at 2026-10-15 was 27,016.
   */
  public const ID_FLOOR = 100000;

  /**
   * Cache tag for "this transcript's units changed" (invalidated by AvTcu).
   */
  public static function unitsCacheTag(int $trid): string {
    return 'av_transcript_units:' . $trid;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('AV node'))
      ->setDescription(new TranslatableMarkup('The audio or video node this transcript belongs to. One transcript per node.'))
      ->setSetting('target_type', 'node')
      ->setRequired(TRUE)
      ->setRevisionable(FALSE)
      ->addConstraint('UniqueField');

    $fields['deleted'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Deleted'))
      ->setDescription(new TranslatableMarkup('Soft-delete flag (ADR 019). A deleted transcript is hidden from everyone but administrators; its units and revisions are kept.'))
      ->setDefaultValue(FALSE)
      ->setInitialValue(FALSE)
      ->setRevisionable(FALSE);

    $fields['deleted_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Deleted at'))
      ->setDescription(new TranslatableMarkup('When it was soft-deleted. Empty while live.'))
      ->setRevisionable(FALSE);

    $fields['deleted_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Deleted by'))
      ->setDescription(new TranslatableMarkup('Who soft-deleted it. Empty while live.'))
      ->setSetting('target_type', 'user')
      ->setRevisionable(FALSE);

    // A snapshot of the node, taken when the node is deleted: its title, legacy
    // key and collections vanish with it, and an administrator reviewing the
    // orphan needs them to know what it was.
    $fields['node_title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Node title when deleted'))
      ->setSetting('max_length', 255)
      ->setRevisionable(FALSE);

    $fields['node_legacy_site'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Node legacy site when deleted'))
      ->setDescription(new TranslatableMarkup('field_legacy_site of the node (ADR 017).'))
      ->setSetting('max_length', 64)
      ->setRevisionable(FALSE);

    $fields['node_legacy_nid'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Node legacy nid when deleted'))
      ->setDescription(new TranslatableMarkup('field_legacy_nid of the node (ADR 017).'))
      ->setSetting('unsigned', TRUE)
      ->setRevisionable(FALSE);

    $fields['node_collections'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Node collections when deleted'))
      ->setDescription(new TranslatableMarkup('JSON list of the ids of the groups the node belonged to.'))
      ->setRevisionable(FALSE);

    $fields['source_file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Source file'))
      ->setDescription(new TranslatableMarkup('The originally uploaded transcript file, the base for revert-to-upload.'))
      ->setSetting('target_type', 'file')
      ->setRevisionable(TRUE);

    $fields['source_format'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Source format'))
      ->setSetting('allowed_values', [
        'vtt' => 'WebVTT',
        'xml' => 'XML',
        'txt' => 'Toolbox',
        'srt' => 'SRT',
      ])
      ->setRevisionable(TRUE);

    $fields['tiers'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Tiers'))
      ->setDescription(new TranslatableMarkup('JSON list of tier names, in display order. Use getTiers() / setTiers().'))
      ->setRevisionable(TRUE);

    $fields['has_edits'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Edited since upload'))
      ->setDescription(new TranslatableMarkup('D7 keep_transcript_edits: staff have changed the text since the upload.'))
      ->setDefaultValue(FALSE)
      ->setRevisionable(TRUE);

    $fields['edits_flagged_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Edits flagged at'))
      ->setRevisionable(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Transcript ' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getNode(): ?NodeInterface {
    $node = $this->get('node')->entity;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isDeleted(): bool {
    return (bool) $this->get('deleted')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDeleted(bool $deleted): static {
    $this->set('deleted', $deleted);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasEdits(): bool {
    return (bool) $this->get('has_edits')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTiers(): array {
    $raw = $this->get('tiers')->value;
    $decoded = ($raw === NULL || $raw === '') ? [] : json_decode($raw, TRUE);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setTiers(array $tiers): static {
    $this->set('tiers', json_encode(array_values($tiers), JSON_UNESCAPED_UNICODE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    \Drupal::service('mandala_av_transcripts.change_notifier')->transcriptSaved($this, !$update);
  }

  /**
   * {@inheritdoc}
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    $notifier = \Drupal::service('mandala_av_transcripts.change_notifier');
    foreach ($entities as $transcript) {
      assert($transcript instanceof self);
      $notifier->transcriptDeleted($transcript);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->hasEdits() && $this->get('edits_flagged_at')->isEmpty()) {
      $this->set('edits_flagged_at', \Drupal::time()->getRequestTime());
    }
  }

}
