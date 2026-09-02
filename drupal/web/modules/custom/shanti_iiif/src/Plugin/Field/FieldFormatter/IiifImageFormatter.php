<?php

namespace Drupal\shanti_iiif\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Renders an image via the configured IIIF server using the entity's
 * field_iiif_id rather than the file URL of the image field itself.
 *
 * Attaches to image fields. The image field still holds the uploaded source
 * (for fallback / non-IIIF environments / future re-upload), but display goes
 * through IIIF using the i3fid carried by the entity.
 *
 * @FieldFormatter(
 *   id = "iiif_image",
 *   label = @Translation("IIIF image"),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class IiifImageFormatter extends FormatterBase {

  public static function defaultSettings(): array {
    return [
      'width' => 800,
      'height' => '',
      'rotation' => 0,
      'scaled' => TRUE,
      'upscale' => FALSE,
      'iiif_id_field' => 'field_iiif_id',
      'rotation_field' => '',
    ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);
    $elements['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width (px)'),
      '#default_value' => $this->getSetting('width'),
      '#min' => 1,
      '#description' => $this->t('Target width passed to IIIF. Leave blank with height blank for full-size.'),
    ];
    $elements['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (px)'),
      '#default_value' => $this->getSetting('height'),
      '#min' => 1,
      '#description' => $this->t('Optional. If blank with "scaled" on, IIIF will scale to fit width while preserving aspect ratio.'),
    ];
    $elements['rotation'] = [
      '#type' => 'select',
      '#title' => $this->t('Rotation'),
      '#default_value' => $this->getSetting('rotation'),
      '#options' => [0 => '0°', 90 => '90°', 180 => '180°', 270 => '270°'],
      '#description' => $this->t('Fallback used when "Rotation field" below is blank or empty on the entity.'),
    ];
    $elements['rotation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rotation field'),
      '#default_value' => $this->getSetting('rotation_field'),
      '#description' => $this->t('Optional. Machine name of an integer field (degrees) that overrides the static "Rotation" setting above per-entity, e.g. <code>field_image_rotation</code>. Leave blank to always use the static value.'),
    ];
    $elements['scaled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Scale within bounds (preserves aspect ratio)'),
      '#default_value' => (bool) $this->getSetting('scaled'),
      '#description' => $this->t('Uses IIIF "!w,h" size syntax. Off uses exact "w,h" which can distort.'),
    ];
    $elements['upscale'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow upscaling past the source image'),
      '#default_value' => (bool) $this->getSetting('upscale'),
      '#description' => $this->t('Uses IIIF "^!w,h" size syntax. Only applies when "Scale within bounds" is on. Useful for fixed-size grid tiles that must fill their box regardless of source resolution.'),
      '#states' => [
        'visible' => [
          ':input[name$="[settings_edit_form][settings][scaled]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $elements['iiif_id_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IIIF identifier field'),
      '#default_value' => $this->getSetting('iiif_id_field'),
      '#description' => $this->t('Machine name of the field on this entity that holds the IIIF identifier (e.g. <code>field_iiif_id</code>).'),
      '#required' => TRUE,
    ];
    return $elements;
  }

  public function settingsSummary(): array {
    $w = $this->getSetting('width') ?: 'auto';
    $h = $this->getSetting('height') ?: 'auto';
    $scaled = $this->getSetting('scaled') ? 'scaled' : 'exact';
    $rotation_field = $this->getSetting('rotation_field');
    $rotation_summary = $rotation_field
      ? $this->t('rotation from @f (falls back to @r°)', [
        '@f' => $rotation_field,
        '@r' => $this->getSetting('rotation'),
      ])
      : $this->t('rotation @r°', ['@r' => $this->getSetting('rotation')]);
    return [
      $this->t('Size: @w × @h (@scaled), @rotation', [
        '@w' => $w,
        '@h' => $h,
        '@scaled' => $scaled,
        '@rotation' => $rotation_summary,
      ]),
      $this->t('IIIF id from: @f', ['@f' => $this->getSetting('iiif_id_field')]),
    ];
  }

  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $entity = $items->getEntity();
    $id_field = $this->getSetting('iiif_id_field');

    if (!$entity->hasField($id_field) || $entity->get($id_field)->isEmpty()) {
      return [];
    }
    $i3fid = trim((string) $entity->get($id_field)->value);
    if ($i3fid === '') {
      return [];
    }

    $rotation = (int) $this->getSetting('rotation');
    $rotation_field = $this->getSetting('rotation_field');
    if ($rotation_field && $entity->hasField($rotation_field) && !$entity->get($rotation_field)->isEmpty()) {
      $rotation = (int) $entity->get($rotation_field)->value;
    }

    $builder = \Drupal::service('shanti_iiif.url_builder');
    $url = $builder->buildUrl(
      $i3fid,
      $this->getSetting('width') ?: NULL,
      $this->getSetting('height') ?: NULL,
      $rotation,
      'full',
      (bool) $this->getSetting('scaled'),
      'jpg',
      (bool) $this->getSetting('upscale'),
    );

    // One element rather than per-item: the IIIF identifier is per-entity,
    // not per-image-item. The image field's items contribute alt text only.
    $alt = '';
    foreach ($items as $item) {
      if (!$item->isEmpty() && !empty($item->alt)) {
        $alt = $item->alt;
        break;
      }
    }

    return [
      [
        '#theme' => 'image',
        '#uri' => $url,
        '#alt' => $alt,
        '#attributes' => [
          'class' => ['iiif-image'],
          'loading' => 'lazy',
          'data-iiif-id' => $i3fid,
        ],
      ],
    ];
  }

}
