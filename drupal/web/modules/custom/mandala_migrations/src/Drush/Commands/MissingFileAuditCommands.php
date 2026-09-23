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
 *
 * Extended 2026-09-23 after the same DDEV-local gap recurred on a
 * different developer's machine, sitewide (8,417 of 8,433 managed files,
 * across `field_transcript`/`field_thumbnail_image`/`field_featured_image`
 * -- see docs/deferred/local-dev-files-provisioning-mechanism.md). dev-0
 * was verified fully complete in both directions (0 files missing from
 * disk; only 12 of 8,459 on-disk files have no DB row, and those are
 * explained) and is now tried FIRST, ahead of D7 production: it's a
 * project-owned environment (not being decommissioned, unlike D7), and
 * being addressable by exact `uri` rather than basename means it doesn't
 * inherit D7's root-level-only blind spot for subdirectories like AV's
 * `transcripts/`.
 */
class MissingFileAuditCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * dev-0's own public files root -- project-owned, confirmed complete
   * (2026-09-23; see class docblock), and internal-only (VPN-gated, same
   * as its SSH host). Addressed by the file's exact relative `uri`, not
   * a basename guess, so subdirectories resolve correctly. If VPN isn't
   * up, requests here simply time out and fall through to the D7 bases
   * below -- no separate reachability flag needed.
   */
  private const DEV0_FILES_BASE = 'https://mandala-dev.internal.lib.virginia.edu/sites/default/files/';

  /**
   * Known D7 production source bases, tried only for whatever dev-0
   * itself doesn't have. Originally the only source (see the class
   * docblock's 2026-09-21 history) for the one field class it was built
   * for (`group.field_featured_image` -- see the two migrations that
   * populate it, `d7_images_collection_featured_image` and
   * `d7_av_collections`/`d7_av_files`). There is no generic way to derive
   * a legacy source URL for an arbitrary field -- it depends entirely on
   * which migration created the reference -- so this map is
   * hand-maintained; extend it if another field/site combination needs
   * the same reachability check later. D7 production is also a
   * degrading fallback, not a durable one: as of 2026-09-23, 5,413 of
   * 8,417 files missing on one developer's DDEV were already confirmed
   * gone here too.
   *
   * Root-level only: a file that lived in a D7 subdirectory (as some AV
   * files do, e.g. `transcripts/`) won't be found here even if it still
   * exists, since only the D11 file's basename is known, not its
   * original D7 path. A negative result from this check means "not at
   * the site's public files root," not "confirmed gone." (dev-0, tried
   * first, does not have this limitation.)
   */
  private const D7_SOURCE_BASES = [
    'images' => 'https://images.mandala.library.virginia.edu/sites/mandala-images.lib.virginia.edu/files/',
    'av' => 'https://av.mandala.library.virginia.edu/sites/mandala-av.lib.virginia.edu/files/',
  ];

  /**
   * Set by findRemoteSource() on its most recent call, since it returns
   * NULL for both "checked every source, found nothing" and "a request
   * errored" -- the caller only needs to tell those apart for reporting,
   * not for the fix decision (no URL either way means nothing to fetch).
   */
  private bool $lastCheckHadError = FALSE;

  /**
   * Set by findRemoteSource() alongside its return value: a short label
   * for which source resolved ('dev-0', 'images', 'av'), purely for
   * reporting -- lets the audit log show where each restored file
   * actually came from.
   */
  private ?string $lastSourceLabel = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'mandala:missing-file-audit')]
  #[CLI\Option(name: 'check-remote-source', description: "For each missing file, try dev-0's own files root first (exact uri match), then known D7 production file roots (currently: Images, AV; basename match, root-level only) to see if it's still recoverable. See class docblock.")]
  #[CLI\Option(name: 'fix', description: 'For missing files confirmed recoverable at a known remote source, re-fetch the bytes and write them to the existing file entity in place (same fid/uri) -- restores the file, does not touch any entity reference, since those were never wrong. Implies --check-remote-source.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit', description: 'Report every managed file whose physical file is missing from disk, and what references it.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit --check-remote-source', description: 'Also check whether each missing file is still fetchable from dev-0 or its known D7 production source.')]
  #[CLI\Usage(name: 'drush mandala:missing-file-audit --fix', description: 'Re-fetch and restore every missing file confirmed recoverable, preferring dev-0 over D7 production.')]
  public function audit(array $options = ['check-remote-source' => FALSE, 'fix' => FALSE]): void {
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
    $checkSource = $fix || (bool) ($options['check-remote-source'] ?? FALSE);
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
        $sourceUrl = $this->findRemoteSource($row->uri, $row->filename);
        $status = $sourceUrl ? "recoverable ({$this->lastSourceLabel})" : 'gone at all known sources too';
        // findRemoteSource() returns NULL for both "checked every source,
        // not found" and "a request errored" -- $lastCheckHadError
        // disambiguates only when needed, to keep the common path (found
        // it) cheap.
        if ($sourceUrl === NULL && $this->lastCheckHadError) {
          $status = 'source check failed';
        }
        $line .= " [{$status}]";
        match (TRUE) {
          $sourceUrl !== NULL => $recoverable++,
          $status === 'gone at all known sources too' => $goneAtSource++,
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
      $this->logger()->notice('Of the missing files: {recoverable} still fetchable from a known remote source (dev-0 or D7 production; re-import candidates), {gone} confirmed gone everywhere checked, {unchecked} not checked (lookup failed or no known source mapped).', [
        'recoverable' => $recoverable,
        'gone' => $goneAtSource,
        'unchecked' => $unchecked,
      ]);
    }

    if ($fix) {
      $this->logger()->success('Restored {fixed} file(s) from their resolved source. {failed} attempted restore(s) failed.', [
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
   * Tries dev-0's own files root first (exact `uri` match), then every
   * known D7 source root (basename match only -- see D7_SOURCE_BASES'
   * docblock for why).
   *
   * @return string|null
   *   The first URL that returns a real 200, or NULL if none did. Sets
   *   $this->lastSourceLabel on success. Check $this->lastCheckHadError
   *   afterward to tell "confirmed gone everywhere" apart from "a request
   *   errored" when NULL.
   */
  private function findRemoteSource(string $uri, string $filename): ?string {
    $client = \Drupal::httpClient();
    $this->lastCheckHadError = FALSE;
    $this->lastSourceLabel = NULL;

    $relativePath = ltrim(str_replace('public://', '', $uri), '/');
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));
    $dev0Url = self::DEV0_FILES_BASE . $encodedPath;
    if ($this->urlReturns200($client, $dev0Url)) {
      $this->lastSourceLabel = 'dev-0';
      return $dev0Url;
    }

    foreach (self::D7_SOURCE_BASES as $label => $base) {
      $url = $base . rawurlencode($filename);
      if ($this->urlReturns200($client, $url)) {
        $this->lastSourceLabel = $label;
        return $url;
      }
    }

    return NULL;
  }

  /**
   * @param \GuzzleHttp\ClientInterface $client
   *   Shared across the whole findRemoteSource() call so a single try/catch
   *   here keeps the caller's loop simple; sets $this->lastCheckHadError on
   *   a thrown request exception (a real error, not just a non-200).
   */
  private function urlReturns200($client, string $url): bool {
    try {
      $response = $client->request('HEAD', $url, ['http_errors' => FALSE, 'timeout' => 10]);
      return $response->getStatusCode() === 200;
    }
    catch (\Throwable) {
      $this->lastCheckHadError = TRUE;
      return FALSE;
    }
  }

  /**
   * Fetches a confirmed-live source URL (dev-0 or D7 production, per
   * findRemoteSource()) and writes it to the given URI in place -- same
   * fid, same uri, so no entity reference anywhere needs to change (they
   * were already correct; only the binary was missing). Verifies the
   * fetched byte count matches what the source itself reported before
   * writing anything, so a truncated download can never silently replace
   * a good row with a bad one.
   *
   * Prepares the destination's parent directory first: `writeData()`
   * throws `DirectoryNotReadyException` rather than creating a missing
   * subdirectory on its own, which a fresh DDEV (or any environment that's
   * never had a given field's files before) won't have -- confirmed live
   * for AV's `transcripts/` (2026-09-23, every restore into that
   * subdirectory failed until this was added).
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
      $directory = $this->fileSystem->dirname($uri);
      $this->fileSystem->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
      $this->fileRepository->writeData($body, $uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}
