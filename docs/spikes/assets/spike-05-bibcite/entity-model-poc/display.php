<?php

/**
 * Spike 5 throwaway (LOCAL DDEV ONLY): attach a KMaps field and a Zotero
 * Collections term-reference field to every reference bundle, and fill
 * reference 46 with the real field values of D7 node 21045. The page itself is
 * rendered by spike5_bibcite_poc_bibcite_reference_view_alter().
 */
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$etm = \Drupal::entityTypeManager();
$bundles = array_keys($etm->getStorage('bibcite_reference_type')->loadMultiple());

if (!Vocabulary::load('zotero_collections')) {
  Vocabulary::create(['vid' => 'zotero_collections', 'name' => 'Zotero Collections'])->save();
}
$fields = [
  'field_zotero_collections' => ['entity_reference', ['target_type' => 'taxonomy_term'], 'Zotero Collections', ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['zotero_collections' => 'zotero_collections']]], -1],
  'field_kmaps_subjects' => ['shanti_kmaps_fields_default', ['kmap_domain' => 'subjects'], 'Subjects', [], -1],
];
foreach ($fields as $name => [$type, $ss, $label, $fs, $card]) {
  if (!FieldStorageConfig::loadByName('bibcite_reference', $name)) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'bibcite_reference', 'type' => $type, 'settings' => $ss, 'cardinality' => $card])->save();
  }
  foreach ($bundles as $b) {
    if (!FieldConfig::loadByName('bibcite_reference', $b, $name)) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'bibcite_reference', 'bundle' => $b, 'label' => $label, 'settings' => $fs])->save();
    }
  }
}
echo "KMaps field storage settings: " . json_encode(FieldStorageConfig::loadByName('bibcite_reference', 'field_kmaps_subjects')->getSettings()) . "\n";

$term = current($etm->getStorage('taxonomy_term')->loadByProperties(['name' => 'Contexts of Contemplation Project', 'vid' => 'zotero_collections']))
  ?: Term::create(['vid' => 'zotero_collections', 'name' => 'Contexts of Contemplation Project']);
$term->save();

$r = $etm->getStorage('bibcite_reference')->load(46);
$r->set('bibcite_secondary_title', 'Complementary Health Practice Review')
  ->set('bibcite_pages', '15-30')
  ->set('bibcite_url', 'http://zotero.org/groups/contemplative_sciences/items/UGS3XSG7')
  ->set('bibcite_abst_e', 'Stress-related illness presents an ever-increasing burden to society (abstract text abbreviated for the PoC).')
  ->set('field_zotero_collections', [['target_id' => $term->id()]])
  ->set('field_kmaps_subjects', [['raw' => 'Contexts of Contemplation Project|subjects-9705|2823/9697/9705', 'domain' => 'subjects', 'id' => '9705', 'header' => 'Contexts of Contemplation Project', 'path' => '2823/9697/9705', 'defids' => '']]);
$r->save();
echo "reference 46 saved with KMaps value: " . json_encode($r->get('field_kmaps_subjects')->getValue()) . "\n";
