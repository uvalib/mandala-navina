<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\file\Plugin\migrate\source\d7\File;
use Drupal\migrate\Row;

/**
 * D7 files referenced by one or more named AV file/image fields.
 *
 * Core's `d7_file` source cannot scope itself to a subset of files -- it
 * returns every row in `file_managed` for the configured scheme. The AV dump
 * carries far more files than AV nodes reference, and each file in this
 * migration is fetched individually over HTTP from the live D7 site by
 * `file_copy`, so pulling the whole table would cost hours and litter D11's
 * file table with unrelated rows.
 *
 * This is the same scoping technique as D7ImageCollectionFeaturedImageFile,
 * generalised: that one hardcodes a single join to
 * `field_data_field_general_featured_image`, which cannot express AV's three
 * unrelated referencing fields at once. A file referenced by more than one of
 * them must still yield exactly ONE source row, so the fid set is assembled as
 * a UNION subquery rather than as joins (a join would fan the row out).
 *
 * @code
 * source:
 *   plugin: d7_av_file
 *   scheme: public
 *   reference_fields:
 *     - field_transcript
 *     - field_thumbnail_image
 *     - field_general_featured_image
 * @endcode
 *
 * Each named field is read from `field_data_{field}` / `{field}_fid`, which is
 * how D7 stores every file and image field.
 *
 * IT ALSO BUILDS THE FETCH URL. `file_copy` needs an absolute, correctly
 * encoded source URL, and the obvious `concat` + core's `urlencode` process
 * plugin gets 14 of these 8,292 files wrong: `urlencode` runs the whole string
 * through parse_url, so a filename containing `?` or `#` (10 files) has its
 * tail torn off as a query string or fragment, and one already containing `%`
 * (4 files) is ambiguous. Encoding here, per path segment, on the raw
 * filepath -- before anything can mistake a literal character for URL syntax --
 * is both correct and simpler to read. 79 files have non-ASCII names (Tibetan
 * among them); rawurlencode handles those the same way.
 *
 * @MigrateSource(
 *   id = "d7_av_file",
 *   source_module = "file"
 * )
 */
class D7AvFile extends File {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();

    $fields = $this->configuration['reference_fields'] ?? [];
    if (empty($fields)) {
      // Silently migrating all 60k+ files because a config key was misspelled
      // is exactly the failure this plugin exists to prevent.
      throw new \RuntimeException('d7_av_file requires a non-empty `reference_fields` list.');
    }

    $subquery = NULL;
    foreach ($fields as $field) {
      $part = $this->select('field_data_' . $field, 'r')
        ->condition('r.deleted', 0)
        ->isNotNull('r.' . $field . '_fid');
      $part->addField('r', $field . '_fid', 'fid');
      if ($subquery === NULL) {
        $subquery = $part;
      }
      else {
        // UNION (not UNION ALL) so a fid referenced by two fields collapses.
        $subquery->union($part);
      }
    }

    $query->condition('f.fid', $subquery, 'IN');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    if (parent::prepareRow($row) === FALSE) {
      return FALSE;
    }

    // The parent has just computed `filepath` -- the path relative to the D7
    // Drupal root, e.g. sites/mandala-av.lib.virginia.edu/files/transcripts/
    // t991.xml. Turn it into a fetchable absolute URL.
    $base = rtrim($this->configuration['source_base_url'] ?? '', '/');
    if ($base === '') {
      throw new \RuntimeException('d7_av_file requires `source_base_url`.');
    }
    $path = (string) $row->getSourceProperty('filepath');
    $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
    $row->setSourceProperty('source_url', $base . '/' . $encoded);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    $fields['source_url'] = $this->t('Absolute, URL-encoded fetch URL on the live D7 site');
    return $fields;
  }

}
