<?php

declare(strict_types=1);

namespace Drupal\mandala_migrations\Drush\Commands;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
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

  /**
   * Set by findD7Source() on its most recent call, since it returns NULL
   * for both "checked every root, found nothing" and "a request errored" --
   * the caller only needs to tell those apart for reporting, not for the
   * fix decision (no URL either way means nothing to fetch).
   */
  private bool $lastCheckHadError = FALSE;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'mandala:missing-file-audit')]
  #[CLI\Option(name: 'check-d7-source', description: "For each missing file, try its basename against known D7 production file roots (currently: Images, AV) to see if it's still recoverable. Root-level only, see class docblock.")]
  #[CLI\Option(name: 'fix', description: 'For missing files confirmed recoverable at a known D7 source, re-fetch the bytes and write them to the existing file entity in place (same fid/uri) -- restores the file, does not touch any entity reference, since those were never wrong. Implies --check-d7-source.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit', description: 'Report every managed file whose physical file is missing from disk, and what references it.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit --check-d7-source', description: 'Also check whether each missing file is still fetchable from its known D7 production source.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit --fix', description: 'Re-fetch and restore every missing file confirmed recoverable at a known D7 source.')]
  public function audit(array $options = ['check-d7-source' => FALSE, 'fix' => FALSE]): void {
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

    $fix = (bool) ($options['fix'] ?? FALSE);
    $checkSource = $fix || (bool) ($options['check-d7-source'] ?? FALSE);
    $recoverable = 0;
    $goneAtSource = 0;
    $unchecked = 0;
    $fixed = 0;
    $fixFailed = 0;

    foreach ($missing as $fid => $row) {
      $refs = $usages[$fid] ?? [];
      $refDescription = $refs ? implode('; ', $refs) : '(no real file/image field references it -- orphaned row)';
      $line = "fid={$fid} uri={$row->uri} -- {$refDescription}";

      $sourceUrl = NULL;
      if ($checkSource) {
        $sourceUrl = $this->findD7Source($row->filename);
        $status = $sourceUrl ? 'recoverable' : 'gone at source root too';
        // findD7Source() returns NULL for both "checked, not found" and "a
        // request errored" -- $lastCheckHadError disambiguates only when
        // needed, to keep the common path (found it) cheap.
        if ($sourceUrl === NULL && $this->lastCheckHadError) {
          $status = 'source check failed';
        }
        $line .= " [{$status}]";
        match ($status) {
          'recoverable' => $recoverable++,
          'gone at source root too' => $goneAtSource++,
          default => $unchecked++,
        };
      }

      if ($fix && $sourceUrl) {
        if ($this->restoreFile($row->uri, $sourceUrl)) {
          $line .= ' RESTORED';
          $fixed++;
        }
        else {
          $line .= ' RESTORE FAILED';
          $fixFailed++;
        }
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

    if ($fix) {
      $this->logger()->success('Restored {fixed} file(s) from their D7 source. {failed} attempted restore(s) failed.', [
        'fixed' => $fixed,
        'failed' => $fixFailed,
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
   * @return string|null
   *   The first URL that returns a real 200, or NULL if none did. Check
   *   $this->lastCheckHadError afterward to tell "confirmed gone" apart
   *   from "a request errored" when NULL.
   */
  private function findD7Source(string $filename): ?string {
    $client = \Drupal::httpClient();
    $this->lastCheckHadError = FALSE;

    foreach (self::D7_SOURCE_BASES as $base) {
      $url = $base . rawurlencode($filename);
      try {
        $response = $client->request('HEAD', $url, ['http_errors' => FALSE, 'timeout' => 10]);
        if ($response->getStatusCode() === 200) {
          return $url;
        }
      }
      catch (\Throwable) {
        $this->lastCheckHadError = TRUE;
      }
    }

    return NULL;
  }

  /**
   * Fetches a confirmed-live D7 source URL and writes it to the given
   * URI in place -- same fid, same uri, so no entity reference anywhere
   * needs to change (they were already correct; only the binary was
   * missing). Verifies the fetched byte count matches what the source
   * itself reported before writing anything, so a truncated download
   * can never silently replace a good row with a bad one.
   */
  private function restoreFile(string $uri, string $sourceUrl): bool {
    $client = \Drupal::httpClient();
    try {
      $response = $client->request('GET', $sourceUrl, ['http_errors' => FALSE, 'timeout' => 30]);
      if ($response->getStatusCode() !== 200) {
        return FALSE;
      }
      $body = (string) $response->getBody();
      $expectedLength = $response->getHeaderLine('Content-Length');
      if ($expectedLength !== '' && (int) $expectedLength !== strlen($body)) {
        return FALSE;
      }
      $this->fileRepository->writeData($body, $uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
