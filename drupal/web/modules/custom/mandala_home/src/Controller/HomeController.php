<?php

declare(strict_types=1);

namespace Drupal\mandala_home\Controller;

/**
 * The site's front page.
 *
 * D7's real mandala.library.virginia.edu/ was a curated hero carousel plus
 * static feature panels, managed entirely as live editorial content (a
 * "Page" node + a custom carousel Block, see
 * docs/deferred/mandala-home-customizable-content-system.md) -- there is no
 * code to port. Until that real content system is designed, this is
 * deliberately a placeholder: links to the two landing pages that do exist.
 */
class HomeController {

  public function content(): array {
    return [
      '#theme' => 'mandala_home',
    ];
  }

}
