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

  /**
   * Value-column suffixes in priority order, by D7 field type family.
   */
  protected const VALUE_SUFFIXES = ['_value', '_target_id', '_fid', '_tid', '_rating'];

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
    // WHY `en` WINS. `und` is D7's LANGUAGE_NONE — what a field carries before
    // it is made translatable. These fields started non-translatable, so every
    // value was `und`; translation was switched on later and subsequent edits
    // wrote `en` rows, leaving `und` as a stale legacy layer. Confirmed against
    // the data: of the 1,732 hosts carrying both languages, 1,694 (97.8%) have
    // an `en` list that fully covers their `und` list. So where an item has an
    // `en` row, that row's delta is the current, authoritative position.
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

    return $query->orderBy('fci.item_id');
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
      // Preserve the single/multi distinction: migrations for single-value
      // sub-fields expect a scalar, and handing them a one-element array would
      // quietly produce the wrong shape.
      $row->setSourceProperty($sub, count($values) === 1 ? $values[0] : $values);
      if ($format_column) {
        $formats = array_map(static fn($r) => $r->f, $rows);
        $row->setSourceProperty($sub . '__format', count($formats) === 1 ? $formats[0] : $formats);
      }
    }

    return parent::prepareRow($row);
  }

}
