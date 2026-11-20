<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\book\Plugin\migrate\source\Book;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;

/**
 * D7 Texts book outline rows, with D7's book id and tie-free weights.
 *
 * Book's own `book` source with two changes (ADR 021 decision 1):
 *
 * 1. `bid` is the node of the menu link's root (`p1`), not `book.bid`. Two D7
 *    pages (15779, 15856) carry a stale `book.bid` naming their parent while
 *    their menu links sit in `book-toc-15582` under that parent; D7 renders
 *    them inside 15582, so that is the book they belong to (2026-11-20 dump).
 *
 * 2. `weight` is normalized so siblings never tie (TX3 decision, 2026-11-06).
 *    Each sibling group is read in D7's order (menu-link weight, link title in
 *    MySQL collation, mlid) and each weight raised to at least one more than the
 *    previous sibling's. Untied groups and book roots come out unchanged. The original D7
 *    weight stays in the `d7_weight` source property and is logged as an
 *    informational migrate message whenever it changes, so the deviation is
 *    auditable from the map.
 *
 * @MigrateSource(
 *   id = "d7_texts_book_outline",
 *   source_module = "book"
 * )
 */
class D7TextsBookOutline extends Book {

  /**
   * Normalized weight by mlid, built on first use.
   *
   * @var int[]|null
   */
  protected ?array $normalizedWeights = NULL;

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $query = parent::query();
    $query->join('book', 'root', '[root].[mlid] = [ml].[p1]');
    $query->addField('root', 'nid', 'root_nid');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    $row->setSourceProperty('bid', $row->getSourceProperty('root_nid'));

    $weight = (int) $row->getSourceProperty('weight');
    $normalized = $this->normalizedWeights()[(int) $row->getSourceProperty('mlid')] ?? $weight;
    $row->setSourceProperty('d7_weight', $weight);
    $row->setSourceProperty('weight', $normalized);
    if ($normalized !== $weight) {
      $this->idMap->saveMessage(
        $row->getSourceIdValues(),
        sprintf('Tied sibling weight normalized: D7 weight %d stored as %d (nid %d).', $weight, $normalized, $row->getSourceProperty('nid')),
        MigrationInterface::MESSAGE_INFORMATIONAL,
      );
    }
    return TRUE;
  }

  /**
   * Computes every book link's normalized weight in one pass.
   */
  protected function normalizedWeights(): array {
    if ($this->normalizedWeights !== NULL) {
      return $this->normalizedWeights;
    }

    // The ORDER BY is the point: MySQL's title collation is D7's tiebreak.
    $query = $this->select('book', 'b');
    $query->join('menu_links', 'ml', '[b].[mlid] = [ml].[mlid]');
    // Roots (plid 0) are separate books, not siblings: leave their weights.
    $query->fields('ml', ['mlid', 'plid', 'weight'])
      ->condition('ml.plid', 0, '<>')
      ->orderBy('ml.plid')
      ->orderBy('ml.weight')
      ->orderBy('ml.link_title')
      ->orderBy('ml.mlid');

    $this->normalizedWeights = [];
    $plid = NULL;
    $previous = NULL;
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $link) {
      $weight = (int) $link['weight'];
      if ($link['plid'] !== $plid) {
        $plid = $link['plid'];
      }
      elseif ($weight <= $previous) {
        $weight = $previous + 1;
      }
      $this->normalizedWeights[(int) $link['mlid']] = $weight;
      $previous = $weight;
    }
    return $this->normalizedWeights;
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return parent::fields() + [
      'root_nid' => $this->t('Node of the menu link root (p1), used as bid'),
      'd7_weight' => $this->t('Original D7 menu-link weight before tie normalization'),
    ];
  }

}
