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
 *  - headings at levels not chosen stay in the body as content;
 *  - <h6 class="h7"> (to h9), Word's Heading 7-9, never split.
 *
 * Deliberate differences from D7:
 *  - weights are sequential per parent with no cap (D7 capped at +-15);
 *  - a section with no text of its own is dropped only if it also has no
 *    subsections (D7 dropped it either way, re-parenting its subsections);
 *  - inline style attributes are stripped, but <span>s are kept (D7 removed
 *    every <span>, losing their lang attributes; Than, 2026-11-17).
 *
 * Only top-level headings split: CKEditor 5 saves a flat block structure, and
 * a heading inside a table or div is treated as content.
 *
 * Word footnotes: on a paste into full_html, the Footnotes module turns most
 * Word footnotes into inline <footnotes data-text> tags, which then travel
 * with their section. About 1% fail and are left in an <ol class="footnotes">
 * list at the end, next to Word's separator (<div><hr>...). Those are taken
 * out and returned as 'leftover_notes', for a final "Notes" page (Than,
 * 2026-11-16); the editor is warned to fix them by hand.
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
   *   'weight'], 'leftover_notes' => string (an <ol>, or ''),
   *   'leftover_count' => int].
   */
  public static function split(string $html, array $levels): array {
    $levels = array_map('intval', $levels);
    $dom = Html::load($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    static::unwrapWordSections($body);
    static::stripStyles($body);
    [$leftover, $count] = static::extractLeftoverNotes($dom, $body);

    $intro = '';
    $sections = [];
    $current = NULL;
    foreach (iterator_to_array($body->childNodes) as $node) {
      $level = static::splitLevel($node, $levels);
      if ($level !== NULL) {
        if ($current !== NULL) {
          $sections[] = $current;
        }
        // A page title is plain text, so footnotes on the heading move to the
        // start of the page's first paragraph (Gnosis: 6 chapter headings).
        $notes = '';
        foreach (iterator_to_array($node->getElementsByTagName('footnotes')) as $fn) {
          $notes .= $dom->saveHTML($fn);
          $fn->parentNode->removeChild($fn);
        }
        $title = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $current = ['title' => $title, 'level' => $level, 'html' => '', 'notes' => $notes];
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
      $section['html'] = static::prependNotes(trim($section['html']), $section['notes']);
      unset($section['notes']);
      if ($section['title'] === '') {
        $section['title'] = 'Section ' . ($i + 1);
      }
    }
    unset($section);

    return [
      'intro' => trim($intro),
      'sections' => static::nest($sections),
      'leftover_notes' => $leftover,
      'leftover_count' => $count,
    ];
  }

  /**
   * Replaces Word's section wrappers (div.WordSection1, ...) by their children.
   *
   * A Word paste into full_html keeps one wrapper per Word section break, with
   * the headings inside, so without this no heading is at the top level.
   */
  protected static function unwrapWordSections(\DOMElement $body): void {
    foreach (iterator_to_array($body->childNodes) as $node) {
      if ($node instanceof \DOMElement && $node->tagName === 'div' && preg_match('/^WordSection\d+$/', trim($node->getAttribute('class')))) {
        static::unwrapWordSections($node);
        while ($node->firstChild) {
          $body->insertBefore($node->firstChild, $node);
        }
        $body->removeChild($node);
      }
    }
  }

  /**
   * Removes every inline style attribute (Word's mso-* formatting).
   */
  protected static function stripStyles(\DOMElement $body): void {
    foreach (iterator_to_array($body->getElementsByTagName('*')) as $element) {
      $element->removeAttribute('style');
    }
  }

  /**
   * Removes unconverted Word footnotes and Word's separator from the body.
   *
   * @return array
   *   [the notes as one <ol> (or ''), how many].
   */
  protected static function extractLeftoverNotes(\DOMDocument $dom, \DOMElement $body): array {
    $notes = [];
    foreach (iterator_to_array($body->childNodes) as $node) {
      if (!$node instanceof \DOMElement) {
        continue;
      }
      if ($node->tagName === 'ol' && preg_match('/\bfootnotes\b/', $node->getAttribute('class'))) {
        foreach ($node->getElementsByTagName('li') as $li) {
          $content = NULL;
          foreach ($li->getElementsByTagName('div') as $div) {
            if (preg_match('/\bfootnote-content\b/', $div->getAttribute('class'))) {
              $content = $div;
              break;
            }
          }
          // Keep everything but Word's "^" back-link: in some items the
          // note itself is a converted <footnotes> tag outside the content div.
          foreach (iterator_to_array($li->getElementsByTagName('a')) as $a) {
            if (preg_match('/\bfootnote-backlink\b/', $a->getAttribute('class'))) {
              $a->parentNode->removeChild($a);
            }
          }
          $html = '';
          foreach ($li->childNodes as $child) {
            $html .= $dom->saveHTML($child);
          }
          $html = preg_replace('/<p>\s*<\/p>|<div class="footnote-content">|<\/div>/', '', $html);
          $notes[] = '<li>' . trim($html) . '</li>';
        }
        $body->removeChild($node);
      }
      elseif ($node->tagName === 'div' && static::isWordSeparator($dom, $node)) {
        $body->removeChild($node);
      }
    }
    return $notes ? ['<ol>' . implode('', $notes) . '</ol>', count($notes)] : ['', 0];
  }

  /**
   * Whether a div is Word's footnote separator: starts with <hr>, no text.
   */
  protected static function isWordSeparator(\DOMDocument $dom, \DOMElement $div): bool {
    $first = $div->firstChild;
    while ($first && !$first instanceof \DOMElement) {
      $first = $first->nextSibling;
    }
    return $first && $first->tagName === 'hr' && !static::hasText(preg_replace('/<hr[^>]*>/i', '', $dom->saveHTML($div)));
  }

  /**
   * Puts heading footnotes at the start of the first paragraph (or a new one).
   */
  protected static function prependNotes(string $html, string $notes): string {
    if ($notes === '') {
      return $html;
    }
    if (preg_match('/^<p\b[^>]*>/', $html, $m)) {
      return $m[0] . $notes . substr($html, strlen($m[0]));
    }
    return '<p>' . $notes . '</p>' . $html;
  }

  /**
   * Returns the heading level if this node is a split heading, else NULL.
   */
  protected static function splitLevel(\DOMNode $node, array $levels): ?int {
    if ($node instanceof \DOMElement && preg_match('/^h([1-6])$/i', $node->tagName, $m)) {
      // <h6 class="h7"> etc. are Word's Heading 7-9; never split points.
      if (preg_match('/\bh([7-9]|[1-9]\d)\b/', $node->getAttribute('class'))) {
        return NULL;
      }
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
      if (static::hasText($section['html']) || $section['notes'] !== '' || $hasChildren) {
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
