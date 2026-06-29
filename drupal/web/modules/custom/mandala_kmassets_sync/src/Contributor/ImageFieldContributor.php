<?php

declare(strict_types=1);

namespace Drupal\mandala_kmassets_sync\Contributor;

use Drupal\node\NodeInterface;
use Drupal\shanti_iiif\IiifUrlBuilder;

/**
 * Adds the image-specific fields to a kmasset document (contract table C).
 *
 * Faithful D11 port of D7 shanti_images_kmaps_fields_solr_doc_alter()
 * (shanti_images.module:1625). Source fields it reads on the D11 shanti_image
 * node:
 *  - field_iiif_id / field_iiif_width / field_iiif_height — IIIF identity
 *    (replaces D7 $node->shanti_uid + _shanti_images_get_node_image()).
 *  - field_image_agents (image_agent paragraphs) → creator + date_start.
 *  - field_image_descriptions (image_descriptions paragraphs) → caption/summary/
 *    description (+ _lang_s) and the presence-conditional *_alt_* arrays.
 *  - field_image_rotation → rotation_s (the D7 $sdog typo meant this was never
 *    written; D11 emits it correctly — contract §7.3.4).
 */
class ImageFieldContributor implements KmassetDocContributorInterface {

  /**
   * IIIF thumbnail bounding box (px). D7 _shanti_images getURL(200, 200).
   */
  protected const THUMB_BOX = 200;

  protected IiifUrlBuilder $iiif;

  public function __construct(IiifUrlBuilder $iiif) {
    $this->iiif = $iiif;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(NodeInterface $node): bool {
    return $node->bundle() === 'shanti_image';
  }

  /**
   * {@inheritdoc}
   */
  public function contribute(array &$doc, NodeInterface $node): void {
    $iiif_id = $this->fieldValue($node, 'field_iiif_id');

    if ($iiif_id !== NULL) {
      $doc['shanti_image_id_s'] = $iiif_id;
    }

    // caption defaults to the node title; overwritten below if descriptions
    // exist (D7 shanti_images.module:1636).
    $doc['caption'] = $node->label();

    $this->contributeCreatorAndDate($doc, $node);
    $this->contributeDescriptions($doc, $node);
    $this->contributeImageGeometry($doc, $node, $iiif_id);
  }

  /**
   * creator ← first agent's name; date_start ← first agent's date.
   */
  protected function contributeCreatorAndDate(array &$doc, NodeInterface $node): void {
    if ($node->get('field_image_agents')->isEmpty()) {
      // D7 unsets date_start when there is no main agent; normalization then
      // defaults it to the epoch.
      unset($doc['date_start']);
      return;
    }

    $agents = $node->get('field_image_agents')->referencedEntities();
    $agent = $agents[0] ?? NULL;
    if ($agent === NULL) {
      unset($doc['date_start']);
      return;
    }

    if ($agent->hasField('field_agent_name') && !$agent->get('field_agent_name')->isEmpty()) {
      $doc['creator'] = $agent->get('field_agent_name')->value;
    }

    if ($agent->hasField('field_agent_dates') && !$agent->get('field_agent_dates')->isEmpty()) {
      // The stored value is a wall-clock date (D7 cataloguer input, carried as
      // 'Y-m-d\TH:i:s'). D7 formatted it with date() in the server's local TZ
      // and labelled it 'Z', so the golden value is the wall clock verbatim.
      // Anchor parsing to UTC to preserve that wall clock rather than shifting.
      $raw = (string) $agent->get('field_agent_dates')->value;
      try {
        $dt = new \DateTime($raw, new \DateTimeZone('UTC'));
        $doc['date_start'] = gmdate('Y-m-d\TH:i:s\Z', $dt->getTimestamp());
      }
      catch (\Exception $e) {
        unset($doc['date_start']);
      }
    }
    else {
      unset($doc['date_start']);
    }
  }

  /**
   * The caption/summary/description family + the *_alt_* arrays.
   *
   * D7 shanti_images.module:1654-1715. The first description fills the primary
   * fields; any remaining descriptions fill the presence-conditional *_alt_*
   * arrays (contract §7.3.5).
   */
  protected function contributeDescriptions(array &$doc, NodeInterface $node): void {
    if ($node->get('field_image_descriptions')->isEmpty()) {
      return;
    }

    $descs = [];
    foreach ($node->get('field_image_descriptions')->referencedEntities() as $para) {
      // Use the filter-processed output, matching D7's safe_value (the golden
      // descriptions carry filtered HTML — <p>/<br> — not the raw input).
      $description = '';
      if ($para->hasField('field_description') && !$para->get('field_description')->isEmpty()) {
        $description = (string) $para->get('field_description')->processed;
      }
      $summary = $para->hasField('field_summary') ? (string) $para->get('field_summary')->value : '';
      if ($summary === '') {
        $summary = $this->truncateSummary($description);
      }
      $language = 'en';
      if ($para->hasField('field_language') && !$para->get('field_language')->isEmpty()) {
        $language = $para->get('field_language')->first()->get('header')->getValue() ?: 'en';
      }
      $authors = [];
      if ($para->hasField('field_author')) {
        foreach ($para->get('field_author') as $author) {
          $authors[] = $author->value;
        }
      }
      $descs[] = [
        'caption' => $para->hasField('field_description_title') ? (string) $para->get('field_description_title')->value : '',
        'summary' => $summary,
        'description' => $description,
        'language' => $language,
        'authors' => $authors,
      ];
    }

    // First description → primary fields.
    $primary = array_shift($descs);
    $doc['caption'] = $primary['caption'];
    $doc['caption_lang_s'] = $primary['language'];
    $doc['summary'] = $primary['summary'];
    $doc['summary_lang_s'] = $primary['language'];
    $doc['description'] = $primary['description'];
    $doc['description_lang_s'] = $primary['language'];
    $doc['description_type_s'] = 'general description';
    $doc['description_authors_s'] = implode(', ', $primary['authors']);

    // Remaining descriptions → alt arrays (only present on multi-desc images).
    if (empty($descs)) {
      return;
    }
    $doc['caption_alt_txt'] = [];
    $doc['caption_alt_lang_ss'] = [];
    $doc['summary_alt_txt'] = [];
    $doc['summary_alt_lang_ss'] = [];
    $doc['description_alt_txt'] = [];
    $doc['description_alt_lang_ss'] = [];
    $doc['description_alt_type_ss'] = [];
    $doc['description_alt_authors_ss'] = [];
    foreach ($descs as $desc) {
      $doc['caption_alt_txt'][] = $desc['caption'];
      $doc['caption_alt_lang_ss'][] = $desc['language'];
      $doc['summary_alt_txt'][] = $desc['summary'];
      $doc['summary_alt_lang_ss'][] = $desc['language'];
      $doc['description_alt_txt'][] = $desc['description'];
      $doc['description_alt_lang_ss'][] = $desc['language'];
      $doc['description_alt_type_ss'][] = 'general description';
      $doc['description_alt_authors_ss'][] = implode(', ', $desc['authors']);
    }
  }

  /**
   * IIIF thumb URL + geometry, source dimensions, rotation, info.json URL.
   *
   * D7 shanti_images.module:1729-1748.
   */
  protected function contributeImageGeometry(array &$doc, NodeInterface $node, ?string $iiif_id): void {
    if ($iiif_id === NULL) {
      return;
    }

    $width = (int) ($this->fieldValue($node, 'field_iiif_width') ?? 0);
    $height = (int) ($this->fieldValue($node, 'field_iiif_height') ?? 0);

    $doc['url_thumb'] = $this->iiif->buildUrl($iiif_id, self::THUMB_BOX, self::THUMB_BOX, 0, 'full', TRUE);
    $doc['url_iiif_s'] = $this->iiif->infoUrl($iiif_id);
    $doc['url_thumb_size'] = '30000';

    if ($width > 0 && $height > 0) {
      $ratio = $width / $height;
      if ($width > $height) {
        $thumb_w = self::THUMB_BOX;
        $thumb_h = (int) floor(self::THUMB_BOX / $ratio);
      }
      else {
        $thumb_h = self::THUMB_BOX;
        $thumb_w = (int) floor(self::THUMB_BOX * $ratio);
      }
      $doc['url_thumb_width'] = (string) $thumb_w;
      $doc['url_thumb_height'] = (string) $thumb_h;
      $doc['img_width_s'] = (string) $width;
      $doc['img_height_s'] = (string) $height;
    }

    // rotation_s — the field the D7 typo dropped. Emit it correctly here.
    if ($node->hasField('field_image_rotation') && !$node->get('field_image_rotation')->isEmpty()) {
      $doc['rotation_s'] = (string) $node->get('field_image_rotation')->value;
    }
  }

  /**
   * Truncates a description to a ≤550-char summary, breaking on a space.
   *
   * D7 shanti_images.module:1673-1679.
   */
  protected function truncateSummary(string $description): string {
    $trunc = preg_replace('/\s+/', ' ', strip_tags($description));
    $truncate = strlen($trunc) > 550;
    $spos = $truncate ? strpos($trunc, ' ', 500) : strlen($trunc);
    if ($spos === FALSE) {
      $spos = strlen($trunc);
    }
    return trim(substr($trunc, 0, $spos), " \n\r\t\v\x00,:;!") . ($truncate ? '...' : '');
  }

  /**
   * Returns the scalar `value` of a single-value field, or NULL if empty.
   */
  protected function fieldValue(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $value = $node->get($field)->value;
    return ($value === NULL || $value === '') ? NULL : (string) $value;
  }

}
