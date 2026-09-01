<?php

declare(strict_types=1);

namespace Drupal\shanti_grid_view\Plugin\views\style;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A PIG.js masonry grid, ported from D7's shanti_grid_view.
 *
 * Scoped to the entity/node case only -- D7's module also supported an
 * arbitrary "data source" mode (shanti/grid/dinfo, field-mapping strings)
 * for non-entity views; not ported here, see
 * docs/planning/b3-masonry-gallery-production-reference.md.
 *
 * Unlike D7 (which computed aspect ratio/rotation/thumbnail URLs directly
 * off the raw SQL result row in hook_views_pre_render(), avoiding per-row
 * entity loads for a 108k-row gallery), this reads $row->_entity -- Views'
 * standard populated entity for an entity-base view. If per-row entity
 * loads turn out to be a real performance problem at Images' actual scale,
 * that's the place to optimize first (see this doc's "What this doc does
 * NOT establish").
 */
#[ViewsStyle(
  id: 'shanti_grid_view',
  title: new TranslatableMarkup('Masonry grid (Shanti)'),
  help: new TranslatableMarkup('Displays rows as a PIG.js masonry grid with a click-to-open info panel. Requires an entity (node) base view.'),
  theme: 'shanti_grid_view',
  display_types: ['normal'],
)]
class GridView extends StylePluginBase {

  protected $usesRowPlugin = FALSE;

  protected $usesFields = FALSE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected IiifUrlBuilder $iiifUrlBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('shanti_iiif.url_builder'),
    );
  }

  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['iiif_id_field'] = ['default' => 'field_iiif_id'];
    $options['width_field'] = ['default' => 'field_iiif_width'];
    $options['height_field'] = ['default' => 'field_iiif_height'];
    $options['rotation_field'] = ['default' => 'field_image_rotation'];
    $options['thumbnail_height'] = ['default' => 250];
    return $options;
  }

  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['iiif_id_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IIIF identifier field'),
      '#default_value' => $this->options['iiif_id_field'],
      '#description' => $this->t('Machine name of the field on each result entity holding the IIIF identifier.'),
      '#required' => TRUE,
    ];
    $form['width_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Width field'),
      '#default_value' => $this->options['width_field'],
      '#description' => $this->t('Machine name of the integer field holding the source image width in pixels, used to precompute masonry aspect ratio server-side (no layout shift while thumbnails load).'),
      '#required' => TRUE,
    ];
    $form['height_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Height field'),
      '#default_value' => $this->options['height_field'],
      '#required' => TRUE,
    ];
    $form['rotation_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rotation field'),
      '#default_value' => $this->options['rotation_field'],
      '#description' => $this->t('Optional. Machine name of an integer degrees field. When 90/270, aspect ratio is inverted to match the rotated tile shape. Leave blank to ignore rotation.'),
    ];
    $form['thumbnail_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Target row height (px)'),
      '#default_value' => $this->options['thumbnail_height'],
      '#min' => 50,
      '#description' => $this->t('PIG.js masonry row height target before fitting tiles to width.'),
    ];
  }

  public function render() {
    $id_field = $this->options['iiif_id_field'];
    $width_field = $this->options['width_field'];
    $height_field = $this->options['height_field'];
    $rotation_field = $this->options['rotation_field'];

    $thumbnail_height = (int) $this->options['thumbnail_height'];
    $images = [];
    foreach ($this->view->result as $row) {
      $entity = $row->_entity ?? NULL;
      if (!$entity instanceof NodeInterface) {
        continue;
      }
      if (!$entity->hasField($id_field) || $entity->get($id_field)->isEmpty()) {
        continue;
      }
      $filename = trim((string) $entity->get($id_field)->value);
      if ($filename === '') {
        continue;
      }

      $width = ($entity->hasField($width_field) && !$entity->get($width_field)->isEmpty())
        ? (float) $entity->get($width_field)->value : 0;
      $height = ($entity->hasField($height_field) && !$entity->get($height_field)->isEmpty())
        ? (float) $entity->get($height_field)->value : 0;
      $aspect_ratio = ($width > 0 && $height > 0) ? ($width / $height) : 1.0;

      $rotation = 0;
      if ($rotation_field && $entity->hasField($rotation_field) && !$entity->get($rotation_field)->isEmpty()) {
        $rotation = (int) $entity->get($rotation_field)->value;
      }
      // A 90/270 rotation swaps effective width/height for layout purposes,
      // same handling as shanti_grid_view_views_pre_render() in D7.
      if ($rotation > 0 && ($rotation / 90) % 2 === 1) {
        $aspect_ratio = $aspect_ratio > 0 ? (1 / $aspect_ratio) : 1.0;
      }

      // Thumbnail sized by height only ("^!,{h}" -- fill exactly to the
      // masonry row height, upscale allowed, width left to the image's own
      // aspect ratio) so one URL works for the whole row regardless of a
      // given image's native resolution.
      $thumb_url = $this->iiifUrlBuilder->buildUrl($filename, NULL, $thumbnail_height, $rotation, 'full', TRUE, 'jpg', TRUE);

      $images[] = [
        'nid' => (int) $entity->id(),
        'aspectRatio' => round($aspect_ratio, 4),
        'title' => $entity->label(),
        'thumbUrl' => $thumb_url,
        'infoUrl' => '/shanti/grid/info/node/' . $entity->id(),
      ];
    }

    $dom_id = Html::getUniqueId('shanti-grid-view');

    return [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#dom_id' => $dom_id,
      '#attached' => [
        'library' => ['shanti_grid_view/masonry-grid'],
        'drupalSettings' => [
          'shantiGridView' => [
            $dom_id => [
              'images' => $images,
              'thumbnailHeight' => $thumbnail_height,
            ],
          ],
        ],
      ],
    ];
  }

}
