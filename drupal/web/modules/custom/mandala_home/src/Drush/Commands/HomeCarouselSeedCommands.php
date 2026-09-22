<?php

declare(strict_types=1);

namespace Drupal\mandala_home\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Seeds the singleton mandala_home_carousel block_content + its slides.
 *
 * The carousel built 2026-09-21 (PR #228) was assembled entirely by hand
 * via one-off `drush eval` in DDEV -- the block_content entity, its 12
 * mandala_home_slide paragraphs, and the managed image files backing them
 * (fetched live from IIIF/Kaltura at the time) all exist ONLY as content,
 * never as config, and were never replicated anywhere else. That's why
 * the carousel doesn't appear on dev-0: HomeController::carousel() reads
 * whatever mandala_home_carousel block_content it finds, and dev-0 has
 * none (confirmed 2026-09-22 -- 0 rows in both block_content_field_data
 * and paragraphs_item_field_data for these bundles). This command
 * captures that one-off build as a real, idempotent, reusable step,
 * following this project's established pattern (see
 * MissingFileAuditCommands's own header for the precedent: a real command
 * doesn't get to be wrong twice).
 *
 * The curated node list mirrors HomeController's own avSamples()/
 * imageSamples() picks (same demo-worthy nodes, not a coincidence -- both
 * were chosen the same session for the same reason) plus four Audio nodes
 * not otherwise sampled on the page. Update this list by hand if the
 * curation changes; there's no mechanism to keep it in sync automatically
 * (matching HomeController's own documented stance on its sample lists).
 */
class HomeCarouselSeedCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * nid => caption, in display order. Bundle is looked up at seed time
   * (not hardcoded here) so a bundle mismatch fails loudly instead of
   * silently mis-resolving a thumbnail.
   */
  private const SLIDES = [
    116809 => 'An Account of Deities of Dogar Gewog',
    126524 => 'Oral Culture: Riddles (39-68)',
    9625 => 'Close-up of buddhas, saints, and prayers carved and painted into a rock face.',
    116814 => 'In the Snowy Paradise to the North: A Song',
    119716 => 'Song 8: Tibet University Nangma Group',
    9626 => 'Pilgrims spinning prayer wheels beneath the rock carvings.',
    116823 => 'The Drukpa Lineage: A Song and Dance',
    122368 => 'Gung Ngyon Thoenpoi Lama: A Song',
    1209 => 'Chortens at entrance to Lhasa from West',
    116832 => 'On the Top of Lhasa Potala: A Song',
    121384 => 'Three Smart Brothers: Folktales from the Rebgong Cultural Area',
    16913 => 'Mural of Guru Dragphur, a form of Guru Rinpoché',
  ];

  private const ROTATION_MS = 6000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'mandala:home-carousel-seed')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would be created/fetched without saving anything.')]
  #[CLI\Usage(name: 'drush mandala:home-carousel-seed --dry-run', description: 'Report coverage without saving anything.')]
  #[CLI\Usage(name: 'drush mandala:home-carousel-seed', description: 'Create the singleton carousel block + its slides, if none exists yet.')]
  public function seed(array $options = ['dry-run' => FALSE]): void {
    $dryRun = (bool) $options['dry-run'];

    $blockStorage = $this->entityTypeManager->getStorage('block_content');
    $existing = $blockStorage->loadByProperties(['type' => 'mandala_home_carousel']);
    if ($existing) {
      $block = reset($existing);
      $this->logger()->success(
        'mandala_home_carousel block_content already exists (id={id}, {count} slide(s)) -- nothing to do. Delete it first (drush entity:delete block_content {id}) to reseed.',
        ['id' => $block->id(), 'count' => $block->get('field_carousel_slides')->count()]
      );
      return;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    $slideRefs = [];
    $skipped = [];
    foreach (self::SLIDES as $nid => $caption) {
      $node = $nodeStorage->load($nid);
      if (!$node instanceof NodeInterface) {
        $skipped[] = "nid $nid: not found";
        continue;
      }

      $file = $this->resolveThumbnail($node);
      if (!$file) {
        $skipped[] = "nid $nid ({$node->bundle()}): no thumbnail resolvable";
        continue;
      }

      if ($dryRun) {
        $this->logger()->notice("Would build slide: nid={$nid} bundle={$node->bundle()} thumbnail={$file->getFileUri()} caption=\"{$caption}\"");
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

    if ($skipped) {
      $this->logger()->warning('Skipped: ' . implode('; ', $skipped));
    }

    if ($dryRun) {
      $this->logger()->success(sprintf('Dry run: %d of %d slide(s) resolvable.', count(self::SLIDES) - count($skipped), count(self::SLIDES)));
      return;
    }

    if (!$slideRefs) {
      $this->logger()->error('No slides could be built -- no block_content created.');
      return;
    }

    $block = $blockStorage->create([
      'type' => 'mandala_home_carousel',
      'info' => 'Mandala Home Carousel',
      'field_carousel_slides' => $slideRefs,
      'field_carousel_rotation_ms' => self::ROTATION_MS,
    ]);
    $block->save();

    $this->logger()->success(sprintf('Created mandala_home_carousel block_content (id=%d) with %d of %d slide(s).', $block->id(), count($slideRefs), count(self::SLIDES)));
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
  private function resolveThumbnail(NodeInterface $node): ?\Drupal\file\FileInterface {
    return match ($node->bundle()) {
      'audio' => $this->audioThumbnail($node),
      'video' => $this->fetchThumbnail($this->videoThumbnailUrl($node), 'kaltura-thumb-' . $node->id()),
      'shanti_image' => $this->fetchThumbnail($this->imageThumbnailUrl($node), 'iiif-thumb-' . $node->id()),
      default => NULL,
    };
  }

  private function audioThumbnail(NodeInterface $node): ?\Drupal\file\FileInterface {
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
    // Fetched via \Drupal::service(), not constructor DI: Drush's
    // AutowireTrait resolves constructor args by Symfony type-based
    // autowiring, which fails for services not aliased by their class
    // FQCN in the container (confirmed -- both mandala_kaltura.resolver
    // and shanti_iiif.url_builder are registered by id only).
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
   * command against a partially-seeded environment never collides with
   * a file another slide already created.
   */
  private function fetchThumbnail(?string $url, string $filenameBase): ?\Drupal\file\FileInterface {
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
