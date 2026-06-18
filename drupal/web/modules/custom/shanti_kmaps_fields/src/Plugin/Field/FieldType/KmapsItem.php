<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'shanti_kmaps_fields_default' field type.
 *
 * @FieldType(
 *   id = "shanti_kmaps_fields_default",
 *   label = @Translation("KMap Term"),
 *   description = @Translation("A KMap Term reference for integrating content into the Mandala ecology."),
 *   default_widget = "kmap_tree_picker",
 *   default_formatter = "kmap_default_formatter"
 * )
 */
class KmapsItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'raw' => [
          'description' => 'The raw content of the KMap term.',
          'type' => 'text',
          'size' => 'normal',
          'not null' => TRUE,
        ],
        'id' => [
          'description' => 'The external integer ID of the KMap term.',
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
        ],
        'header' => [
          'description' => 'The default title of the KMap term.',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
        ],
        'domain' => [
          'description' => 'The KMap domain, e.g. subjects or places.',
          'type' => 'varchar',
          'length' => 20,
          'not null' => TRUE,
          'default' => '',
        ],
        'path' => [
          'description' => 'The ancestral path of the KMap term.',
          'type' => 'text',
          'size' => 'normal',
          'not null' => TRUE,
        ],
        'defids' => [
          'description' => 'Pipe-delimited UIDs of definitions associated with this asset.',
          'type' => 'text',
          'size' => 'normal',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [
        'id' => ['id'],
        'domain' => ['domain'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['raw'] = DataDefinition::create('string')
      ->setLabel(t('Raw'));
    $properties['id'] = DataDefinition::create('integer')
      ->setLabel(t('KMap ID'));
    $properties['header'] = DataDefinition::create('string')
      ->setLabel(t('Header/Name'));
    $properties['domain'] = DataDefinition::create('string')
      ->setLabel(t('Domain'));
    $properties['path'] = DataDefinition::create('string')
      ->setLabel(t('Ancestral Path'));
    $properties['defids'] = DataDefinition::create('string')
      ->setLabel(t('Definition IDs'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return [
      'kmap_domain' => 'subjects',
      'search_root_kmapid' => NULL,
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $element = [];
    $element['kmap_domain'] = [
      '#type' => 'select',
      '#title' => $this->t('KMap Domain'),
      '#description' => $this->t('The KMaps domain to use for this field.'),
      '#required' => TRUE,
      '#options' => [
        'subjects' => $this->t('Subjects'),
        'places' => $this->t('Places'),
        'terms' => $this->t('Terms'),
      ],
      '#default_value' => $this->getSetting('kmap_domain'),
    ];
    $element['search_root_kmapid'] = [
      '#type' => 'number',
      '#title' => $this->t('Search-root KMaps ID'),
      '#description' => $this->t('Restrict the picker to descendants of this KMaps node. Leave empty for whole-domain search. Examples: <code>2823</code> (collections root), <code>301</code> (language root).'),
      '#default_value' => $this->getSetting('search_root_kmapid'),
      '#min' => 1,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $raw = $this->get('raw')->getValue();
    if (empty($raw)) {
      return TRUE;
    }
    $values = array_map('trim', explode('|', $raw));
    return !isset($values[0], $values[1], $values[2]);
  }

  /**
   * Returns a composite key usable in Solr: e.g. "subjects-385".
   */
  public function getKmapKey(): string {
    return $this->get('domain')->getValue() . '-' . $this->get('id')->getValue();
  }

}
