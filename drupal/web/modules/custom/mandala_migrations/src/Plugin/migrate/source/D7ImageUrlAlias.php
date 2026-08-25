<?php

namespace Drupal\mandala_migrations\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * D7 url_alias rows belonging to shanti_image nodes.
 *
 * D7 used pathauto to present user-friendly paths (e.g.
 * `image/village-and-houses-2`). Preserving those paths in D11 is a
 * requirement, not best-effort — they are the URLs people bookmarked, shared
 * and cited. See ADR 016 decision 7.
 *
 * SCOPE — shanti_image nodes only. D7 collection/subcollection nodes migrate to
 * D11 *Groups*, whose canonical path is `/group/{id}` rather than `/node/{nid}`,
 * so their aliases need a different destination and are deliberately not handled
 * here. Anything whose source path is not `node/{nid}` (taxonomy terms, views,
 * user paths) is likewise out of scope.
 *
 * MULTIPLE ALIASES PER NODE ARE EXPECTED AND KEPT. D7 pathauto can leave older
 * alias rows in place when a title changes, and each is a real URL somebody may
 * have saved. Keying on `pid` migrates every one, so old aliases keep resolving;
 * Drupal serves the most recent as canonical and treats the rest as additional
 * inbound paths. Deduplicating here would silently break saved links, which is
 * the opposite of the point.
 *
 * @MigrateSource(
 *   id = "d7_image_url_alias",
 *   source_module = "path"
 * )
 */
class D7ImageUrlAlias extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('url_alias', 'ua')
      ->fields('ua', ['pid', 'source', 'alias', 'language']);

    // Only node paths can map to a migrated node. Narrow before the join so the
    // expression below is evaluated over as few rows as possible.
    $query->condition('ua.source', 'node/%', 'LIKE');

    // Join on the nid embedded in the source path. 'node/' is 5 characters, so
    // the nid starts at position 6 (SUBSTRING is 1-indexed). The expression sits
    // on the url_alias side, which lets MySQL use node's PRIMARY KEY for each
    // probe rather than scanning both tables.
    //
    // CAST to UNSIGNED matters: without it MySQL compares an integer column to a
    // string and coerces per row, which both defeats the index and quietly
    // matches things like 'node/12abc'.
    $query->join('node', 'n', 'n.nid = CAST(SUBSTRING(ua.source, 6) AS UNSIGNED)');
    $query->condition('n.type', 'shanti_image');
    $query->addField('n', 'nid', 'nid');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'pid' => 'D7 url_alias primary key',
      'source' => 'D7 internal path (node/{nid})',
      'alias' => 'D7 alias path, no leading slash (image/village-and-houses-2)',
      'language' => "D7 language code; '' means language-neutral",
      'nid' => 'D7 node nid, extracted from the source path',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['pid' => ['type' => 'integer', 'alias' => 'ua']];
  }

}
