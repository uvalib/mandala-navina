<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\mandala_kaltura\KalturaConfigResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * AV12: browser-direct chunked upload widget for the `kaltura` field type.
 *
 * A separate plugin from contrib `kaltura_media`'s own `KalturaWidget`
 * (plain manual-entry text boxes), not an edit of it in place, so the
 * manual-entry widget stays available as a fallback. Targets the same
 * `field_types = {"kaltura"}` and renders the same four hidden-field keys
 * (`entry_id`/`partner_id`/`uiconf_id`/`domain`) that widget uses, so
 * massageFormValues() below is nearly identical to contrib's.
 *
 * The actual upload never touches this class or any Drupal request cycle
 * -- js/kaltura-upload.js uploads straight to Kaltura from the browser
 * using a session minted by AV11 (mandala_kaltura.upload_session), and
 * only writes the four hidden fields on success. This widget's job is
 * purely to render the file input + hidden fields and attach that JS.
 *
 * @FieldWidget(
 *   id = "kaltura_upload",
 *   label = @Translation("Kaltura (browser upload)"),
 *   field_types = {"kaltura"},
 * )
 */
class KalturaUploadWidget extends WidgetBase {

  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected readonly KalturaConfigResolver $resolver,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   *
   * WidgetBase::create() relies on core's constructor autowiring, which
   * only resolves services that carry a class-based autowire alias --
   * true for core services like ImageWidget's ElementInfoManagerInterface,
   * but not for this module's own `mandala_kaltura.resolver` (confirmed
   * live: autowiring throws AutowiringFailedException for it). Overriding
   * create() to fetch it by service id explicitly is the fix.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('mandala_kaltura.resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta] ?? NULL;

    // The two node bundles this field type is used on today (Sprint 3 AV) --
    // matches how AV9's KalturaConfiguredFormatter and this field's own
    // storage are scoped, not a generic "any entity" assumption.
    $bundle = $items->getEntity()->bundle();
    $mediaType = $bundle === 'audio' ? 'audio' : 'video';

    $preset = $this->resolver->resolve('default');
    $playerUiconfId = $preset['uiconf_id'] ?? '';

    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'mandala-kaltura-upload';
    $element['#attributes']['data-kaltura-media-type'] = $mediaType;
    $element['#attributes']['data-kaltura-player-uiconf-id'] = $playerUiconfId;

    $element['upload'] = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'type' => 'file',
        // Deliberately outside Drupal's managed-file machinery -- the file
        // is never submitted with this form. JS intercepts the change
        // event and uploads directly to Kaltura (AV12); Drupal only ever
        // sees the resulting entry_id below.
        'accept' => $mediaType === 'audio' ? 'audio/*' : 'video/*',
      ],
    ];

    $element['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['class' => ['mandala-kaltura-upload-status']],
    ];

    foreach (['entry_id', 'partner_id', 'uiconf_id', 'domain'] as $key) {
      $element[$key] = [
        '#type' => 'hidden',
        '#default_value' => $item->{$key} ?? NULL,
        '#attributes' => ['data-kaltura-field' => $key],
      ];
    }

    $element['#attached']['library'][] = 'mandala_kaltura/kaltura-upload';
    $element['#attached']['drupalSettings']['mandalaKaltura']['uploadSessionUrl'] =
      Url::fromRoute('mandala_kaltura.upload_session')->toString();

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $violation, array $form, FormStateInterface $form_state) {
    return isset($violation->arrayPropertyPath[0]) ? $element[$violation->arrayPropertyPath[0]] : $element;
  }

  /**
   * {@inheritdoc}
   *
   * Matches contrib KalturaWidget::massageFormValues() -- empty string
   * becomes NULL for all four columns, same field storage either widget
   * writes to.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as $delta => $value) {
      foreach (['entry_id', 'partner_id', 'uiconf_id', 'domain'] as $key) {
        if (($value[$key] ?? '') === '') {
          $values[$delta][$key] = NULL;
        }
      }
    }
    return $values;
  }

}
