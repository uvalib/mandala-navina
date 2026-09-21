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

  /**
   * Builds a thumbnail image URL for a Kaltura entry.
   *
   * Ports D7's real `_kaltura_thumbnail_base_url()` -- a predictable
   * per-entry URL built from the same site constants every preset already
   * carries (not a per-preset value; D7's thumbnail helper never varied by
   * view mode either). AV9's gallery card passes this straight to
   * `shanti-thumbnail`'s `default_image_url` slot: a plain `<img src>`
   * fallback, no image style, no local file, no Media entity required.
   *
   * @param string $entryId
   *   The Kaltura entry id (the field's `entry_id` property value).
   * @param int|null $width
   *   Optional target width. Omitted (the default, matching every existing
   *   caller): Kaltura's own bare thumbnail endpoint, which defaults to a
   *   fixed 120x68 -- fine for a small gallery card, too low-res for
   *   anything shown larger (confirmed live: mandala_home's hero carousel).
   *   Passing a width asks Kaltura's own resizing, preserving aspect ratio
   *   when $height is omitted (confirmed live: width=1200 alone returns a
   *   proper 1200x675 for a 16:9 source, not a stretched crop).
   * @param int|null $height
   *   Optional target height; only meaningful together with $width.
   * @param int|null $quality
   *   Optional JPEG quality (1-100); only meaningful together with $width.
   *
   * @return string
   *   The thumbnail URL.
   */
  public function thumbnailUrl(string $entryId, ?int $width = NULL, ?int $height = NULL, ?int $quality = NULL): string {
    $settings = $this->settings();
    $url = sprintf(
      '%s/p/%s/sp/%s/thumbnail/entry_id/%s',
      $settings->get('server_url'),
      $settings->get('partner_id'),
      $settings->get('subp_id'),
      $entryId,
    );
    if ($width !== NULL) {
      $url .= '/width/' . $width;
      if ($height !== NULL) {
        $url .= '/height/' . $height;
      }
      if ($quality !== NULL) {
        $url .= '/quality/' . $quality;
      }
    }
    return $url;
  }

  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('mandala_kaltura.settings');
  }

}
