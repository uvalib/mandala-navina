<?php

declare(strict_types=1);

namespace Drupal\mandala_home\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
      '#av_samples' => $this->buildAvSamples(),
      '#image_samples' => $this->buildSamples($this->imageSamples()),
      '#group_samples' => $this->groupSamples(),
      '#attached' => ['library' => ['mandala_home/home']],
    ];
  }

  /**
   * Builds the hero carousel render array from the single
   * mandala_home_carousel block_content instance, if one exists with
   * slides. Editors manage its content through the normal "Custom block
   * library" admin UI. Rendering goes through the entity's own view
   * builder (not a hand-built array here) so this looks and behaves
   * identically if the same block is ever placed via Block Layout or
   * Layout Builder instead of this hardcoded route -- see
   * CarouselBuilder and mandala_home_block_content_view_alter() for where
   * the actual markup comes from, and
   * docs/deferred/mandala-home-customizable-content-system.md for the
   * still-undecided direction this is meant to already be compatible with.
   */
  private function carousel(): ?array {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $blocks = $storage->loadByProperties(['type' => 'mandala_home_carousel']);
    $block = reset($blocks);
    if (!$block || $block->get('field_carousel_slides')->isEmpty()) {
      return NULL;
    }
    return $this->entityTypeManager->getViewBuilder('block_content')->view($block);
  }

  /**
   * AV nodes, each demonstrating a real fix from this session's work.
   * Leaning toward Tibetan/Chinese-language content, matching AV's actual
   * corpus (mostly Tibetan/Himalayan oral culture, some Chinese
   * translations) rather than the handful of unrelated English-language
   * test content also present.
   *
   * Keyed by D7 legacy nid (site is always 'audio-video', ADR 017's
   * composite key), NOT D11 nid. Confirmed 2026-09-22: DDEV's and dev-0's
   * AV node ids have diverged by a clean, uniform +4187 (every AV node in
   * DDEV is dev-0's id + 4187, no exceptions found; Images and Groups are
   * unaffected) -- most likely a full AV-sized migration batch created
   * and rolled back once during DDEV's own local migration-development
   * history, permanently consuming that many AUTO_INCREMENT values
   * (MySQL never reclaims them) before the currently-live migration ran.
   * A D11-nid-keyed list here silently showed WRONG content on dev-0 --
   * real nodes, just not the intended ones, sometimes even the wrong
   * bundle (an audio pick resolving to an unrelated video) -- and nothing
   * errored, so it went unnoticed until checked directly. See
   * CarouselSeeder's own docblock for the same fix applied there first,
   * and buildAvSamples() below for the resolution.
   */
  private function avSamples(): array {
    return [
      33126 => 'Multi-language description list (6 translations across English/Tibetan/Chinese, collapsed by default) + owning collection link + corrected Technical Metadata (the instantiation single-valued-winner scoring bug) + Kaltura duration',
      1773 => 'Bsang offering ritual -- Related Media now populated (field_relation_identifier backfill, was empty on 6,114 paragraphs before the migration-ordering fix)',
      1793 => 'Tibetan folktale recording -- Video/Audio Overview always shows date/title even with no creator or description (previously the whole block was silently suppressed on 16 nodes corpus-wide)',
      3551 => 'Tibetan song -- duration now sourced from the real Kaltura media length, not PBCore\'s catalog value, which disagrees here by several minutes (see docs/deferred/av15-pbcore-duration-vs-kaltura-duration.md)',
      24621 => 'Tulku Urgyen Buddhist teaching -- Availability & Access panel (field_available_from) populated with real data',
      11986 => 'Bhutanese oral account of local deities -- Technical Metadata/Details panels with real PBCore + KMaps data',
      2229 => 'Amdo (Rebgong) folktale, video -- same Technical Metadata fix as the flagship node, different region',
      1801 => 'Kham-region song recording -- another Technical Metadata/instantiation example, different region again',
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
   * Resolves avSamples()'s legacy_nid => blurb map to the same link +
   * description shape buildSamples() produces, but via
   * field_legacy_site/field_legacy_nid (ADR 017's composite key) instead
   * of a direct node load -- see avSamples()'s own docblock for why a
   * raw D11 nid isn't safe to hardcode for this content.
   */
  private function buildAvSamples(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $samples = [];
    foreach ($this->avSamples() as $legacyNid => $blurb) {
      $nodes = $storage->loadByProperties([
        'field_legacy_site' => 'audio-video',
        'field_legacy_nid' => $legacyNid,
      ]);
      $node = reset($nodes);
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
