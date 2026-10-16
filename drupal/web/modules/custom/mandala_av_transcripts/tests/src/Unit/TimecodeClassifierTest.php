<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_av_transcripts\Unit;

use Drupal\mandala_av_transcripts\TimecodeClassifier;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @coversDefaultClass \Drupal\mandala_av_transcripts\TimecodeClassifier
 * @group mandala_av_transcripts
 */
class TimecodeClassifierTest extends UnitTestCase {

  /**
   * Real D7 rows: the expected status comes from an independent SQL version of
   * the rules, run over all 245,158 units (see the fixture's `source`).
   */
  public static function d7Rows(): array {
    $fixture = json_decode(file_get_contents(__DIR__ . '/../../fixtures/d7-timecodes.json'), TRUE);
    $cases = [];
    foreach ($fixture['units'] as $u) {
      $label = sprintf('tcu %d (%s) %s', $u['tcuid'], $u['expected'], $u['note'] ?? '');
      $cases[trim($label)] = [$u['start'], $u['end'], $u['next_start'], $u['expected']];
    }
    return $cases;
  }

  #[DataProvider('d7Rows')]
  public function testRealD7Rows(string $start, string $end, ?string $next, string $expected): void {
    $this->assertSame($expected, (new TimecodeClassifier())->classify($start, $end, $next));
  }

  /**
   * The fixture covers every status, so a rule cannot be dropped unnoticed.
   */
  public function testFixtureCoversEveryStatus(): void {
    $seen = array_unique(array_column(json_decode(file_get_contents(__DIR__ . '/../../fixtures/d7-timecodes.json'), TRUE)['units'], 'expected'));
    $all = ['ok', 'zero_length_shared_start', 'zero_length_other', 'inverted', 'last_unit_no_end', 'end_implausible'];
    $this->assertEqualsCanonicalizing($all, $seen);
  }

  public static function rules(): array {
    return [
      'ordinary' => [10, 12, 12, 'ok'],
      'last unit, ordinary' => [10, 12, NULL, 'ok'],
      'overlaps next, normal in multi-speaker transcripts' => [10, 15, 12, 'ok'],
      'overlap just inside the tolerance' => [10, 612, 12, 'ok'],
      'overrun past the tolerance' => [10, 613, 12, 'end_implausible'],
      'long unit that does not overrun is fine' => [10, 5000, 6000, 'ok'],
      'a 22 hour recording is not implausible by itself' => [79254, 79260, 79260, 'ok'],
      'last unit under four hours' => [10, 14410, NULL, 'ok'],
      'last unit over four hours' => [10, 14411, NULL, 'end_implausible'],
      'end before start' => [10, 9, 12, 'inverted'],
      'end before start, last unit' => [10, 9, NULL, 'inverted'],
      'end missing on a middle unit' => [10, 0, 12, 'inverted'],
      'end missing on the last unit' => [10, 0, NULL, 'last_unit_no_end'],
      'end NULL on the last unit' => [10, NULL, NULL, 'last_unit_no_end'],
      'end NULL on a middle unit' => [10, NULL, 12, 'inverted'],
      'zero length, next starts together' => [10, 10, 10, 'zero_length_shared_start'],
      'zero length, next later (point cue)' => [10, 10, 12, 'zero_length_other'],
      'zero length, last unit' => [10, 10, NULL, 'zero_length_other'],
      'untimed pair at zero' => [0, 0, 0, 'zero_length_shared_start'],
      'untimed last unit at zero is zero length, not "no end"' => [0, 0, NULL, 'zero_length_other'],
      'negative start with a good end' => [-0.125, 3.378, 3.378, 'ok'],
    ];
  }

  #[DataProvider('rules')]
  public function testRules(float|int|null $start, float|int|null $end, float|int|null $next, string $expected): void {
    $this->assertSame($expected, (new TimecodeClassifier())->classify($start, $end, $next));
  }

  /**
   * Values compare as stored (whole milliseconds), not as raw floats.
   */
  public function testSubMillisecondNoiseDoesNotDecide(): void {
    $c = new TimecodeClassifier();
    // D7 single-precision noise: 12.3450002670288 is 12.345 once stored.
    $this->assertSame('zero_length_other', $c->classify(12.345, 12.3450002670288, 13));
    $this->assertSame('zero_length_shared_start', $c->classify('12.345', '12.345', 12.3450002670288));
    // A real one-millisecond inversion is still an inversion.
    $this->assertSame('inverted', $c->classify('89.667', '89.666', '90'));
    // String and float inputs agree.
    $this->assertSame($c->classify('1.500', '2.000', '2.000'), $c->classify(1.5, 2.0, 2.0));
  }

  /**
   * A whole transcript is classified using each unit's successor.
   */
  public function testClassifyAllUsesTheNextUnit(): void {
    $units = [
      ['start' => 0, 'end' => 0],
      ['start' => 0, 'end' => 0],
      ['start' => 5, 'end' => 5],
      ['start' => 9, 'end' => 0],
    ];
    $this->assertSame(
      ['zero_length_shared_start', 'zero_length_other', 'zero_length_other', 'last_unit_no_end'],
      (new TimecodeClassifier())->classifyAll($units),
    );
    $this->assertSame([], (new TimecodeClassifier())->classifyAll([]));
  }

  /**
   * Every status the classifier can return is one the entity allows.
   */
  public function testStatusesMatchTheEntity(): void {
    $reflection = new \ReflectionClass(TimecodeClassifier::class);
    $statuses = array_filter($reflection->getConstants(), fn($v, $k) => !str_starts_with($k, 'MAX_'), ARRAY_FILTER_USE_BOTH);
    $this->assertEqualsCanonicalizing(
      array_keys(\Drupal\mandala_av_transcripts\Entity\AvTcu::TIMECODE_STATUSES),
      array_values($statuses),
    );
  }

}
