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
 * Access is not defined here yet: with no access handler Drupal denies
 * everyone except administrators, which fails closed until the access phase.
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
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->hasEdits() && $this->get('edits_flagged_at')->isEmpty()) {
      $this->set('edits_flagged_at', \Drupal::time()->getRequestTime());
    }
  }

}
