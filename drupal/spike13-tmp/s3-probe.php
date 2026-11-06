<?php
foreach (\Drupal\group\Entity\GroupRelationshipType::loadMultiple() as $t) { if (str_starts_with($t->getPluginId(),'group_node:')) echo $t->id(),' ',$t->getPluginId(),"\n"; }
echo "roles: ", implode(',', array_keys(\Drupal\user\Entity\Role::loadMultiple())), "\n";
echo "gcca on book: ", \Drupal\field\Entity\FieldConfig::load('node.book.field_group_content_access') ? 'yes':'no', "; storage: ", \Drupal\field\Entity\FieldStorageConfig::load('node.field_group_content_access') ? 'yes':'no', "\n";
$g = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['type'=>'collection']);
foreach (array_slice($g,0,400) as $x) { $v=$x->get('field_group_access')->value; $c[$v]=($c[$v]??0)+1; if(!isset($ex[$v])) $ex[$v]=$x->id().' '.$x->label(); }
print_r($c); print_r($ex);
$bk = \Drupal::database()->query("select bid,count(*) c from book where bid in (123372,123746,123748,123761,123816,123817) group by bid")->fetchAll(); print_r($bk);
