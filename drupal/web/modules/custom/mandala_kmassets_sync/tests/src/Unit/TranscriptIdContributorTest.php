<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_kmassets_sync\Unit;

use Drupal\mandala_kmassets_sync\Contributor\TranscriptIdContributor;
use Drupal\mandala_kmassets_sync\Transcript\TridResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\mandala_kmassets_sync\Contributor\TranscriptIdContributor
 * @group mandala_kmassets_sync
 */
class TranscriptIdContributorTest extends UnitTestCase {

  private function node(string $bundle): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    return $node;
  }

  private function resolver(?int $trid): TridResolverInterface {
    $resolver = $this->createMock(TridResolverInterface::class);
    $resolver->method('resolve')->willReturn($trid);
    return $resolver;
  }

  /**
   * @covers ::applies
   */
  public function testAppliesOnlyToAvBundles(): void {
    $c = new TranscriptIdContributor($this->resolver(1));
    $this->assertTrue($c->applies($this->node('audio')));
    $this->assertTrue($c->applies($this->node('video')));
    $this->assertFalse($c->applies($this->node('shanti_image')));
  }

  /**
   * @covers ::contribute
   */
  public function testWritesIntegerTrid(): void {
    $doc = ['id' => 'audio-video-11-5'];
    (new TranscriptIdContributor($this->resolver(27016)))->contribute($doc, $this->node('video'));
    $this->assertSame(27016, $doc['trid_i']);
  }

  /**
   * @covers ::contribute
   */
  public function testOmitsFieldWithoutTranscript(): void {
    $doc = ['id' => 'audio-video-11-5'];
    (new TranscriptIdContributor($this->resolver(NULL)))->contribute($doc, $this->node('audio'));
    $this->assertArrayNotHasKey('trid_i', $doc);
  }

}
