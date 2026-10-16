<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mandala_av_transcripts\Access\AvTcuAccessControlHandler;
use Drupal\mandala_av_transcripts\AvTcuStorageSchema;

/**
 * One time-coded unit (TCU) of a transcript (Spike 11).
 *
 * Non-fieldable: base fields only. Revisionable per unit, so an edit to one
 * unit's text writes one small revision row and never touches its neighbours.
 *
 * The id is D7's `tcuid` (so `#tcu/NNN` deep links keep working) and is set
 * explicitly by the migration. Ids the database allocates start at ID_FLOOR,
 * clear of D7's range (see AvTranscriptIdSequence).
 *
 * Timecodes are DECIMAL(10,3) seconds. Bad values from D7 are carried as found:
 * `timecode_status` classifies them and `end_original` keeps the pre-fix end
 * whenever a fix has been applied. `end` is nullable (the last unit of a
 * transcript may have none).
 *
 * Access is the parent node's: see AvTcuAccessControlHandler.
 */
#[ContentEntityType(
  id: 'av_tcu',
  label: new TranslatableMarkup('AV transcript unit'),
  label_collection: new TranslatableMarkup('AV transcript units'),
  label_singular: new TranslatableMarkup('AV transcript unit'),
  label_plural: new TranslatableMarkup('AV transcript units'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'storage' => SqlContentEntityStorage::class,
    'access' => AvTcuAccessControlHandler::class,
    'storage_schema' => AvTcuStorageSchema::class,
  ],
  base_table: 'av_tcu',
  revision_table: 'av_tcu_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
  translatable: FALSE,
  admin_permission: 'administer av transcripts',
)]
class AvTcu extends RevisionableContentEntityBase implements AvTcuInterface {

  use EntityChangedTrait;
  use RevisionLogEntityTrait;

  /**
   * First id the database allocates; D7's highest tcuid at 2026-10-15 was 1,394,104.
   */
  public const ID_FLOOR = 2000000;

  /**
   * Allowed timecode_status values (classifier arrives in a later phase).
   */
  public const TIMECODE_STATUSES = [
    'ok' => 'OK',
    'zero_length_shared_start' => 'Zero length, shares its start with the next unit',
    'zero_length_other' => 'Zero length, other',
    'inverted' => 'End before start',
    'last_unit_no_end' => 'Last unit, no end',
    'end_implausible' => 'End implausibly late',
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    $fields['transcript'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Transcript'))
      ->setSetting('target_type', 'av_transcript')
      ->setRequired(TRUE)
      ->setRevisionable(FALSE);

    $fields['sequence'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Sequence'))
      ->setDescription(new TranslatableMarkup('Position of the unit within its transcript, from 0.'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['start'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Start (s)'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 3)
      ->setRequired(TRUE)
      ->setRevisionable(TRUE);

    $fields['end'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('End (s)'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 3)
      ->setRevisionable(TRUE);

    $fields['end_original'] = BaseFieldDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('End before correction (s)'))
      ->setDescription(new TranslatableMarkup('Set only when the end was changed from the value found in D7.'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 3)
      ->setRevisionable(TRUE);

    $fields['timecode_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Timecode status'))
      ->setSetting('allowed_values', static::TIMECODE_STATUSES)
      ->setDefaultValue('ok')
      ->setRevisionable(TRUE);

    $fields['tiers'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Tier text'))
      ->setDescription(new TranslatableMarkup('JSON object, tier name to text. Use getTiers() / setTiers().'))
      ->setRevisionable(TRUE);

    $fields['speakers'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Speakers'))
      ->setDescription(new TranslatableMarkup('JSON object, speaker tier name to value. Use getSpeakers() / setSpeakers().'))
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
    return 'Unit ' . $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getTranscript(): ?AvTranscriptInterface {
    $transcript = $this->get('transcript')->entity;
    return $transcript instanceof AvTranscriptInterface ? $transcript : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTiers(): array {
    return $this->decodeMap('tiers');
  }

  /**
   * {@inheritdoc}
   */
  public function setTiers(array $tiers): static {
    return $this->encodeMap('tiers', $tiers);
  }

  /**
   * {@inheritdoc}
   */
  public function getSpeakers(): array {
    return $this->decodeMap('speakers');
  }

  /**
   * {@inheritdoc}
   */
  public function setSpeakers(array $speakers): static {
    return $this->encodeMap('speakers', $speakers);
  }

  /**
   * Decodes a JSON-object field to an array, tolerating empty values.
   */
  private function decodeMap(string $field): array {
    $raw = $this->get($field)->value;
    $decoded = ($raw === NULL || $raw === '') ? [] : json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Encodes an array to a JSON object (an empty map stays {}).
   */
  private function encodeMap(string $field, array $map): static {
    $this->set($field, json_encode((object) $map, JSON_UNESCAPED_UNICODE));
    return $this;
  }

}
