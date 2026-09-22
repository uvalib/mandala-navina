<?php

declare(strict_types=1);

namespace Drupal\mandala_home;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;

/**
 * Builds the hero-carousel render array from a mandala_home_carousel
 * block_content entity's slides.
 *
 * The single source of truth for the carousel's presentation. Called both
 * by HomeController (the site's hardcoded front-page route, today's only
 * placement) and by mandala_home_block_content_view_alter() -- fired
 * whenever Drupal's normal entity view builder renders this bundle,
 * which is also the path Block Layout and a future Layout Builder
 * placement both go through. Keeping this logic here instead of inline in
 * the controller is what makes the carousel placeable through either
 * mechanism without looking different or breaking -- see
 * docs/deferred/mandala-home-customizable-content-system.md (the home
 * page becoming a real editable/Layout-Builder-managed page is a real,
 * still-undecided direction, and this is the piece of today's build that
 * needed to already be compatible with it).
 */
class CarouselBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function build(BlockContentInterface $block): ?array {
    if ($block->get('field_carousel_slides')->isEmpty()) {
      return NULL;
    }

    $image_style = ImageStyle::load('wide');
    $slides = [];
    foreach ($block->get('field_carousel_slides')->referencedEntities() as $slide) {
      if ($slide->get('field_slide_image')->isEmpty()) {
        continue;
      }
      $image_item = $slide->get('field_slide_image')->first();
      $file = $image_item->entity;
      if (!$file) {
        continue;
      }
      $link_item = $slide->get('field_slide_link')->isEmpty() ? NULL : $slide->get('field_slide_link')->first();
      $link_url = $link_item ? $link_item->getUrl() : NULL;
      $slides[] = [
        'image_url' => $image_style ? $image_style->buildUrl($file->getFileUri()) : $file->createFileUrl(),
        'image_alt' => $image_item->alt ?? '',
        'caption' => $slide->get('field_slide_caption')->value ?? '',
        'link_url' => $link_url ? $link_url->toString() : NULL,
        'link_title' => $link_item ? $link_item->title : NULL,
        'link_type' => $link_url ? $this->linkedAssetType($link_url) : NULL,
      ];
    }
    if (!$slides) {
      return NULL;
    }

    return [
      '#theme' => 'mandala_home_carousel',
      '#slides' => $slides,
      '#rotation_ms' => (int) $block->get('field_carousel_rotation_ms')->value,
      '#cache' => ['tags' => $block->getCacheTags()],
    ];
  }

  /**
   * Maps a slide link to an asset-type icon key ('audio'/'video'/'image'),
   * by resolving it to the node it actually points at (if it's a node link
   * at all -- a collection/group link, or an external URL, has no single
   * asset type, so those fall back to the template's generic icon).
   */
  private function linkedAssetType(Url $url): ?string {
    if (!$url->isRouted() || $url->getRouteName() !== 'entity.node.canonical') {
      return NULL;
    }
    $nid = $url->getRouteParameters()['node'] ?? NULL;
    $node = $nid ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if (!$node) {
      return NULL;
    }
    return match ($node->bundle()) {
      'audio' => 'audio',
      'video' => 'video',
      'shanti_image' => 'image',
      default => NULL,
    };
  }

}
