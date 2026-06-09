<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'kmap_default_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "kmap_default_formatter",
 *   label = @Translation("KMaps Default"),
 *   field_types = {
 *     "shanti_kmaps_fields_default"
 *   }
 * )
 */
class KmapsDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, string $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $domain = $item->domain;
      $id = $item->id;
      $header = $item->header;
      $key = "{$domain}-{$id}";

      $elements[$delta] = [
        '#markup' => '<span class="kmaps-term" data-kmaps-key="' . htmlspecialchars($key) . '">'
          . htmlspecialchars($header)
          . '</span>',
      ];
    }
    return $elements;
  }

}
