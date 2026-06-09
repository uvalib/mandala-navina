<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'kmap_tree_picker' widget.
 *
 * @FieldWidget(
 *   id = "kmap_tree_picker",
 *   label = @Translation("KMaps Tree Picker"),
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
    int $delta,
    array $element,
    array &$form,
    FormStateInterface $form_state
  ): array {
    $item = $items[$delta] ?? NULL;
    $raw = $item ? $item->raw : '';
    $header = $item ? $item->header : '';

    // The hidden raw field stores the pipe-delimited value: raw|id|header|domain|path|defids
    $element['raw'] = [
      '#type' => 'hidden',
      '#default_value' => $raw,
      '#attributes' => ['class' => ['kmaps-field-raw']],
    ];

    // Visible label for the selected term (read-only display; JS populates this)
    $element['header_display'] = [
      '#type' => 'textfield',
      '#title' => $element['#title'] ?? $this->t('KMap Term'),
      '#default_value' => $header,
      '#attributes' => [
        'class' => ['kmaps-field-header-display'],
        'readonly' => 'readonly',
      ],
      '#description' => $this->t('Use the tree picker to select a KMaps term.'),
    ];

    // TODO: attach Fancytree / KMaps widget JS library here once ported.
    // $element['#attached']['library'][] = 'shanti_kmaps_fields/tree_picker';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      $raw = trim($value['raw'] ?? '');
      if (empty($raw)) {
        continue;
      }
      // Raw format: id|header|domain|path|defids  (pipe-delimited)
      $parts = array_map('trim', explode('|', $raw));
      $value['raw'] = $raw;
      $value['id'] = isset($parts[0]) ? (int) $parts[0] : 0;
      $value['header'] = $parts[1] ?? '';
      $value['domain'] = $parts[2] ?? '';
      $value['path'] = $parts[3] ?? '';
      $value['defids'] = $parts[4] ?? '';
    }
    return $values;
  }

}
