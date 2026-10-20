<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\mandala_av_transcripts\OrphanManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Administrator housekeeping for soft-deleted transcripts (ADR 019).
 *
 * Drush runs with no Drupal user, so there is no permission to check: these
 * need shell access to the server, and purge asks for confirmation.
 */
class OrphanCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly OrphanManager $orphans,
  ) {
    parent::__construct();
  }

  /**
   * List soft-deleted transcripts and what is known about their node.
   */
  #[CLI\Command(name: 'av-transcripts:orphans', aliases: ['avt-orphans'])]
  #[CLI\Usage(name: 'drush av-transcripts:orphans', description: 'List them.')]
  #[CLI\Usage(name: 'drush av-transcripts:orphans --format=json', description: 'For scripting.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'Transcript',
    'state' => 'State',
    'node_title' => 'Node title',
    'legacy_site' => 'Legacy site',
    'legacy_nid' => 'Legacy nid',
    'deleted_at' => 'Deleted at',
    'deleted_by' => 'By uid',
    'units' => 'Units',
    'untimed_or_bad' => 'Bad timecodes',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'state', 'node_title', 'legacy_site', 'legacy_nid', 'deleted_at', 'units', 'untimed_or_bad'])]
  public function orphans(array $options = ['format' => 'table']): RowsOfFields {
    $rows = $this->orphans->list();
    foreach ($rows as &$row) {
      $row['deleted_at'] = $row['deleted_at'] === NULL ? '' : gmdate('Y-m-d H:i', $row['deleted_at']);
    }
    return new RowsOfFields($rows);
  }

  /**
   * Print a soft-deleted transcript, to take a copy before purging.
   */
  #[CLI\Command(name: 'av-transcripts:export', aliases: ['avt-export'])]
  #[CLI\Argument(name: 'id', description: 'Transcript id.')]
  #[CLI\Option(name: 'as', description: 'json (everything; default), srt or vtt (for reading).')]
  #[CLI\Usage(name: 'drush av-transcripts:export 123 > 123.json', description: 'Faithful copy.')]
  public function export(string $id, array $options = ['as' => 'json']): string {
    return $this->orphans->export((int) $id, (string) $options['as']);
  }

  /**
   * Restore a soft-deleted transcript whose node still exists.
   */
  #[CLI\Command(name: 'av-transcripts:restore', aliases: ['avt-restore'])]
  #[CLI\Argument(name: 'id', description: 'Transcript id.')]
  public function restore(string $id): void {
    $this->orphans->restore((int) $id);
    $this->logger()->success('Restored transcript {id}.', ['id' => $id]);
  }

  /**
   * Permanently delete soft-deleted transcripts, their units and revisions.
   */
  #[CLI\Command(name: 'av-transcripts:purge', aliases: ['avt-purge'])]
  #[CLI\Argument(name: 'ids', description: 'Transcript ids, space separated.')]
  #[CLI\Option(name: 'dry-run', description: 'Say what would go, delete nothing.')]
  #[CLI\Usage(name: 'drush av-transcripts:purge 123 124 --dry-run', description: 'Check first.')]
  public function purge(array $ids, array $options = ['dry-run' => FALSE]): void {
    $ids = array_map('intval', $ids);
    $known = $this->orphans->list();
    $unknown = array_diff($ids, array_keys($known));
    if ($unknown) {
      throw new \InvalidArgumentException('Not soft-deleted transcripts: ' . implode(', ', $unknown) . '. Nothing was deleted.');
    }
    $units = array_sum(array_map(static fn (int $id): int => $known[$id]['units'], $ids));
    $this->io()->writeln(sprintf('%d transcript(s), %d unit(s) and all their revisions.', count($ids), $units));
    if ($options['dry-run']) {
      $this->logger()->notice('Dry run: nothing deleted.');
      return;
    }
    if (!$this->io()->confirm('Permanently delete these? This cannot be undone. Have you exported them?', FALSE)) {
      throw new \Exception('Cancelled.');
    }
    $removed = $this->orphans->purge($ids);
    $this->logger()->success('Purged {t} transcript(s) and {u} unit(s).', ['t' => count($removed), 'u' => array_sum($removed)]);
  }

}
