<?php

namespace Drupal\shanti_kmaps_fields\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\shanti_kmaps_fields\KmapsPopoverInfoService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders KMaps terms as tags with a hover popover.
 *
 * The popover shows term info, an ancestor breadcrumb, a "Full Entry" link,
 * and non-zero "Related X (N)" links. Ports D7's kmap_popover_formatter /
 * shanti_sarvaka_info_popover(): the popover content is rendered fully
 * server-side into the markup, and the client JS (kmaps-popover.js) just
 * wires Bootstrap's popover to that already-present static content — no
 * AJAX fetch at any point.
 *
 * @FieldFormatter(
 *   id = "kmap_popover_formatter",
 *   label = @Translation("KMaps Tags (with popover)"),
 *   field_types = {
 *     "shanti_kmaps_fields_default"
 *   }
 * )
 */
class KmapPopoverFormatter extends FormatterBase {

  /**
   * The KMaps popover info service.
   */
  protected KmapsPopoverInfoService $popoverInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $formatter = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    assert($formatter instanceof static);
    $formatter->popoverInfo = $container->get('shanti_kmaps_fields.popover_info');
    return $formatter;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    if ($items->isEmpty()) {
      return [];
    }

    $tags = [];
    foreach ($items as $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $domain = $item->domain ?? '';
      $id = (int) ($item->id ?? 0);
      $header = $item->header ?? '';
      $defids = $item->defids ?? NULL;

      $data = $this->popoverInfo->getPopoverData($domain, $id, $header, $defids);
      if ($data === NULL) {
        // Term doc unavailable (Solr down, or a stale reference) - fall
        // back to a plain tag, no popover, rather than showing nothing.
        $tags[] = [
          '#markup' => '<span class="kmaps-term-tag" data-kmaps-key="' . htmlspecialchars("{$domain}-{$id}") . '">'
          . htmlspecialchars($header) . '</span>',
        ];
        continue;
      }

      $tags[] = [
        '#theme' => 'kmaps_popover',
        '#label' => $data['label'],
        '#domain' => $data['domain'],
        '#kid' => $data['kid'],
        '#ftypes' => $data['ftypes'],
        '#desc' => $data['desc'],
        '#defs' => $data['defs'],
        '#tree' => $data['tree'],
        '#links' => $data['links'],
      ];
    }

    if (empty($tags)) {
      return [];
    }

    return [[
      '#theme' => 'kmaps_field_tags',
      '#tags' => $tags,
      '#attached' => ['library' => ['shanti_kmaps_fields/kmaps_popover']],
    ],
    ];
  }

}
