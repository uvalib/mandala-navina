<?php

/**
 * @file
 * Spike 13 S5 (throwaway): each rendered popover vs the definition D7 links to.
 *
 * Expected text per citation, in document order: the definition the 'next'
 * rule resolves to (what a D7 reader reaches by following the chapter's own
 * Notes page). Also reports where citation links point.
 */

use Drupal\Component\Utility\Html;
use Drupal\node\Entity\Node;
use Drupal\spike13_render\Controller\WholeBookController;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/s5-lib.php';

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$map = \Drupal::database()->query('SELECT sourceid1, destid1 FROM {migrate_map_spike13_node}')->fetchAllKeyed();
$norm = fn($s) => mb_substr(preg_replace('/\s+/', ' ', trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5))), 0, 60);

foreach ([15256, 15582, 15988, 16110, 14679] as $d7bid) {
  $r = s5_resolve(s5_pages($d7bid), 'next');
  $expect = [];
  foreach ($r['cites'] as $cs) {
    foreach ($cs as $c) {
      if ($c['text'] !== NULL) {
        $expect[] = $norm($c['text']);
      }
    }
  }
  $node = Node::load($map[$d7bid]);
  foreach (['a', 'b'] as $fn) {
  \Drupal::requestStack()->push(Request::create('/x', 'GET', ['fn' => $fn]));
  $html = (string) \Drupal::service('renderer')->renderInIsolation(WholeBookController::create(\Drupal::getContainer())->view($node));
  \Drupal::requestStack()->pop();
  $dom = Html::load($html);
  $xp = new \DOMXPath($dom);
  $bad = 0;
  $dead = 0;
  $wrong = 0;
  $ids = [];
  foreach ($xp->query('//*[@id]') as $el) {
    $ids[$el->getAttribute('id')] = ($ids[$el->getAttribute('id')] ?? 0) + 1;
  }
  $dupIds = count(array_filter($ids, fn($n) => $n > 1));
  if ($fn === "a") { foreach (array_filter($ids, fn($n) => $n > 1) as $k => $n) { echo "    dup $k x$n\n"; } }
  $i = 0;
  foreach ($xp->query('//a[contains(@class, "footnote__citation")]') as $a) {
    $got = $norm($a->getAttribute('title'));
    if ($got !== ($expect[$i] ?? NULL)) {
      if ($bad++ < 2) {
        echo "  #$i got [$got]\n     want [" . ($expect[$i] ?? 'none') . "]\n";
      }
    }
    $id = substr($a->getAttribute('href'), 1);
    $t = $xp->query('//*[@id="' . $id . '"]');
    $dead += $t->length === 0;
    if ($t->length) {
      // What footnotes.dialog.js shows: the target's .js-footnote-reference-text.
      $ref = $t->item(0)->parentNode;
      $txt = $xp->query('.//*[contains(@class, "js-footnote-reference-text")]', $ref);
      $wrong += !$txt->length || $norm($dom->saveHTML($txt->item(0))) !== ($expect[$i] ?? NULL);
    }
    $i++;
  }
  printf("%d %s: %d citations, %d popover mismatches, %d links to a missing target, %d targets with the wrong text, %d duplicate ids\n", $d7bid, $fn, $i, $bad, $dead, $wrong, $dupIds);
  }
}
