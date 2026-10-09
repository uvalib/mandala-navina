<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_file_hygiene\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that file entities are normalized to NFC on save.
 *
 * @group mandala_file_hygiene
 */
#[RunTestsInSeparateProcesses]
class FilenameNormalizationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'file', 'user', 'mandala_file_hygiene'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('user');
    $this->installSchema('file', ['file_usage']);
  }

  public function testNfdFilenameIsNormalizedToNfc(): void {
    $nfc_name = "Rinpoché.txt";
    $nfd_name = \Normalizer::normalize($nfc_name, \Normalizer::NFD);
    $this->assertNotSame($nfc_name, $nfd_name, 'Test fixture must actually differ at the byte level.');

    $file_system = \Drupal::service('file_system');
    file_put_contents($file_system->realpath('public://') . '/' . $nfd_name, 'contents');

    $file = File::create([
      'uri' => 'public://' . $nfd_name,
      'filename' => $nfd_name,
      'status' => 1,
    ]);
    $file->save();

    $this->assertSame($nfc_name, $file->getFilename());
    $this->assertTrue(\Normalizer::isNormalized($file->getFileUri(), \Normalizer::NFC));
    $this->assertFileExists($file_system->realpath($file->getFileUri()));
    $this->assertFileDoesNotExist($file_system->realpath('public://' . $nfd_name));
    $this->assertSame('contents', file_get_contents($file_system->realpath($file->getFileUri())));
  }

  public function testAlreadyNfcFilenameIsUnchanged(): void {
    $name = 'plain-ascii.txt';
    $file_system = \Drupal::service('file_system');
    file_put_contents($file_system->realpath('public://') . '/' . $name, 'contents');

    $file = File::create([
      'uri' => 'public://' . $name,
      'filename' => $name,
      'status' => 1,
    ]);
    $file->save();

    $this->assertSame($name, $file->getFilename());
    $this->assertSame('public://' . $name, $file->getFileUri());
  }

}
