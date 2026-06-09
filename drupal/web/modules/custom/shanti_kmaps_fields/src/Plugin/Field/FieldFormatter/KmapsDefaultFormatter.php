<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Renders KMaps terms as linked tags pointing to the KMaps explorer.
 *
 * @FieldFormatter(
 *   id = "kmap_default_formatter",
 *   label = @Translation("KMaps Tags"),
 *   field_types = {
 *     "shanti_kmaps_fields_default"
 *   }
 * )
 */
class KmapsDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    if ($items->isEmpty()) {
      return [];
    }

    $config = \Drupal::config('shanti_kmaps_admin.settings');
    $tags = [];

    foreach ($items as $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $domain  = $item->domain ?? '';
      $id      = (int) ($item->id ?? 0);
      $header  = $item->header ?? '';
      $key     = "{$domain}-{$id}";

      // Build explorer link if configured.
      $explorer_key = 'explorer_' . $domain;
      $explorer_tpl = $config->get($explorer_key) ?? '';
      $explorer_url = str_replace('__KMAPID__', $id, $explorer_tpl);

      if ($explorer_url) {
        $tag = [
          '#type' => 'link',
          '#title' => $header,
          '#url' => \Drupal\Core\Url::fromUri($explorer_url),
          '#options' => ['attributes' => [
            'class' => ['kmaps-term-tag'],
            'data-kmaps-key' => $key,
            'target' => '_blank',
          ]],
        ];
      }
      else {
        $tag = [
          '#markup' => '<span class="kmaps-term-tag" data-kmaps-key="' . htmlspecialchars($key) . '">'
            . htmlspecialchars($header) . '</span>',
        ];
      }
      $tags[] = $tag;
    }

    if (empty($tags)) {
      return [];
    }

    return [[
      '#theme' => 'kmaps_field_tags',
      '#tags' => $tags,
      '#attached' => ['library' => ['shanti_kmaps_fields/kmaps_display']],
    ]];
  }

}
