<?php

/**
 * @file
 * Spike 13 S5 (throwaway): citation and Notes counts, D7 vs the D11 renders.
 *
 * D7 side: the published outline pages' bodies from d7_texts (D7's whole-book
 * view prints them as-is, S2). D11 side: the spike13_render controller as
 * uid 1, with ?fn unset, a and b.
 *
 * Run: drush scr drupal/spike13-tmp/s5-check.php
 */

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Database;
use Drupal\node\Entity\Node;
use Drupal\spike13_render\Controller\WholeBookController;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/s5-lib.php';

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$d7db = Database::getConnection('default', 'migrate');
$map = \Drupal::database()->query('SELECT sourceid1, destid1 FROM {migrate_map_spike13_node}')->fetchAllKeyed();

function s5_count(string $html, string $xpath): int {
  return (new \DOMXPath(Html::load($html)))->query($xpath)->length;
}

$CITE_D7 = '//a[starts-with(@name, "nb")]';
$DEF = '//div[contains(concat(" ", normalize-space(@class), " "), " footnote ")]';
$CITE_FN = '//a[contains(@class, "footnote__citation")]';
$LIST = '//ol[@class="spike13-notes-list"]/li';

echo "d7bid\tsource\tcites_fn\tcites_d7style\tnotes\tnotes_listed\tempty_popover\n";
foreach ([15256, 15582, 15988, 16110, 14679, 15641] as $d7bid) {
  // D7: published pages of the book (the view's filter), bodies as stored.
  $vals = $d7db->query('SELECT f.field_book_content_value FROM {book} b JOIN {node} n ON n.nid = b.nid
    JOIN {field_data_field_book_content} f ON f.entity_id = b.nid WHERE b.bid = :b AND n.status = 1', [':b' => $d7bid])->fetchCol();
  $d7 = implode("\n", array_map('s5_unwrap', $vals));
  printf("%d\tD7\t-\t%d\t%d\t-\t-\n", $d7bid, s5_count($d7, $CITE_D7), s5_count($d7, $DEF));

  $node = Node::load($map[$d7bid]);
  foreach (['', 'a', 'b'] as $fn) {
    $req = Request::create('/spike13/book/' . $node->id(), 'GET', $fn ? ['fn' => $fn] : []);
    \Drupal::requestStack()->push($req);
    $build = WholeBookController::create(\Drupal::getContainer())->view($node);
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    \Drupal::requestStack()->pop();
    $xp = new \DOMXPath(Html::load($html));
    $empty = 0;
    foreach ($xp->query($CITE_FN) as $a) {
      $empty += trim($a->getAttribute('title')) === '';
    }
    printf("%d\tD11%s\t%d\t%d\t%d\t%d\t%d\n", $d7bid, $fn ? " $fn" : ' raw', $xp->query($CITE_FN)->length,
      $xp->query($CITE_D7)->length, $xp->query($DEF)->length, $xp->query($LIST)->length, $empty);
    if ($d7bid === 15988 && $fn) {
      file_put_contents("/tmp/s5-15988-$fn.html", $html);
    }
  }
}
