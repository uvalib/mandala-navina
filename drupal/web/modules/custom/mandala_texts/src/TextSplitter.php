<?php

namespace Drupal\mandala_texts;

use Drupal\Component\Utility\Html;

/**
 * Splits one document's HTML into a tree of sections at chosen heading levels.
 *
 * Port of D7 shanti_texts_splitter's splitting rules:
 *  - text before the first split heading stays as the original node's body;
 *  - each split heading starts a section, titled by the heading's text, holding
 *    everything up to the next split heading (the heading itself is dropped);
 *  - sections nest by level: an h3 goes under the h2 before it, and so on;
 *    a section with no lower-level heading above it goes under the original;
 *  - headings at levels not chosen stay in the body as content.
 *
 * Deliberate differences from D7:
 *  - weights are sequential per parent with no cap (D7 capped at +-15);
 *  - a section with no text of its own is dropped only if it also has no
 *    subsections (D7 dropped it either way, re-parenting its subsections).
 *
 * Only top-level headings split: CKEditor 5 saves a flat block structure, and
 * a heading inside a table or div is treated as content.
 */
class TextSplitter {

  /**
   * Splits HTML into an intro and a flat, ordered list of sections.
   *
   * @param string $html
   *   The document body.
   * @param int[] $levels
   *   Heading levels (1-6) that start a new section.
   *
   * @return array
   *   ['intro' => string, 'sections' => list of ['title', 'level', 'html',
   *   'parent' (index into sections, or NULL for the original node),
   *   'weight']].
   */
  public static function split(string $html, array $levels): array {
    $levels = array_map('intval', $levels);
    $dom = Html::load($html);
    $body = $dom->getElementsByTagName('body')->item(0);

    $intro = '';
    $sections = [];
    $current = NULL;
    foreach (iterator_to_array($body->childNodes) as $node) {
      $level = static::splitLevel($node, $levels);
      if ($level !== NULL) {
        if ($current !== NULL) {
          $sections[] = $current;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $current = ['title' => $title, 'level' => $level, 'html' => ''];
        continue;
      }
      $markup = $dom->saveHTML($node);
      if ($current === NULL) {
        $intro .= $markup;
      }
      else {
        $current['html'] .= $markup;
      }
    }
    if ($current !== NULL) {
      $sections[] = $current;
    }

    $sections = static::dropEmpty($sections);
    foreach ($sections as $i => &$section) {
      $section['html'] = trim($section['html']);
      if ($section['title'] === '') {
        $section['title'] = 'Section ' . ($i + 1);
      }
    }
    unset($section);

    return ['intro' => trim($intro), 'sections' => static::nest($sections)];
  }

  /**
   * Returns the heading level if this node is a split heading, else NULL.
   */
  protected static function splitLevel(\DOMNode $node, array $levels): ?int {
    if ($node instanceof \DOMElement && preg_match('/^h([1-6])$/i', $node->tagName, $m)) {
      $level = (int) $m[1];
      return in_array($level, $levels, TRUE) ? $level : NULL;
    }
    return NULL;
  }

  /**
   * Drops sections with no text of their own and no subsections.
   */
  protected static function dropEmpty(array $sections): array {
    $kept = [];
    foreach ($sections as $i => $section) {
      $next = $sections[$i + 1] ?? NULL;
      $hasChildren = $next !== NULL && $next['level'] > $section['level'];
      if (static::hasText($section['html']) || $hasChildren) {
        $kept[] = $section;
      }
    }
    return $kept;
  }

  /**
   * Whether HTML has visible text (or an image/table/embed).
   */
  protected static function hasText(string $html): bool {
    if (preg_match('/<(img|table|iframe|video|audio|drupal-media|hr)\b/i', $html)) {
      return TRUE;
    }
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}]+/u', '', $text)) !== '';
  }

  /**
   * Assigns each section its parent index and a sequential weight.
   */
  protected static function nest(array $sections): array {
    $stack = [];
    $weights = [];
    foreach ($sections as $i => &$section) {
      while ($stack && $sections[end($stack)]['level'] >= $section['level']) {
        array_pop($stack);
      }
      $parent = $stack ? end($stack) : NULL;
      $key = $parent ?? 'root';
      $section['parent'] = $parent;
      $section['weight'] = $weights[$key] = isset($weights[$key]) ? $weights[$key] + 1 : 0;
      $stack[] = $i;
    }
    unset($section);
    return $sections;
  }

}
