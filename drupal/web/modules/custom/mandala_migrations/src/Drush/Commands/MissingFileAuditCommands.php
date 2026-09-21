<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Audits every real file/image-referencing field for rows whose physical
 * file is missing from disk.
 *
 * Built 2026-09-21 after a manual, one-off version of this check (done
 * while curating Mandala Home carousel demo content) found `fid 451`
 * missing on disk, then a corrected full-corpus pass found 126 of 210
 * `group.field_featured_image` references broken -- 60% of collection/
 * subcollection hero images, corpus-wide (confirmed on dev-0 too, not a
 * local DDEV artifact). See docs/deferred/
 * collection-featured-images-missing-on-production.md.
 *
 * The first ad hoc version of this check had a real bug worth remembering
 * as the reason this is a proper command, not a one-off script: it matched
 * missing fids against ANY table with a `_target_id` column, which
 * false-positives whenever a missing file's fid numerically collides with
 * an unrelated paragraph's or taxonomy term's id (entity id spaces are
 * independent, so this happens constantly at scale). This command instead
 * discovers real file/image fields via `field_storage_config` (type
 * `file`/`image`, or `entity_reference` targeting the `file` entity type)
 * before ever looking for usages -- it cannot repeat that mistake.
 */
class MissingFileAuditCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Known D7 production source bases for the one field class this
   * currently affects (`group.field_featured_image` -- see the two
   * migrations that populate it, `d7_images_collection_featured_image`
   * and `d7_av_collections`/`d7_av_files`). There is no generic way to
   * derive a legacy source URL for an arbitrary field -- it depends
   * entirely on which migration created the reference -- so this map is
   * hand-maintained; extend it if another field/site combination needs
   * the same reachability check later.
   *
   * Root-level only: a file that lived in a D7 subdirectory (as some AV
   * files do, e.g. `transcripts/`) won't be found here even if it still
   * exists, since only the D11 file's basename is known, not its
   * original D7 path. A negative result from this check means "not at
   * the site's public files root," not "confirmed gone."
   */
  private const D7_SOURCE_BASES = [
    'images' => 'https://images.mandala.library.virginia.edu/sites/mandala-images.lib.virginia.edu/files/',
    'av' => 'https://av.mandala.library.virginia.edu/sites/mandala-av.lib.virginia.edu/files/',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'mandala:missing-file-audit')]
  #[CLI\Option(name: 'check-d7-source', description: "For each missing file, try its basename against known D7 production file roots (currently: Images, AV) to see if it's still recoverable. Root-level only, see class docblock.")]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit', description: 'Report every managed file whose physical file is missing from disk, and what references it.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit --check-d7-source', description: 'Also check whether each missing file is still fetchable from its known D7 production source.')]
  public function audit(array $options = ['check-d7-source' => FALSE]): void {
    $db = Database::getConnection();

    $fields = $this->realFileFields();
    if (!$fields) {
      $this->logger()->warning('No file/image-referencing fields found -- nothing to audit.');
      return;
    }

    $total = (int) $db->query('SELECT COUNT(*) FROM {file_managed}')->fetchField();
    $missing = $this->findMissingFiles($db);

    if (!$missing) {
      $this->logger()->success('No missing files -- every {total} file_managed row has a real file on disk.', ['total' => $total]);
      return;
    }

    $usages = $this->findUsages($db, $fields, array_keys($missing));

    $checkSource = (bool) ($options['check-d7-source'] ?? FALSE);
    $recoverable = 0;
    $goneAtSource = 0;
    $unchecked = 0;

    foreach ($missing as $fid => $row) {
      $refs = $usages[$fid] ?? [];
      $refDescription = $refs ? implode('; ', $refs) : '(no real file/image field references it -- orphaned row)';
      $line = "fid={$fid} uri={$row->uri} -- {$refDescription}";

      if ($checkSource) {
        $status = $this->checkD7Source($row->filename);
        $line .= " [{$status}]";
        match ($status) {
          'recoverable' => $recoverable++,
          'gone at source root too' => $goneAtSource++,
          default => $unchecked++,
        };
      }

      $this->logger()->notice($line);
    }

    $this->logger()->warning('{missing} of {total} managed files ({pct}%) are missing from disk.', [
      'missing' => count($missing),
      'total' => $total,
      'pct' => round(count($missing) / $total * 100, 1),
    ]);

    if ($checkSource) {
      $this->logger()->notice('Of the missing files: {recoverable} still fetchable from a known D7 production root (re-import candidates), {gone} confirmed gone there too, {unchecked} not checked (root-level lookup failed or no known source mapped).', [
        'recoverable' => $recoverable,
        'gone' => $goneAtSource,
        'unchecked' => $unchecked,
      ]);
    }
  }

  /**
   * Every real file/image-referencing field, across all entity types --
   * found from field storage config, never guessed from a table naming
   * pattern (see class docblock for why that distinction matters).
   *
   * @return array<int, array{entity_type: string, field: string}>
   */
  private function realFileFields(): array {
    $out = [];
    $storage = $this->entityTypeManager->getStorage('field_storage_config');
    foreach ($storage->loadMultiple() as $fieldStorage) {
      $type = $fieldStorage->getType();
      $isFileField = in_array($type, ['file', 'image'], TRUE)
        || ($type === 'entity_reference' && $fieldStorage->getSetting('target_type') === 'file');
      if ($isFileField) {
        $out[] = [
          'entity_type' => $fieldStorage->getTargetEntityTypeId(),
          'field' => $fieldStorage->getName(),
        ];
      }
    }
    return $out;
  }

  /**
   * @return array<int, object{fid: int, uri: string, filename: string, filesize: int, status: int}>
   *   Keyed by fid.
   */
  private function findMissingFiles($db): array {
    $rows = $db->query('SELECT fid, uri, filename, filesize, status FROM {file_managed} ORDER BY fid')->fetchAll();
    $missing = [];
    foreach ($rows as $row) {
      $path = $this->fileSystem->realpath($row->uri);
      if (!$path || !file_exists($path)) {
        $missing[$row->fid] = $row;
      }
    }
    return $missing;
  }

  /**
   * @param array<int, array{entity_type: string, field: string}> $fields
   * @param array<int, int> $fids
   *
   * @return array<int, array<int, string>>
   *   fid => list of human-readable "entity_type entity_id (bundle) via
   *   table" usage descriptions.
   */
  private function findUsages($db, array $fields, array $fids): array {
    $usages = array_fill_keys($fids, []);
    $schema = $db->schema();

    foreach ($fields as $field) {
      $col = $field['field'] . '_target_id';
      foreach (["{$field['entity_type']}__{$field['field']}", "{$field['entity_type']}_revision__{$field['field']}"] as $table) {
        if (!$schema->tableExists($table)) {
          continue;
        }
        $hits = $db->query(
          "SELECT entity_id, bundle, {$col} AS fid FROM {{$table}} WHERE {$col} IN (:fids[])",
          [':fids[]' => $fids]
        )->fetchAll();
        foreach ($hits as $hit) {
          $desc = "{$field['entity_type']} {$hit->entity_id} (" . ($hit->bundle ?? $field['entity_type']) . ") via {$table}";
          if (!in_array($desc, $usages[$hit->fid], TRUE)) {
            $usages[$hit->fid][] = $desc;
          }
        }
      }
    }

    return $usages;
  }

  /**
   * Tries a missing file's basename against every known D7 source root.
   *
   * @return string
   *   'recoverable', 'gone at source root too', or 'source check failed'
   *   (a request error, not a definitive answer either way).
   */
  private function checkD7Source(string $filename): string {
    $client = \Drupal::httpClient();
    $sawRequestError = FALSE;

    foreach (self::D7_SOURCE_BASES as $base) {
      $url = $base . rawurlencode($filename);
      try {
        $response = $client->request('HEAD', $url, ['http_errors' => FALSE, 'timeout' => 10]);
        if ($response->getStatusCode() === 200) {
          return 'recoverable';
        }
      }
      catch (\Throwable) {
        $sawRequestError = TRUE;
      }
    }

    return $sawRequestError ? 'source check failed' : 'gone at source root too';
  }

}
