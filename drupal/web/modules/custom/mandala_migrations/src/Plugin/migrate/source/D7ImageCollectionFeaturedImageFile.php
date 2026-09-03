<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\file\Plugin\migrate\source\d7\File;

/**
 * D7 files referenced by a collection/subcollection's featured-image field.
 *
 * Core's own `d7_file` source (which this extends) has no way to scope
 * itself to a subset of files -- it always returns every row in
 * `file_managed` matching the configured scheme, unconditionally. D7's
 * `mandala_d7_images` file table has 55,122 rows total; only ~150 are ever
 * referenced by field_general_featured_image. Migrating all 55k just to
 * reach those 150 would be wasteful (each file is fetched individually
 * over HTTP in the destination `file_copy` process plugin) and would pull
 * thousands of unrelated files into D11's file table. Inner-joining the
 * featured-image field table scopes the source to exactly the files this
 * migration actually needs -- same technique as D7ImageSatellite's
 * fan-out join, just for scoping rather than fan-out.
 *
 * @MigrateSource(
 *   id = "d7_image_collection_featured_image_file",
 *   source_module = "file"
 * )
 */
class D7ImageCollectionFeaturedImageFile extends File {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $query->innerJoin(
      'field_data_field_general_featured_image',
      'r',
      'r.field_general_featured_image_fid = f.fid',
    );
    // A file could in principle be referenced by more than one
    // collection/subcollection -- distinct() keeps the source iterator
    // (and therefore the migrate map) to one row per fid.
    $query->distinct();
    return $query;
  }

}
