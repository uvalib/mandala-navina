<?php

declare(strict_types=1);

namespace Drupal\mandala_home\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\image\Entity\ImageStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The site's front page.
 *
 * D7's real mandala.library.virginia.edu/ was a curated hero carousel plus
 * static feature panels, managed entirely as live editorial content (a
 * "Page" node + a custom carousel Block, see
 * docs/deferred/mandala-home-customizable-content-system.md) -- there was no
 * code to port. The D11 equivalent (mandala_home_carousel block_content type
 * + mandala_home_slide paragraphs, decided 2026-09-18) is now wired up here;
 * this page otherwise remains a placeholder for the two static feature
 * panels (D7's WYSIWYG body HTML -- the D11 equivalent is a plain Basic
 * block, not built here) and links to the two landing pages that do exist.
 *
 * The sample-content lists (added 2026-09-18, for demo purposes) are a
 * hardcoded curated set, not a query -- each one was picked and verified
 * live in DDEV specifically because it shows a real fix from this sprint's
 * AV work (or, for Images, a representative example of already-shipped
 * Sprint 1/2 work), not because it's structurally distinguished in any way
 * a query could select on. Update this list by hand as new demo-worthy
 * fixes land; there's no mechanism (and no need for one) to keep it in sync
 * automatically.
 */
class HomeController implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  public function content(): array {
    return [
      '#theme' => 'mandala_home',
      '#carousel' => $this->carousel(),
      '#av_samples' => $this->buildSamples($this->avSamples()),
      '#image_samples' => $this->buildSamples($this->imageSamples()),
      '#group_samples' => $this->groupSamples(),
      '#attached' => ['library' => ['mandala_home/home']],
    ];
  }

  /**
   * Builds the hero carousel render array from the single
   * mandala_home_carousel block_content instance, if one exists with
   * slides. Editors manage its content through the normal "Custom block
   * library" admin UI; there is deliberately no block-placement UI wiring
   * here (see docs/deferred/mandala-home-customizable-content-system.md) --
   * this page's whole markup already comes from this controller/template,
   * so the carousel is pulled in the same way.
   */
  private function carousel(): ?array {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $blocks = $storage->loadByProperties(['type' => 'mandala_home_carousel']);
    $block = reset($blocks);
    if (!$block || $block->get('field_carousel_slides')->isEmpty()) {
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
  private function linkedAssetType($url): ?string {
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

  /**
   * AV nodes, each demonstrating a real fix from this session's work.
   * Leaning toward Tibetan/Chinese-language content, matching AV's actual
   * corpus (mostly Tibetan/Himalayan oral culture, some Chinese
   * translations) rather than the handful of unrelated English-language
   * test content also present.
   */
  private function avSamples(): array {
    return [
      126524 => 'Multi-language description list (6 translations across English/Tibetan/Chinese, collapsed by default) + owning collection link + corrected Technical Metadata (the instantiation single-valued-winner scoring bug) + Kaltura duration',
      121099 => 'Bsang offering ritual -- Related Media now populated (field_relation_identifier backfill, was empty on 6,114 paragraphs before the migration-ordering fix)',
      115866 => 'Tibetan folktale recording -- Video/Audio Overview always shows date/title even with no creator or description (previously the whole block was silently suppressed on 16 nodes corpus-wide)',
      122368 => 'Tibetan song -- duration now sourced from the real Kaltura media length, not PBCore\'s catalog value, which disagrees here by several minutes (see docs/deferred/av15-pbcore-duration-vs-kaltura-duration.md)',
      116965 => 'Tulku Urgyen Buddhist teaching -- Availability & Access panel (field_available_from) populated with real data',
      116809 => 'Bhutanese oral account of local deities -- Technical Metadata/Details panels with real PBCore + KMaps data',
      121384 => 'Amdo (Rebgong) folktale, video -- same Technical Metadata fix as the flagship node, different region',
      115874 => 'Kham-region song recording -- another Technical Metadata/instantiation example, different region again',
    ];
  }

  /**
   * Images nodes, each representative of already-shipped Images work,
   * leaning toward Tibetan/Chinese-content examples.
   *
   * nid 5 (Blue Grosbeak) was here as the IIIF/OpenSeadragon deep-zoom
   * demo pick -- dropped 2026-09-18: confirmed it's one of 10 known
   * test/dev images (nid 1-10) whose IIIF viewer 404s on a legacy S3 key
   * layout (docs/deferred/iiif-cantaloupe-404-information-disclosure.md).
   * The node itself still loads fine (200), only the embedded image is
   * broken, which would have been actively misleading in a demo. The
   * remaining picks below already carry real IIIF data (confirmed live),
   * so no replacement was needed.
   */
  private function imageSamples(): array {
    return [
      9625 => 'Deep-zoom IIIF viewer: buddhas/prayers carved into a rock face (Lhasa) -- real subject metadata + collection membership',
      9626 => 'Pilgrims spinning prayer wheels (Lhasa)',
      9627 => 'Prayer flags on 1000 Buddha Hill (Lhasa)',
      22143 => 'Chinese restaurant owner at Lhasa Gongkar Airport -- Chinese/Tibetan intersection',
      1209 => 'Historic photo: chortens at the entrance to Lhasa (Frederick Williamson Collection, 1930s)',
      16913 => 'Mural of Guru Dragphur, a form of Guru Rinpoché (Tsering Gyalpo Collection)',
      17032 => 'Maitreya, Buddha of the Future, mural (Tsering Gyalpo Collection)',
    ];
  }

  /**
   * Renders a nid => blurb map into link + description pairs the template
   * can iterate over.
   */
  private function buildSamples(array $nidsToBlurbs): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $samples = [];
    foreach ($nidsToBlurbs as $nid => $blurb) {
      $node = $storage->load($nid);
      if (!$node) {
        continue;
      }
      $samples[] = [
        'label' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'blurb' => $blurb,
      ];
    }
    return $samples;
  }

  /**
   * A mix of real collections and subcollections, drawn from both AV and
   * Images membership (so the front page also demos ADR 011's group
   * nesting, not just individual nodes -- and shows that the same group
   * model serves both asset types, not one built for Images and reused).
   */
  private function groupSamples(): array {
    $storage = $this->entityTypeManager->getStorage('group');
    $groups = [
      172 => 'Collection, 1,807 AV items -- the flagship AV node\'s top-level collection',
      387 => 'Subcollection (AV) -- the flagship AV node\'s actual owning group, one level under Tibetan and Himalayan Library (ADR 011 nesting)',
      16 => 'Collection, 1,243 Images members',
      327 => 'Subcollection (AV), 181 members -- Amdo region',
      79 => 'Subcollection (Images), 4,091 members -- Drepung Monastery',
      86 => 'Subcollection (Images), 2,594 members -- Lhasa',
      337 => 'Subcollection (AV), 487 members -- Gangsol Nomadic Oral Folk Traditions',
      25 => 'Collection (Images), 699 members -- Tsering Gyalpo, the source of the mural images above',
    ];
    $samples = [];
    foreach ($groups as $gid => $blurb) {
      $group = $storage->load($gid);
      if (!$group) {
        continue;
      }
      $samples[] = [
        'label' => $group->label(),
        'url' => $group->toUrl()->toString(),
        'blurb' => $blurb,
      ];
    }
    return $samples;
  }

}
