<?php

/**
 * @file
 * Spike 13 S5 (throwaway): migration-time footnote transform, two variants.
 *
 * Stands in for the Spike 4b transform, but writes to side tables instead of
 * rewriting nodes, so both variants can be rendered from the same content:
 * - {spike13_s5_text}: nid, variant (a|b), transformed body.
 * - {spike13_s5_notes}: the Option 3 list for variant b, in outline order.
 * - {spike13_s5_links}: per citing page, in document order, the citation's own
 *   id and its definition's id. The footnotes filter writes its own href/id
 *   (to its list, which is off), so the controller rewrites them from here.
 * Ids are unique across the whole-book page: s5c-<D7 nid of citing page>-<key>,
 * s5n-<D7 nid of Notes page>-<key>. D7's own nb/n anchors repeat in anthologies.
 * Definitions are written in the shape footnotes.dialog.js looks for
 * (.js-footnote-reference / .js-footnote-reference-text).
 * Variant a: citations become <footnotes data-value data-text>, Notes pages
 * stay as D7 has them. Variant b: also removes the definitions (and the
 * footnote-divider rule) from the Notes pages; the list comes from the table.
 * Resolution rule: 'next' (see s5_resolve()).
 *
 * Run: drush scr drupal/spike13-tmp/s5-transform.php
 */

use Drupal\Component\Utility\Html;
use Drupal\filter\Entity\FilterFormat;

require_once __DIR__ . '/s5-lib.php';

$books = [15256, 15582, 15988, 16110, 14679, 15641];
$db = \Drupal::database();
$schema = $db->schema();

// full_html plus filter_footnotes with its own list off: neither variant uses
// the stock list (4b: it drops render-cache hits).
if (!FilterFormat::load('spike13_fn')) {
  $full = FilterFormat::load('full_html');
  $filters = $full->get('filters');
  $filters['filter_footnotes'] = [
    'id' => 'filter_footnotes',
    'provider' => 'footnotes',
    'status' => TRUE,
    'weight' => -20,
    'settings' => [
      'footnotes_collapse' => FALSE,
      'footnotes_css' => TRUE,
      'footnotes_dialog' => TRUE,
      'footnotes_footer_disable' => TRUE,
    ],
  ];
  FilterFormat::create(['format' => 'spike13_fn', 'name' => 'Spike 13 S5 footnotes', 'filters' => $filters, 'weight' => 60])->save();
}

foreach (['spike13_s5_text', 'spike13_s5_notes', 'spike13_s5_links'] as $t) {
  if ($schema->tableExists($t)) {
    $schema->dropTable($t);
  }
}
$schema->createTable('spike13_s5_text', ['fields' => [
  'nid' => ['type' => 'int', 'not null' => TRUE],
  'variant' => ['type' => 'varchar_ascii', 'length' => 1, 'not null' => TRUE],
  'value' => ['type' => 'text', 'size' => 'big'],
], 'primary key' => ['nid', 'variant']]);
$schema->createTable('spike13_s5_notes', ['fields' => [
  'bid' => ['type' => 'int', 'not null' => TRUE],
  'seq' => ['type' => 'int', 'not null' => TRUE],
  'def_nid' => ['type' => 'int', 'not null' => TRUE],
  'label' => ['type' => 'varchar', 'length' => 32],
  'target' => ['type' => 'varchar_ascii', 'length' => 64],
  'backlink' => ['type' => 'varchar_ascii', 'length' => 64],
  'text' => ['type' => 'text', 'size' => 'big'],
], 'primary key' => ['bid', 'seq']]);
$schema->createTable('spike13_s5_links', ['fields' => [
  'nid' => ['type' => 'int', 'not null' => TRUE],
  'seq' => ['type' => 'int', 'not null' => TRUE],
  'cid' => ['type' => 'varchar_ascii', 'length' => 64],
  'target' => ['type' => 'varchar_ascii', 'length' => 64],
], 'primary key' => ['nid', 'seq']]);

/**
 * A definition in the module's list shape, with a back-link to its citation.
 */
function s5_def_html(\DOMDocument $dom, string $tag, string $target, ?string $backlink, string $label, string $text): \DOMElement {
  $frag = Html::load('<' . $tag . ' class="footnote js-footnote-reference">'
    . '<a class="note" id="' . $target . '"' . ($backlink ? ' href="#' . $backlink . '"' : '') . '>[' . Html::escape($label) . ']</a> '
    . '<span class="js-footnote-reference-text">' . $text . '</span></' . $tag . '>');
  return $dom->importNode($frag->getElementsByTagName('body')->item(0)->firstChild, TRUE);
}

$anchorKey = fn(string $key) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', $key), '-');

foreach ($books as $d7bid) {
  $pages = s5_pages($d7bid);
  $bid = (int) $db->query('SELECT destid1 FROM {migrate_map_spike13_node} WHERE sourceid1 = :s', [':s' => $d7bid])->fetchField();
  $r = s5_resolve($pages, 'next');
  $seq = 0;
  // Citation ids, and each definition's first citation (its back-link target).
  $cids = [];
  $backlinks = [];
  foreach ($r['cites'] as $nid => $cs) {
    $used = [];
    foreach ($cs as $i => $c) {
      $base = 's5c-' . $pages[$nid]['d7'] . '-' . $anchorKey($c['key']);
      $used[$base] = ($used[$base] ?? 0) + 1;
      $cids[$nid][$i] = $base . ($used[$base] > 1 ? '-' . $used[$base] : '');
      if ($c['def_nid']) {
        $backlinks[$c['def_nid'] . '|' . $c['key']] ??= $cids[$nid][$i];
      }
    }
  }
  $target = fn(int $defNid, string $key) => 's5n-' . $pages[$defNid]['d7'] . '-' . $anchorKey($key);
  foreach ($pages as $nid => $p) {
    $cites = $r['cites'][$nid] ?? [];
    $defs = $r['defs'][$nid] ?? [];
    if (!$cites && !$defs) {
      continue;
    }
    foreach (['a', 'b'] as $variant) {
      $dom = Html::load($p['value']);
      $xp = new \DOMXPath($dom);
      $i = 0;
      foreach (iterator_to_array($xp->query('//a[@name]')) as $a) {
        if (!preg_match('/^nb(.+)$/', $a->getAttribute('name'))) {
          continue;
        }
        $c = $cites[$i];
        $cid = $cids[$nid][$i++];
        // Unresolved (no definition anywhere, e.g. 15641): left as D7 has it.
        if ($c['text'] === NULL) {
          continue;
        }
        $fn = $dom->createElement('footnotes');
        $fn->setAttribute('data-value', trim($a->textContent));
        $fn->setAttribute('data-text', $c['text']);
        $a->parentNode->replaceChild($fn, $a);
        if ($variant === 'a') {
          $db->insert('spike13_s5_links')->fields(['nid' => $nid, 'seq' => $i - 1, 'cid' => $cid, 'target' => $target($c['def_nid'], $c['key'])])->execute();
        }
      }
      if ($variant === 'a') {
        // Definitions stay on the Notes page, rewritten with unique ids.
        $j = 0;
        foreach (iterator_to_array($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " footnote ")]')) as $div) {
          $d = $defs[$j++];
          if ($d['key'] === NULL) {
            continue;
          }
          $div->parentNode->replaceChild(s5_def_html($dom, 'div', $target($nid, $d['key']), $backlinks[$nid . '|' . $d['key']] ?? NULL, preg_match('/(\d+)$/', $d['key'], $m) ? $m[1] : $d['key'], $d['text']), $div);
        }
      }
      if ($variant === 'b') {
        foreach (iterator_to_array($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " footnote ")] | //hr[contains(@class, "footnote-divider")]')) as $el) {
          $el->parentNode->removeChild($el);
        }
      }
      $db->insert('spike13_s5_text')->fields(['nid' => $nid, 'variant' => $variant, 'value' => Html::serialize($dom)])->execute();
    }
    // Option 3 list rows: every definition, cited or not (D7 shows them all).
    foreach ($defs as $d) {
      $label = $d['key'] !== NULL && preg_match('/(\d+)$/', $d['key'], $m) ? $m[1] : '';
      $db->insert('spike13_s5_notes')->fields([
        'bid' => $bid, 'seq' => $seq++, 'def_nid' => $nid, 'label' => $label, 'text' => $d['text'],
        'target' => $d['key'] !== NULL ? $target($nid, $d['key']) : NULL,
        'backlink' => $backlinks[$nid . '|' . $d['key']] ?? NULL,
      ])->execute();
    }
  }
  printf("%d: %d pages transformed, %d notes rows\n", $d7bid,
    $db->query('SELECT COUNT(*) FROM {spike13_s5_text} t JOIN {book} b ON b.nid = t.nid WHERE b.bid = :b AND variant = :v', [':b' => $bid, ':v' => 'a'])->fetchField(), $seq);
}
