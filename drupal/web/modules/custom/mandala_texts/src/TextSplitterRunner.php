<?php

namespace Drupal\mandala_texts;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Creates Book pages from a split plan, as a batch.
 *
 * Each page is a duplicate of the original node, as in D7 (same author,
 * status, text format, KMaps terms, visibility and Group collections), with its
 * own title, body and outline position. Not copied: the legacy nid (a split
 * page is new D11 content), the path alias, and the splitter fields.
 */
class TextSplitterRunner {

  use StringTranslationTrait;

  /**
   * Pages created per batch step.
   */
  const CHUNK = 20;

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Splits $node if its splitter checkbox is on; returns a batch or NULL.
   *
   * Saves $node with the intro as its body and the checkbox turned off. A node
   * not yet in a book becomes the root of a new one.
   */
  public function prepare(NodeInterface $node): ?array {
    if ($node->bundle() !== 'book' || !$node->get('field_split_text')->value) {
      return NULL;
    }
    $levels = array_column($node->get('field_split_headings')->getValue(), 'value');
    $body = $node->get('field_book_content');
    $plan = TextSplitter::split((string) $body->value, $levels ?: [1]);

    $node->set('field_split_text', FALSE);
    if ($plan['sections']) {
      $node->set('field_book_content', ['value' => $plan['intro'], 'format' => $body->format]);
      if (empty($node->getBook()['bid'])) {
        $node->setBook(['bid' => 'new', 'pid' => 0, 'weight' => 0]);
      }
    }
    $node->save();

    if (!$plan['sections']) {
      return NULL;
    }
    return [
      'title' => $this->t('Splitting @title into pages', ['@title' => $node->label()]),
      'operations' => [[[static::class, 'batchCreate'], [(int) $node->id(), $plan['sections']]]],
      'finished' => [static::class, 'batchFinished'],
      'progress_message' => '',
    ];
  }

  /**
   * Batch operation: creates the next CHUNK of pages.
   */
  public static function batchCreate(int $nid, array $sections, &$context): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $original = $storage->load($nid);
    if (!isset($context['sandbox']['next'])) {
      $context['sandbox']['next'] = 0;
      $context['results'] = ['nid' => $nid, 'created' => []];
    }
    $created = &$context['results']['created'];
    $bid = $original->getBook()['bid'];

    $end = min(count($sections), $context['sandbox']['next'] + static::CHUNK);
    for ($i = $context['sandbox']['next']; $i < $end; $i++) {
      $s = $sections[$i];
      $pid = $s['parent'] === NULL ? $nid : $created[$s['parent']];
      $page = static::createPage($original, $s['title'], $s['html'], $bid, $pid, $s['weight']);
      $created[$i] = (int) $page->id();
    }
    $context['sandbox']['next'] = $end;
    $context['finished'] = $end / count($sections);
    $context['message'] = t('Created @n of @total pages', ['@n' => $end, '@total' => count($sections)]);
  }

  /**
   * Creates one page under $pid.
   */
  public static function createPage(NodeInterface $original, string $title, string $html, int $bid, int $pid, int $weight): NodeInterface {
    $page = $original->createDuplicate();
    $page->setTitle(mb_substr($title, 0, 255));
    $page->set('field_book_content', ['value' => $html, 'format' => $original->get('field_book_content')->format]);
    $page->set('field_split_text', FALSE);
    $page->set('field_split_headings', []);
    $page->set('field_legacy_nid', NULL);
    $page->set('path', []);
    $page->setBook(['bid' => $bid, 'pid' => $pid, 'weight' => $weight]);
    $page->save();
    static::copyGroups($original, $page);
    return $page;
  }

  /**
   * Adds $page to every Group collection $original belongs to.
   *
   * D7 cleared the collection reference on split pages (it crashed the site),
   * leaving their access to OG. In D11 a page outside its book's collection
   * would lose the collection's access rules, so it goes in the same groups.
   */
  protected static function copyGroups(NodeInterface $original, NodeInterface $page): void {
    if (!\Drupal::moduleHandler()->moduleExists('group')) {
      return;
    }
    $relationships = \Drupal::entityTypeManager()->getStorage('group_relationship')->loadByEntity($original);
    foreach ($relationships as $relationship) {
      $relationship->getGroup()->addRelationship($page, $relationship->getPluginId());
    }
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results): void {
    $count = count($results['created'] ?? []);
    if ($success) {
      \Drupal::messenger()->addStatus(\Drupal::translation()->formatPlural($count, 'Split into 1 new page.', 'Split into @count new pages.'));
    }
    else {
      \Drupal::messenger()->addError(t('Splitting stopped after @count pages. The pages created so far are in the book outline.', ['@count' => $count]));
    }
  }

}
