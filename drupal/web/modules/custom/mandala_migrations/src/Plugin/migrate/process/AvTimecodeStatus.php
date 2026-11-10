<?php

namespace Drupal\mandala_migrations\Plugin\migrate\process;

use Drupal\mandala_av_transcripts\TimecodeClassifier;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Classifies a unit's timecodes into an `av_tcu` `timecode_status`.
 *
 * A thin wrapper over TimecodeClassifier so the migration YAML shows where the
 * status comes from. The source is exactly three values, in this order: the
 * unit's start, its end, and the start of the next unit in sequence (NULL for
 * the last unit). The d7_av_tcu source supplies all three as exact
 * DECIMAL(10,3) strings in the same (start, tcuid) order that `sequence` uses,
 * so the status and the stored order cannot disagree.
 *
 * Nothing is repaired here: the status only says how far to trust the times
 * (docs/deferred/transcript-bad-timecodes-investigate-and-fix.md).
 *
 * @code
 * timecode_status:
 *   plugin: mandala_av_timecode_status
 *   source: [start_s, end_s, next_start_s]
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "mandala_av_timecode_status"
 * )
 */
class AvTimecodeStatus extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_array($value) || count($value) !== 3) {
      throw new MigrateException('mandala_av_timecode_status needs exactly three source values: start, end, next start.');
    }
    [$start, $end, $next] = array_values($value);
    if ($start === NULL || $start === '') {
      throw new MigrateException('A unit with no start cannot be classified.');
    }
    return (new TimecodeClassifier())->classify($start, $end, $next);
  }

}
