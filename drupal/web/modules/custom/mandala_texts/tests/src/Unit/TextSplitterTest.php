<?php

namespace Drupal\Tests\mandala_texts\Unit;

use Drupal\mandala_texts\TextSplitter;
use Drupal\Tests\UnitTestCase;

/**
 * @group mandala_texts
 */
class TextSplitterTest extends UnitTestCase {

  public function testIntroAndSections(): void {
    $r = TextSplitter::split('<p>Intro</p><h1>One</h1><p>a</p><h1>Two</h1><p>b</p>', [1]);
    $this->assertSame('<p>Intro</p>', $r['intro']);
    $this->assertSame(['One', 'Two'], array_column($r['sections'], 'title'));
    $this->assertSame(['<p>a</p>', '<p>b</p>'], array_column($r['sections'], 'html'));
    $this->assertSame([NULL, NULL], array_column($r['sections'], 'parent'));
    $this->assertSame([0, 1], array_column($r['sections'], 'weight'));
  }

  public function testNestingByLevel(): void {
    $html = '<h1>A</h1><p>a</p><h2>A1</h2><p>x</p><h3>A1a</h3><p>y</p><h2>A2</h2><p>z</p><h1>B</h1><p>b</p>';
    $r = TextSplitter::split($html, [1, 2, 3]);
    $this->assertSame(['A', 'A1', 'A1a', 'A2', 'B'], array_column($r['sections'], 'title'));
    $this->assertSame([NULL, 0, 1, 0, NULL], array_column($r['sections'], 'parent'));
    $this->assertSame([0, 0, 0, 1, 1], array_column($r['sections'], 'weight'));
  }

  public function testUnchosenLevelsStayAsContent(): void {
    $r = TextSplitter::split('<h1>A</h1><h2>Sub</h2><p>a</p>', [1]);
    $this->assertCount(1, $r['sections']);
    $this->assertSame('<h2>Sub</h2><p>a</p>', $r['sections'][0]['html']);
  }

  public function testEmptySectionWithChildrenIsKept(): void {
    // D7 dropped "Part One" here and re-parented its chapter.
    $r = TextSplitter::split('<h1>Part One</h1><p>&nbsp;</p><h2>Ch 1</h2><p>c</p><h1>Empty</h1><p> </p>', [1, 2]);
    $this->assertSame(['Part One', 'Ch 1'], array_column($r['sections'], 'title'));
    $this->assertSame([NULL, 0], array_column($r['sections'], 'parent'));
  }

  public function testWeightsAreNotCapped(): void {
    $html = '';
    for ($i = 0; $i < 40; $i++) {
      $html .= "<h1>S$i</h1><p>t</p>";
    }
    $r = TextSplitter::split($html, [1]);
    $this->assertSame(39, end($r['sections'])['weight']);
  }

  public function testTitleFromHeadingTextAndFallback(): void {
    $r = TextSplitter::split("<h1><span class=\"bo\" lang=\"bo\">བོད་</span>\n  Title</h1><p>a</p><h1> </h1><p>b</p>", [1]);
    $this->assertSame(['བོད་ Title', 'Section 2'], array_column($r['sections'], 'title'));
  }

  public function testNoHeadingsMeansNoSections(): void {
    $r = TextSplitter::split('<p>Only text</p>', [1, 2]);
    $this->assertSame('<p>Only text</p>', $r['intro']);
    $this->assertSame([], $r['sections']);
  }

  public function testImageOnlySectionIsKept(): void {
    $r = TextSplitter::split('<h1>Plate</h1><p><img src="a.jpg"></p>', [1]);
    $this->assertCount(1, $r['sections']);
  }

}
