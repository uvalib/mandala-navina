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
 * SCOPE — configurable via `node_types`, defaulting to `['shanti_image']`.
 *
 * The *source* shape is identical for every node type (a `url_alias` row whose
 * `source` is `node/{nid}`), but the *destination path* is not: `shanti_image`
 * nodes became D11 nodes (`/node/{nid}`) while collection/subcollection nodes
 * became D11 **Groups** (`/group/{id}`). So the node-type filter lives here and
 * the path construction lives in each migration's `process`. Two migrations,
 * one source plugin.
 *
 * Anything whose source path is not `node/{nid}` — `file/*`, `user/*`, taxonomy,
 * views — is out of scope entirely; see
 * docs/deferred/d7-alias-preservation-scope-beyond-shanti-image.md.
 *
 * ("Image" in the plugin name is the *site* — the D7 Images install, which holds
 * collections as well as images — not the `shanti_image` bundle.)
 *
 * KEYED ON `pid`, ONE ROW PER ALIAS — not per node. D7 pathauto *can* leave older
 * alias rows in place when a title changes, and each of those is a real URL
 * somebody may have saved, so deduplicating would silently break saved links.
 *
 * Measured against the 2026-06-11 production Images dump, this dump happens to
 * carry **exactly one alias per node** — 111,304 alias rows across 111,304
 * distinct nodes, with **zero** nodes holding more than one. So the duplicate
 * case does not arise here; keying on `pid` simply means it is handled correctly
 * if a later dump or another site does carry duplicates.
 *
 * Also measured: **39 of the 111,343 `shanti_image` nodes have no alias at all**
 * (111,343 − 111,304). That is a property of the source data, not a migration
 * defect — those nodes simply keep `/node/{nid}` as their only path. It is why
 * the expected alias count is *below* the node count, not above it.
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
    $query->condition('n.type', $this->nodeTypes(), 'IN');
    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'type', 'node_type');

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
      'node_type' => 'D7 node type (shanti_image, collection, subcollection)',
    ];
  }

  /**
   * Node types whose aliases this instance should yield.
   *
   * Defaults to shanti_image so an existing migration that does not declare
   * `node_types` keeps its previous behaviour exactly.
   *
   * @return string[]
   *   D7 node type machine names.
   */
  protected function nodeTypes() {
    $types = $this->configuration['node_types'] ?? ['shanti_image'];
    return is_array($types) ? $types : [$types];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['pid' => ['type' => 'integer', 'alias' => 'ua']];
  }

}
