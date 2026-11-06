<?php

namespace Drupal\spike13_render\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a whole book in outline order with D7's single_text_body markup.
 *
 * Spike 13 S3: the outline is read from the flat {book} table rather than
 * BookManager::bookTreeAllData(), which drops a hidden parent together with its
 * whole subtree (D7's view filters each row on its own, so it does not).
 * ?cache=naive|bubbled|full selects how cacheability is declared, so the S3
 * test can show which ones leak.
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
    $mode = \Drupal::request()->query->get('cache', 'full');
    $flat = $this->flatOutline((int) $bid, $nodes, $pids);
    // Spike only: D7 anchors use the D7 nid, which the migrate map holds.
    $d7 = $this->db->query('SELECT destid1, sourceid1 FROM {migrate_map_spike13_node} WHERE destid1 IN (:ids[])', [':ids[]' => array_keys($flat)])->fetchAllKeyed();

    $html = '';
    $shown = 0;
    // Contents rows (D7 single_text_toc: published, depth > 1), built in the same
    // access loop as the sections so the two cannot disagree.
    $toc = [];
    $meta = new CacheableMetadata();
    $meta->addCacheTags(['spike13_book:' . $bid]);
    foreach ($flat as $nid => $depth) {
      $n = $nodes[$nid];
      $access = $n->access('view', NULL, TRUE);
      if ($mode === 'bubbled') {
        // Every outline node, hidden or not: a hidden page that becomes visible
        // (or a group that changes visibility) has to refresh this render.
        $meta->addCacheableDependency($access);
        $meta->addCacheableDependency($n);
      }
      elseif ($mode === 'full') {
        // Same, minus the per-user context the forbidden results declare: the
        // membership context below already determines that decision, and a
        // 'user' context makes dynamic_page_cache refuse the response.
        $meta->addCacheTags($access->getCacheTags());
        $meta->addCacheTags($n->getCacheTags());
        $meta->mergeCacheMaxAge($access->getCacheMaxAge());
        $meta->addCacheContexts(array_diff($access->getCacheContexts(), ['user']));
      }
      if (!$access->isAllowed()) {
        continue;
      }
      if ($mode === 'naive') {
        $meta->addCacheableDependency($n);
      }
      $shown++;
      $id = $d7[$nid] ?? $nid;
      if ($depth > 1) {
        $toc[$nid] = ['id' => $id, 'pid' => $pids[$nid], 'title' => $n->label()];
      }
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
    $contexts = ['url.query_args:cache'];
    if ($mode === 'naive') {
      $contexts[] = 'user.permissions';
    }
    elseif ($mode === 'full') {
      $contexts = array_merge($contexts, ['user.permissions', 'user.roles:authenticated', 'user.mandala_collection_memberships']);
    }
    $meta->addCacheContexts($contexts);
    $build = [
      '#markup' => Markup::create('<!-- shown=' . $shown . ' -->' . $this->contents((int) $bid, $nodes, $d7, $toc) . $html),
    ];
    $meta->applyTo($build);
    return $build;
  }

  /**
   * The Contents list, shaped like D7's single_text_toc.
   *
   * D7 renders that view with views_tree: a row whose parent is not in the
   * result set (unpublished, hidden from the viewer, or the book root, which the
   * depth > 1 filter removes) becomes a top-level entry, not a child of its
   * nearest visible ancestor. $toc is in outline order, so children keep it.
   */
  protected function contents(int $bid, array $nodes, array $d7, array $toc): string {
    $byParent = [];
    foreach ($toc as $nid => $row) {
      $byParent[isset($toc[$row['pid']]) ? $row['pid'] : 0][] = $nid;
    }
    $list = function (int $parent) use (&$list, &$byParent, $toc): string {
      if (empty($byParent[$parent])) {
        return '';
      }
      $out = '<ul>';
      foreach ($byParent[$parent] as $nid) {
        $id = $toc[$nid]['id'];
        $out .= '<li><a href="#shanti-texts-' . $id . '" id="shanti-texts-toc-' . $id . '">' . Html::escape($toc[$nid]['title']) . '</a>' . $list($nid) . '</li>';
      }
      return $out . '</ul>';
    };
    $head = '';
    if (isset($nodes[$bid]) && $nodes[$bid]->access('view')) {
      $rid = $d7[$bid] ?? $bid;
      $head = '<div class="shanti-texts-toc-root"><a href="#shanti-texts-' . $rid . '">' . Html::escape($nodes[$bid]->label()) . '</a></div>';
    }
    return '<div id="shanti-texts-toc">' . $head . $list(0) . '</div>';
  }

  /**
   * Outline nid => depth in Book's sibling order (weight, title, nid).
   *
   * Same key Book's tree uses (50000 + weight, title, nid, ksort), but over the
   * unpruned {book} rows, so an inaccessible parent does not hide its children.
   *
   * @param \Drupal\node\NodeInterface[]|null $nodes
   *   Filled with every outline node, keyed by nid.
   */
  protected function flatOutline(int $bid, &$nodes, &$pids = []): array {
    $rows = $this->db->select('book', 'b')->fields('b', ['nid', 'pid', 'weight', 'depth'])
      ->condition('b.bid', $bid)->execute()->fetchAllAssoc('nid');
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple(array_keys($rows));
    $children = [];
    foreach ($rows as $nid => $r) {
      if (!isset($nodes[$nid])) {
        continue;
      }
      $pids[(int) $nid] = (int) $r->pid;
      $children[(int) $r->pid][(50000 + (int) $r->weight) . ' ' . $nodes[$nid]->label() . ' ' . $nid] = (int) $nid;
    }
    $flat = [];
    $walk = function (int $pid) use (&$walk, &$children, &$flat, $rows) {
      if (empty($children[$pid])) {
        return;
      }
      ksort($children[$pid]);
      foreach ($children[$pid] as $nid) {
        $flat[$nid] = (int) $rows[$nid]->depth;
        $walk($nid);
      }
    };
    $walk(0);
    return $flat;
  }

}
