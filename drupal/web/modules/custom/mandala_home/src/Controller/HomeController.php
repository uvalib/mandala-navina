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
 * docs/deferred/mandala-home-customizable-content-system.md) -- there is no
 * code to port. Until that real content system is designed, this is
 * deliberately a placeholder: links to the two landing pages that do exist.
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
      '#av_samples' => $this->buildSamples($this->avSamples()),
      '#image_samples' => $this->buildSamples($this->imageSamples()),
      '#group_samples' => $this->groupSamples(),
      '#attached' => ['library' => ['mandala_home/home']],
    ];
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
   * Images nodes, each representative of already-shipped Images work.
   * Leaning toward Tibetan/Chinese-content examples; Blue Grosbeak is the
   * one exception, kept because it's the specific node verified to carry
   * real IIIF data for the OpenSeadragon deep-zoom viewer (Sprint 2, #170).
   */
  private function imageSamples(): array {
    return [
      5 => 'OpenSeadragon deep-zoom IIIF viewer (Sprint 2, PR #170) with real subject metadata',
      9625 => 'Buddhas/prayers carved into a rock face (Lhasa) -- real subject metadata + collection membership',
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
