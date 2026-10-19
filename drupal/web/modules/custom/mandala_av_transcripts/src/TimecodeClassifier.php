<?php

declare(strict_types=1);

namespace Drupal\mandala_av_transcripts;

/**
 * Classifies a unit's timecodes into an `av_tcu` `timecode_status`.
 *
 * Pure: it depends only on the unit's start, its end and the start of the next
 * unit in sequence. It never changes a value; `end_original` and any repair are
 * the migration's business. The status only says how far to trust the times.
 *
 * Rules, calibrated on D7's 245,158 units (docs/planning/t2-transcript-entities-plan.md):
 *
 * 1. No end: `end` is NULL, or 0 while `start` is positive (D7 stored 0 for
 *    "unknown"). On the last unit that is `last_unit_no_end`; on any other
 *    unit the end is simply before the start, so `inverted`.
 * 2. `end` before `start`: `inverted`.
 * 3. `end` equal to `start`: `zero_length_shared_start` when the next unit
 *    starts at the same instant (3,111 of D7's 3,299; mostly 3,039 untimed
 *    units at 0:00), otherwise `zero_length_other` (a point cue, or the last
 *    unit).
 * 4. `end_implausible`: the end runs more than MAX_OVERRUN past the next
 *    unit's start (54 units in D7), or the last unit lasts longer than
 *    MAX_LAST_UNIT_SPAN (no neighbour to compare with). Not a plain
 *    "ends after N hours": D7 holds genuine 22-hour recordings whose units
 *    legitimately start past 79,000 s. Overlap alone is normal in multi-speaker
 *    transcripts (5,864 units end after the next one starts) and is `ok`.
 * 5. Otherwise `ok`.
 *
 * All comparisons are in whole milliseconds, because the column is
 * DECIMAL(10,3): D7 stores single-precision floats whose sub-millisecond noise
 * (two "inverted" units differ by 0.001 s) must not decide a status.
 * Classify after the values have been rounded to what will be stored.
 *
 * A negative start has no status of its own (one unit in D7) and classifies as
 * the rest of its values say.
 */
final class TimecodeClassifier {

  public const OK = 'ok';
  public const ZERO_LENGTH_SHARED_START = 'zero_length_shared_start';
  public const ZERO_LENGTH_OTHER = 'zero_length_other';
  public const INVERTED = 'inverted';
  public const LAST_UNIT_NO_END = 'last_unit_no_end';
  public const END_IMPLAUSIBLE = 'end_implausible';

  /**
   * How far past the next unit's start an end may run before it is implausible.
   */
  public const MAX_OVERRUN_MS = 600000;

  /**
   * How long the last unit may last before it is implausible (4 hours).
   */
  public const MAX_LAST_UNIT_SPAN_MS = 14400000;

  /**
   * Classifies one unit.
   *
   * @param float|int|string $start
   *   Seconds.
   * @param float|int|string|null $end
   *   Seconds; NULL when absent.
   * @param float|int|string|null $nextStart
   *   Start of the next unit in sequence, NULL for the last unit.
   */
  public function classify(float|int|string $start, float|int|string|null $end, float|int|string|null $nextStart): string {
    $s = self::ms($start);
    $e = $end === NULL ? NULL : self::ms($end);
    $n = $nextStart === NULL ? NULL : self::ms($nextStart);

    if ($e === NULL || ($e === 0 && $s > 0)) {
      return $n === NULL ? self::LAST_UNIT_NO_END : self::INVERTED;
    }
    if ($e < $s) {
      return self::INVERTED;
    }
    if ($e === $s) {
      return $n === $s ? self::ZERO_LENGTH_SHARED_START : self::ZERO_LENGTH_OTHER;
    }
    if ($n !== NULL ? $e > $n + self::MAX_OVERRUN_MS : $e - $s > self::MAX_LAST_UNIT_SPAN_MS) {
      return self::END_IMPLAUSIBLE;
    }
    return self::OK;
  }

  /**
   * Classifies a whole transcript's units, already in sequence order.
   *
   * @param array<int, array{start: float|int|string, end: float|int|string|null}> $units
   *
   * @return string[]
   *   One status per unit, same keys.
   */
  public function classifyAll(array $units): array {
    $statuses = [];
    $keys = array_keys($units);
    foreach ($keys as $i => $key) {
      $next = isset($keys[$i + 1]) ? $units[$keys[$i + 1]]['start'] : NULL;
      $statuses[$key] = $this->classify($units[$key]['start'], $units[$key]['end'] ?? NULL, $next);
    }
    return $statuses;
  }

  /**
   * Seconds to whole milliseconds, exactly as DECIMAL(10,3) will store them.
   */
  private static function ms(float|int|string $seconds): int {
    return (int) round((float) $seconds * 1000);
  }

}
