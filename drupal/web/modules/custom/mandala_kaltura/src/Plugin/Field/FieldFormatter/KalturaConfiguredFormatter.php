<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mandala_kaltura\KalturaConfigResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Kaltura player formatter driven by a named AV10 preset (Sprint 3 AV9).
 *
 * Renders the same shape D7's production embed actually used
 * (field_kaltura_build_embed(), confirmed by reading that code directly --
 * see docs/planning/av10-kaltura-configuration-layer.md's "open question 2"):
 * a responsive width/height container plus a kWidget.embed() call with only
 * targetId/wid/uiconf_id/entry_id. The field item still supplies entry_id
 * (genuinely per-node data); everything else -- which player, dimensions,
 * site constants -- comes from the selected preset, not the value AV4
 * happened to freeze onto the node at migration time.
 *
 * @FieldFormatter(
 *   id = "mandala_kaltura_configured",
 *   label = @Translation("Kaltura player (configured)"),
 *   field_types = {
 *     "kaltura"
 *   }
 * )
 */
class KalturaConfiguredFormatter extends FormatterBase {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    protected readonly KalturaConfigResolver $resolver,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('mandala_kaltura.resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return ['preset' => 'default'] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $presetIds = $this->resolver->getPresetIds();
    $form['preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Player configuration'),
      '#options' => array_combine($presetIds, $presetIds),
      '#default_value' => $this->getSetting('preset'),
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [$this->t('Player configuration: @preset', ['@preset' => $this->getSetting('preset')])];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $preset = $this->resolver->resolve((string) $this->getSetting('preset'));
    if ($preset === NULL) {
      // Configured preset was removed after this display was saved -- fail
      // visibly in logs rather than silently rendering nothing useful.
      \Drupal::logger('mandala_kaltura')->error(
        'Formatter on @field references unknown preset "@preset".',
        ['@field' => $this->fieldDefinition->getName(), '@preset' => $this->getSetting('preset')],
      );
      return [];
    }

    $elements = [];
    foreach ($items as $delta => $item) {
      if (empty($item->entry_id)) {
        continue;
      }
      $elements[$delta] = [
        '#theme' => 'mandala_kaltura_player',
        '#html_id' => 'mandala-kaltura-player-' . $item->entry_id . '-' . $delta,
        '#partner_id' => $preset['partner_id'],
        '#subp_id' => $preset['subp_id'],
        '#server_url' => $preset['server_url'],
        '#uiconf_id' => $preset['custom_player'] ?: $preset['uiconf_id'],
        '#entry_id' => $item->entry_id,
        '#width' => $preset['player_width'],
        '#height' => $preset['player_height'],
      ];
    }
    return $elements;
  }

}
