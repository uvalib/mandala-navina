<?php

declare(strict_types=1);

namespace Drupal\Tests\mandala_legacy_redirect\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\Core\Routing\TrustedRedirectResponse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests host-aware resolution of legacy bare-nid URLs (ADR 016 / ADR 017).
 *
 * @group mandala_legacy_redirect
 */
#[RunTestsInSeparateProcesses]
class LegacyHostRedirectTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'options', 'filter',
    'mandala_legacy_redirect',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter', 'mandala_legacy_redirect']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_legacy_site', 'entity_type' => 'node', 'type' => 'list_string',
      'settings' => ['allowed_values' => [
        'images' => 'Images', 'texts' => 'Texts', 'audio-video' => 'Audio-Video',
      ]],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_legacy_nid', 'entity_type' => 'node', 'type' => 'integer',
      'settings' => ['unsigned' => TRUE],
    ])->save();
    foreach (['field_legacy_site', 'field_legacy_nid'] as $name) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'page'])->save();
    }
    // Anonymous (uid 0) may see published content, and nothing else.
    Role::create(['id' => 'anonymous', 'label' => 'Anonymous'])->save();
    user_role_grant_permissions('anonymous', ['access content']);
  }

  protected function node(string $site, int $nid, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'page', 'title' => "$site $nid", 'status' => (int) $published,
      'field_legacy_site' => $site, 'field_legacy_nid' => $nid,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Dispatches a request to the subscriber and returns its response, if any.
   */
  protected function handle(string $host, string $path) {
    $request = Request::create("http://$host$path");
    $event = new RequestEvent($this->container->get('kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('mandala_legacy_redirect.subscriber')->onRequest($event);
    return $event->getResponse();
  }

  public function testRedirectsToEntityResolvedByHostAndLegacyNid(): void {
    $images = $this->node('images', 151);
    $av = $this->node('audio-video', 151);
    $this->assertNotSame($images->id(), $av->id());

    $response = $this->handle('av.mandala.library.virginia.edu', '/node/151');
    $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('https://mandala.library.virginia.edu/node/' . $av->id(), $response->getTargetUrl());

    $response = $this->handle('images.mandala.library.virginia.edu', '/node/151/');
    $this->assertSame('https://mandala.library.virginia.edu/node/' . $images->id(), $response->getTargetUrl());
  }

  public function testUnresolvedNidOnLegacyHostIs404NotFallThrough(): void {
    // A D11 node whose own nid equals the requested legacy nid must not be served.
    $node = $this->node('images', 999);
    $this->expectException(NotFoundHttpException::class);
    $this->handle('texts.mandala.library.virginia.edu', '/node/' . $node->id());
  }

  public function testHiddenEntityIs404NotRedirect(): void {
    $this->node('images', 5, FALSE);
    $this->expectException(NotFoundHttpException::class);
    $this->handle('images.mandala.library.virginia.edu', '/node/5');
  }

  public function testAmbiguousKeyIs404(): void {
    $this->node('images', 7);
    $this->node('images', 7);
    $this->expectException(NotFoundHttpException::class);
    $this->handle('images.mandala.library.virginia.edu', '/node/7');
  }

  public function testNonLegacyHostAndNonBareNidPathsAreUntouched(): void {
    $this->node('images', 151);
    $this->assertNull($this->handle('mandala.library.virginia.edu', '/node/151'));
    $this->assertNull($this->handle('mandala.ddev.site', '/node/151'));
    $this->assertNull($this->handle('images.mandala.library.virginia.edu', '/node/151/edit'));
    $this->assertNull($this->handle('images.mandala.library.virginia.edu', '/image/some-slug'));
  }

}
