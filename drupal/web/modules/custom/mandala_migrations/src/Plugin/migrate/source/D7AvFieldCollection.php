<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 `field_collection_item` rows for one host field, with their sub-fields.
 *
 * AV's PBCore and workflow metadata is built entirely from D7 field_collections
 * (see the AV content-model audit). Unlike Images — whose satellite metadata was
 * separate *nodes* referenced by inline entity form, hence `D7ImageSatellite` —
 * a field_collection item is already embedded in and owned by its host. So the
 * source shape is completely different and Images' plugins do not apply.
 *
 * ONE PLUGIN, SEVENTEEN MIGRATIONS. Every field_collection has the same source
 * shape: an item row in `field_collection_item`, a link row in
 * `field_data_{field_name}` tying it to its host and delta, and N sub-field
 * value tables keyed on the item id. Only the field name and the sub-field list
 * vary, so both are configuration:
 *
 * @code
 * source:
 *   plugin: d7_av_field_collection
 *   field_name: field_pbcore_title
 *   sub_fields: [field_title, field_title_type, field_language]
 *   host_bundles: [audio, video]     # optional; this is the default
 * @endcode
 *
 * NESTING. Two of AV's collections are nested one level deeper — the three note
 * collections inside `field_workflow`, and `field_pbcore_format_id` inside
 * `field_pbcore_instantiation`. Those hosts are `field_collection_item` rows,
 * not nodes, so `host_entity_type` is configurable and `host_bundles` is simply
 * not applied when the host is not a node. The migrations for the inner
 * collections must run BEFORE their hosts.
 *
 * ARCHIVED ITEMS ARE EXCLUDED. `field_collection_item.archived = 1` marks an
 * item whose host dropped it; D7 leaves the row behind. Measured against the
 * 2026-09-01 production dump this affects only 4 of 125,528 items (3 in
 * `field_pbcore_format_id`, 1 in `field_pbcore_instantiation`), but they are
 * genuinely orphaned and must not migrate.
 *
 * VALUE COLUMNS ARE DISCOVERED, NOT ASSUMED. A D7 field's value column is named
 * after the field plus a type-dependent suffix — `_value` for text/list/date,
 * `_target_id` for entityreference, `_fid` for file/image, `_tid` for taxonomy,
 * `_rating` for fivestar. Rather than hardcode a map that silently yields NULL
 * when it is wrong, this plugin reads the table's real columns and takes the
 * first that matches a known suffix. Formatted-text sub-fields additionally
 * expose `{sub_field}__format`.
 *
 * MULTI-VALUE SUB-FIELDS return an array; single-value return a scalar. Two of
 * AV's sub-fields are unlimited-cardinality (`field_pbcore_annotation` and
 * `field_pbcore_date_available` inside `field_pbcore_instantiation`), so this is
 * not hypothetical.
 *
 * @MigrateSource(
 *   id = "d7_av_field_collection",
 *   source_module = "field_collection"
 * )
 */
class D7AvFieldCollection extends SqlBase {

  use AvLanguageLayerTrait;

  /**
   * Value-column suffixes in priority order, by D7 field type family.
   */
  protected const VALUE_SUFFIXES = ['_value', '_target_id', '_fid', '_tid', '_rating'];

  /**
   * [host_id => winning item_id] for a cardinality-1 host field, else NULL.
   *
   * @var array|null
   */
  protected ?array $singleValuedWinners = NULL;

  /**
   * Whether the host field is cardinality 1; NULL until first looked up.
   *
   * Separate from $singleValuedWinners so a multi-valued field resolves the
   * question once rather than re-querying field_config for every row.
   *
   * @var bool|null
   */
  protected ?bool $isSingleValued = NULL;

  /**
   * [host_id => [item_id => layer]] for a cardinality-1 host field.
   *
   * @var array
   */
  protected array $singleValuedCandidates = [];

  /**
   * Resolved [value_column, format_column] per sub-field name.
   *
   * @var array[]
   */
  protected array $subFieldColumns = [];

  /**
   * D7 field types per sub-field name.
   *
   * @var string[]
   */
  protected array $subFieldTypes = [];

  /**
   * {@inheritdoc}
   */
  public function query() {
    $field_name = $this->configuration['field_name'];
    $host_entity_type = $this->configuration['host_entity_type'] ?? 'node';
    $link_table = 'field_data_' . $field_name;

    // The item row is the spine; the link table supplies host + delta. An inner
    // join is deliberate: an item with no link row is orphaned and excluded on
    // the same reasoning as archived items.
    $query = $this->select('field_collection_item', 'fci')
      ->fields('fci', ['item_id', 'revision_id']);
    $query->innerJoin($link_table, 'l', "l.{$field_name}_value = fci.item_id");
    $query->addField('l', 'entity_id', 'host_id');
    $query->addField('l', 'entity_type', 'host_entity_type');
    $query->addField('l', 'bundle', 'host_bundle');

    $query->condition('fci.field_name', $field_name);
    $query->condition('fci.archived', 0);
    $query->condition('l.entity_type', $host_entity_type);
    $query->condition('l.deleted', 0);

    // Host-bundle scoping only makes sense for node hosts; a nested collection's
    // host is a field_collection_item whose "bundle" is the parent field name.
    if ($host_entity_type === 'node') {
      $bundles = $this->configuration['host_bundles'] ?? ['audio', 'video'];
      $query->condition('l.bundle', $bundles, 'IN');
    }

    // ONE ROW PER ITEM, ORDERED BY THE `en` LAYER.
    //
    // D7 stores the host field per language, so the same field_collection item
    // is frequently linked twice — once under `und` and once under `en`.
    // Measured on `field_pbcore_title` in the 2026-09-01 dump: 20,799 link rows
    // for 18,647 distinct items, with 2,083 items linked at *different deltas*
    // under the two languages. Without deduplication each of those attaches its
    // paragraph to the same node twice; across all 17 collections that is
    // 147,836 raw rows against 125,101 real items.
    //
    // WHY `en` WINS. `und` is D7's LANGUAGE_NONE. Per Yuji (2026-09-08), it was
    // written here by an earlier conversion to language-specific storage: rather
    // than assume the pre-existing language-agnostic data was English, that
    // conversion labelled it `und` — "unknown", not "none". Later edits under
    // the language-aware setup wrote `en` rows on top, leaving `und` as the
    // older layer. So where an item has an `en` row, that row's delta is the
    // current, authoritative position.
    //
    // That caution was justified, and the data still shows it: only ~42% of the
    // `und`-layer title items carry English as their CONTENT language (2,937 of
    // 6,950 — the rest are Tibetan 1,766, Chinese 1,144, Dzongkha 86, and 1,017
    // with none recorded). Defaulting them to `eng` would have mislabelled
    // roughly 3,900 items.
    //
    // Confirmed against the data: of the 1,732 hosts carrying both languages on
    // field_pbcore_title, 1,694 (97.8%) have an `en` list that fully covers
    // their `und` list.
    //
    // BUT `und` CANNOT SIMPLY BE DROPPED. 6,958 items exist only in `und`, and
    // even on hosts that DO have `en` rows there are `und`-only items that `en`
    // never picked up — 44 on titles, but 790 on descriptions, 746 on
    // publishers and 533 on contributors. Dropping `und` would silently lose
    // them. So this is a UNION of items with `en`-preferred ordering, not a
    // language filter.
    //
    // The improvement is large and was measured, not assumed. On
    // `field_pbcore_title`, ordering is ambiguous (two items resolving to the
    // same delta on one host) for **42** hosts under this rule, against **1,739**
    // under a plain MIN(delta) — with the identical 18,647 items preserved
    // either way.
    //
    // Grouping is safe because no item is ever linked to more than one host
    // node (verified: 0 items with >1 distinct entity_id) — only the position
    // was ever ambiguous, never the ownership.
    $query->groupBy('fci.item_id');
    $query->groupBy('fci.revision_id');
    $query->groupBy('l.entity_id');
    $query->groupBy('l.entity_type');
    $query->groupBy('l.bundle');
    $query->addExpression("COALESCE(MIN(CASE WHEN l.language = 'en' THEN l.delta END), MIN(l.delta))", 'delta');

    // SINGLE-VALUED HOST FIELDS: drop the losing layer's item HERE, in the
    // query, so it is not a source row at all.
    //
    // This was originally done by returning FALSE from prepareRow(), which is
    // wrong in a way that only shows up two stages later: a row dropped in
    // prepareRow never reaches the id map, so migrate still counts it as
    // UNPROCESSED. `checkRequirements()` then refuses every dependent
    // migration -- "d7_av_audio did not meet the requirements. Missing
    // migrations d7_av_pbcore_instantiation" -- with 5,297 of 5,964 rows
    // imported and nothing actually wrong. Excluding them from the source
    // makes the totals honest (5,297 in, 5,297 mapped, 0 unprocessed).
    $losers = $this->losingItemIds();
    if ($losers) {
      $query->condition('fci.item_id', $losers, 'NOT IN');
    }

    return $query->orderBy('fci.item_id');
  }

  /**
   * Resolves a sub-field's value and format column names, once per sub-field.
   *
   * The discovery itself is the point of VALUE_SUFFIXES (see the class docs);
   * what is cached is only the answer. It used to be recomputed inside
   * prepareRow, which meant a `SHOW COLUMNS` and a `tableExists` per sub-field
   * PER ROW -- on the order of 600,000 redundant round trips across the 125,101
   * items in the full run, for a result that cannot change mid-migration.
   *
   * @param string $sub
   *   The D7 sub-field name.
   *
   * @return array
   *   [value_column, format_column|NULL].
   */
  protected function subFieldColumns(string $sub): array {
    if (isset($this->subFieldColumns[$sub])) {
      return $this->subFieldColumns[$sub];
    }
    $table = 'field_data_' . $sub;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      // A sub-field declared in config but absent from the dump is a real
      // configuration error, not something to paper over with a NULL.
      throw new \RuntimeException("Sub-field table {$table} does not exist in the D7 source (field: {$sub}).");
    }

    $columns = $this->getDatabase()->query("SHOW COLUMNS FROM {$table}")->fetchCol();
    $value_column = NULL;
    foreach (self::VALUE_SUFFIXES as $suffix) {
      if (in_array($sub . $suffix, $columns, TRUE)) {
        $value_column = $sub . $suffix;
        break;
      }
    }
    if ($value_column === NULL) {
      throw new \RuntimeException("Could not identify a value column for sub-field {$sub} in {$table}.");
    }
    $format_column = in_array($sub . '_format', $columns, TRUE) ? $sub . '_format' : NULL;

    return $this->subFieldColumns[$sub] = [$value_column, $format_column];
  }

  /**
   * Whether a sub-field is itself a nested field_collection reference.
   *
   * Read from D7's own field_config so the answer follows the source rather
   * than a hand-maintained list.
   */
  protected function isFieldCollection(string $sub): bool {
    if (!isset($this->subFieldTypes[$sub])) {
      $this->subFieldTypes[$sub] = (string) $this->select('field_config', 'fc')
        ->fields('fc', ['type'])
        ->condition('fc.field_name', $sub)
        ->condition('fc.deleted', 0)
        ->execute()
        ->fetchField();
    }
    return $this->subFieldTypes[$sub] === 'field_collection';
  }

  /**
   * Item ids excluded because a cardinality-1 host cannot reference them.
   *
   * @return int[]
   *   The losing items, or an empty array for a multi-valued host field.
   */
  protected function losingItemIds(): array {
    $winners = $this->getSingleValuedWinners();
    if ($winners === NULL) {
      return [];
    }
    $keep = array_flip($winners);
    $losers = [];
    foreach ($this->singleValuedCandidates as $host_id => $items) {
      foreach (array_keys($items) as $item_id) {
        if (!isset($keep[$item_id])) {
          $losers[] = (int) $item_id;
        }
      }
    }
    return $losers;
  }

  /**
   * Winning item per host when the host field is cardinality 1.
   *
   * @return array|null
   *   [host_id => item_id], or NULL when the host field is multi-valued and no
   *   collapsing is needed.
   */
  protected function getSingleValuedWinners(): ?array {
    if ($this->isSingleValued === FALSE) {
      return NULL;
    }
    if ($this->singleValuedWinners !== NULL) {
      return $this->singleValuedWinners;
    }
    $field_name = $this->configuration['field_name'];
    $cardinality = (int) $this->select('field_config', 'fc')
      ->fields('fc', ['cardinality'])
      ->condition('fc.field_name', $field_name)
      ->condition('fc.deleted', 0)
      ->execute()
      ->fetchField();
    $this->isSingleValued = ($cardinality === 1);
    if (!$this->isSingleValued) {
      return NULL;
    }

    $host_entity_type = $this->configuration['host_entity_type'] ?? 'node';
    $link_table = 'field_data_' . $field_name;
    $query = $this->select('field_collection_item', 'fci')
      ->fields('fci', ['item_id']);
    $query->innerJoin($link_table, 'l', "l.{$field_name}_value = fci.item_id");
    $query->addField('l', 'entity_id', 'host_id');
    $query->condition('fci.field_name', $field_name);
    $query->condition('fci.archived', 0);
    $query->condition('l.entity_type', $host_entity_type);
    $query->condition('l.deleted', 0);
    if ($host_entity_type === 'node') {
      $query->condition('l.bundle', $this->configuration['host_bundles'] ?? ['audio', 'video'], 'IN');
    }
    $query->groupBy('fci.item_id');
    $query->groupBy('l.entity_id');
    $query->addExpression("MAX(CASE WHEN l.language = 'en' THEN 'en' ELSE 'und' END)", 'layer');

    $candidates = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $record) {
      $candidates[(int) $record['host_id']][(int) $record['item_id']] = $record['layer'];
    }
    $this->singleValuedCandidates = $candidates;
    $this->singleValuedWinners = $this->resolveSingleValued($candidates, $field_name);
    return $this->singleValuedWinners;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'item_id' => $this->t('Field collection item ID'),
      'revision_id' => $this->t('Field collection revision ID'),
      'host_id' => $this->t('Host entity ID'),
      'host_entity_type' => $this->t('Host entity type'),
      'host_bundle' => $this->t('Host bundle'),
      'delta' => $this->t('Delta on the host field'),
    ];
    foreach ($this->configuration['sub_fields'] ?? [] as $sub) {
      $fields[$sub] = $this->t('Sub-field @f', ['@f' => $sub]);
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'item_id' => [
        'type' => 'integer',
        'alias' => 'fci',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $item_id = $row->getSourceProperty('item_id');

    foreach ($this->configuration['sub_fields'] ?? [] as $sub) {
      $table = 'field_data_' . $sub;
      [$value_column, $format_column] = $this->subFieldColumns($sub);

      $select = $this->getDatabase()->select($table, 't')
        ->condition('t.entity_type', 'field_collection_item')
        ->condition('t.entity_id', $item_id)
        ->condition('t.deleted', 0)
        ->orderBy('t.delta');
      $select->addField('t', $value_column, 'v');
      if ($format_column) {
        $select->addField('t', $format_column, 'f');
      }
      $rows = $select->execute()->fetchAll();

      if (!$rows) {
        $row->setSourceProperty($sub, NULL);
        continue;
      }
      $values = array_map(static fn($r) => $r->v, $rows);

      // A NESTED field_collection reference is always a LIST, whatever its
      // length, and its migration consumes it through `sub_process` -- which
      // needs an array of arrays, not a scalar and not a flat list.
      //
      // This is the shape that broke the four nested reference fields on the
      // 2026-09-09 run, and it broke them almost silently. 4,561 of the 4,562
      // workflow-note hosts have exactly ONE note, so they hit the scalar
      // branch below and sub_process quietly produced nothing; only the single
      // host with TWO notes reached the flat-array branch and raised an error
      // ("Input array should hold elements of type array, instead element was
      // of type 'string'"). The result was 6,814 paragraphs created and ZERO
      // referenced -- 4,562 workflow notes and 2,252 pbcore format IDs -- with
      // one error line to show for it.
      if ($this->isFieldCollection($sub)) {
        $row->setSourceProperty($sub, array_map(
          static fn($v) => ['value' => $v],
          $values
        ));
        continue;
      }

      // Everything else preserves the single/multi distinction: migrations for
      // single-value sub-fields expect a scalar, and handing them a
      // one-element array would quietly produce the wrong shape.
      $row->setSourceProperty($sub, count($values) === 1 ? $values[0] : $values);
      if ($format_column) {
        $formats = array_map(static fn($r) => $r->f, $rows);
        $row->setSourceProperty($sub . '__format', count($formats) === 1 ? $formats[0] : $formats);
      }
    }

    return parent::prepareRow($row);
  }

}
