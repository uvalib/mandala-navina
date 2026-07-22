<?php

namespace Drupal\spike_footnotes_demo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Spike 4b, Option 3 prototype.
 *
 * Assembles a composite "book view" (all pages sharing a book id, D7-style)
 * and an end-of-book footnote Notes list built by reading a dedicated
 * resolved-data table directly — NOT via the footnotes module's stock
 * FootnotesGroup accumulator, which is empirically confirmed to silently
 * drop entries for any page whose entity render output is already cached
 * (see docs/spikes/spike-04b-ckeditor5-footnotes.md, "CONFIRMED" section).
 *
 * The per-citation links (rendered by the footnotes filter on each page)
 * are unaffected by that bug either way, since the resolved text is baked
 * into each citation's own <footnotes data-value data-text> tag — this
 * controller only replaces the *aggregation* mechanism, per Option 3.
 */
class BookNotesDemoController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * Renders the composite book view for a given book id.
   */
  public function view($bid) {
    $rows = $this->database->select('spike_footnotes_resolved', 'r')
      ->fields('r', ['nid', 'page_weight', 'number', 'text'])
      ->condition('bid', $bid)
      ->orderBy('page_weight')
      ->orderBy('number')
      ->execute()
      ->fetchAll();

    if (!$rows) {
      return [
        '#markup' => $this->t('No demo data found for book id @bid. Run scripts/seed-demo.php first.', ['@bid' => $bid]),
      ];
    }

    $nids = [];
    foreach ($rows as $row) {
      $nids[$row->nid] = $row->nid;
    }

    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    $pages_build = [];
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if (!$node) {
        continue;
      }
      $pages_build[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['spike-book-page'], 'data-nid' => $nid],
        'title' => ['#markup' => '<h3>' . $node->label() . ' (nid ' . $nid . ')</h3>'],
        'body' => $view_builder->view($node, 'full'),
      ];
    }

    // Option 3: build the Notes list from the dedicated table, not from
    // Drupal::service('footnotes.group') / the filter's static accumulator.
    // This is correct regardless of which pages above were cache HITs vs
    // MISSes, because it never reads render output or in-request state at
    // all — it reads the same resolved data the citations themselves were
    // built from.
    $notes_items = [];
    foreach ($rows as $row) {
      $notes_items[] = [
        '#markup' => '<strong>[' . $row->number . ']</strong> ' . $row->text
          . ' <em>(from nid ' . $row->nid . ')</em>',
      ];
    }

    return [
      'explanation' => [
        '#markup' => '<p><em>Spike 4b / Option 3 demo — pages above rendered independently (some may be render-cache hits from a prior standalone view); Notes list below assembled from the dedicated <code>spike_footnotes_resolved</code> table, not the footnotes module\'s stock accumulator.</em></p>',
      ],
      'pages' => [
        '#theme' => 'item_list',
        '#items' => $pages_build,
        '#title' => $this->t('Book pages (bid=@bid)', ['@bid' => $bid]),
        '#list_type' => 'div',
      ],
      'notes' => [
        '#theme' => 'item_list',
        '#items' => $notes_items,
        '#title' => $this->t('Notes (assembled via Option 3, book-wide)'),
        '#list_type' => 'ol',
      ],
    ];
  }

}
