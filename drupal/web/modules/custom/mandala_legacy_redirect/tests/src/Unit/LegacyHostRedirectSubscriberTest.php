<?php

namespace Drupal\Tests\mandala_legacy_redirect\Unit;

use Drupal\mandala_legacy_redirect\EventSubscriber\LegacyHostRedirectSubscriber as S;
use Drupal\Tests\UnitTestCase;

/**
 * @group mandala_legacy_redirect
 */
class LegacyHostRedirectSubscriberTest extends UnitTestCase {

  /**
   * @dataProvider paths
   */
  public function testLegacyNid(string $path, ?string $expected): void {
    $this->assertSame($expected, S::legacyNid($path));
  }

  public static function paths(): array {
    return [
      ['/node/1631632', '1631632'],
      ['/node/1631632/', '1631632'],
      ['/node/0', NULL],
      ['/node/012', NULL],
      ['/node/12/edit', NULL],
      ['/image/village-and-houses-2', NULL],
      ['/nodes/12', NULL],
      ['/', NULL],
    ];
  }

  public function testSiteForHost(): void {
    $hosts = [['host' => 'av.mandala.library.virginia.edu', 'site' => 'audio-video']];
    $this->assertSame('audio-video', S::siteForHost('AV.mandala.library.virginia.edu', $hosts));
    $this->assertNull(S::siteForHost('mandala.library.virginia.edu', $hosts));
    $this->assertNull(S::siteForHost('mandala.ddev.site', $hosts));
  }

}
