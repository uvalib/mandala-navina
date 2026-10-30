<?php

/**
 * Spike 5 throwaway (LOCAL DDEV ONLY, never export config): legacy-key fields,
 * /sources/{id} aliases and sample legacy keys on bibcite_reference.
 *
 *   MODE=setup|teardown ddev drush php:script docs/spikes/assets/spike-05-bibcite/legacy-redirect-prototype/setup.php
 */
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\path_alias\Entity\PathAlias;

$mode = getenv('MODE') ?: 'setup';
$etm = \Drupal::entityTypeManager();
$bundles = array_keys($etm->getStorage('bibcite_reference_type')->loadMultiple());
$site = FieldStorageConfig::loadByName('node', 'field_legacy_site');
$nid = FieldStorageConfig::loadByName('node', 'field_legacy_nid');

if ($mode === 'teardown') {
  foreach (['field_legacy_site', 'field_legacy_nid'] as $name) {
    if ($s = FieldStorageConfig::loadByName('bibcite_reference', $name)) {
      $s->delete();
    }
  }
  $ids = $etm->getStorage('path_alias')->getQuery()->accessCheck(FALSE)->condition('path', '/bibcite/reference/%', 'LIKE')->execute();
  $etm->getStorage('path_alias')->delete($etm->getStorage('path_alias')->loadMultiple($ids));
  echo "removed fields and " . count($ids) . " aliases\n";
  return;
}

foreach ([['field_legacy_site', $site, 'Legacy site'], ['field_legacy_nid', $nid, 'Legacy nid']] as [$name, $src, $label]) {
  if (!FieldStorageConfig::loadByName('bibcite_reference', $name)) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'bibcite_reference'] + array_intersect_key($src->toArray(), array_flip(['type', 'settings', 'cardinality', 'module'])))->save();
  }
  foreach ($bundles as $b) {
    if (!FieldConfig::loadByName('bibcite_reference', $b, $name)) {
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'bibcite_reference', 'bundle' => $b, 'label' => $label])->save();
    }
  }
}

// Alias every reference at /sources/{id} (stand-in for pathauto).
foreach ($etm->getStorage('bibcite_reference')->loadMultiple() as $r) {
  $path = '/bibcite/reference/' . $r->id();
  if (\Drupal::service('path_alias.manager')->getAliasByPath($path) === $path) {
    PathAlias::create(['path' => $path, 'alias' => '/sources/' . $r->id(), 'langcode' => 'en'])->save();
  }
}

// Fake D7 nids deliberately unlike the D11 ids.
foreach ([47 => 12345, 46 => 67890] as $id => $legacy) {
  $r = $etm->getStorage('bibcite_reference')->load($id);
  $r->set('field_legacy_site', 'sources')->set('field_legacy_nid', $legacy)->save();
}
echo "bundles: " . count($bundles) . "; 47 => sources/12345, 46 => sources/67890\n";
