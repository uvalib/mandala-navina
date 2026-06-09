<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * KMaps term picker widget using Drupal autocomplete.
 *
 * Renders one autocomplete input per delta. The user types a term name,
 * selects from suggestions (fetched from the kmterms Solr index via the
 * shanti_kmaps_fields.autocomplete route), and the selection is stored as
 * the pipe-delimited raw value: id|header|domain|path|defids
 *
 * @FieldWidget(
 *   id = "kmap_tree_picker",
 *   label = @Translation("KMaps Term Picker"),
 *   field_types = {
 *     "shanti_kmaps_fields_default"
 *   }
 * )
 */
class KmapsTreePickerWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    array $element,
    array &$form,
    FormStateInterface $form_state
  ): array {
    $item = $items[$delta] ?? NULL;
    $domain = $this->fieldDefinition->getSetting('kmap_domain') ?: 'subjects';

    // Current stored value as raw pipe-delimited string.
    $raw = ($item && !$item->isEmpty()) ? $this->itemToRaw($item) : '';

    // Human-readable display: "header (domain-id)" matches what autocomplete returns as label.
    $display = '';
    if ($item && !$item->isEmpty()) {
      $display = $item->header . ' (' . $item->domain . '-' . $item->id . ')';
    }

    // Autocomplete URL for this domain.
    $autocomplete_url = Url::fromRoute('shanti_kmaps_fields.autocomplete', ['domain' => $domain])->toString();

    $element['#type'] = 'container';
    $element['#attributes']['class'][] = 'kmaps-field-item';

    // The visible autocomplete input. Its displayed value is the human label;
    // on selection the JS copies the machine 'value' into the hidden raw field.
    $element['search'] = [
      '#type' => 'textfield',
      '#title' => $element['#title'] ?? $this->t('KMaps @domain term', ['@domain' => ucfirst($domain)]),
      '#title_display' => $delta === 0 ? 'before' : 'invisible',
      '#default_value' => $display,
      '#autocomplete_route_name' => 'shanti_kmaps_fields.autocomplete',
      '#autocomplete_route_parameters' => ['domain' => $domain],
      '#size' => 60,
      '#maxlength' => 512,
      '#attributes' => [
        'class' => ['kmaps-search-input'],
        'data-kmaps-raw-target' => 'kmaps-raw-' . $domain . '-' . $delta,
      ],
      '#description' => $delta === 0 ? $this->t('Type to search for a @domain KMaps term.', ['@domain' => $domain]) : '',
    ];

    // Hidden field that holds the authoritative pipe-delimited raw value.
    // JS updates this when a suggestion is selected from the autocomplete.
    $element['raw'] = [
      '#type' => 'hidden',
      '#default_value' => $raw,
      '#attributes' => [
        'class' => ['kmaps-raw-value'],
        'id' => 'kmaps-raw-' . $domain . '-' . $delta,
      ],
    ];

    $element['#attached']['library'][] = 'shanti_kmaps_fields/kmaps_widget';

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Parses the raw pipe-delimited value (set by JS on autocomplete select)
   * back into the individual field columns.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $result = [];
    foreach ($values as $value) {
      $raw = trim($value['raw'] ?? '');
      if ($raw === '') {
        // Fall back: user may have typed directly into the search box without
        // selecting from autocomplete — raw stays empty, skip this delta.
        continue;
      }

      // raw format: id|header|domain|path|defids
      $parts = array_pad(explode('|', $raw, 5), 5, '');
      [$id, $header, $domain, $path, $defids] = $parts;

      if (!is_numeric($id) || empty($header) || empty($domain)) {
        continue;
      }

      $result[] = [
        'raw'    => $raw,
        'id'     => (int) $id,
        'header' => $header,
        'domain' => $domain,
        'path'   => $path,
        'defids' => $defids,
      ];
    }
    return $result;
  }

  /**
   * Converts a loaded field item back to the pipe-delimited raw string.
   */
  private function itemToRaw($item): string {
    return implode('|', [
      $item->id ?? '',
      $item->header ?? '',
      $item->domain ?? '',
      $item->path ?? '',
      $item->defids ?? '',
    ]);
  }

}
