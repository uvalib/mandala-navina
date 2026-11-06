<?php

namespace Drupal\spike13_render\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a whole book in outline order with D7's single_text_body markup.
 */
class WholeBookController extends ControllerBase {

  public function __construct(protected $bookManager, protected $db) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('book.manager'), $container->get('database'));
  }

  public function view(NodeInterface $node) {
    $link = $this->bookManager->loadBookLink($node->id(), FALSE);
    $bid = $link['bid'] ?? NULL;
    if (!$bid) {
      return ['#markup' => 'Not in a book.'];
    }
    $flat = [];
    if (\Drupal::request()->query->get('order') !== 'path') {
      // Book's own sibling order: weight, then title (what D7's view does too, via sort_within_level).
      $this->flatten($this->bookManager->bookTreeAllData($bid), $flat);
    }
    else {
      // Raw materialized path p1..p9, kept only to show it is NOT D7's order.
      $q = $this->db->select('book', 'b')->fields('b', ['nid', 'depth'])->condition('b.bid', $bid);
      foreach (range(1, 9) as $i) {
        $q->orderBy('b.p' . $i);
      }
      foreach ($q->execute() as $r) {
        $flat[(int) $r->nid] = (int) $r->depth;
      }
    }
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_keys($flat));
    // Spike only: D7 anchors use the D7 nid, which the migrate map holds.
    $d7 = $this->db->query('SELECT destid1, sourceid1 FROM {migrate_map_spike13_node} WHERE destid1 IN (:ids[])', [':ids[]' => array_keys($flat)])->fetchAllKeyed();

    $html = '';
    $tags = ['spike13_book:' . $bid];
    foreach ($flat as $nid => $depth) {
      if (!isset($nodes[$nid]) || !$nodes[$nid]->isPublished() || !$nodes[$nid]->access('view')) {
        continue;
      }
      $n = $nodes[$nid];
      $tags = array_merge($tags, $n->getCacheTags());
      $id = $d7[$nid] ?? $nid;
      $body = '';
      if (!$n->get('field_book_content')->isEmpty()) {
        $item = $n->get('field_book_content')->first();
        $body = (string) check_markup($item->value, $item->format);
      }
      $html .= '<div class="shanti-texts-section">'
        . '<a name="shanti-texts-' . $id . '"></a>'
        . '<div id="shanti-texts-' . $id . '" class="shanti-texts-section-title text-level-' . $depth . '">' . Html::escape($n->label()) . '</div>'
        . '<div class="shanti-texts-section-content">' . $body . '</div></div>';
    }
    if (\Drupal::request()->query->get('mem')) {
      return new \Symfony\Component\HttpFoundation\Response('peak=' . round(memory_get_peak_usage(TRUE) / 1048576) . 'MB');
    }
    return [
      '#markup' => Markup::create($html),
      '#cache' => ['tags' => $tags, 'contexts' => ['user.permissions', 'url.query_args:order']],
    ];
  }

  protected function flatten(array $tree, array &$flat): void {
    foreach ($tree as $item) {
      $flat[$item['link']['nid']] = (int) $item['link']['depth'];
      if ($item['below']) {
        $this->flatten($item['below'], $flat);
      }
    }
  }

}
