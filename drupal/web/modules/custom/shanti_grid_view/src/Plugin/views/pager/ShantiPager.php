<?php

declare(strict_types=1);

namespace Drupal\shanti_grid_view\Plugin\views\pager;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\Pager\PagerPreprocess;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsPager;
use Drupal\views\Plugin\views\pager\Full;

/**
 * A page-jump widget pager, ported from D7's "pagerer" contrib module.
 *
 * Production configures pagerer's "widget" display type (a type-a-page-
 * number-and-press-Enter text input, flanked by first/previous/next/last
 * icon links) and prints the same $pager variable twice in its view
 * template -- once above the results, once below (see
 * views-view--image-gallery.html.twig). This is a purpose-built port of
 * that one specific widget configuration, not a general port of pagerer's
 * full preset system (sliders, scrollpanes, progressive/adaptive link
 * lists) -- this view only ever used the widget display.
 *
 * Reuses core's own \Drupal\Core\Pager\PagerPreprocess::preprocessPager()
 * for first/previous/next/last link building (query-parameter preservation,
 * AJAX/live-preview route selection) rather than reimplementing it -- see
 * that class for the URL-building logic this delegates to.
 */
#[ViewsPager(
  id: 'shanti_pager',
  title: new TranslatableMarkup('Shanti pager'),
  short_title: new TranslatableMarkup('Shanti'),
  help: new TranslatableMarkup('Page-jump widget pager, matching production shanti_grid_view.'),
  theme: 'shanti_pager',
  register_theme: FALSE,
)]
class ShantiPager extends Full {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PagerManagerInterface $pager_manager,
    PagerParametersInterface $pager_parameters,
    protected PagerPreprocess $pagerPreprocess,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $pager_manager, $pager_parameters);
  }

  /**
   * {@inheritdoc}
   */
  public function render($input) {
    $element = $this->options['id'];
    $route_name = !empty($this->view->live_preview) ? '<current>' : '<none>';

    // Delegate first/previous/next/last link building to core's own pager
    // preprocessing -- same query-parameter-preserving logic core's default
    // pager theme uses, rather than reimplementing it.
    $variables = [
      'pager' => [
        '#tags' => [],
        '#element' => $element,
        '#parameters' => $input,
        '#quantity' => 0,
        '#route_name' => $route_name,
      ],
    ];
    $this->pagerPreprocess->preprocessPager($variables);
    if (empty($variables['items'])) {
      // Only one page -- no pager needed.
      return [];
    }

    $pager = $this->pagerManager->getPager($element);
    $current_page = $pager->getCurrentPage();

    // A URL template for the widget's own page-jump input: the 'page' query
    // parameter holds a placeholder the client JS substitutes with the
    // typed target page before navigating, mirroring pagerer.js's own
    // 'pagererpage' placeholder technique (pagerer.module:
    // _pagerer_itemize_js_element()).
    $query = $this->pagerManager->getUpdatedParameters($input, $element, '__SHANTI_PAGE__');
    $path = Url::fromRoute($route_name, [], ['query' => $query])->toString();

    return [
      '#theme' => 'shanti_pager',
      '#first_href' => $variables['items']['first']['href'] ?? NULL,
      '#previous_href' => $variables['items']['previous']['href'] ?? NULL,
      '#next_href' => $variables['items']['next']['href'] ?? NULL,
      '#last_href' => $variables['items']['last']['href'] ?? NULL,
      '#current_page' => $current_page + 1,
      '#total_pages' => $pager->getTotalPages(),
      '#widget_state' => Json::encode([
        'path' => $path,
        'total' => $pager->getTotalPages(),
        'totalItems' => $pager->getTotalItems(),
        'current' => $current_page,
      ]),
      '#attached' => [
        'library' => ['shanti_grid_view/shanti-pager'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args'],
      ],
    ];
  }

}
