<?php

/**
 * @file
 * Idempotent builder for the Mandala Images (shanti_image) content model.
 *
 * Sprint 1, tasks 1a.1–1a.3 (see docs/sprints/sprint-01-images-implementation.md).
 * Authored from the Images Content-Model Audit (docs/planning/images-content-model-audit.md);
 * field types/cardinalities and list option sets were lifted verbatim from the D7
 * `shanti_image_type` / `shanti_external_classification` Features so the D11 model
 * round-trips the legacy data (ADR 008 faithful-migration floor).
 *
 * Builds:
 *   - taxonomy vocabulary  external_classification_scheme (+5 scheme fields)
 *   - paragraph types      image_agent, image_descriptions, external_classification
 *   - content type         shanti_image (+48 fields)
 *
 * Run:   ddev drush php:script scripts/setup/images_content_model.php
 * Then:  ddev drush cex   (export the resulting config to drupal/config/sync)
 *
 * Idempotent: re-running only creates what is missing.
 *
 * DEFERRED (intentionally not built here — flagged inline below):
 *   - KMaps search-root settings (collections root 2823, language root 301):
 *     the field type currently exposes only `kmap_domain`. This is the Spike 1
 *     tail, task 1a.4.
 *   - OG/Group fields group_content_access (Visibility) + field_og_collection_ref:
 *     handled by the Group module in Step 1b, not as node fields.
 *   - field_agent_name is a NEW field with no D7 field_base counterpart: in D7 the
 *     agent's name was the image_agent *node title*. Paragraphs have no title, so the
 *     title must migrate into an explicit field. FLAG FOR TEAM REVIEW.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\taxonomy\Entity\Vocabulary;

/** Default widget per D11 field type, for clean exported form displays. */
const WIDGETS = [
  'string' => 'string_textfield',
  'text_long' => 'text_textarea',
  'link' => 'link_default',
  'datetime' => 'datetime_default',
  'float' => 'number',
  'integer' => 'number',
  'boolean' => 'boolean_checkbox',
  'list_string' => 'options_select',
  'list_integer' => 'options_select',
  'image' => 'image_image',
  'shanti_kmaps_fields_default' => 'kmap_tree_picker',
  'entity_reference_revisions' => 'paragraphs',
  'entity_reference' => 'options_select',
];

/** Default formatter per D11 field type, for clean exported view displays. */
const FORMATTERS = [
  'string' => 'string',
  'text_long' => 'text_default',
  'link' => 'link',
  'datetime' => 'datetime_default',
  'float' => 'number_decimal',
  'integer' => 'number_integer',
  'boolean' => 'boolean',
  'list_string' => 'list_default',
  'list_integer' => 'list_default',
  'image' => 'image',
  'shanti_kmaps_fields_default' => 'kmap_default_formatter',
  'entity_reference_revisions' => 'entity_reference_revisions_entity_view',
  'entity_reference' => 'entity_reference_label',
];

$weights = [];

/**
 * Create a field (storage + instance) and wire it into the default displays.
 *
 * $spec keys: name, type, card, label, required(bool), storage(array), instance(array),
 *             default(array), widget(str override), formatter(str override).
 */
function build_field(string $entity_type, string $bundle, array $spec): void {
  global $weights;
  $name = $spec['name'];
  $type = $spec['type'];

  if (!FieldStorageConfig::loadByName($entity_type, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'type' => $type,
      'cardinality' => $spec['card'],
      'settings' => $spec['storage'] ?? [],
    ])->save();
  }

  if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
    $cfg = [
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $spec['label'],
      'required' => $spec['required'] ?? FALSE,
      'settings' => $spec['instance'] ?? [],
    ];
    if (!empty($spec['default'])) {
      $cfg['default_value'] = $spec['default'];
    }
    FieldConfig::create($cfg)->save();
  }

  $key = "$entity_type:$bundle";
  $w = $weights[$key] = ($weights[$key] ?? 0) + 1;

  $form = EntityFormDisplay::load("$entity_type.$bundle.default")
    ?? EntityFormDisplay::create([
      'targetEntityType' => $entity_type, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE,
    ]);
  $form->setComponent($name, ['type' => $spec['widget'] ?? WIDGETS[$type], 'weight' => $w])->save();

  $view = EntityViewDisplay::load("$entity_type.$bundle.default")
    ?? EntityViewDisplay::create([
      'targetEntityType' => $entity_type, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE,
    ]);
  $view->setComponent($name, ['type' => $spec['formatter'] ?? FORMATTERS[$type], 'label' => 'above', 'weight' => $w])->save();
}

// Shorthand spec builders.
$str   = fn(string $n, string $l, int $c = 1, bool $r = FALSE) => ['name' => $n, 'type' => 'string', 'card' => $c, 'label' => $l, 'required' => $r];
$long  = fn(string $n, string $l) => ['name' => $n, 'type' => 'text_long', 'card' => 1, 'label' => $l];
$link  = fn(string $n, string $l, bool $r = FALSE) => ['name' => $n, 'type' => 'link', 'card' => 1, 'label' => $l, 'required' => $r];
$date  = fn(string $n, string $l) => ['name' => $n, 'type' => 'datetime', 'card' => 1, 'label' => $l, 'storage' => ['datetime_type' => 'datetime']];
$float = fn(string $n, string $l) => ['name' => $n, 'type' => 'float', 'card' => 1, 'label' => $l];
$int   = fn(string $n, string $l) => ['name' => $n, 'type' => 'integer', 'card' => 1, 'label' => $l];
$bool  = fn(string $n, string $l, string $on, string $off) => ['name' => $n, 'type' => 'boolean', 'card' => 1, 'label' => $l, 'instance' => ['on_label' => $on, 'off_label' => $off]];
$lstr  = fn(string $n, string $l, array $opts, bool $r = FALSE, array $def = []) => ['name' => $n, 'type' => 'list_string', 'card' => 1, 'label' => $l, 'required' => $r, 'storage' => ['allowed_values' => $opts], 'default' => $def];
$lint  = fn(string $n, string $l, array $opts, bool $r = FALSE) => ['name' => $n, 'type' => 'list_integer', 'card' => 1, 'label' => $l, 'required' => $r, 'storage' => ['allowed_values' => $opts]];
$kmap  = fn(string $n, string $l, string $domain) => ['name' => $n, 'type' => 'shanti_kmaps_fields_default', 'card' => -1, 'label' => $l, 'instance' => ['kmap_domain' => $domain]];
$para  = fn(string $n, string $l, string $target, bool $r = FALSE) => [
  'name' => $n, 'type' => 'entity_reference_revisions', 'card' => -1, 'label' => $l, 'required' => $r,
  'storage' => ['target_type' => 'paragraph'],
  'instance' => ['handler' => 'default:paragraph', 'handler_settings' => ['target_bundles' => [$target => $target], 'negate' => 0]],
];

// ---------------------------------------------------------------------------
// 1a.3  Taxonomy vocabulary: external_classification_scheme
// ---------------------------------------------------------------------------
if (!Vocabulary::load('external_classification_scheme')) {
  Vocabulary::create([
    'vid' => 'external_classification_scheme',
    'name' => 'External Classification Scheme',
    'description' => 'External controlled-vocabulary schemes (Getty TGN, LoC, …) referenced by image classifications. The term name is the scheme name; the D7 scheme node title.',
  ])->save();
}
foreach ([
  $str('field_scheme_abbreviation', 'Abbreviation'),
  $link('field_scheme_home_url', 'Home URL'),
  $str('field_scheme_item_url', 'Item URL template'),
  $str('field_scheme_record_path', 'Record path'),
  $lstr('field_scheme_record_type', 'Record type', ['json' => 'JSON', 'rdf' => 'RDF', 'xml' => 'XML']),
] as $spec) {
  build_field('taxonomy_term', 'external_classification_scheme', $spec);
}

// ---------------------------------------------------------------------------
// 1a.2  Paragraph type: image_agent
// ---------------------------------------------------------------------------
if (!ParagraphsType::load('image_agent')) {
  ParagraphsType::create(['id' => 'image_agent', 'label' => 'Image Agent'])->save();
}
$agent_roles = [
  'architect' => 'Architect', 'artist' => 'Artist', 'author' => 'Author',
  'cartographer' => 'Cartographer', 'cataloger' => 'Cataloger', 'contributor' => 'Contributor',
  'designer' => 'Designer', 'digitizer' => 'Digitizer', 'editor' => 'Editor',
  'other' => 'Other', 'owner' => 'Owner', 'photographer' => 'Photographer',
  'producer' => 'Producer', 'publisher' => 'Publisher', 'sponsor' => 'Sponsor',
  'translator' => 'Translator',
];
foreach ([
  // NEW field — carries the D7 image_agent node title (the agent's name). FLAG FOR REVIEW.
  $str('field_agent_name', 'Name', 1, TRUE),
  $lstr('field_agent_role', 'Role', $agent_roles, FALSE, [['value' => 'photographer']]),
  $kmap('field_agent_place', 'Place', 'places'),
  $date('field_agent_dates', 'Dates'),
  $str('field_agent_dates_approx', 'Dates (approximate)'),
  $long('field_agent_notes', 'Notes'),
] as $spec) {
  build_field('paragraph', 'image_agent', $spec);
}

// ---------------------------------------------------------------------------
// 1a.2  Paragraph type: image_descriptions
// ---------------------------------------------------------------------------
if (!ParagraphsType::load('image_descriptions')) {
  ParagraphsType::create(['id' => 'image_descriptions', 'label' => 'Image Description'])->save();
}
foreach ([
  $long('field_description', 'Description'),
  $str('field_summary', 'Summary'),
  $str('field_author', 'Author', -1),
  // kmap_domain 'terms' is a placeholder; language search-root 301 deferred to 1a.4.
  $kmap('field_language', 'Language', 'terms'),
] as $spec) {
  build_field('paragraph', 'image_descriptions', $spec);
}

// ---------------------------------------------------------------------------
// 1a.2  Paragraph type: external_classification
// ---------------------------------------------------------------------------
if (!ParagraphsType::load('external_classification')) {
  ParagraphsType::create(['id' => 'external_classification', 'label' => 'External Classification'])->save();
}
foreach ([
  // D7 entityreference → scheme node; now references the scheme taxonomy term.
  [
    'name' => 'field_external_class_scheme', 'type' => 'entity_reference', 'card' => 1, 'label' => 'Scheme',
    'storage' => ['target_type' => 'taxonomy_term'],
    'instance' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['external_classification_scheme' => 'external_classification_scheme']]],
  ],
  $str('field_external_class_id', 'External ID'),
  $long('field_external_class_note', 'Note'),
] as $spec) {
  build_field('paragraph', 'external_classification', $spec);
}

// ---------------------------------------------------------------------------
// 1a.1  Content type: shanti_image  (48 fields; OG fields deferred to Step 1b)
// ---------------------------------------------------------------------------
if (!NodeType::load('shanti_image')) {
  NodeType::create([
    'type' => 'shanti_image',
    'name' => 'Shanti Image',
    'description' => 'Primary Mandala Images metadata record. Satellites (agents, descriptions, classifications) are embedded paragraphs; the external scheme is a taxonomy vocabulary.',
    'new_revision' => TRUE,
  ])->save();
}

$image_type_opts = [
  'artwork' => 'Artwork', 'blueprint' => 'Blueprint', 'chart' => 'Chart', 'drawing' => 'Drawing',
  'graphic' => 'Graphic', 'logo' => 'Logo', 'map' => 'Map', 'music' => 'Music Score',
  'other' => 'Other', 'photograph' => 'Photograph', 'scan-object' => 'Scan (Object)',
  'scan-text' => 'Scan (Text)', 'screenshot' => 'Screenshot', 'video-still' => 'Video Still',
];

$shanti_image_fields = [
  // Image + identity.
  [
    'name' => 'field_image', 'type' => 'image', 'card' => -1, 'label' => 'Image',
    'instance' => ['file_extensions' => 'jpg jpeg png jp2 tif tiff cr2', 'alt_field' => TRUE, 'alt_field_required' => FALSE],
  ],
  $str('field_original_filename', 'Original Filename'),
  $str('field_other_ids', 'Other IDs', -1), // legacy MMS IDs — must survive migration.

  // KMaps (Spike 1). Collections search-root 2823 deferred to 1a.4.
  $kmap('field_subjects', 'Subjects', 'subjects'),
  $kmap('field_places', 'Places', 'places'),
  $kmap('field_kmap_terms', 'Terms', 'terms'),
  $kmap('field_kmap_collections', 'Kmap Collections', 'terms'),

  // Embedded satellite paragraphs.
  $para('field_image_agents', 'Image Agents', 'image_agent', TRUE),
  $para('field_image_descriptions', 'Image Descriptions', 'image_descriptions'),
  $para('field_external_classification', 'Other Classifications', 'external_classification'),

  // Descriptive metadata (required list fields keep D7 keys verbatim).
  $lstr('field_image_type', 'Image Type', $image_type_opts, TRUE),
  $lstr('field_image_color', 'Color', ['color' => 'Color', 'bw' => 'Black & White'], TRUE),
  $lstr('field_image_quality', 'Quality', ['Excellent' => 'Excellent', 'Good' => 'Good', 'Average' => 'Average', 'Poor' => 'Poor', 'Unusable' => 'Unusable'], TRUE),
  $lint('field_image_rotation', 'Rotation', [0 => '0º', 90 => '90º', 180 => '180º', 270 => '270º'], TRUE),
  $str('field_keywords', 'Keywords'),
  $str('field_spot_feature', 'Spot Feature'),
  $str('field_physical_size', 'Physical Size'),
  $long('field_image_materials', 'Materials'),
  $long('field_image_enhancement', 'Enhancement'),
  $long('field_image_notes', 'Image Notes'),
  $str('field_general_note', 'General Note'),
  $long('field_technical_notes', 'Technical Notes'),
  $long('field_classification_notes', 'Classification Notes'),
  $long('field_admin_notes', 'Admin Notes'),
  $str('field_private_note', 'Private Note'),
  $bool('field_image_digital', 'Digital', 'Yes', 'No'),
  $bool('field_noise_reduction', 'Noise Reduction', 'On', 'Off'),

  // Rights / provenance.
  $str('field_copyright_holder', 'Copyright Holder'),
  $date('field_copyright_date', 'Copyright Date'),
  $long('field_copyright_statement', 'Copyright Statement'),
  $link('field_license_url', 'License URL', TRUE),
  $long('field_rights_notes', 'Rights Notes'),
  $str('field_organization_name', 'Organization Name'),
  $str('field_project_name', 'Project Name'),
  $str('field_sponsor_name', 'Sponsor Name'),

  // Geo.
  $str('field_latitude', 'Latitude'),
  $str('field_longitude', 'Longitude'),
  $float('field_altitude', 'Altitude'),

  // Camera / EXIF (aperture & exposure_bias are TEXT in D7, not numeric — preserved).
  $str('field_aperture', 'Aperture'),
  $str('field_exposure_bias', 'Exposure Bias'),
  $float('field_focal_length', 'Focal Length'),
  $int('field_iso_speed_rating', 'ISO Speed Rating'),
  $str('field_lens', 'Lens'),
  $str('field_light_source', 'Light Source'),
  $str('field_metering_mode', 'Metering Mode'),
  $str('field_sensing_method', 'Sensing Method'),
  $str('field_flash_settings', 'Flash Settings'),
  $str('field_image_capture_device', 'Capture Device'),
];

foreach ($shanti_image_fields as $spec) {
  build_field('node', 'shanti_image', $spec);
}

print "Images content model built. shanti_image fields: " . count($shanti_image_fields) . "\n";
print "Run: ddev drush cex  to export to config/sync.\n";
