<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

/**
 * The one place AV's D7 `und`/`en` storage layers are reconciled.
 *
 * D7 stores a host's field_collection reference per language. `und` is D7's
 * LANGUAGE_NONE, written by an earlier conversion to language-specific storage
 * that declined to assume the pre-existing data was English; later edits wrote
 * `en` rows on top. So the same logical item is frequently linked twice, and
 * the two layers disagree about position and sometimes about identity.
 *
 * TWO DIFFERENT RECONCILIATIONS, because the two cases are different data:
 *
 *   MULTI-VALUED fields (11 of the 13) -- a UNION with `en`-preferred ordering.
 *   Both layers hold real, distinct items: 6,958 exist only under `und`, and
 *   even hosts that carry `en` have `und`-only items (790 on descriptions, 746
 *   on publishers, 533 on contributors). Dropping `und` would lose them. See
 *   D7AvFieldCollection::query().
 *
 *   SINGLE-VALUED fields -- pick ONE item, by content. This trait. A
 *   cardinality-1 host field cannot hold both, and D11 would silently keep
 *   whichever reference it saw first.
 *
 * WHY "PICK THE RICHER ITEM" IS EXACT HERE, NOT A HEURISTIC. Measured on
 * `field_pbcore_instantiation` (the only affected field: 667 of its 5,297 hosts
 * link two DIFFERENT items; `field_workflow`, the other cardinality-1 field, has
 * zero) against the 2026-09-01 production dump:
 *
 *   353 hosts -- the `und` item is COMPLETELY empty and the `en` item has data.
 *   314 hosts -- the `en` item's populated sub-fields are a strict SUBSET of the
 *                `und` item's (307 of them with `und` genuinely richer, 7 with
 *                both empty).
 *     0 hosts -- both items hold something the other lacks.
 *
 * In every single case one item contains the other, so taking the one with more
 * populated sub-fields loses nothing at all. Neither simple rule works: always-
 * `en` would blank 307 instantiations, always-`und` would blank 353.
 *
 * ⚠ This is the one place AV's `en`-is-authoritative rule does NOT hold, and it
 * is worth knowing why before extending it: for this field the later `en` edit
 * frequently created a THINNER record than the `und` one it was written beside.
 * Whether that reflects an editing convention is a question for AV staff, like
 * the 42 remaining ordering ties. The rule below is data-preserving either way.
 */
trait AvLanguageLayerTrait {

  /**
   * Reduces each host's candidate items to the single most complete one.
   *
   * @param array $candidates
   *   [host_id => [item_id => language_of_that_link, ...]].
   * @param string $field_name
   *   The D7 host field name, which is also the field_collection bundle.
   *
   * @return array
   *   [host_id => item_id], one winner per host.
   */
  protected function resolveSingleValued(array $candidates, string $field_name): array {
    // Only hosts with a genuine conflict need scoring; the rest are free.
    $contested = [];
    $resolved = [];
    foreach ($candidates as $host_id => $items) {
      if (count($items) === 1) {
        $resolved[$host_id] = (int) array_key_first($items);
      }
      else {
        $contested[$host_id] = $items;
      }
    }
    if (!$contested) {
      return $resolved;
    }

    $all_ids = [];
    foreach ($contested as $items) {
      foreach (array_keys($items) as $item_id) {
        $all_ids[] = (int) $item_id;
      }
    }
    $scores = $this->populatedSubFieldCounts($all_ids, $field_name);

    foreach ($contested as $host_id => $items) {
      $best = NULL;
      $best_score = -1;
      $best_lang = NULL;
      foreach ($items as $item_id => $language) {
        $item_id = (int) $item_id;
        $score = $scores[$item_id] ?? 0;
        // Score first; then the `en` layer; then the lowest item_id, so the
        // outcome never depends on row order.
        $better = $score > $best_score
          || ($score === $best_score && $language === 'en' && $best_lang !== 'en')
          || ($score === $best_score && $language === $best_lang && $item_id < $best);
        if ($better) {
          $best = $item_id;
          $best_score = $score;
          $best_lang = $language;
        }
      }
      $resolved[$host_id] = $best;
    }

    return $resolved;
  }

  /**
   * Counts populated sub-field rows for each of the given items.
   *
   * The sub-field list is DISCOVERED from `field_config_instance` rather than
   * taken from migration config: this trait is used by both the paragraph
   * source and the node source, and duplicating a sub-field list across the two
   * is exactly how they would drift apart.
   *
   * @param int[] $item_ids
   *   field_collection_item ids to score.
   * @param string $bundle
   *   The field_collection bundle, which is the host field's name.
   *
   * @return array
   *   [item_id => number of populated sub-field rows].
   */
  protected function populatedSubFieldCounts(array $item_ids, string $bundle): array {
    if (!$item_ids) {
      return [];
    }

    $instances = $this->select('field_config_instance', 'fci')
      ->fields('fci', ['field_name'])
      ->condition('fci.entity_type', 'field_collection_item')
      ->condition('fci.bundle', $bundle)
      ->condition('fci.deleted', 0)
      ->execute()
      ->fetchCol();

    $counts = array_fill_keys($item_ids, 0);
    foreach ($instances as $sub_field) {
      $table = 'field_data_' . $sub_field;
      if (!$this->getDatabase()->schema()->tableExists($table)) {
        continue;
      }
      $query = $this->select($table, 't')
        ->condition('t.entity_type', 'field_collection_item')
        ->condition('t.entity_id', $item_ids, 'IN')
        ->condition('t.deleted', 0)
        ->groupBy('t.entity_id');
      $query->addField('t', 'entity_id', 'item_id');
      $query->addExpression('COUNT(*)', 'n');
      foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $record) {
        $counts[(int) $record['item_id']] += (int) $record['n'];
      }
    }

    return $counts;
  }

}
