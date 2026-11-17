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

  public function testInlineFootnotesTravelWithTheirSection(): void {
    $r = TextSplitter::split('<h1>A</h1><p>a<footnotes data-text="Note A" data-value=""></footnotes></p><h1>B</h1><p>b</p>', [1]);
    $this->assertStringContainsString('Note A', $r['sections'][0]['html']);
    $this->assertStringNotContainsString('Note A', $r['sections'][1]['html']);
    $this->assertSame(0, $r['leftover_count']);
  }

  public function testLeftoverWordNotesAreExtracted(): void {
    // Shape of a Word paste in full_html (King of Concentration, Gnosis).
    $html = '<h1>A</h1><p>a</p>'
      . '<ol class="footnotes"><li class="footnote-definition" id="ftn100"><p><a class="footnote-backlink" href="#_ftnref100">^</a></p>'
      . '<div class="footnote-content"><p><span>x</span>Leftover one</p></div></li>'
      . '<li class="footnote-definition" id="ftn101"><p><a class="footnote-backlink" href="#_ftnref101">^</a></p>'
      . '<div class="footnote-content"><p>Leftover two</p></div></li></ol>'
      . '<div><hr align="left" size="1" width="33%"><div><div></div></div></div>';
    $r = TextSplitter::split($html, [1]);
    $this->assertSame(2, $r['leftover_count']);
    $this->assertSame('<ol><li><p><span>x</span>Leftover one</p></li><li><p>Leftover two</p></li></ol>', $r['leftover_notes']);
  }

  public function testLeftoverNoteKeepsAFootnoteOutsideTheContentDiv(): void {
    // Gnosis: some leftover items hold the note as a converted <footnotes> tag.
    $html = '<h1>A</h1><p>a</p><ol class="footnotes"><li class="footnote-definition" id="ftn260">'
      . '<p><footnotes data-text="Kept note" data-value=""></footnotes></p>'
      . '<div class="footnote-content"><p>Text</p></div></li></ol>';
    $r = TextSplitter::split($html, [1]);
    $this->assertStringContainsString('data-text="Kept note"', $r['leftover_notes']);
    $this->assertStringContainsString('<p>Text</p>', $r['leftover_notes']);
    $this->assertSame('<p>a</p>', $r['sections'][0]['html']);
  }

  public function testWordSectionWrappersAreUnwrapped(): void {
    // Achievement of Gnosis: every heading sat inside a div.WordSectionN.
    $html = '<div class="WordSection1"><p>Title page</p></div><div class="WordSection2"><h1>Ch 1</h1><p>a</p></div>'
      . '<div class="WordSection3"><h1>Ch 2</h1><p>b</p></div><div class="other"><h1>Kept inside</h1></div>';
    $r = TextSplitter::split($html, [1]);
    $this->assertSame('<p>Title page</p>', $r['intro']);
    $this->assertSame(['Ch 1', 'Ch 2'], array_column($r['sections'], 'title'));
    $this->assertStringContainsString('<div class="other"><h1>Kept inside</h1></div>', $r['sections'][1]['html']);
  }

  public function testHrWithTextIsNotTreatedAsSeparator(): void {
    $r = TextSplitter::split('<h1>A</h1><div><hr><p>Real text</p></div>', [1]);
    $this->assertStringContainsString('Real text', $r['sections'][0]['html']);
  }

  public function testHeadingFootnotesMoveIntoTheFirstParagraph(): void {
    $html = '<h1>Chapter 3<footnotes data-text="Source" data-value=""></footnotes></h1><p class="Verse1">Text</p>'
      . '<h1>Chapter 4<footnotes data-text="Other" data-value=""></footnotes></h1><table><tr><td>t</td></tr></table>';
    $r = TextSplitter::split($html, [1]);
    $this->assertSame(['Chapter 3', 'Chapter 4'], array_column($r['sections'], 'title'));
    $this->assertSame('<p class="Verse1"><footnotes data-text="Source" data-value=""></footnotes>Text</p>', $r['sections'][0]['html']);
    $this->assertStringStartsWith('<p><footnotes data-text="Other"', $r['sections'][1]['html']);
  }

  public function testDeepWordHeadingsNeverSplit(): void {
    $r = TextSplitter::split('<h6>Six</h6><p>a</p><h6 class="h7">Seven</h6><p>b</p><h6 class="h9 bo">Nine</h6><p>c</p>', [6]);
    $this->assertSame(['Six'], array_column($r['sections'], 'title'));
    $this->assertStringContainsString('<h6 class="h7">Seven</h6>', $r['sections'][0]['html']);
  }

  public function testImageOnlySectionIsKept(): void {
    $r = TextSplitter::split('<h1>Plate</h1><p><img src="a.jpg"></p>', [1]);
    $this->assertCount(1, $r['sections']);
  }

  public function testInlineStylesAreStrippedButSpansKept(): void {
    $r = TextSplitter::split('<p style="margin:0">a <span lang="bo" style="mso-x:1">b</span></p><h1 style="color:red">One</h1><p><b style="x">c</b></p>', [1]);
    $this->assertSame('<p>a <span lang="bo">b</span></p>', $r['intro']);
    $this->assertSame('<p><b>c</b></p>', $r['sections'][0]['html']);
  }

}
