<?php

/**
 * @file
 * Spike 13 S5 helpers (throwaway): parse D7 shanti_footnotes markup.
 *
 * D7 pattern (Spike 4b): citation <a href="#nN" name="nbN" class="note">N</a>;
 * definition <div class="footnote"><a name="nN"/><a href="#nbN" class="note">[N]</a> text</div>,
 * after <hr class="footnote-divider"/>. The div may carry xmlns:* attributes.
 * Keys are not always numbers: anthology chapters use nb-b5-1 / n-b5-1.
 */

use Drupal\Component\Utility\Html;

/**
 * Pages of a D11 book in outline order: [nid => ['d7' => d7nid, 'title', 'value']].
 */
function s5_pages(int $d7bid): array {
  $db = \Drupal::database();
  $bid = $db->query('SELECT destid1 FROM {migrate_map_spike13_node} WHERE sourceid1 = :s', [':s' => $d7bid])->fetchField();
  $rows = $db->query('SELECT b.nid, b.pid, b.weight, b.depth, n.title, c.field_book_content_value v, m.sourceid1 d7
    FROM {book} b JOIN {node_field_data} n ON n.nid = b.nid
    LEFT JOIN {node__field_book_content} c ON c.entity_id = b.nid
    LEFT JOIN {migrate_map_spike13_node} m ON m.destid1 = b.nid
    WHERE b.bid = :b', [':b' => $bid])->fetchAllAssoc('nid');
  // Same sibling key as WholeBookController::flatOutline().
  $children = [];
  foreach ($rows as $nid => $r) {
    $children[(int) $r->pid][(50000 + (int) $r->weight) . ' ' . $r->title . ' ' . $nid] = (int) $nid;
  }
  $out = [];
  $walk = function (int $pid) use (&$walk, &$children, &$out, $rows) {
    if (empty($children[$pid])) {
      return;
    }
    ksort($children[$pid]);
    foreach ($children[$pid] as $nid) {
      $out[$nid] = ['d7' => (int) $rows[$nid]->d7, 'title' => $rows[$nid]->title, 'value' => s5_unwrap((string) $rows[$nid]->v)];
      $walk($nid);
    }
  };
  $walk(0);
  return $out;
}

/**
 * Strips a whole-XHTML-document wrapper (25 D7 rows, 23 in bid 15582).
 *
 * Their "<title/>" is not self-closing to an HTML parser: it opens a title that
 * swallows the page as text, so full_html renders those pages escaped.
 */
function s5_unwrap(string $html): string {
  // The wrapper is opened but, in the rows checked, never closed.
  if (preg_match('/^\s*<html\b.*?<body[^>]*>/is', $html, $m)) {
    $html = preg_replace('/<\/body>\s*<\/html>\s*$/i', '', substr($html, strlen($m[0])));
  }
  return $html;
}

/**
 * Citation keys in document order, and definitions as [key, inner html].
 *
 * The key is what follows nb/n ("12", "-b5-1").
 */
function s5_parse(string $html): array {
  $cites = $defs = [];
  if ($html === '') {
    return [$cites, $defs];
  }
  $dom = Html::load($html);
  $xp = new \DOMXPath($dom);
  foreach ($xp->query('//a[@name]') as $a) {
    if (preg_match('/^nb(.+)$/', $a->getAttribute('name'), $m)) {
      $cites[] = $m[1];
    }
  }
  foreach ($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " footnote ")]') as $div) {
    $n = NULL;
    $text = '';
    // Many rows write <a name="nN"/>, which HTML does not self-close: libxml
    // leaves it open and the definition text ends up inside it. So keep the
    // anchor's children, and drop only the "[N]" back-link.
    $walk = function (\DOMNode $parent) use (&$walk, &$n, &$text, $dom) {
      foreach (iterator_to_array($parent->childNodes) as $child) {
        if ($child instanceof \DOMElement && $child->tagName === 'a') {
          if ($n === NULL && preg_match('/^n([^b].*|\d.*)$/', $child->getAttribute('name'), $m)) {
            $n = $m[1];
            $walk($child);
            continue;
          }
          if (str_starts_with($child->getAttribute('href'), '#nb')) {
            continue;
          }
        }
        $text .= $dom->saveHTML($child);
      }
    };
    $walk($div);
    $defs[] = [$n, trim(preg_replace('/\s+/', ' ', $text))];
  }
  return [$cites, $defs];
}

/**
 * Resolve every citation to a definition.
 *
 * 'book' is the Spike 4b rule: match the key anywhere in the book (ambiguous
 * when the key is defined more than once). 'next' matches the key on the first
 * page at or after the citation, in outline order, that defines it.
 *
 * @return array
 *   'cites' => [nid => [['key', 'def_nid' => nid|NULL, 'text' => html|NULL, 'ambiguous']]],
 *   'defs' => [def_nid => [['key', 'text', 'cited']]].
 */
function s5_resolve(array $pages, string $rule): array {
  $parsed = [];
  $defsAt = [];
  foreach ($pages as $nid => $p) {
    $parsed[$nid] = s5_parse($p['value']);
    foreach ($parsed[$nid][1] as [$k, $t]) {
      if ($k !== NULL) {
        $defsAt[$k][] = [$nid, $t];
      }
    }
  }
  $order = array_flip(array_keys($pages));
  $out = ['cites' => [], 'defs' => []];
  foreach ($parsed as $nid => [$cites, $defs]) {
    foreach ($defs as [$k, $t]) {
      $out['defs'][$nid][] = ['key' => $k, 'text' => $t, 'cited' => FALSE];
    }
  }
  foreach ($parsed as $nid => [$cites]) {
    foreach ($cites as $k) {
      $hit = NULL;
      $cands = $defsAt[$k] ?? [];
      if ($rule === 'book') {
        $hit = $cands[0] ?? NULL;
      }
      else {
        foreach ($cands as $c) {
          if ($order[$c[0]] >= $order[$nid]) {
            $hit = $c;
            break;
          }
        }
      }
      $out['cites'][$nid][] = ['key' => $k, 'def_nid' => $hit[0] ?? NULL, 'text' => $hit[1] ?? NULL, 'ambiguous' => count($cands) > 1];
      if ($hit) {
        foreach ($out['defs'][$hit[0]] as &$d) {
          if ($d['key'] === $k && $d['text'] === $hit[1]) {
            $d['cited'] = TRUE;
          }
        }
        unset($d);
      }
    }
  }
  return $out;
}
