<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\node\Plugin\migrate\source\d7\Node;

/**
 * D7 `audio` / `video` nodes, enriched with paragraph lookup keys.
 *
 * The stock d7_node source cannot supply the one thing the AV node migrations
 * need: for each of the thirteen `field_collection` reference fields, the
 * ORDERED list of `field_collection_item.item_id` values that
 * `d7_av_*` migrated into paragraphs. Those migrations are keyed on `item_id`
 * (see D7AvFieldCollection::getIds()), so the host node looks each paragraph up
 * by item_id and nothing else.
 *
 * WHY NOT USE THE PARENT'S FIELD VALUES. The parent DOES load these fields --
 * `field_data_field_pbcore_title` etc. are ordinary field tables and
 * FieldableEntity::getFieldValues() returns them as [delta => ['value' =>
 * item_id, ...]]. Using that would be wrong twice over:
 *
 *   1. It reads every language row and collapses them onto `$values[$delta]`,
 *      last row wins. D7 links the same field_collection item under BOTH `und`
 *      and `en` -- 147,836 raw link rows against 125,101 real items -- and
 *      2,083 of those pairs sit at *different* deltas. So the parent's list is
 *      both duplicated and mis-ordered.
 *   2. It does not exclude `archived` items, which D7 leaves behind when a host
 *      drops a collection item.
 *
 * This plugin therefore recomputes the lists with EXACTLY the rule
 * D7AvFieldCollection uses to decide which items exist and where they sit --
 * `en`-preferred delta over a union of both language layers, archived excluded.
 * The two must agree: if the source plugin yields an item the node never
 * references, that paragraph is orphaned; if the node references an item the
 * source plugin skipped, migration_lookup returns NULL and the node loses a
 * value. Keeping the rule in one documented place (below) and asserting the
 * totals match is the guard against that drifting apart.
 *
 * ORDERING TIE-BREAK. Two items on one host can still resolve to the same
 * delta -- 42 hosts on field_pbcore_title, down from 1,739 under a plain
 * MIN(delta) (see the 2026-09-08 session log). `item_id` breaks the tie:
 * deterministic and creation-ordered, but arbitrary. AV staff have not yet been
 * asked whether a cataloguing convention should decide it instead; until they
 * are, this is the documented fallback, not a considered answer.
 *
 * @MigrateSource(
 *   id = "d7_av_node",
 *   source_module = "node"
 * )
 */
class D7AvNode extends Node {

  use AvLanguageLayerTrait;

  /**
   * D7 field_collection host fields => the source property carrying their keys.
   *
   * Thirteen fields; `field_pbcore_instantiation` and `field_workflow` are
   * cardinality 1 in D7 but are handled identically -- a one-element list.
   */
  protected const PARAGRAPH_FIELDS = [
    'field_kmap_annotation',
    'field_pbcore_contributor',
    'field_pbcore_coverage',
    'field_pbcore_creator',
    'field_pbcore_description',
    'field_pbcore_extension',
    'field_pbcore_identifier',
    'field_pbcore_instantiation',
    'field_pbcore_publisher',
    'field_pbcore_relation',
    'field_pbcore_sponsor',
    'field_pbcore_title',
    'field_workflow',
  ];

  /**
   * Per-field [nid => [item_id, ...]] maps, built once on first use.
   *
   * @var array[]|null
   */
  protected ?array $paragraphKeys = NULL;

  /**
   * Cached D7 field cardinalities, keyed by field name.
   *
   * @var int[]
   */
  protected array $cardinalities = [];

  /**
   * {@inheritdoc}
   *
   * Honours an optional `nids` source-config list, the same scoped-import
   * escape hatch D7ShantiImage provides -- unlike migrate_tools' `--idlist`,
   * which still calls prepareRow() on every source row.
   */
  public function query() {
    $query = parent::query();
    $nids = $this->configuration['nids'] ?? [];
    if (!empty($nids)) {
      $query->condition('n.nid', $nids, 'IN');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }
    $nid = (int) $row->getSourceProperty('nid');

    foreach ($this->getParagraphKeys() as $field => $by_nid) {
      // Overwrite, do not merge: the parent has already populated $field with
      // its own (duplicated, mis-ordered) reading of the link table.
      $row->setSourceProperty($field . '_items', $by_nid[$nid] ?? []);
      $row->setSourceProperty($field, NULL);
    }

    return TRUE;
  }

  /**
   * Builds the per-field paragraph key maps.
   *
   * One query per field over the whole source, rather than thirteen queries per
   * node: 13 queries instead of ~150,000, at the cost of holding ~125,000
   * integers in memory for the life of the migration.
   *
   * @return array[]
   *   [field_name => [nid => [item_id, ...]]], each item_id list ordered.
   */
  protected function getParagraphKeys(): array {
    if ($this->paragraphKeys !== NULL) {
      return $this->paragraphKeys;
    }
    $this->paragraphKeys = [];

    foreach (static::PARAGRAPH_FIELDS as $field) {
      $link_table = 'field_data_' . $field;
      $query = $this->select('field_collection_item', 'fci')
        ->fields('fci', ['item_id']);
      $query->innerJoin($link_table, 'l', "l.{$field}_value = fci.item_id");
      $query->addField('l', 'entity_id', 'host_id');
      $query->condition('fci.field_name', $field);
      $query->condition('fci.archived', 0);
      $query->condition('l.entity_type', 'node');
      $query->condition('l.bundle', ['audio', 'video'], 'IN');
      $query->condition('l.deleted', 0);

      // THE SHARED RULE. Identical to D7AvFieldCollection::query(): one row per
      // item, positioned by the `en` layer where it exists and by `und`
      // otherwise. `und` is D7's LANGUAGE_NONE, written by an earlier
      // conversion to language-specific storage that declined to assume the
      // pre-existing data was English; later edits wrote `en` on top, so `en`
      // is the authoritative layer where present. It is a UNION, not a filter:
      // 6,958 items exist only under `und`, and even hosts that do carry `en`
      // have `und`-only items (790 on descriptions, 746 on publishers, 533 on
      // contributors) that dropping `und` would silently lose.
      $query->groupBy('fci.item_id');
      $query->groupBy('l.entity_id');
      $query->addExpression("COALESCE(MIN(CASE WHEN l.language = 'en' THEN l.delta END), MIN(l.delta))", 'delta');
      // Which layer this item is linked under, for the single-valued rule
      // below. An item linked under both counts as `en`.
      $query->addExpression("MAX(CASE WHEN l.language = 'en' THEN 'en' ELSE 'und' END)", 'layer');

      // item_id is the documented, arbitrary tie-break -- see the class docs.
      $query->orderBy('delta');
      $query->orderBy('fci.item_id');

      $ordered = [];
      $layers = [];
      // FETCH_ASSOC explicitly: the fetch mode a migrate SqlBase query returns
      // is not guaranteed to be object mode, and reading ->item_id off an array
      // fails SILENTLY under PHP's warning handling -- every paragraph list came
      // back empty, with the node still migrating "successfully".
      foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $record) {
        $host = (int) $record['host_id'];
        $item = (int) $record['item_id'];
        $ordered[$host][] = $item;
        $layers[$host][$item] = $record['layer'];
      }

      // A cardinality-1 host field cannot hold both layers' items, so the union
      // above has to collapse to one. 667 of field_pbcore_instantiation's 5,297
      // hosts link two different items; field_workflow has none. See
      // AvLanguageLayerTrait for why picking the richer item is exact here.
      if ($this->isSingleValued($field)) {
        $winners = $this->resolveSingleValued($layers, $field);
        foreach ($ordered as $host => $items) {
          $ordered[$host] = isset($winners[$host]) ? [$winners[$host]] : [];
        }
      }

      // Shaped as [['item_id' => N], ...] rather than a flat list of integers:
      // the host migration consumes these through `sub_process`, which treats
      // each element as a row and needs a named property to map from.
      $map = [];
      foreach ($ordered as $host => $items) {
        foreach ($items as $item) {
          $map[$host][] = ['item_id' => $item];
        }
      }
      $this->paragraphKeys[$field] = $map;
    }

    return $this->paragraphKeys;
  }

  /**
   * Whether a D7 host field has cardinality 1.
   *
   * Read from D7's own field_config rather than listed here, so the rule
   * follows the source data instead of a hand-maintained list that can rot.
   */
  protected function isSingleValued(string $field_name): bool {
    if (!isset($this->cardinalities[$field_name])) {
      $this->cardinalities[$field_name] = (int) $this->select('field_config', 'fc')
        ->fields('fc', ['cardinality'])
        ->condition('fc.field_name', $field_name)
        ->condition('fc.deleted', 0)
        ->execute()
        ->fetchField();
    }
    return $this->cardinalities[$field_name] === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    foreach (static::PARAGRAPH_FIELDS as $field) {
      $fields[$field . '_items'] = $this->t('Ordered field_collection item IDs for @f', ['@f' => $field]);
    }
    return $fields;
  }

}
