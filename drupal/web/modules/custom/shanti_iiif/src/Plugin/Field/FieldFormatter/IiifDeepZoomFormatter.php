<?php

namespace Drupal\shanti_iiif\Plugin\Field\FieldFormatter;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a static thumbnail plus a click-to-open OpenSeadragon deep-zoom
 * viewer, sourced from the entity's IIIF identifier field.
 *
 * Ports D7's shanti-main-images.js click-to-open SeaDragon overlay
 * (sarvaka_images theme) to a Drupal behavior: the OpenSeadragon library and
 * viewer instance are only created on first click, not on page load.
 *
 * @FieldFormatter(
 *   id = "iiif_deep_zoom",
 *   label = @Translation("IIIF deep-zoom viewer"),
 *   field_types = {
 *     "image"
 *   }
 * )
 */
class IiifDeepZoomFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    protected IiifUrlBuilder $urlBuilder,
    protected ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('shanti_iiif.url_builder'),
      $container->get('extension.list.module'),
    );
  }

  public static function defaultSettings(): array {
    return [
      'width' => 800,
      'height' => '',
      'scaled' => TRUE,
      'iiif_id_field' => 'field_iiif_id',
      'rotation_field' => 'field_image_rotation',
    ] + parent::defaultSettings();
  }

  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $elements = parent::settingsForm($form, $form_state);
    $elements['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail width (px)'),
      '#default_value' => $this->getSetting('width'),
      '#min' => 1,
      '#description' => $this->t('Size of the static thumbnail shown before the viewer opens. The deep-zoom viewer itself always fetches full resolution tiles from the IIIF server regardless of this setting.'),
    ];
    $elements['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail height (px)'),
      '#default_value' => $this->getSetting('height'),
      '#min' => 1,
    ];
    $elements['scaled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Scale thumbnail within bounds (preserves aspect ratio)'),
      '#default_value' => (bool) $this->getSetting('scaled'),
    ];
    $elements['iiif_id_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IIIF identifier field'),
      '#default_value' => $this->getSetting('iiif_id_field'),
      '#description' => $this->t('Machine name of the field on this entity that holds the IIIF identifier (e.g. <code>field_iiif_id</code>).'),
      '#required' => TRUE,
    ];
    $elements['rotation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rotation field'),
      '#default_value' => $this->getSetting('rotation_field'),
      '#description' => $this->t('Machine name of an integer field (degrees) that sets the initial viewer rotation. Leave blank for no rotation.'),
    ];
    return $elements;
  }

  public function settingsSummary(): array {
    $w = $this->getSetting('width') ?: 'auto';
    $h = $this->getSetting('height') ?: 'auto';
    return [
      $this->t('Thumbnail: @w × @h, deep-zoom viewer on click', ['@w' => $w, '@h' => $h]),
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

    $rotation_field = $this->getSetting('rotation_field');
    $rotation = 0;
    if ($rotation_field && $entity->hasField($rotation_field) && !$entity->get($rotation_field)->isEmpty()) {
      $rotation = (int) $entity->get($rotation_field)->value;
    }

    $thumb_url = $this->urlBuilder->buildUrl(
      $i3fid,
      $this->getSetting('width') ?: NULL,
      $this->getSetting('height') ?: NULL,
      $rotation,
      'full',
      (bool) $this->getSetting('scaled'),
    );
    $info_url = $this->urlBuilder->infoUrl($i3fid);

    $alt = '';
    foreach ($items as $item) {
      if (!$item->isEmpty() && !empty($item->alt)) {
        $alt = $item->alt;
        break;
      }
    }

    $images_path = base_path() . $this->moduleExtensionList->getPath('shanti_iiif') . '/js/vendor/openseadragon/images';

    return [
      [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['iiif-deep-zoom'],
          'data-iiif-info-url' => $info_url,
          'data-iiif-rotation' => (string) $rotation,
        ],
        'thumbnail' => [
          '#theme' => 'image',
          '#uri' => $thumb_url,
          '#alt' => $alt,
          '#attributes' => [
            'class' => ['iiif-image'],
            'loading' => 'lazy',
            'data-iiif-id' => $i3fid,
          ],
        ],
        'trigger' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('View full image'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['iiif-deep-zoom-trigger'],
            'aria-label' => $this->t('Open zoomable image viewer'),
          ],
        ],
        '#attached' => [
          'library' => ['shanti_iiif/deep-zoom-viewer'],
          'drupalSettings' => [
            'shantiIiif' => [
              'imagesPath' => $images_path,
            ],
          ],
        ],
      ],
    ];
  }

}
