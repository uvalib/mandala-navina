<?php

declare(strict_types=1);

namespace Drupal\mandala_kaltura;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

/**
 * Resolves a named Kaltura player/uploader preset to a flat, render-ready array.
 *
 * The entire read surface AV9's field formatter and AV12's upload widget need
 * from AV10's configuration layer (Sprint 3). Deliberately read-only: adding
 * or changing a preset means editing `mandala_kaltura.settings.yml` and
 * running `config:import`, engineering-owned like every other structured
 * setting in this project (confirmed with Yuji 2026-09-15) — there is no
 * write path here, and none is planned unless AV staff self-service becomes
 * an actual requirement later.
 */
class KalturaConfigResolver {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns every configured preset id, in the order they're defined.
   *
   * The only thing a formatter settings form's preset dropdown needs.
   */
  public function getPresetIds(): array {
    return array_keys($this->settings()->get('presets') ?? []);
  }

  /**
   * Resolves a preset by id, merged with the site-wide constants.
   *
   * @param string $presetId
   *   A key under `presets` in `mandala_kaltura.settings` (e.g. 'default').
   *
   * @return array|null
   *   A flat array of every preset field plus `partner_id`/`subp_id`/
   *   `server_url`, or NULL if no preset with that id exists.
   */
  public function resolve(string $presetId): ?array {
    $settings = $this->settings();
    $preset = $settings->get("presets.$presetId");
    if ($preset === NULL) {
      return NULL;
    }

    return $preset + [
      'partner_id' => $settings->get('partner_id'),
      'subp_id' => $settings->get('subp_id'),
      'server_url' => $settings->get('server_url'),
    ];
  }

  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('mandala_kaltura.settings');
  }

}
