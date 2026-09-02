<?php

namespace Drupal\shanti_iiif;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds URLs against the configured IIIF Image API 2.x server.
 *
 * Pure URL builder — no DB, no HTTP. Mirrors the static
 * ShantiImage::buildIIIFURL from D7 but reads from config instead of D7
 * variables, and drops the legacy env-suffix swap (handled now by per-env
 * config overrides per the DSF settings convention).
 *
 * IIIF Image API 2.x URL form:
 *   {server}{path}{identifier}/{region}/{size}/{rotation}/{quality}.{format}
 */
class IiifUrlBuilder {

  protected ConfigFactoryInterface $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Build an IIIF Image API derivative URL.
   *
   * @param string $i3fid
   *   IIIF identifier — e.g. "shanti-image-680687".
   * @param int|string|null $width
   *   Target width, or NULL/empty for unconstrained width.
   * @param int|string|null $height
   *   Target height, or NULL/empty to mirror $width (when $scaled) or
   *   leave unconstrained.
   * @param int $rotation
   *   Rotation in degrees (0, 90, 180, 270).
   * @param string $region
   *   IIIF region — "full" or "x,y,w,h" or "pct:x,y,w,h" or "square".
   * @param bool $scaled
   *   Whether to use IIIF "scale within bounds" (prefixes "!"). When false,
   *   uses exact w,h which can distort. D7 default is true.
   * @param string $format
   *   Output format — "jpg" (default), "png", "tif", "gif".
   * @param bool $upscale
   *   Whether to allow enlarging past the source's native size (IIIF "^"
   *   prefix, combined with $scaled as "^!w,h"). Only meaningful when
   *   $scaled is TRUE. Default FALSE preserves prior behavior for existing
   *   callers; grid/masonry thumbnails want TRUE so a tile always fills its
   *   box exactly regardless of the source image's native resolution.
   */
  public function buildUrl(
    string $i3fid,
    int|string|null $width = 800,
    int|string|null $height = NULL,
    int $rotation = 0,
    string $region = 'full',
    bool $scaled = TRUE,
    string $format = 'jpg',
    bool $upscale = FALSE,
  ): string {
    $base = $this->identifierBase($i3fid);
    $size = $this->buildSize($width, $height, $scaled, $upscale);
    return $base . '/' . $region . '/' . $size . '/' . $rotation . '/default.' . $format;
  }

  /**
   * Build the info.json URL for an identifier.
   */
  public function infoUrl(string $i3fid): string {
    return $this->identifierBase($i3fid) . '/info.json';
  }

  /**
   * Resolve {server}{path}{i3fid} for the configured server.
   */
  protected function identifierBase(string $i3fid): string {
    $config = $this->configFactory->get('shanti_iiif.settings');
    $server = rtrim((string) $config->get('view_url'), '/');
    $path = (string) $config->get('view_path') ?: '/';
    if (!str_starts_with($path, '/')) {
      $path = '/' . $path;
    }
    if (!str_ends_with($path, '/')) {
      $path .= '/';
    }
    return $server . $path . $i3fid;
  }

  /**
   * Compose the IIIF size segment from width/height/scaled.
   */
  protected function buildSize(int|string|null $width, int|string|null $height, bool $scaled, bool $upscale = FALSE): string {
    $w = ($width === NULL || $width === '') ? NULL : $width;
    $h = ($height === NULL || $height === '') ? NULL : $height;

    if ($w === NULL && $h === NULL) {
      return 'full';
    }

    if ($h === NULL && $scaled) {
      $h = $w;
    }

    // The "!" (and "^!") scale-mode prefix requires both dimensions -- the
    // Cantaloupe server this reads from returns 400 for e.g. "^!,250"
    // (confirmed live). When only one dimension is given, IIIF's own
    // unprefixed "w," / ",h" syntax already means "fit within bounds,
    // preserve aspect ratio" for that single dimension, so no prefix (and
    // no $upscale) applies -- there's nothing left for "!" to mean.
    $prefix = ($scaled && $w !== NULL && $h !== NULL) ? (($upscale ? '^' : '') . '!') : '';
    return $prefix . ($w ?? '') . ',' . ($h ?? '');
  }

}
