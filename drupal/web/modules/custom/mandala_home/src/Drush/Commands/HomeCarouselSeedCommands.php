<?php

declare(strict_types=1);

namespace Drupal\mandala_home\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Manual/on-demand entry point for CarouselSeeder -- see that class's own
 * docblock for the full story (why this exists, and why it's deliberately
 * NOT wired into hook_update_N). This command is what actually fixed
 * dev-0 2026-09-22: a module already installed in an environment never
 * gets a fresh hook_install() run, so mandala_home_install() alone can't
 * retroactively backfill an environment that predates it.
 */
class HomeCarouselSeedCommands extends DrushCommands {

  #[CLI\Command(name: 'mandala:home-carousel-seed')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would be created/fetched without saving anything.')]
  #[CLI\Usage(name: 'drush mandala:home-carousel-seed --dry-run', description: 'Report coverage without saving anything.')]
  #[CLI\Usage(name: 'drush mandala:home-carousel-seed', description: 'Create the singleton carousel block + its slides, if none exists yet.')]
  public function seed(array $options = ['dry-run' => FALSE]): void {
    // \Drupal::service(), not constructor DI: Drush's AutowireTrait
    // resolves constructor args by Symfony type-based autowiring, which
    // fails for mandala_home.carousel_seeder since it's registered by
    // service id only, not aliased by class FQCN -- confirmed the hard
    // way building this command originally.
    $result = \Drupal::service('mandala_home.carousel_seeder')->seed((bool) $options['dry-run']);

    if ($result['skipped']) {
      $this->logger()->warning('Skipped: ' . implode('; ', $result['skipped']));
    }

    switch ($result['status']) {
      case 'already_exists':
        $this->logger()->success(sprintf(
          'mandala_home_carousel block_content already exists (id=%d, %d slide(s)) -- nothing to do. Delete it first (drush entity:delete block_content %d) to reseed.',
          $result['block_id'], $result['created'], $result['block_id']
        ));
        break;

      case 'dry_run':
        $this->logger()->success(sprintf('Dry run: %d of %d slide(s) resolvable.', $result['created'], $result['total']));
        break;

      case 'no_slides':
        $this->logger()->error('No slides could be built -- no block_content created.');
        break;

      case 'created':
        $this->logger()->success(sprintf('Created mandala_home_carousel block_content (id=%d) with %d of %d slide(s).', $result['block_id'], $result['created'], $result['total']));
        break;
    }
  }

}
