<?php

declare(strict_types=1);

namespace Drupal\mandala_home;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;

/**
 * Creates the singleton mandala_home_carousel block_content + its slides,
 * if none exists yet in this environment.
 *
 * The carousel built 2026-09-21 (PR #228) was assembled entirely by hand
 * via one-off `drush eval` in DDEV -- the block_content entity, its 12
 * mandala_home_slide paragraphs, and the managed image files backing them
 * all exist only as content, never as config, so a `main` merge + deploy
 * never carries them anywhere. That's why the carousel didn't appear on
 * dev-0: confirmed 2026-09-22, 0 rows in both block_content_field_data
 * and paragraphs_item_field_data for these bundles there.
 *
 * This is the shared logic behind two callers, kept in one place
 * deliberately:
 *   - HomeCarouselSeedCommands (`drush mandala:home-carousel-seed`) --
 *     manual/on-demand, what actually fixed dev-0 (a module already
 *     installed there doesn't get a fresh hook_install() run).
 *   - mandala_home_install() (hook_install()) -- automatic, fires once
 *     the first time this module is ever enabled in a NEW environment
 *     (a fresh DDEV rebuild, staging if wiped, production's first
 *     deploy). Deliberately NOT hook_update_N(): that fires on every
 *     environment's next deploy regardless of whether the module was
 *     already there, which is the wrong trigger for "ship this module's
 *     own default content once." Whether this curated DEMO content
 *     (not real editorial curation) is what should actually land on
 *     production's very first install is still an open call --
 *     see docs/deferred/mandala-home-customizable-content-system.md.
 *
 * The curated node list mirrors HomeController's own avSamples()/
 * imageSamples() picks (same demo-worthy nodes, not a coincidence -- both
 * were chosen the same session for the same reason) plus four Audio nodes
 * not otherwise sampled on the page. Update this list by hand if the
 * curation changes; there's no mechanism to keep it in sync automatically
 * (matching HomeController's own documented stance on its sample lists).
 */
class CarouselSeeder {

  /**
   * Curated by D7 legacy identity (field_legacy_site + field_legacy_nid,
   * ADR 017's composite key), NOT by D11 node id. Confirmed the hard way
   * 2026-09-22: DDEV and dev-0 were independently migrated, so D11 nids
   * for the SAME D7 content differ across environments even though
   * per-bundle totals match exactly (D7 nid 33126, this list's "Oral
   * Culture: Riddles" slide, is nid 126524 in DDEV but 122337 on dev-0).
   * A list keyed by D11 nid silently resolves the wrong node -- or, as
   * happened on dev-0's first real run, no node at all -- depending on
   * the environment. Bundle is looked up at seed time (not hardcoded
   * here) so a bundle mismatch fails loudly instead of silently
   * mis-resolving a thumbnail.
   */
  private const SLIDES = [
    ['site' => 'audio-video', 'legacy_nid' => 11986, 'caption' => 'An Account of Deities of Dogar Gewog'],
    ['site' => 'audio-video', 'legacy_nid' => 33126, 'caption' => 'Oral Culture: Riddles (39-68)'],
    ['site' => 'images', 'legacy_nid' => 110836, 'caption' => 'Close-up of buddhas, saints, and prayers carved and painted into a rock face.'],
    ['site' => 'audio-video', 'legacy_nid' => 12741, 'caption' => 'In the Snowy Paradise to the North: A Song'],
    ['site' => 'audio-video', 'legacy_nid' => 27, 'caption' => 'Song 8: Tibet University Nangma Group'],
    ['site' => 'images', 'legacy_nid' => 110846, 'caption' => 'Pilgrims spinning prayer wheels beneath the rock carvings.'],
    ['site' => 'audio-video', 'legacy_nid' => 12786, 'caption' => 'The Drukpa Lineage: A Song and Dance'],
    ['site' => 'audio-video', 'legacy_nid' => 3551, 'caption' => 'Gung Ngyon Thoenpoi Lama: A Song'],
    ['site' => 'images', 'legacy_nid' => 15136, 'caption' => 'Chortens at entrance to Lhasa from West'],
    ['site' => 'audio-video', 'legacy_nid' => 13001, 'caption' => 'On the Top of Lhasa Potala: A Song'],
    ['site' => 'audio-video', 'legacy_nid' => 2229, 'caption' => 'Three Smart Brothers: Folktales from the Rebgong Cultural Area'],
    ['site' => 'images', 'legacy_nid' => 183971, 'caption' => 'Mural of Guru Dragphur, a form of Guru Rinpoché'],
  ];

  private const ROTATION_MS = 6000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * @param bool $dryRun
   *   Report what would happen without saving anything.
   *
   * @return array{status: string, block_id: ?int, created: int, total: int, skipped: string[]}
   *   status is one of: 'already_exists', 'dry_run', 'created', 'no_slides'.
   */
  public function seed(bool $dryRun = FALSE): array {
    $blockStorage = $this->entityTypeManager->getStorage('block_content');
    $existing = $blockStorage->loadByProperties(['type' => 'mandala_home_carousel']);
    if ($existing) {
      $block = reset($existing);
      return [
        'status' => 'already_exists',
        'block_id' => (int) $block->id(),
        'created' => (int) $block->get('field_carousel_slides')->count(),
        'total' => count(self::SLIDES),
        'skipped' => [],
      ];
    }

    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    $slideRefs = [];
    $skipped = [];
    foreach (self::SLIDES as $slideDef) {
      $node = $this->resolveNode($slideDef['site'], $slideDef['legacy_nid']);
      if (!$node) {
        $skipped[] = "{$slideDef['site']}/{$slideDef['legacy_nid']}: no matching node (field_legacy_site + field_legacy_nid)";
        continue;
      }
      $caption = $slideDef['caption'];
      $nid = $node->id();

      $file = $this->resolveThumbnail($node);
      if (!$file) {
        $skipped[] = "{$slideDef['site']}/{$slideDef['legacy_nid']} (nid=$nid, {$node->bundle()}): no thumbnail resolvable";
        continue;
      }

      if ($dryRun) {
        continue;
      }

      $slide = $paragraphStorage->create([
        'type' => 'mandala_home_slide',
        'field_slide_image' => ['target_id' => $file->id()],
        'field_slide_link' => ['uri' => 'entity:node/' . $nid],
        'field_slide_caption' => $caption,
      ]);
      $slide->save();
      $slideRefs[] = ['target_id' => $slide->id(), 'target_revision_id' => $slide->getRevisionId()];
    }

    if ($dryRun) {
      return [
        'status' => 'dry_run',
        'block_id' => NULL,
        'created' => count(self::SLIDES) - count($skipped),
        'total' => count(self::SLIDES),
        'skipped' => $skipped,
      ];
    }

    if (!$slideRefs) {
      return [
        'status' => 'no_slides',
        'block_id' => NULL,
        'created' => 0,
        'total' => count(self::SLIDES),
        'skipped' => $skipped,
      ];
    }

    $block = $blockStorage->create([
      'type' => 'mandala_home_carousel',
      'info' => 'Mandala Home Carousel',
      'field_carousel_slides' => $slideRefs,
      'field_carousel_rotation_ms' => self::ROTATION_MS,
    ]);
    $block->save();

    return [
      'status' => 'created',
      'block_id' => (int) $block->id(),
      'created' => count($slideRefs),
      'total' => count(self::SLIDES),
      'skipped' => $skipped,
    ];
  }

  /**
   * Resolves a curated slide's D7 legacy identity to this environment's
   * own D11 node -- never a raw D11 nid, which isn't stable across
   * independently-migrated environments (see the SLIDES docblock).
   */
  private function resolveNode(string $site, int $legacyNid): ?NodeInterface {
    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'field_legacy_site' => $site,
      'field_legacy_nid' => $legacyNid,
    ]);
    $node = reset($nodes);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Resolves (fetching + saving as a managed file if not already local)
   * the thumbnail image for a node, by bundle:
   *   - audio: reuses the already-migrated field_thumbnail_image file
   *     (real migrated data, present in every environment already --
   *     confirmed corpus-wide 2,844/4,187 audio nodes have one).
   *   - video: fetches Kaltura's live thumbnail endpoint via the same
   *     KalturaConfigResolver::thumbnailUrl() AV9's player formatter uses.
   *   - shanti_image: fetches the IIIF server's own thumbnail derivative
   *     via the same IiifUrlBuilder every other IIIF-backed view uses.
   */
  private function resolveThumbnail(NodeInterface $node): ?FileInterface {
    return match ($node->bundle()) {
      'audio' => $this->audioThumbnail($node),
      'video' => $this->fetchThumbnail($this->videoThumbnailUrl($node), 'kaltura-thumb-' . $node->id()),
      'shanti_image' => $this->fetchThumbnail($this->imageThumbnailUrl($node), 'iiif-thumb-' . $node->id()),
      default => NULL,
    };
  }

  private function audioThumbnail(NodeInterface $node): ?FileInterface {
    if ($node->get('field_thumbnail_image')->isEmpty()) {
      return NULL;
    }
    return $node->get('field_thumbnail_image')->first()->entity;
  }

  private function videoThumbnailUrl(NodeInterface $node): ?string {
    if ($node->get('field_video')->isEmpty()) {
      return NULL;
    }
    $entryId = $node->get('field_video')->first()->entry_id ?? NULL;
    if (!$entryId) {
      return NULL;
    }
    // Fetched via \Drupal::service(), not constructor DI: both
    // mandala_kaltura.resolver and shanti_iiif.url_builder are
    // registered by service id only, not aliased by class FQCN, so
    // Symfony-style autowiring (as Drush's AutowireTrait uses) can't
    // resolve them -- confirmed the hard way building the drush command
    // this replaces.
    return \Drupal::service('mandala_kaltura.resolver')->thumbnailUrl($entryId, 1200);
  }

  private function imageThumbnailUrl(NodeInterface $node): ?string {
    if ($node->get('field_iiif_id')->isEmpty()) {
      return NULL;
    }
    $iiifId = (string) $node->get('field_iiif_id')->value;
    return \Drupal::service('shanti_iiif.url_builder')->buildUrl($iiifId, 1200, NULL, 0, 'full', TRUE, 'jpg', FALSE);
  }

  /**
   * Fetches a remote thumbnail URL and saves it as a new managed file.
   * Each call gets its own filename (nid-keyed), so re-running this
   * against a partially-seeded environment never collides with a file
   * another slide already created.
   */
  private function fetchThumbnail(?string $url, string $filenameBase): ?FileInterface {
    if (!$url) {
      return NULL;
    }
    try {
      $response = \Drupal::httpClient()->request('GET', $url, ['http_errors' => FALSE, 'timeout' => 30]);
      if ($response->getStatusCode() !== 200) {
        return NULL;
      }
      $body = (string) $response->getBody();
      if ($body === '') {
        return NULL;
      }
      return $this->fileRepository->writeData($body, "public://{$filenameBase}.jpg", FileSystemInterface::EXISTS_RENAME);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
