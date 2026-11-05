<?php

/**
 * Spike 13 S2 setup (throwaway). Run: ddev drush php:script <this file>.
 *
 * Adds the `field_book_content` text_long field (D7's section text) to the
 * `book` bundle so the S1 stand-in nodes can carry real content.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

if (!FieldStorageConfig::loadByName('node', 'field_book_content')) {
  FieldStorageConfig::create([
    'field_name' => 'field_book_content',
    'entity_type' => 'node',
    'type' => 'text_long',
  ])->save();
}
if (!FieldConfig::loadByName('node', 'book', 'field_book_content')) {
  FieldConfig::create([
    'field_name' => 'field_book_content',
    'entity_type' => 'node',
    'bundle' => 'book',
    'label' => 'Book content',
  ])->save();
}
echo "field_book_content ready\n";
